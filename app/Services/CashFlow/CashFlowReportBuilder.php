<?php

namespace App\Services\CashFlow;

use App\Models\CashFlowSnapshot;
use App\Models\CharterContract;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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
    private WeekGrid $grid;

    private CarbonImmutable $today;

    /** @var array<string, mixed> */
    private array $params = [];

    /** @var array<string, float> */
    private array $fx = [];

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
            ?? ['receipts' => $this->grid->zeros(), 'costs' => $this->grid->zeros(), 'bookings' => 0];

        $actuals = $this->source('actuals', 'Fluxuri efective an anterior (OMC)', fn () => $this->actuals($opening['total']))
            ?? ['lastyear' => [], 'in' => $this->grid->zeros(), 'out' => $this->grid->zeros()];

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
     * The cash position: the balances saved in the parameters at their date,
     * rolled forward with every bank and cash document OMC has since then.
     *
     * @return array<string, mixed>
     */
    private function opening(): array
    {
        $opening = $this->params['opening'] ?? [];
        $date = ! empty($opening['date']) ? CarbonImmutable::parse($opening['date'])->startOfDay() : null;

        $components = [];
        $currencies = ['RON', 'EUR', 'USD'];

        foreach (['bank' => 'Conturi curente bănci', 'cash' => 'Numerar în casierii', 'deposits' => 'Depozite bancare / plasamente'] as $key => $label) {
            foreach ($currencies as $currency) {
                $components[$key][$currency] = (float) ($opening[$key][$currency] ?? 0);
            }
            $components[$key]['label'] = $label;
        }

        if ($date === null) {
            return [
                ...$this->emptyOpening(),
                'components' => $components,
                '_skipped' => true,
                '_message' => 'Introduceți soldul inițial (bănci, casierii, depozite) și data lui în parametri.',
            ];
        }

        $rolled = array_fill_keys($currencies, 0.0);
        $flows = $this->omc->dailyFlows($date->addDay(), $this->today->addDay());

        foreach ($flows as $flow) {
            $currency = in_array($flow['currency'], $currencies, true) ? $flow['currency'] : 'RON';
            $amount = $currency === $flow['currency'] ? $flow['amount'] : $flow['lei'];
            $rolled[$currency] += $flow['kind'] === 'in' ? $amount : -$amount;
        }

        $total = 0.0;
        $byCurrency = array_fill_keys($currencies, 0.0);

        foreach ($currencies as $currency) {
            $byCurrency[$currency] = $components['bank'][$currency] + $components['cash'][$currency] + $components['deposits'][$currency] + $rolled[$currency];
            $total += $this->lei($byCurrency[$currency], $currency);
        }

        return [
            'date' => $date->toDateString(),
            'as_of' => $this->today->toDateString(),
            'components' => $components,
            'rolled' => array_map(fn (float $v) => round($v, 2), $rolled),
            'by_currency' => array_map(fn (float $v) => round($v, 2), $byCurrency),
            'total' => round($total, 2),
            '_rows' => count($flows),
            '_message' => sprintf('Solduri la %s rulate cu documentele de bancă/casă până la %s.', $date->format('d.m.Y'), $this->today->format('d.m.Y')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyOpening(): array
    {
        return ['date' => null, 'as_of' => $this->today->toDateString(), 'components' => [], 'rolled' => [], 'by_currency' => [], 'total' => 0.0];
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
        $fallbackDays = (int) ($this->params['payables']['days_before_checkin'] ?? 7);
        $bookings = 0;
        $tranches = 0;

        foreach ($this->connections() as $connection) {
            $rows = $this->etrip->openBookings($connection, $this->today->subYear());
            $bookings += count($rows);

            foreach ($rows as $booking) {
                $segment = BookingSegments::of($booking);
                $channel = BookingSegments::channel($booking['client_type']);

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
        $baseSeason = $this->params['scenario']['charter_base_season'] ?? null;
        $factor = (float) ($this->params['scenario']['charter_factor'] ?? 1);
        $targetSeason = $this->params['scenario']['charter_target_season'] ?? null;
        $targetContracted = $targetSeason !== null && $contracts->contains(fn (CharterContract $c) => $c->season === $targetSeason);
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

                if ($baseSeason !== null && $contract->season === $baseSeason && ! $targetContracted && $factor > 0) {
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

        if ($baseSeason === null) {
            $message .= ' Sezonul următor nu este estimat (alegeți sezonul de bază în parametri).';
        } elseif ($targetContracted) {
            $message .= " Sezonul {$targetSeason} este contractat; estimarea nu se mai aplică.";
        }

        return [
            'signed' => $rotations[CharterContract::STATUS_SIGNED],
            'draft' => $rotations[CharterContract::STATUS_DRAFT],
            'deposit' => $deposit,
            'taxes' => $taxes,
            'estimate' => $estimate,
            'estimate_taxes' => $estimateTaxes,
            'contracts' => $summary,
            '_rows' => $flights,
            '_message' => $message,
            '_skipped' => $contracts->isEmpty(),
        ];
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
     * @return array<string, mixed>
     */
    private function opex(): array
    {
        $months = max(1, (int) config('cashflow.omc.opex_months', 12));
        $from = $this->today->startOfMonth()->subMonths($months);
        $to = $this->today->startOfMonth();
        $computed = [];
        $message = null;

        try {
            $byAccount = $this->omc->monthlyAverageByAccount($from, $to, $months);

            foreach ((array) config('cashflow.opex') as $category) {
                if ($category['accounts'] === []) {
                    continue;
                }

                $sum = 0.0;

                foreach ($byAccount as $account => $monthly) {
                    foreach ($category['accounts'] as $prefix) {
                        if (str_starts_with($account, $prefix)) {
                            $sum += $monthly;
                            break;
                        }
                    }
                }

                $computed[$category['key']] = round($sum, 2);
            }

            $message = sprintf('Medii lunare din facturile furnizor OMC %s – %s.', $from->format('m.Y'), $to->subDay()->format('m.Y'));
        } catch (Throwable $e) {
            report($e);
            $message = 'OMC indisponibil pentru medii ('.trim(($e->getPrevious() ?? $e)->getMessage()).'); se folosesc valorile din parametri.';
        }

        $catalogue = CashFlowParameters::opexCatalogue($this->params, $computed);
        $lines = [];

        foreach ($catalogue as $category) {
            $lines[$category['key']] = $this->scheduleOpex((float) $category['monthly'], $category['rule']);
        }

        return ['lines' => $lines, 'catalogue' => $catalogue, '_rows' => count($computed), '_message' => $message];
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
     * @return array<string, mixed>
     */
    private function newSales(): array
    {
        $receipts = $this->grid->zeros();
        $costs = $this->grid->zeros();

        if (! ($this->params['scenario']['enabled'] ?? true)) {
            return ['receipts' => $receipts, 'costs' => $costs, 'bookings' => 0, '_skipped' => true, '_message' => 'Scenariul este dezactivat în parametri.'];
        }

        $factor = max(0.0, (float) ($this->params['scenario']['factor'] ?? 1));
        $daysBefore = (int) ($this->params['payables']['days_before_checkin'] ?? 7);
        $from = $this->grid->lastYearMonday(0);
        $to = $this->grid->end()->subWeeks(52);
        $bookings = 0;
        $structure = [];

        foreach ($this->connections() as $connection) {
            $curve = $this->etrip->bookingCurve($connection, $from, $to, $daysBefore);
            $bookings += $curve['bookings'];

            foreach ($curve['receipts'] as $row) {
                $this->grid->add($receipts, CarbonImmutable::parse($row['week'])->addWeeks(52), $this->lei($row['amount'], $row['currency']) * $factor);
            }

            foreach ($curve['costs'] as $row) {
                $lei = $this->lei($row['cost'], $row['currency']) * $factor;

                if ($this->grid->add($costs, CarbonImmutable::parse($row['week'])->addWeeks(52), $lei)) {
                    $this->collect($structure, $row['category'], $row['currency'], $row['cost'] * $factor, $lei);
                }
            }

            foreach ($curve['tickets'] as $row) {
                $lei = $this->lei($row['cost'], $row['currency']) * $factor;

                if ($this->grid->add($costs, CarbonImmutable::parse($row['week'])->addWeeks(52), $lei)) {
                    $this->collect($structure, 'flight', $row['currency'], $row['cost'] * $factor, $lei);
                }
            }
        }

        return [
            'receipts' => $receipts,
            'costs' => $costs,
            'bookings' => $bookings,
            'structure' => array_values($structure),
            '_rows' => $bookings,
            '_message' => sprintf('%d dosare create între %s și %s, decalate 52 de săptămâni × %.2f.', $bookings, $from->format('d.m.Y'), $to->subDay()->format('d.m.Y'), $factor),
        ];
    }

    /**
     * Last year's actual receipts and payments per week (OMC), and the cash
     * position at the end of each of those weeks reconstructed backwards
     * from today's position.
     *
     * @return array<string, mixed>
     */
    private function actuals(float $openingTotal): array
    {
        $from = $this->grid->lastYearMonday(0);
        $flows = $this->omc->dailyFlows($from, $this->today->addDay());
        $daily = [];

        foreach ($flows as $flow) {
            $daily[$flow['day']] ??= ['in' => 0.0, 'out' => 0.0];
            $daily[$flow['day']][$flow['kind']] += $flow['lei'];
        }

        // Net movement per day, walked backwards from today's position.
        $balanceAfter = [];
        $running = $openingTotal;

        for ($day = $this->today; $day->gte($from); $day = $day->subDay()) {
            $key = $day->toDateString();
            $balanceAfter[$key] = $running;
            $running -= ($daily[$key]['in'] ?? 0.0) - ($daily[$key]['out'] ?? 0.0);
        }

        $in = $this->grid->zeros();
        $out = $this->grid->zeros();
        $lastyear = [];

        for ($i = 0; $i < $this->grid->weeks; $i++) {
            $monday = $this->grid->lastYearMonday($i);
            $sunday = $monday->addDays(6);
            $weekIn = 0.0;
            $weekOut = 0.0;

            for ($day = $monday; $day->lte($sunday); $day = $day->addDay()) {
                $weekIn += $daily[$day->toDateString()]['in'] ?? 0.0;
                $weekOut += $daily[$day->toDateString()]['out'] ?? 0.0;
            }

            $in[$i] = round($weekIn, 2);
            $out[$i] = round($weekOut, 2);
            $closing = $sunday->gte($this->today) ? null : ($balanceAfter[$sunday->toDateString()] ?? null);
            $openingLy = $balanceAfter[$monday->subDay()->toDateString()] ?? null;

            $lastyear[] = [
                'week' => $this->grid->monday($i)->toDateString(),
                'ly_week' => $monday->toDateString(),
                'ly_in' => $in[$i],
                'ly_out' => $out[$i],
                'ly_bal' => $closing !== null ? round($closing, 2) : null,
                'ly_bal_open' => $openingLy !== null ? round($openingLy, 2) : null,
            ];
        }

        return [
            'lastyear' => $lastyear,
            'in' => $in,
            'out' => $out,
            '_rows' => count($flows),
            '_message' => sprintf('Documente de bancă/casă OMC din %s; soldul anului anterior este reconstituit din soldul de azi (estimare).', $from->format('d.m.Y')),
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
        $this->line('B10', 'Încasări din vânzări noi (scenariu: curba anului anterior × factor)', 'B', $scenario['receipts'], scenario: true, note: 'eTrip: încasările dosarelor create în aceeași săptămână a anului anterior');
        $this->line('B', 'TOTAL ÎNCASĂRI OPERAȚIONALE', 'B', $this->sum(['B1', 'B2', 'B3', 'B4', 'B5', 'B6', 'B7', 'B8', 'B9'], $scenarioOn ? ['B10'] : []), kind: 'total');

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
        $this->line('C12', 'Charter sezon următor estimat – rotații (program decalat un an × factor)', 'C', $charter['estimate'], scenario: true);
        $this->line('C13', 'Charter sezon următor estimat – taxe aeroport', 'C', $charter['estimate_taxes'], scenario: true);
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

        $this->line('F1', 'Încasări clienți/parteneri efective (an anterior)', 'F', $actuals['in'], kind: 'reference', note: 'OMC: documente de încasare, aceeași săptămână a anului anterior');
        $this->line('F2', 'Plăți efective – furnizori, salarii, taxe (an anterior)', 'F', $actuals['out'], kind: 'reference', note: 'OMC: documente de plată, aceeași săptămână a anului anterior');
        $this->line('F3', 'Flux net efectiv (an anterior)', 'F', array_map(fn (float $in, float $out) => round($in - $out, 2), $actuals['in'], $actuals['out']), kind: 'reference');

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
            'kpis' => $kpis,
            'opening' => $opening,
            'structure' => [
                'receivables' => $receivables['structure'] ?? [],
                'payables' => $payables['structure'] ?? [],
                'new_sales' => $scenario['structure'] ?? [],
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
    private function line(string $code, string $label, string $section, array $values, string $kind = 'value', bool $scenario = false, ?string $note = null, ?string $key = null): void
    {
        $numeric = $kind !== 'text';

        $this->lines[] = [
            'code' => $code,
            'key' => $key,
            'label' => $label,
            'section' => $section,
            'kind' => $kind,
            'scenario' => $scenario,
            'note' => $note,
            'values' => $numeric ? array_map(fn ($v) => round((float) $v, 2), $values) : array_values($values),
            'total' => $numeric && in_array($kind, ['value', 'total', 'reference'], true) ? round(array_sum(array_map('floatval', $values)), 2) : null,
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
