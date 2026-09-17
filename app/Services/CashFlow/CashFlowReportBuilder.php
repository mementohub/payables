<?php

namespace App\Services\CashFlow;

use App\Models\CashFlowSnapshot;
use App\Models\CharterContract;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Builds the WCFR 52 Weeks snapshot: the opening cash position rolled forward
 * with OMC, the receivables and supplier payables of the bookings in eTrip,
 * the charter contracts kept in the app, the OPEX averages, the new-sales
 * scenario from last year's booking curve and last year's actual flows for
 * the comparison. Every source is read on its own so one failure leaves the
 * others in the report, flagged on the sources panel.
 */
class CashFlowReportBuilder
{
    /** Weeks before the horizon kept in the snapshot as actual flows. */
    public const RECENT_WEEKS = 13;

    private WeekGrid $grid;

    private CarbonImmutable $today;

    /** @var array<string, mixed> */
    private array $params = [];

    /** @var array<string, float> */
    private array $fx = [];

    /** Last closed month-end in OMC, when the position was read from it. */
    private ?CarbonImmutable $anchor = null;

    /** @var list<array{key: string, label: string, status: string, message: ?string, ms: int, rows: int}> */
    private array $sources = [];

    /** @var list<array<string, mixed>> */
    private array $lines = [];

    /** @var array<string, mixed> */
    private array $detail = [];

    public function __construct(
        private EtripCashFlowReader $etrip,
        private OmcCashFlowReader $omc,
        private CashFlowParameters $parameters,
        private ReceivablesScheduler $scheduler,
    ) {}

    public function build(?string $builtBy = null, ?CarbonInterface $today = null): CashFlowSnapshot
    {
        $startedAt = microtime(true);
        $this->today = CarbonImmutable::instance($today ?? CarbonImmutable::now((string) config('cashflow.timezone', 'Europe/Bucharest')))->startOfDay();
        $this->grid = new WeekGrid($this->today, (int) config('cashflow.weeks', 52));
        $this->params = $this->parameters->load();
        $this->sources = [];
        $this->lines = [];
        $this->detail = [];
        $this->anchor = null;

        $snapshot = new CashFlowSnapshot([
            'built_at' => now(),
            'week_start' => $this->grid->start->toDateString(),
            'built_by' => $builtBy,
            'status' => CashFlowSnapshot::STATUS_FAILED,
        ]);

        try {
            $payload = $this->compute();
            $failed = array_filter($this->sources, fn (array $source) => $source['status'] === 'error');

            $snapshot->payload = $payload;
            $snapshot->status = $failed === [] ? CashFlowSnapshot::STATUS_OK : CashFlowSnapshot::STATUS_PARTIAL;
            $snapshot->error = $failed === [] ? null : implode("\n", array_map(fn (array $source) => "{$source['label']}: {$source['message']}", $failed));
        } catch (Throwable $e) {
            report($e);
            $snapshot->status = CashFlowSnapshot::STATUS_FAILED;
            $snapshot->error = trim($e->getMessage());
            $snapshot->payload = null;
        }

        $snapshot->sources = $this->sources;
        $snapshot->duration_ms = (int) round((microtime(true) - $startedAt) * 1000);
        $snapshot->save();

        $this->prune();

        return $snapshot;
    }

    /**
     * @return array<string, mixed>
     */
    private function compute(): array
    {
        $this->fx = $this->source('fx', 'Curs valutar', fn () => $this->loadRates()) ?? $this->paramRates();

        $opening = $this->source('opening', 'Sold inițial (OMC)', fn () => $this->opening()) ?? $this->emptyOpening();

        $receivables = $this->source('receivables', 'Încasări din rezervări (eTrip)', fn () => $this->receivables())
            ?? ['lines' => array_fill_keys(array_keys(BookingSegments::LABELS), $this->grid->zeros()), 'overdue_recent' => [], 'overdue_old' => [], 'beyond' => [], 'structure' => [], 'bookings' => 0];

        $payables = $this->source('payables', 'Plăți furnizori din rezervări (eTrip)', fn () => $this->payables())
            ?? ['lines' => ['hotel' => $this->grid->zeros(), 'transfer' => $this->grid->zeros(), 'insurance' => $this->grid->zeros(), 'flight' => $this->grid->zeros(), 'other' => $this->grid->zeros()], 'structure' => []];

        $charter = $this->source('charter', 'Contracte charter (aplicație)', fn () => $this->charter())
            ?? ['signed' => $this->grid->zeros(), 'draft' => $this->grid->zeros(), 'deposit' => $this->grid->zeros(), 'taxes' => $this->grid->zeros(), 'estimate' => $this->grid->zeros(), 'estimate_taxes' => $this->grid->zeros(), 'contracts' => []];

        $suppliers = $this->source('suppliers_open', 'Furnizori – facturi neachitate (OMC)', fn () => $this->openSuppliers())
            ?? ['line' => $this->grid->zeros(), 'total' => 0.0, 'overdue' => 0.0, 'mode' => 'none'];

        $opex = $this->source('opex', 'OPEX (OMC, medii 12 luni)', fn () => $this->opex())
            ?? ['lines' => [], 'catalogue' => CashFlowParameters::opexCatalogue($this->params)];

        $scenario = $this->source('new_sales', 'Vânzări noi (curba anului anterior, eTrip)', fn () => $this->newSales())
            ?? ['receipts' => array_fill_keys(array_keys(BookingSegments::LABELS), $this->grid->zeros()), 'costs' => $this->grid->zeros(), 'bookings' => 0];

        $actuals = $this->source('actuals', 'Fluxuri efective an anterior (OMC)', fn () => $this->actuals($opening['total']))
            ?? ['lastyear' => [], 'recent' => [], 'in' => $this->grid->zeros(), 'out_partner' => $this->grid->zeros(), 'out_salaries' => $this->grid->zeros(), 'out_other' => $this->grid->zeros()];

        return $this->assemble($opening, $receivables, $payables, $charter, $suppliers, $opex, $scenario, $actuals);
    }

    /**
     * Run one source, timing it and recording how it went; null when it failed.
     *
     * @template T
     *
     * @param  callable(): T  $read
     * @return T|null
     */
    private function source(string $key, string $label, callable $read): mixed
    {
        $started = microtime(true);

        try {
            $result = $read();
            $this->sources[] = [
                'key' => $key,
                'label' => $label,
                'status' => is_array($result) && ($result['_skipped'] ?? false) ? 'skipped' : 'ok',
                'message' => is_array($result) ? ($result['_message'] ?? null) : null,
                'ms' => (int) round((microtime(true) - $started) * 1000),
                'rows' => is_array($result) ? (int) ($result['_rows'] ?? 0) : 0,
            ];

            if (is_array($result)) {
                unset($result['_rows'], $result['_message'], $result['_skipped']);
            }

            return $result;
        } catch (Throwable $e) {
            report($e);
            $cause = $e->getPrevious() ?? $e;
            $this->sources[] = [
                'key' => $key,
                'label' => $label,
                'status' => 'error',
                'message' => trim($cause->getMessage()),
                'ms' => (int) round((microtime(true) - $started) * 1000),
                'rows' => 0,
            ];

            return null;
        }
    }

    // ---------------------------------------------------------------- sources

    /**
     * @return array<string, mixed>
     */
    private function loadRates(): array
    {
        $rates = $this->paramRates();

        if (($this->params['fx']['mode'] ?? 'auto') !== 'auto') {
            return [...$rates, '_message' => 'Cursuri din parametri.'];
        }

        $connection = $this->connections()[0] ?? null;

        if ($connection === null) {
            return [...$rates, '_message' => 'Nicio bază eTrip; cursuri din parametri.'];
        }

        $live = $this->etrip->rates($connection);

        foreach ($live as $currency => $rate) {
            if ($rate > 0) {
                $rates[$currency] = round($rate, 4);
            }
        }

        return [...$rates, '_message' => sprintf('BNR din %s: EUR %.4f, USD %.4f.', config('etrip.connections.'.$connection, $connection), $rates['EUR'] ?? 0, $rates['USD'] ?? 0), '_rows' => count($live)];
    }

    /**
     * @return array<string, float>
     */
    private function paramRates(): array
    {
        return [
            'RON' => 1.0,
            'EUR' => (float) ($this->params['fx']['EUR'] ?? 5.0),
            'USD' => (float) ($this->params['fx']['USD'] ?? 4.5),
        ];
    }

    /**
     * The treasury position: the last closed month-end balances in OMC
     * (bank accounts, cash desks, deposits on 5081) rolled forward with
     * every bank and cash document up to today.
     *
     * @return array<string, mixed>
     */
    private function opening(): array
    {
        $anchor = $this->omc->monthEndAnchor($this->today);

        if ($anchor === null) {
            return [
                ...$this->emptyOpening(),
                '_skipped' => true,
                '_message' => 'OMC nu are solduri de sfârșit de lună (eu_banca_sold): poziția de trezorerie nu poate fi calculată.',
            ];
        }

        $this->anchor = $anchor;
        $position = $this->omc->openingPosition($anchor, $this->today);
        $currencies = array_values(array_unique(['RON', 'EUR', 'USD', ...array_column($position, 'currency')]));
        $rows = [];
        $labels = [
            'bank_open' => 'Conturi curente bănci la '.$anchor->format('d.m.Y'),
            'bank_in' => 'Încasări prin bancă după '.$anchor->format('d.m.Y'),
            'bank_out' => 'Plăți prin bancă după '.$anchor->format('d.m.Y'),
            'bank_now' => 'Conturi curente bănci azi',
            'cash_open' => 'Numerar în casierii la '.$anchor->format('d.m.Y'),
            'cash_in' => 'Încasări în numerar',
            'cash_out' => 'Plăți în numerar',
            'cash_now' => 'Numerar în casierii azi',
            'deposits_open' => 'Depozite bancare (5081) la '.$anchor->format('d.m.Y'),
            'deposits_change' => 'Depozite plasate (+) / lichidate (−) după '.$anchor->format('d.m.Y'),
            'deposits_now' => 'Depozite bancare azi',
            'position' => 'Poziție de trezorerie azi',
        ];

        foreach ($labels as $key => $label) {
            $rows[$key] = ['key' => $key, 'label' => $label, 'values' => array_fill_keys($currencies, 0.0)];
        }

        $byCurrency = array_fill_keys($currencies, 0.0);
        $total = 0.0;

        foreach ($position as $row) {
            $currency = $row['currency'];
            $bankNow = $row['bank_open'] + $row['bank_in'] - $row['bank_out'];
            $cashNow = $row['cash_open'] + $row['cash_in'] - $row['cash_out'];
            $depositsNow = $row['deposits_open'] + $row['deposits_change'];

            foreach (['bank_open', 'bank_in', 'bank_out', 'cash_open', 'cash_in', 'cash_out', 'deposits_open', 'deposits_change'] as $key) {
                $rows[$key]['values'][$currency] = round($row[$key], 2);
            }

            $rows['bank_now']['values'][$currency] = round($bankNow, 2);
            $rows['cash_now']['values'][$currency] = round($cashNow, 2);
            $rows['deposits_now']['values'][$currency] = round($depositsNow, 2);
            $rows['position']['values'][$currency] = round($bankNow + $cashNow + $depositsNow, 2);
            $byCurrency[$currency] = round($bankNow + $cashNow + $depositsNow, 2);
            $total += $this->lei($bankNow + $cashNow + $depositsNow, $currency);
        }

        return [
            'date' => $anchor->toDateString(),
            'as_of' => $this->today->toDateString(),
            'currencies' => $currencies,
            'rows' => array_values($rows),
            'by_currency' => $byCurrency,
            'total' => round($total, 2),
            '_rows' => count($position),
            '_message' => sprintf(
                'Solduri OMC la %s (bănci, casierii, depozite 5081) rulate cu documentele de bancă și casă până la %s.',
                $anchor->format('d.m.Y'),
                $this->today->format('d.m.Y'),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyOpening(): array
    {
        return ['date' => null, 'as_of' => $this->today->toDateString(), 'currencies' => ['RON', 'EUR', 'USD'], 'rows' => [], 'by_currency' => [], 'total' => 0.0];
    }

    /**
     * @return array<string, mixed>
     */
    private function receivables(): array
    {
        $lines = array_fill_keys(array_keys(BookingSegments::LABELS), $this->grid->zeros());
        $overdueRecent = [];
        $overdueOld = [];
        $beyond = [];
        $structure = [];
        $recentDays = (int) ($this->params['overdue']['recent_days'] ?? 60);
        $fallbackDays = (int) config('cashflow.etrip.receivables.fallback_days', 21);
        $from = $this->today->subDays((int) config('cashflow.etrip.receivables.lookback_days', 365));
        $to = $this->today->addDays((int) config('cashflow.etrip.receivables.lookahead_days', 400));
        $minBalance = (float) config('cashflow.etrip.receivables.min_balance', 0.5);
        $bookings = 0;
        $tranches = 0;

        foreach ($this->connections() as $connection) {
            $rows = $this->etrip->openBookings($connection, $from, $to, $minBalance);
            $bookings += count($rows);

            foreach ($rows as $booking) {
                $segment = BookingSegments::of($booking);
                $channel = BookingSegments::channel($booking);

                foreach ($this->scheduler->tranches($booking, $fallbackDays) as $tranche) {
                    $tranches++;
                    $currency = $tranche['currency'];
                    $lei = $this->lei($tranche['amount'], $currency);
                    $due = CarbonImmutable::parse($tranche['date']);

                    if ($due->lt($this->today)) {
                        if ($due->diffInDays($this->today) <= $recentDays) {
                            $bucket = 'restant_recent';
                            $overdueRecent[$currency] = ($overdueRecent[$currency] ?? 0.0) + $tranche['amount'];
                        } else {
                            $bucket = 'restant_vechi';
                            $overdueOld[$currency] = ($overdueOld[$currency] ?? 0.0) + $tranche['amount'];
                        }
                    } elseif ($this->grid->index($due) === null) {
                        $bucket = 'dupa_orizont';
                        $beyond[$currency] = ($beyond[$currency] ?? 0.0) + $tranche['amount'];
                    } else {
                        $bucket = $tranche['type'];
                        $this->grid->add($lines[$segment], $due, $lei);
                    }

                    $key = "{$segment}|{$bucket}|{$channel}|{$currency}";
                    $structure[$key] ??= ['segment' => $segment, 'label' => BookingSegments::LABELS[$segment], 'bucket' => $bucket, 'channel' => $channel, 'currency' => $currency, 'amount' => 0.0, 'lei' => 0.0, 'tranches' => 0];
                    $structure[$key]['amount'] += $tranche['amount'];
                    $structure[$key]['lei'] += $lei;
                    $structure[$key]['tranches']++;
                }
            }
        }

        return [
            'lines' => $lines,
            'overdue_recent' => array_map(fn (float $v) => round($v, 2), $overdueRecent),
            'overdue_old' => array_map(fn (float $v) => round($v, 2), $overdueOld),
            'beyond' => array_map(fn (float $v) => round($v, 2), $beyond),
            'structure' => array_values(array_map(fn (array $row) => [...$row, 'amount' => round($row['amount'], 2), 'lei' => round($row['lei'], 2)], $structure)),
            'bookings' => $bookings,
            '_rows' => $bookings,
            '_message' => sprintf('%d dosare confirmate cu sold de încasat, %d scadențe.', $bookings, $tranches),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payables(): array
    {
        $daysBefore = (int) ($this->params['payables']['days_before_checkin'] ?? 7);
        $ticketDays = (int) ($this->params['payables']['ticket_days'] ?? 7);
        $prepaid = max(0.0, min(100.0, (float) ($this->params['payables']['prepaid_pct'] ?? 0))) / 100;
        $lines = array_fill_keys(['hotel', 'transfer', 'insurance', 'flight', 'other'], $this->grid->zeros());
        $structure = [];
        $rows = 0;

        // Services paid `daysBefore` days ahead of check-in: only those whose
        // payment day has not come yet; earlier ones sit in the supplier balance.
        $firstCheckin = $this->today->addDays($daysBefore);
        $lastCheckin = $this->grid->end()->addDays($daysBefore);

        foreach ($this->connections() as $connection) {
            foreach ($this->etrip->payables($connection, $firstCheckin, $lastCheckin, $daysBefore) as $row) {
                $rows++;
                $factor = in_array($row['category'], ['hotel', 'transfer'], true) ? 1 - $prepaid : 1;
                $lei = $this->lei($row['cost'], $row['currency']) * $factor;
                $this->grid->add($lines[$row['category']], $row['week'], $lei, carryEarly: true);
                $this->collect($structure, $row['category'], $row['currency'], $row['cost'] * $factor, $lei, $row['items']);
            }

            foreach ($this->etrip->ticketsOrdered($connection, $this->today->subDays($ticketDays), $this->today->addDay()) as $row) {
                $rows++;
                $lei = $this->lei($row['cost'], $row['currency']);
                $lines['flight'][0] += $lei;
                $this->collect($structure, 'flight', $row['currency'], $row['cost'], $lei, $row['items']);
            }
        }

        return [
            'lines' => $lines,
            'structure' => array_values($structure),
            '_rows' => $rows,
            '_message' => sprintf('Servicii cu check-in de la %s; plata cu %d zile înainte; bilete comandate în ultimele %d zile.', $firstCheckin->format('d.m.Y'), $daysBefore, $ticketDays),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $structure
     */
    private function collect(array &$structure, string $category, string $currency, float $amount, float $lei, int $items = 0): void
    {
        $key = "{$category}|{$currency}";
        $structure[$key] ??= ['category' => $category, 'label' => self::PAYABLE_LABELS[$category] ?? $category, 'currency' => $currency, 'amount' => 0.0, 'lei' => 0.0, 'items' => 0];
        $structure[$key]['amount'] = round($structure[$key]['amount'] + $amount, 2);
        $structure[$key]['lei'] = round($structure[$key]['lei'] + $lei, 2);
        $structure[$key]['items'] += $items;
    }

    public const PAYABLE_LABELS = [
        'hotel' => 'Cazare',
        'transfer' => 'Transfer / servicii la sol',
        'insurance' => 'Asigurări',
        'flight' => 'Bilete avion',
        'other' => 'Altele',
    ];

    /**
     * @return array<string, mixed>
     */
    private function charter(): array
    {
        $rotations = [CharterContract::STATUS_SIGNED => $this->grid->zeros(), CharterContract::STATUS_DRAFT => $this->grid->zeros()];
        $deposit = $this->grid->zeros();
        $taxes = $this->grid->zeros();
        $estimate = $this->grid->zeros();
        $estimateTaxes = $this->grid->zeros();
        $summary = [];

        $contracts = CharterContract::query()->with(['flights' => fn ($q) => $q->orderBy('flight_date')])->orderBy('season')->orderBy('name')->get();
        $factor = (float) ($this->params['scenario']['charter_factor'] ?? 1);
        [$estimates, $contracted] = $this->charterEstimates($contracts);
        $flights = 0;

        foreach ($contracts as $contract) {
            $netFactor = $contract->deposit_percent !== null ? 1 - (float) $contract->deposit_percent / 100 : 1.0;
            $row = ['id' => $contract->id, 'name' => $contract->name, 'season' => $contract->season, 'status' => $contract->status, 'operator' => $contract->operator, 'currency' => $contract->currency, 'flights' => $contract->flights->count(), 'total_net' => 0.0, 'in_horizon' => 0.0, 'taxes' => 0.0, 'deposit' => 0.0];

            foreach ($contract->flights as $flight) {
                $flight->setRelation('contract', $contract);
                $flights++;
                $row['total_net'] += (float) $flight->net_value;
                $payDate = $flight->paymentDate();

                if ($payDate->gte($this->today)) {
                    $lei = $this->lei((float) $flight->net_value * $netFactor, $contract->currency);
                    $bucket = $contract->status === CharterContract::STATUS_DRAFT ? CharterContract::STATUS_DRAFT : CharterContract::STATUS_SIGNED;

                    if ($this->grid->add($rotations[$bucket], $payDate, $lei)) {
                        $row['in_horizon'] += (float) $flight->net_value * $netFactor;
                    }
                }

                $taxDate = $flight->taxesPaymentDate();

                if ((float) $flight->taxes > 0 && $taxDate->gte($this->today) && $this->grid->add($taxes, $taxDate, $this->lei((float) $flight->taxes, $contract->currency))) {
                    $row['taxes'] += (float) $flight->taxes;
                }

                if ($factor > 0 && array_key_exists($contract->season, $estimates)) {
                    $shifted = $payDate->copy()->addDays(364);

                    if ($shifted->gte($this->today)) {
                        $this->grid->add($estimate, $shifted, $this->lei((float) $flight->net_value * $factor, $contract->currency));
                    }

                    $shiftedTax = $taxDate->copy()->addDays(364);

                    if ((float) $flight->taxes > 0 && $shiftedTax->gte($this->today)) {
                        $this->grid->add($estimateTaxes, $shiftedTax, $this->lei((float) $flight->taxes * $factor, $contract->currency));
                    }
                }
            }

            if (! $contract->deposit_paid && $contract->deposit_due_date !== null) {
                $amount = $contract->deposit_amount !== null
                    ? (float) $contract->deposit_amount
                    : ($contract->deposit_percent !== null ? (float) ($contract->contract_value ?? $row['total_net']) * (float) $contract->deposit_percent / 100 : 0.0);

                if ($amount > 0 && $contract->deposit_due_date->gte($this->today) && $this->grid->add($deposit, $contract->deposit_due_date, $this->lei($amount, $contract->currency))) {
                    $row['deposit'] = $amount;
                }
            }

            $summary[] = array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $row);
        }

        $message = sprintf('%d contracte, %d rotații.', $contracts->count(), $flights);

        foreach ($estimates as $base => $target) {
            $message .= sprintf(' Sezonul %s este estimat din programul %s decalat 364 de zile × %.2f.', $target ?? 'următor', $base, $factor);
        }

        foreach ($contracted as $base => $target) {
            $message .= sprintf(' Sezonul %s este contractat; programul %s nu se mai decalează.', $target, $base);
        }

        if ($estimates === [] && $contracted === []) {
            $message .= ' Niciun sezon următor de estimat: nu există contracte semnate.';
        }

        return [
            'signed' => $rotations[CharterContract::STATUS_SIGNED],
            'draft' => $rotations[CharterContract::STATUS_DRAFT],
            'deposit' => $deposit,
            'taxes' => $taxes,
            'estimate' => $estimate,
            'estimate_taxes' => $estimateTaxes,
            'estimates' => $estimates,
            'contracts' => $summary,
            '_rows' => $flights,
            '_message' => $message,
            '_skipped' => $contracts->isEmpty(),
        ];
    }

    /**
     * Which seasons the next-season estimate (C12/C13) is built from: the
     * base season chosen in the parameters or, by default, every signed
     * season whose next edition has no contract yet.
     *
     * @param  Collection<int, CharterContract>  $contracts
     * @return array{0: array<string, ?string>, 1: array<string, string>} the estimated base => target seasons, and the base => target pairs already contracted
     */
    private function charterEstimates(Collection $contracts): array
    {
        $seasons = $contracts->pluck('season')->unique();
        $paramBase = trim((string) ($this->params['scenario']['charter_base_season'] ?? ''));
        $paramTarget = trim((string) ($this->params['scenario']['charter_target_season'] ?? ''));
        $bases = [];

        if ($paramBase !== '') {
            $bases[$paramBase] = $paramTarget !== '' ? $paramTarget : self::nextSeason($paramBase);
        } else {
            foreach ($contracts->where('status', CharterContract::STATUS_SIGNED)->pluck('season')->unique() as $season) {
                $bases[$season] = self::nextSeason($season);
            }
        }

        $estimates = [];
        $contracted = [];

        foreach ($bases as $base => $target) {
            if ($target !== null && $seasons->contains($target)) {
                $contracted[$base] = $target;
            } else {
                $estimates[$base] = $target;
            }
        }

        return [$estimates, $contracted];
    }

    /**
     * The next edition of a season label: S26 → S27, W26-27 → W27-28.
     */
    public static function nextSeason(string $season): ?string
    {
        $next = preg_replace_callback(
            '/\d+/',
            fn (array $match) => str_pad((string) ((int) $match[0] + 1), strlen($match[0]), '0', STR_PAD_LEFT),
            trim($season),
            -1,
            $count,
        );

        return $count > 0 && $next !== null ? $next : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function openSuppliers(): array
    {
        $weeks = max(1, (int) ($this->params['payables']['supplier_balance_weeks'] ?? 2));
        $line = $this->grid->zeros();
        $manual = $this->params['payables']['supplier_balance'] ?? null;

        if ($manual !== null && $manual !== '') {
            $total = (float) $manual;

            for ($i = 0; $i < $weeks; $i++) {
                $line[$i] += $total / $weeks;
            }

            return ['line' => $line, 'total' => round($total, 2), 'overdue' => round($total, 2), 'mode' => 'manual', '_message' => sprintf('Sold introdus manual: %s RON pe %d săptămâni.', number_format($total, 0, ',', '.'), $weeks)];
        }

        $since = $this->today->subYears(max(1, (int) config('omc.open_window_years', 2)));
        $rows = $this->omc->openSupplierInvoices($since);
        $overdue = 0.0;
        $total = 0.0;
        $byCurrency = [];

        foreach ($rows as $row) {
            $total += $row['lei'];
            $byCurrency[$row['currency']] = ($byCurrency[$row['currency']] ?? 0.0) + $row['amount'];
            $due = CarbonImmutable::parse($row['due']);

            if ($due->lt($this->today)) {
                $overdue += $row['lei'];
            } else {
                $this->grid->add($line, $due, $row['lei'], carryEarly: true);
            }
        }

        for ($i = 0; $i < $weeks; $i++) {
            $line[$i] += $overdue / $weeks;
        }

        return [
            'line' => $line,
            'total' => round($total, 2),
            'overdue' => round($overdue, 2),
            'by_currency' => array_map(fn (float $v) => round($v, 2), $byCurrency),
            'mode' => 'omc',
            '_rows' => count($rows),
            '_message' => sprintf('Facturi furnizori deschise din OMC: %s RON, din care scadente depășite %s RON plătite pe %d săptămâni.', number_format($total, 0, ',', '.'), number_format($overdue, 0, ',', '.'), $weeks),
        ];
    }

    /**
     * Monthly OPEX per category: supplier-invoice lines by expense account
     * for the categories invoiced by suppliers, ledger postings paid from
     * the bank or cash desk for salaries, taxes, fees and dividends. Both
     * are 12-month averages of the last closed months.
     *
     * @return array<string, mixed>
     */
    private function opex(): array
    {
        $months = max(1, (int) config('cashflow.omc.opex_months', 12));
        $end = ($this->anchor ?? $this->today->startOfMonth()->subDay())->addDay();
        $from = $end->subMonthsNoOverflow($months)->startOfMonth();
        $computed = [];
        $messages = [];
        $categories = (array) config('cashflow.opex');

        foreach (['invoices' => 'monthlyAverageByAccount', 'ledger' => 'monthlyLedgerByAccount'] as $basis => $method) {
            $wanted = array_filter($categories, fn (array $c) => ($c['basis'] ?? 'invoices') === $basis && $c['accounts'] !== []);

            if ($wanted === []) {
                continue;
            }

            try {
                $byAccount = $this->omc->{$method}($from, $end, $months);

                foreach ($wanted as $category) {
                    $sum = 0.0;

                    foreach ($byAccount as $account => $monthly) {
                        foreach ($category['accounts'] as $prefix) {
                            if (str_starts_with((string) $account, $prefix)) {
                                $sum += $monthly;
                                break;
                            }
                        }
                    }

                    $computed[$category['key']] = round(($category['rule']['type'] ?? 'uniform') === 'quarterly' ? $sum * 3 : $sum, 2);
                }

                $messages[] = $basis === 'invoices' ? 'facturi furnizor' : 'registru jurnal';
            } catch (Throwable $e) {
                report($e);
                $messages[] = sprintf('%s indisponibil (%s)', $basis === 'invoices' ? 'facturi furnizor' : 'registru jurnal', trim(($e->getPrevious() ?? $e)->getMessage()));
            }
        }

        $catalogue = CashFlowParameters::opexCatalogue($this->params, $computed);
        $lines = [];

        foreach ($catalogue as $category) {
            $lines[$category['key']] = $this->scheduleOpex((float) $category['monthly'], $category['rule']);
        }

        return [
            'lines' => $lines,
            'catalogue' => $catalogue,
            '_rows' => count($computed),
            '_message' => sprintf('Medii lunare OMC %s – %s din %s; valorile din parametri au prioritate.', $from->format('m.Y'), $end->subDay()->format('m.Y'), implode(' și ', $messages)),
        ];
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return list<float>
     */
    private function scheduleOpex(float $monthly, array $rule): array
    {
        $series = $this->grid->zeros();

        if ($monthly <= 0) {
            return $series;
        }

        if (($rule['type'] ?? 'uniform') === 'uniform') {
            $weekly = $monthly * 12 / 52;

            return array_fill(0, $this->grid->weeks, round($weekly, 2));
        }

        $day = max(1, (int) ($rule['day'] ?? 1));
        $months = $rule['type'] === 'quarterly' ? array_map('intval', (array) ($rule['months'] ?? [1, 4, 7, 10])) : range(1, 12);
        $cursor = $this->grid->start->startOfMonth();

        while ($cursor->lt($this->grid->end())) {
            if (in_array($cursor->month, $months, true)) {
                $payDay = $cursor->setDay(min($day, $cursor->daysInMonth));

                if ($payDay->gte($this->today)) {
                    $this->grid->add($series, $payDay, $monthly);
                }
            }

            $cursor = $cursor->addMonthNoOverflow();
        }

        return $series;
    }

    /**
     * The new-sales scenario: what the bookings created in the same weeks
     * of last year collected (by the segment of the booking) and cost (by
     * category), shifted 52 weeks ahead and scaled by the factor.
     *
     * @return array<string, mixed>
     */
    private function newSales(): array
    {
        $receipts = array_fill_keys(array_keys(BookingSegments::LABELS), $this->grid->zeros());
        $costs = $this->grid->zeros();

        if (! ($this->params['scenario']['enabled'] ?? true)) {
            return ['receipts' => $receipts, 'costs' => $costs, 'bookings' => 0, 'receipts_structure' => [], 'structure' => [], '_skipped' => true, '_message' => 'Scenariul este dezactivat în parametri.'];
        }

        $factor = max(0.0, (float) ($this->params['scenario']['factor'] ?? 1));
        $daysBefore = (int) ($this->params['payables']['days_before_checkin'] ?? 7);
        $from = $this->grid->lastYearMonday(0);
        $to = $this->grid->end()->subWeeks(52);
        $bookings = 0;
        $receiptsStructure = [];
        $structure = [];

        foreach ($this->connections() as $connection) {
            $curve = $this->etrip->bookingCurve($connection, $from, $to, $daysBefore);
            $bookings += $curve['bookings'];

            foreach ($curve['receipts'] as $row) {
                $segment = BookingSegments::of($row);
                $lei = $this->lei($row['amount'], $row['currency']) * $factor;

                if ($this->grid->add($receipts[$segment], CarbonImmutable::parse($row['week'])->addWeeks(52), $lei)) {
                    $key = "{$segment}|{$row['currency']}";
                    $receiptsStructure[$key] ??= ['segment' => $segment, 'label' => BookingSegments::LABELS[$segment], 'currency' => $row['currency'], 'amount' => 0.0, 'lei' => 0.0, 'receipts' => 0];
                    $receiptsStructure[$key]['amount'] = round($receiptsStructure[$key]['amount'] + $row['amount'] * $factor, 2);
                    $receiptsStructure[$key]['lei'] = round($receiptsStructure[$key]['lei'] + $lei, 2);
                    $receiptsStructure[$key]['receipts'] += (int) ($row['receipts'] ?? 0);
                }
            }

            foreach ($curve['costs'] as $row) {
                $lei = $this->lei($row['cost'], $row['currency']) * $factor;

                if ($this->grid->add($costs, CarbonImmutable::parse($row['week'])->addWeeks(52), $lei)) {
                    $this->collect($structure, $row['category'], $row['currency'], $row['cost'] * $factor, $lei, (int) ($row['items'] ?? 0));
                }
            }

            foreach ($curve['tickets'] as $row) {
                $lei = $this->lei($row['cost'], $row['currency']) * $factor;

                if ($this->grid->add($costs, CarbonImmutable::parse($row['week'])->addWeeks(52), $lei)) {
                    $this->collect($structure, 'flight', $row['currency'], $row['cost'] * $factor, $lei, (int) ($row['items'] ?? 0));
                }
            }
        }

        return [
            'receipts' => $receipts,
            'costs' => $costs,
            'bookings' => $bookings,
            'receipts_structure' => array_values($receiptsStructure),
            'structure' => array_values($structure),
            '_rows' => $bookings,
            '_message' => sprintf('%d dosare create între %s și %s, decalate 52 de săptămâni × %.2f; încasările pe segmentul dosarului, costurile pe categorie.', $bookings, $from->format('d.m.Y'), $to->subDay()->format('d.m.Y'), $factor),
        ];
    }

    /**
     * Last year's actual receipts and payments per week (OMC, by class),
     * and the treasury position at the end of each of those weeks: the
     * closed month-end balances are the anchors, the weekly net flows are
     * added in between and the residual to the next month-end (FX
     * revaluation, interest, timing) is spread evenly.
     *
     * @return array<string, mixed>
     */
    private function actuals(float $openingTotal): array
    {
        $from = $this->grid->lastYearMonday(0)->subMonth()->startOfMonth();
        $flows = $this->omc->dailyFlows($from, $this->today->addDay());
        $weekly = [];

        foreach ($flows as $flow) {
            if ($flow['group'] === OmcCashFlowReader::GROUP_INTERNAL) {
                continue;
            }

            $monday = CarbonImmutable::parse($flow['day'])->startOfWeek(CarbonInterface::MONDAY)->toDateString();
            $weekly[$monday] ??= ['in' => 0.0, 'out' => 0.0, 'in_other' => 0.0, 'out_partner' => 0.0, 'out_salaries' => 0.0, 'out_other' => 0.0];
            $weekly[$monday][$flow['kind']] += $flow['lei'];

            if ($flow['kind'] === 'in' && $flow['group'] !== OmcCashFlowReader::GROUP_PARTNER) {
                $weekly[$monday]['in_other'] += $flow['lei'];
            } elseif ($flow['kind'] === 'out') {
                $weekly[$monday][match ($flow['group']) {
                    OmcCashFlowReader::GROUP_PARTNER => 'out_partner',
                    OmcCashFlowReader::GROUP_SALARIES => 'out_salaries',
                    default => 'out_other',
                }] += $flow['lei'];
            }
        }

        $anchors = [];

        try {
            foreach ($this->omc->monthEndPositions($from, $this->today) as $position) {
                $total = 0.0;

                foreach (['bank', 'cash', 'deposits'] as $section) {
                    foreach ($position[$section] as $currency => $amount) {
                        $rate = $currency === 'RON' ? 1.0 : ($position['rates'][$currency] ?? $this->fx[$currency] ?? 1.0);
                        $total += $amount * $rate;
                    }
                }

                $anchors[$position['date']] = round($total, 2);
            }
        } catch (Throwable $e) {
            report($e);
        }

        if ($this->anchor !== null && ! isset($anchors[$this->anchor->toDateString()])) {
            // No month-end table row for the anchor month: use today's position as the last anchor.
            $anchors[$this->today->toDateString()] = $openingTotal;
        }

        ksort($anchors);
        $net = fn (string $monday) => ($weekly[$monday]['in'] ?? 0.0) - ($weekly[$monday]['out'] ?? 0.0);

        $balanceAt = function (CarbonImmutable $monday) use ($anchors, $net): ?float {
            $weekEnd = $monday->addDays(6);
            $previous = null;
            $next = null;

            foreach ($anchors as $date => $total) {
                if ($date <= $weekEnd->toDateString()) {
                    $previous = $date;
                } elseif ($next === null) {
                    $next = $date;
                }
            }

            if ($previous === null) {
                return null;
            }

            // Weeks whose end falls after the previous anchor (and up to the next one).
            $span = [];
            $cursor = CarbonImmutable::parse($previous)->subDays(6)->startOfWeek(CarbonInterface::MONDAY);
            $limit = $next !== null ? CarbonImmutable::parse($next) : $monday;

            while ($cursor->lte($limit)) {
                $end = $cursor->addDays(6);

                if ($end->toDateString() > $previous && ($next === null || $end->toDateString() <= $next)) {
                    $span[] = $cursor->toDateString();
                }

                $cursor = $cursor->addWeek();
            }

            $cumulative = 0.0;
            $total = 0.0;
            $k = 0;

            foreach ($span as $week) {
                $total += $net($week);

                if ($week <= $monday->toDateString()) {
                    $cumulative += $net($week);
                    $k++;
                }
            }

            if ($next === null) {
                return $anchors[$previous] + $cumulative;
            }

            $count = max(1, count($span));

            return $anchors[$previous] + $cumulative + ($anchors[$next] - $anchors[$previous] - $total) * $k / $count;
        };

        // This year's last weeks as OMC recorded them, with the position at the end of each
        // week walked back from today's position (the current, partial week comes first).
        $recent = [];
        $net = fn (string $monday) => ($weekly[$monday]['in'] ?? 0.0) - ($weekly[$monday]['out'] ?? 0.0);
        $running = $openingTotal - $net($this->grid->start->toDateString());

        for ($i = 1; $i <= self::RECENT_WEEKS; $i++) {
            $monday = $this->grid->start->subWeeks($i);
            $key = $monday->toDateString();
            $week = $weekly[$key] ?? ['in' => 0.0, 'out' => 0.0, 'out_partner' => 0.0, 'out_salaries' => 0.0];

            array_unshift($recent, [
                'week' => $key,
                'in' => round($week['in'], 2),
                'out' => round($week['out'], 2),
                'out_partner' => round($week['out_partner'], 2),
                'out_salaries' => round($week['out_salaries'], 2),
                'balance' => round($running, 2),
            ]);

            $running -= $net($key);
        }

        $in = $this->grid->zeros();
        $outPartner = $this->grid->zeros();
        $outSalaries = $this->grid->zeros();
        $outOther = $this->grid->zeros();
        $lastyear = [];

        for ($i = 0; $i < $this->grid->weeks; $i++) {
            $monday = $this->grid->lastYearMonday($i);
            $key = $monday->toDateString();
            $week = $weekly[$key] ?? ['in' => 0.0, 'out' => 0.0, 'in_other' => 0.0, 'out_partner' => 0.0, 'out_salaries' => 0.0, 'out_other' => 0.0];
            $in[$i] = round($week['in'], 2);
            $outPartner[$i] = round($week['out_partner'], 2);
            $outSalaries[$i] = round($week['out_salaries'], 2);
            $outOther[$i] = round($week['out_other'], 2);
            $closing = $monday->addDays(6)->gte($this->today) ? null : $balanceAt($monday);
            $opening = $balanceAt($monday->subWeek());

            $lastyear[] = [
                'week' => $this->grid->monday($i)->toDateString(),
                'ly_week' => $key,
                'ly_in' => $in[$i],
                'ly_out' => round($week['out'], 2),
                'ly_out_partener' => $outPartner[$i],
                'ly_out_salarii' => $outSalaries[$i],
                'ly_out_alte' => $outOther[$i],
                'ly_in_alte' => round($week['in_other'], 2),
                'ly_bal' => $closing !== null ? round($closing, 2) : null,
                'ly_bal_open' => $opening !== null ? round($opening, 2) : null,
            ];
        }

        return [
            'lastyear' => $lastyear,
            'recent' => $recent,
            'in' => $in,
            'out_partner' => $outPartner,
            'out_salaries' => $outSalaries,
            'out_other' => $outOther,
            '_rows' => count($flows),
            '_message' => sprintf(
                'Documente de bancă/casă OMC din %s (fără transferuri între conturi proprii, depozite și linii de credit); soldul anului anterior pornește din soldurile de sfârșit de lună (%d luni) cu fluxurile nete între ele.',
                $from->format('d.m.Y'),
                count($anchors),
            ),
        ];
    }

    // --------------------------------------------------------------- assembly

    /**
     * @param  array<string, mixed>  $opening
     * @param  array<string, mixed>  $receivables
     * @param  array<string, mixed>  $payables
     * @param  array<string, mixed>  $charter
     * @param  array<string, mixed>  $suppliers
     * @param  array<string, mixed>  $opex
     * @param  array<string, mixed>  $scenario
     * @param  array<string, mixed>  $actuals
     * @return array<string, mixed>
     */
    private function assemble(array $opening, array $receivables, array $payables, array $charter, array $suppliers, array $opex, array $scenario, array $actuals): array
    {
        $weeks = $this->grid->weeks;
        $scenarioOn = (bool) ($this->params['scenario']['enabled'] ?? true);

        $this->line('A', 'Sold inițial de trezorerie (bănci + casierii + depozite)', 'A', array_fill(0, $weeks, 0.0), kind: 'balance', note: 'S+1: parametri rulați cu OMC; apoi soldul final al săptămânii anterioare');

        $codes = ['pachete' => 'B1', 'circuite' => 'B2', 'exotic' => 'B3', 'sphinx' => 'B4', 'cazare' => 'B5', 'bilete' => 'B6', 'altele' => 'B7'];

        foreach ($codes as $segment => $code) {
            $this->line($code, 'Încasări '.BookingSegments::LABELS[$segment].' – avansuri și solduri conform scadențarului eTrip', 'B', $receivables['lines'][$segment] ?? $this->grid->zeros(), note: 'eTrip: dosare confirmate, scadențe viitoare minus încasat');
        }

        $recovery = $this->recovery($receivables['overdue_recent'] ?? [], (float) ($this->params['overdue']['recent_pct'] ?? 0), (int) ($this->params['overdue']['recent_weeks'] ?? 4));
        $recoveryOld = $this->recovery($receivables['overdue_old'] ?? [], (float) ($this->params['overdue']['old_pct'] ?? 0), (int) ($this->params['overdue']['recent_weeks'] ?? 4));
        $this->line('B8', sprintf('Recuperare solduri restante ≤ %d zile (scadență depășită)', (int) ($this->params['overdue']['recent_days'] ?? 60)), 'B', $recovery, note: sprintf('%s%% din restanțe, egal pe %d săptămâni', $this->params['overdue']['recent_pct'] ?? 0, $this->params['overdue']['recent_weeks'] ?? 4));
        $this->line('B9', sprintf('Recuperare solduri restante > %d zile', (int) ($this->params['overdue']['recent_days'] ?? 60)), 'B', $recoveryOld, note: sprintf('%s%% din restanțele vechi', $this->params['overdue']['old_pct'] ?? 0));
        $newSales = [];

        foreach (array_keys($codes) as $index => $segment) {
            $code = 'B10.'.($index + 1);
            $newSales[] = $code;
            $this->line($code, 'Vânzări noi – '.BookingSegments::LABELS[$segment], 'B', $scenario['receipts'][$segment] ?? $this->grid->zeros(), scenario: true, note: 'eTrip: încasările dosarelor din acest segment create în aceeași săptămână a anului anterior, × factor', parent: 'B10');
        }

        $this->line('B10', 'Încasări din vânzări noi – total (scenariu: curba anului anterior × factor)', 'B', $this->sum($newSales), kind: 'subtotal', scenario: true, note: 'suma liniilor B10.1–B10.7; intră în total doar cu scenariul pornit');
        $this->line('B', 'TOTAL ÎNCASĂRI OPERAȚIONALE', 'B', $this->sum(['B1', 'B2', 'B3', 'B4', 'B5', 'B6', 'B7', 'B8', 'B9'], $scenarioOn ? $newSales : []), kind: 'total');

        $this->line('C1', 'Plăți cazare (hoteluri) – rezervări existente', 'C', $payables['lines']['hotel'], note: 'eTrip: cost furnizor net, plată cu N zile înainte de check-in');
        $this->line('C2', 'Plăți transferuri, excursii, autocar, servicii la sol', 'C', $payables['lines']['transfer']);
        $this->line('C3', 'Plăți asigurări', 'C', $payables['lines']['insurance']);
        $this->line('C4', sprintf('Plăți bilete avion linie (comenzi din ultimele %d zile)', (int) ($this->params['payables']['ticket_days'] ?? 7)), 'C', $payables['lines']['flight']);
        $this->line('C5', 'Alte costuri directe de produs', 'C', $payables['lines']['other']);
        $this->line('C6', 'Charter – rotații contracte semnate', 'C', $charter['signed'], note: 'contracte charter din aplicație, plata cu N zile înainte de zbor');
        $this->line('C7', 'Charter – rotații contracte draft (net de depozit)', 'C', $charter['draft']);
        $this->line('C8', 'Charter – depozite contracte', 'C', $charter['deposit']);
        $this->line('C9', 'Charter – taxe aeroport (reconciliere lunară, estimare)', 'C', $charter['taxes']);
        $this->line('C10', 'Furnizori – sold neachitat la data raportului (facturi scadente)', 'C', $suppliers['line'], note: $suppliers['mode'] === 'manual' ? 'parametri' : 'OMC: facturi furnizor deschise, pe scadență');
        $this->line('C11', 'Plăți furnizori pentru vânzări noi – cazare, servicii, bilete (scenariu)', 'C', $scenario['costs'], scenario: true);
        $estimates = (array) ($charter['estimates'] ?? []);
        $estimated = count($estimates) === 1 && current($estimates) !== null ? 'Charter '.current($estimates).' estimat' : 'Charter sezon următor estimat';
        $estimatedFrom = $estimates === [] ? 'program decalat un an × factor' : 'programul '.implode(', ', array_keys($estimates)).' decalat un an × factor';
        $this->line('C12', $estimated.' – rotații ('.$estimatedFrom.')', 'C', $charter['estimate'], scenario: true, note: 'sezonul de bază din parametri sau, implicit, fiecare sezon semnat al cărui sezon următor nu este contractat');
        $this->line('C13', $estimated.' – taxe aeroport', 'C', $charter['estimate_taxes'], scenario: true);
        $this->line('C', 'TOTAL PLĂȚI DIRECTE DE PRODUS', 'C', $this->sum(['C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'C8', 'C9', 'C10'], $scenarioOn ? ['C11', 'C12', 'C13'] : []), kind: 'total');

        $opexCodes = [];

        foreach ($opex['catalogue'] as $index => $category) {
            $code = 'D'.($index + 1);
            $opexCodes[] = $code;
            $this->line($code, $category['label'], 'D', $opex['lines'][$category['key']] ?? $this->grid->zeros(), note: $this->opexNote($category), key: $category['key']);
        }

        $this->line('D', 'TOTAL COSTURI CORPORATE ȘI INDIRECTE', 'D', $this->sum($opexCodes), kind: 'total');

        $balance = $opening['total'];
        $net = $this->grid->zeros();
        $closing = $this->grid->zeros();
        $openingLine = $this->grid->zeros();
        $b = $this->values('B');
        $c = $this->values('C');
        $d = $this->values('D');

        for ($i = 0; $i < $weeks; $i++) {
            $openingLine[$i] = round($balance, 2);
            $net[$i] = round($b[$i] - $c[$i] - $d[$i], 2);
            $balance += $net[$i];
            $closing[$i] = round($balance, 2);
        }

        $this->lines[0]['values'] = $openingLine;
        $minimum = (float) ($this->params['thresholds']['minimum'] ?? 0);
        $comfort = (float) ($this->params['thresholds']['comfort'] ?? 0);

        $this->line('E1', 'FLUX NET OPERAȚIONAL (B − C − D)', 'E', $net, kind: 'total');
        $this->line('E2', 'SOLD FINAL DE TREZORERIE', 'E', $closing, kind: 'balance');
        $this->line('E3', 'Prag minim de siguranță', 'E', array_fill(0, $weeks, $minimum), kind: 'threshold');
        $this->line('E4', 'Marja peste pragul minim (deficit dacă este negativ)', 'E', array_map(fn (float $v) => round($v - $minimum, 2), $closing), kind: 'total');
        $this->line('E5', 'Semnal', 'E', array_map(fn (float $v) => $v < $minimum ? 'DEFICIT' : ($v < $comfort ? 'ATENȚIE' : 'OK'), $closing), kind: 'text');

        $existing = $this->sum(['B1', 'B2', 'B3', 'B4', 'B5', 'B6', 'B7', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'C8', 'C9']);
        $coverage = array_map(fn (float $v) => $v > 0 ? 'existing' : 'scenario', $existing);
        $this->line('E6', 'Acoperire date', 'E', array_map(fn (string $c) => $c === 'existing'
            ? ($scenarioOn ? 'rezervări existente + vânzări noi (scenariu)' : 'rezervări existente')
            : 'doar scenariu vânzări noi', $coverage), kind: 'text');

        $outSuppliers = array_map(fn (float $a, float $b) => round($a + $b, 2), $actuals['out_partner'], $actuals['out_other']);
        $this->line('F1', 'Încasări clienți/parteneri efective (an anterior)', 'F', $actuals['in'], kind: 'reference', note: 'OMC: documente de încasare bancă + casă, aceeași săptămână a anului anterior');
        $this->line('F2', 'Plăți furnizori și alte plăți efective (an anterior)', 'F', $outSuppliers, kind: 'reference', note: 'OMC: plăți către parteneri și alte plăți, fără transferuri interne');
        $this->line('F3', 'Plăți salarii și taxe efective (an anterior)', 'F', $actuals['out_salaries'], kind: 'reference', note: 'OMC: plăți cu cont corespondent 421/425/43x/44x/457/462');
        $this->line('F4', 'Flux net efectiv (an anterior)', 'F', array_map(fn (float $in, float $sup, float $sal) => round($in - $sup - $sal, 2), $actuals['in'], $outSuppliers, $actuals['out_salaries']), kind: 'reference');

        $minIndex = array_keys($closing, min($closing))[0] ?? 0;
        $first13 = fn (array $values) => round(array_sum(array_slice($values, 0, min(13, $weeks))), 2);

        $kpis = [
            'opening' => round($opening['total'], 2),
            'closing_13' => $closing[min(12, $weeks - 1)],
            'closing_52' => $closing[$weeks - 1],
            'min_closing' => ['value' => $closing[$minIndex], 'week' => $this->grid->monday($minIndex)->toDateString(), 'index' => $minIndex],
            'weeks_below_minimum' => count(array_filter($closing, fn (float $v) => $v < $minimum)),
            'weeks_below_comfort' => count(array_filter($closing, fn (float $v) => $v < $comfort)),
            'in_13' => $first13($b),
            'out_13' => round($first13($c) + $first13($d), 2),
            'in_52' => round(array_sum($b), 2),
            'out_52' => round(array_sum($c) + array_sum($d), 2),
            'receivables_existing' => round(array_sum($this->sum(['B1', 'B2', 'B3', 'B4', 'B5', 'B6', 'B7'])), 2),
            'payables_existing' => round(array_sum($this->sum(['C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'C8', 'C9'])), 2),
            'overdue_recent' => $receivables['overdue_recent'] ?? [],
            'overdue_old' => $receivables['overdue_old'] ?? [],
            'beyond_horizon' => $receivables['beyond'] ?? [],
            'suppliers_open' => $suppliers['total'] ?? 0.0,
            'bookings' => $receivables['bookings'] ?? 0,
            'scenario_bookings' => $scenario['bookings'] ?? 0,
            'scenario_receipts' => round(array_sum($this->values('B10')), 2),
        ];

        return [
            'generated' => now()->toIso8601String(),
            'today' => $this->today->toDateString(),
            'week_start' => $this->grid->start->toDateString(),
            'weeks' => $this->grid->mondays(),
            'currency' => 'RON',
            'fx' => array_map(fn ($v) => round((float) $v, 4), $this->fx),
            'lines' => $this->lines,
            'coverage' => $coverage,
            'lastyear' => $actuals['lastyear'],
            'recent' => $actuals['recent'],
            'kpis' => $kpis,
            'opening' => $opening,
            'structure' => [
                'receivables' => $receivables['structure'] ?? [],
                'payables' => $payables['structure'] ?? [],
                'new_sales_receipts' => $scenario['receipts_structure'] ?? [],
                'new_sales_costs' => $scenario['structure'] ?? [],
                'suppliers_open' => ['total' => $suppliers['total'] ?? 0.0, 'overdue' => $suppliers['overdue'] ?? 0.0, 'by_currency' => $suppliers['by_currency'] ?? [], 'mode' => $suppliers['mode'] ?? 'none'],
            ],
            'charter' => $charter['contracts'] ?? [],
            'opex' => $opex['catalogue'],
            'params' => $this->params,
        ];
    }

    /**
     * @param  array<string, float>  $overdue  native amounts by currency
     * @return list<float>
     */
    private function recovery(array $overdue, float $pct, int $weeks): array
    {
        $series = $this->grid->zeros();
        $weeks = max(1, min($weeks, $this->grid->weeks));
        $total = 0.0;

        foreach ($overdue as $currency => $amount) {
            $total += $this->lei((float) $amount, (string) $currency);
        }

        $perWeek = $total * max(0.0, min(100.0, $pct)) / 100 / $weeks;

        for ($i = 0; $i < $weeks; $i++) {
            $series[$i] = round($perWeek, 2);
        }

        return $series;
    }

    /**
     * @param  array<string, mixed>  $category
     */
    private function opexNote(array $category): string
    {
        $rule = $category['rule'];
        $when = match ($rule['type'] ?? 'uniform') {
            'monthly' => 'ziua '.$rule['day'].' a lunii',
            'quarterly' => 'trimestrial, ziua '.$rule['day'],
            default => 'uniform pe săptămâni',
        };
        $origin = $category['override'] !== null ? 'valoare din parametri' : ($category['computed'] !== null ? 'medie OMC 12 luni' : 'valoare implicită');

        return sprintf('%s RON/lună, %s (%s)', number_format((float) $category['monthly'], 0, ',', '.'), $when, $origin);
    }

    /**
     * @param  list<float|string>  $values
     */
    private function line(string $code, string $label, string $section, array $values, string $kind = 'value', bool $scenario = false, ?string $note = null, ?string $key = null, ?string $parent = null): void
    {
        $numeric = $kind !== 'text';

        $this->lines[] = [
            'code' => $code,
            'key' => $key,
            'parent' => $parent,
            'label' => $label,
            'section' => $section,
            'kind' => $kind,
            'scenario' => $scenario,
            'note' => $note,
            'values' => $numeric ? array_map(fn ($v) => round((float) $v, 2), $values) : array_values($values),
            'total' => $numeric && in_array($kind, ['value', 'subtotal', 'total', 'reference'], true) ? round(array_sum(array_map('floatval', $values)), 2) : null,
        ];
    }

    /**
     * @param  list<string>  $codes
     * @param  list<string>  $optional
     * @return list<float>
     */
    private function sum(array $codes, array $optional = []): array
    {
        $result = $this->grid->zeros();

        foreach ([...$codes, ...$optional] as $code) {
            foreach ($this->values($code) as $i => $value) {
                $result[$i] += $value;
            }
        }

        return array_map(fn (float $v) => round($v, 2), $result);
    }

    /**
     * @return list<float>
     */
    private function values(string $code): array
    {
        foreach ($this->lines as $line) {
            if ($line['code'] === $code) {
                return array_map('floatval', $line['values']);
            }
        }

        return $this->grid->zeros();
    }

    /**
     * @return list<string>
     */
    private function connections(): array
    {
        $known = array_keys((array) config('etrip.connections', []));

        return array_values(array_filter((array) ($this->params['etrip_connections'] ?? []), fn ($name) => in_array($name, $known, true)));
    }

    private function lei(float $amount, string $currency): float
    {
        $currency = OmcCashFlowReader::currency($currency);

        return $amount * (float) ($this->fx[$currency] ?? 1.0);
    }

    private function prune(): void
    {
        $keep = max(1, (int) config('cashflow.keep_snapshots', 60));
        $ids = CashFlowSnapshot::query()->orderByDesc('built_at')->orderByDesc('id')->skip($keep)->take(500)->pluck('id');

        if ($ids->isNotEmpty()) {
            CashFlowSnapshot::query()->whereIn('id', $ids)->delete();
        }
    }
}
