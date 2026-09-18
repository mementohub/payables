<?php

namespace App\Services\CashFlow;

use App\Models\CashFlowSnapshot;
use App\Models\CharterContract;
use App\Models\EtripSupplier;
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

    /** Past weeks the actual cash-flow history covers. */
    public const HISTORY_WEEKS = 52;

    /** Booking segment → receipts line. */
    public const SEGMENT_LINES = ['pachete' => 'B1', 'circuite' => 'B2', 'exotic' => 'B3', 'sphinx' => 'B4', 'cazare' => 'B5', 'bilete' => 'B6', 'altele' => 'B7'];

    /** Charter series → report line. */
    private const CHARTER_LINES = [
        CharterContract::STATUS_SIGNED => 'C6',
        CharterContract::STATUS_DRAFT => 'C7',
        'deposit' => 'C8',
        'taxes' => 'C9',
        'incoming' => 'B10',
    ];

    /** eTrip payable category → product payments line. */
    public const PAYABLE_LINES = ['hotel' => 'C1', 'transfer' => 'C2', 'insurance' => 'C3', 'flight' => 'C4', 'other' => 'C5'];

    private WeekGrid $grid;

    private CarbonImmutable $today;

    /** @var array<string, mixed> */
    private array $params = [];

    /** @var array<string, float> */
    private array $fx = [];

    /** Last closed month-end in OMC: the window the OPEX averages cover. */
    private ?CarbonImmutable $anchor = null;

    /** The day the treasury position is stated at, the end of yesterday. */
    private ?CarbonImmutable $positionAsOf = null;

    /**
     * BNR rates OMC holds for that day, used to convert the position.
     *
     * @var array<string, float>
     */
    private array $positionRates = [];

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
        private ActualCashFlowClassifier $classifier,
        private CashFlowDetailRecorder $recorder,
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
        $this->positionAsOf = null;
        $this->positionRates = [];

        $snapshot = new CashFlowSnapshot([
            'built_at' => now(),
            'week_start' => $this->grid->start->toDateString(),
            'built_by' => $builtBy,
            'status' => CashFlowSnapshot::STATUS_FAILED,
        ]);

        try {
            $this->recorder->start();
        } catch (Throwable $e) {
            // The report goes on without its cell details.
            report($e);
        }

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

        try {
            $snapshot->payload !== null ? $this->recorder->attach($snapshot) : $this->recorder->discard();
        } catch (Throwable $e) {
            report($e);
        }

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
            ?? ['signed' => $this->grid->zeros(), 'draft' => $this->grid->zeros(), 'deposit' => $this->grid->zeros(), 'taxes' => $this->grid->zeros(), 'incoming' => $this->grid->zeros(), 'estimate' => $this->grid->zeros(), 'estimate_taxes' => $this->grid->zeros(), 'contracts' => []];

        $suppliers = $this->source('suppliers_open', 'Furnizori – facturi neachitate (OMC)', fn () => $this->openSuppliers())
            ?? ['line' => $this->grid->zeros(), 'total' => 0.0, 'overdue' => 0.0, 'mode' => 'none'];

        $advances = config('cashflow.advances.enabled', true)
            ? $this->source('advances', 'Avansuri plătite furnizorilor (OMC)', fn () => $this->advances($suppliers, $charter, $payables))
            : null;

        if ($advances !== null) {
            [$suppliers, $charter, $payables] = [$advances['suppliers'], $advances['charter'], $advances['payables']];
        }

        $opex = $this->source('opex', 'OPEX (OMC, medii 12 luni)', fn () => $this->opex())
            ?? ['lines' => [], 'catalogue' => CashFlowParameters::opexCatalogue($this->params)];

        $scenario = $this->source('new_sales', 'Vânzări noi (curba anului anterior, eTrip)', fn () => $this->newSales())
            ?? ['receipts' => array_fill_keys(array_keys(BookingSegments::LABELS), $this->grid->zeros()), 'costs' => $this->grid->zeros(), 'bookings' => 0];

        $actuals = $this->source('actuals', 'Fluxuri efective an anterior (OMC)', fn () => $this->actuals($opening['total']))
            ?? ['lastyear' => [], 'recent' => [], 'history' => [], 'in' => $this->grid->zeros(), 'out_partner' => $this->grid->zeros(), 'out_salaries' => $this->grid->zeros(), 'out_other' => $this->grid->zeros()];

        $classified = $this->source('actual_lines', 'Flux efectiv pe liniile raportului (OMC + eTrip)', fn () => $this->classifier->classify(
            array_map(fn (int $i) => $this->grid->start->subWeeks($i)->toDateString(), range(self::HISTORY_WEEKS, 0)),
            $this->today,
            $this->connections(),
            $opex['catalogue'],
            self::SEGMENT_LINES,
        )) ?? ['lines' => [], 'classified' => []];

        return [
            ...$this->assemble($opening, $receivables, $payables, $charter, $suppliers, $opex, $scenario, $actuals, $classified),
            'advances' => $advances['summary'] ?? [],
        ];
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
        $this->recorder->source($key);

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
            $this->recorder->forget($key);
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
     * The treasury position at the end of yesterday: the last closed balance
     * of the bank accounts, cash desks and 5081 deposits, each rolled with
     * the documents recorded after it, up to and including yesterday. The
     * amounts are converted at the BNR rate OMC holds for that day, because
     * the position states what the accounts held on a date.
     *
     * @return array<string, mixed>
     */
    private function opening(): array
    {
        $this->anchor = $this->omc->monthEndAnchor($this->today);
        $anchors = $this->omc->balanceAnchors($this->today);
        $asOf = $anchors['as_of'];
        $this->positionAsOf = $asOf;

        if ($anchors['bank'] === null && $anchors['cash'] === null && $anchors['deposits'] === null) {
            return [
                ...$this->emptyOpening(),
                '_skipped' => true,
                '_message' => 'OMC nu are solduri salvate (eu_banca_sold, casa_sold, conta_sold 5081): poziția de trezorerie nu poate fi calculată.',
            ];
        }

        $position = $this->omc->openingPosition($anchors, $asOf);
        $this->positionRates = $this->omc->ratesAt($asOf);
        $currencies = array_values(array_unique(['RON', 'EUR', 'USD', ...array_column($position, 'currency')]));
        $rows = [];
        $day = $asOf->format('d.m.Y');
        $base = fn (string $section) => $anchors[$section]?->format('d.m.Y') ?? 'lipsă';
        $labels = [
            'bank_open' => 'Conturi curente bănci – sold contabil de bază ('.$base('bank').')',
            'bank_in' => 'Încasări prin bancă până la '.$day,
            'bank_out' => 'Plăți prin bancă până la '.$day,
            'bank_now' => 'Conturi curente bănci la '.$day,
            'cash_open' => 'Numerar în casierii – sold contabil de bază ('.$base('cash').')',
            'cash_in' => 'Încasări în numerar până la '.$day,
            'cash_out' => 'Plăți în numerar până la '.$day,
            'cash_now' => 'Numerar în casierii la '.$day,
            'deposits_open' => 'Depozite bancare (5081) – sold contabil de bază ('.$base('deposits').')',
            'deposits_change' => 'Depozite plasate (+) / lichidate (−) până la '.$day,
            'deposits_now' => 'Depozite bancare la '.$day,
            'position' => 'Poziție de trezorerie la '.$day,
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
            $total += $this->positionLei($bankNow + $cashNow + $depositsNow, $currency);
        }

        $rates = array_intersect_key($this->positionRates, array_flip($currencies));

        return [
            'date' => $asOf->toDateString(),
            'as_of' => $asOf->toDateString(),
            'base' => ['bank' => $anchors['bank']?->toDateString(), 'cash' => $anchors['cash']?->toDateString(), 'deposits' => $anchors['deposits']?->toDateString()],
            'fallback' => (bool) $anchors['fallback'],
            'rates' => array_map(fn (float $rate) => round($rate, 4), $rates),
            'currencies' => $currencies,
            'rows' => array_values($rows),
            'by_currency' => $byCurrency,
            'total' => round($total, 2),
            '_rows' => count($position),
            '_message' => sprintf(
                'Poziția de trezorerie la %s (%s): soldurile contabile de bază – bănci %s, casierii %s, depozite 5081 %s – rulate cu documentele de bancă și casă până la %s inclusiv%s.%s',
                $day,
                $anchors['fallback'] ? 'cea mai recentă dată cu solduri în OMC' : 'sfârșitul zilei de ieri',
                $base('bank'),
                $base('cash'),
                $base('deposits'),
                $day,
                $rates !== [] ? ', la cursul BNR din OMC de la acea dată ('.implode(', ', array_map(fn ($c, $r) => $c.' '.number_format($r, 4, ',', '.'), array_keys($rates), $rates)).')' : '',
                $anchors['fallback'] ? ' OMC nu are solduri înainte de ieri; s-a folosit cea mai recentă dată disponibilă.' : '',
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyOpening(): array
    {
        return [
            'date' => null,
            'as_of' => $this->today->subDay()->toDateString(),
            'base' => ['bank' => null, 'cash' => null, 'deposits' => null],
            'fallback' => false,
            'rates' => [],
            'currencies' => ['RON', 'EUR', 'USD'],
            'rows' => [],
            'by_currency' => [],
            'total' => 0.0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function receivables(): array
    {
        $lines = array_fill_keys(array_keys(BookingSegments::LABELS), $this->grid->zeros());
        $overdueRecent = [];
        $overdueOld = [];
        $overdueRows = ['recent' => [], 'old' => []];
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
                    $due = $this->day($tranche['date']);
                    $piece = [
                        'client' => $booking['client'] ?? null,
                        'booking' => $booking['id'],
                        'connection' => $connection,
                        'segment' => $segment,
                        'channel' => $channel,
                        'type' => $tranche['type'],
                        'due' => $tranche['date'],
                        'currency' => $currency,
                        'amount' => $tranche['amount'],
                        'lei' => $lei,
                        'start_date' => $booking['start_date'] ?? null,
                    ];

                    if ($due->lt($this->today)) {
                        $days = (int) $due->diffInDays($this->today);

                        if ($days <= $recentDays) {
                            $bucket = 'restant_recent';
                            $overdueRecent[$currency] = ($overdueRecent[$currency] ?? 0.0) + $tranche['amount'];
                            $overdueRows['recent'][] = [...$piece, 'days' => $days];
                        } else {
                            $bucket = 'restant_vechi';
                            $overdueOld[$currency] = ($overdueOld[$currency] ?? 0.0) + $tranche['amount'];
                            $overdueRows['old'][] = [...$piece, 'days' => $days];
                        }
                    } elseif ($this->grid->index($due) === null) {
                        $bucket = 'dupa_orizont';
                        $beyond[$currency] = ($beyond[$currency] ?? 0.0) + $tranche['amount'];
                    } else {
                        $bucket = $tranche['type'];
                        $this->put($lines[$segment], self::SEGMENT_LINES[$segment], $due, $lei, 'tranche', $this->bookingLabel($piece), [
                            'group' => $tranche['type'],
                            'reference' => $booking['id'],
                            'date' => $tranche['date'],
                            'currency' => $currency,
                            'amount' => $tranche['amount'],
                            'meta' => ['connection' => $connection, 'channel' => $channel, 'start_date' => $booking['start_date'] ?? null, 'total_due' => round((float) $booking['total_due'], 2), 'paid' => round((float) $booking['paid'], 2)],
                        ]);
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
            'overdue_rows' => $overdueRows,
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
        $claims = [];
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
                $week = CarbonImmutable::parse($row['week']);
                // The check-ins paid in that week; the first week also carries the earlier ones.
                $checkinFrom = $week->addDays($daysBefore)->max($firstCheckin);
                $index = $this->grid->column($row['week'], carryEarly: true);

                if ($index !== null && ! empty($row['supplier'])) {
                    $claims[] = ['supplier' => $connection.'|'.$row['supplier'], 'series' => $row['category'], 'line' => self::PAYABLE_LINES[$row['category']] ?? 'C5', 'index' => $index, 'lei' => $lei, 'label' => $row['supplier_name'] ?? $row['supplier'], 'priority' => 1];
                }

                $this->put($lines[$row['category']], self::PAYABLE_LINES[$row['category']] ?? 'C5', $row['week'], $lei, 'services', $row['supplier_name'] ?? $row['supplier'] ?? 'Furnizor eTrip', [
                    'group' => self::PAYABLE_LABELS[$row['category']] ?? $row['category'],
                    'reference' => $row['supplier'] ?? null,
                    'currency' => $row['currency'],
                    'amount' => $row['cost'] * $factor,
                    'meta' => [
                        'connection' => $connection,
                        'supplier' => $row['supplier'] ?? null,
                        'category' => $row['category'],
                        'items' => $row['items'],
                        'bookings' => $row['bookings'] ?? null,
                        'checkin_from' => $checkinFrom->toDateString(),
                        'checkin_to' => $week->addDays($daysBefore + 6)->toDateString(),
                        'days_before' => $daysBefore,
                        'factor' => round($factor, 4),
                    ],
                ], carryEarly: true);
                $this->collect($structure, $row['category'], $row['currency'], $row['cost'] * $factor, $lei, $row['items']);
            }

            foreach ($this->etrip->ticketsOrdered($connection, $this->today->subDays($ticketDays), $this->today->addDay()) as $row) {
                $rows++;
                $lei = $this->lei($row['cost'], $row['currency']);
                $this->put($lines['flight'], 'C4', $this->grid->start, $lei, 'tickets', $row['supplier_name'] ?? $row['supplier'] ?? 'Bilete de linie', [
                    'group' => 'Bilete comandate',
                    'reference' => $row['supplier'] ?? null,
                    'currency' => $row['currency'],
                    'amount' => $row['cost'],
                    'meta' => ['connection' => $connection, 'supplier' => $row['supplier'] ?? null, 'items' => $row['items'], 'ordered_week' => $row['week'], 'ticket_days' => $ticketDays],
                ]);
                $this->collect($structure, 'flight', $row['currency'], $row['cost'], $lei, $row['items']);
            }
        }

        return [
            'lines' => $lines,
            'claims' => $claims,
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
     * The charter contracts, each settled on its own terms: rotations on the
     * notice the contract gives, airport taxes the way that contract settles
     * them, the deposit on its due date, and the contracts where CHR sells
     * the seats as money coming in. Contracts marked as not counting stay
     * out of the report and only carry their terms.
     *
     * @return array<string, mixed>
     */
    private function charter(): array
    {
        $series = array_fill_keys(
            [CharterContract::STATUS_SIGNED, CharterContract::STATUS_DRAFT, 'deposit', 'taxes', 'incoming'],
            $this->grid->zeros(),
        );
        $estimate = $this->grid->zeros();
        $estimateTaxes = $this->grid->zeros();
        $summary = [];

        $contracts = CharterContract::query()
            ->with(['flights' => fn ($q) => $q->orderBy('flight_date')])
            ->orderByDesc('in_cash_flow')
            ->orderBy('season')
            ->orderBy('name')
            ->get();

        $factor = (float) ($this->params['scenario']['charter_factor'] ?? 1);
        [$estimates, $contracted] = $this->charterEstimates($contracts->where('in_cash_flow', true));
        $claims = [];
        // Paid deposits the contracts already set against their coming rotations, by counterparty.
        $depositsInUse = [];
        // What the contract makes us pay, by counterparty, for the advances to settle.
        $claim = function (CharterContract $contract, string $key, CarbonInterface $date, float $lei, string $reference) use (&$claims): void {
            $index = $this->grid->index($date);

            if ($index !== null && $key !== 'incoming' && (string) $contract->counterparty !== '') {
                $claims[] = ['key' => ActualCashFlowClassifier::normalize((string) $contract->counterparty), 'series' => $key, 'line' => self::CHARTER_LINES[$key], 'index' => $index, 'lei' => $lei, 'label' => $contract->name, 'reference' => $reference, 'priority' => $key === 'deposit' ? 0 : 1];
            }
        };
        $flights = 0;
        $counted = 0;

        foreach ($contracts as $contract) {
            $incomingContract = $contract->isIncoming();
            $withTaxes = $contract->taxesRideWithRotation();
            $bucket = $contract->status === CharterContract::STATUS_DRAFT
                ? CharterContract::STATUS_DRAFT
                : CharterContract::STATUS_SIGNED;

            $row = [
                'id' => $contract->id,
                'name' => $contract->name,
                'counterparty' => $contract->counterparty,
                'season' => $contract->season,
                'status' => $contract->status,
                'direction' => $contract->direction,
                'in_cash_flow' => $contract->in_cash_flow,
                'operator' => $contract->operator,
                'currency' => $contract->currency,
                'flights' => $contract->flights->count(),
                'total_net' => 0.0,
                'in_horizon' => 0.0,
                'taxes' => 0.0,
                'deposit' => 0.0,
                'terms' => $this->charterTerms($contract),
            ];

            foreach ($contract->flights as $flight) {
                $row['total_net'] += (float) $flight->net_value;
            }

            if (! $contract->in_cash_flow) {
                $summary[] = array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $row);

                continue;
            }

            $counted++;
            $settled = $this->depositSettlement($contract, $withTaxes, $row['total_net']);

            if ($contract->deposit_paid && ! $incomingContract && (string) $contract->counterparty !== '') {
                $key = ActualCashFlowClassifier::normalize((string) $contract->counterparty);

                foreach ($contract->flights as $flight) {
                    if (isset($settled[$flight->id]) && $flight->setRelation('contract', $contract)->paymentDate()->gte($this->today)) {
                        $depositsInUse[$key] = ($depositsInUse[$key] ?? 0.0) + $this->charterLei($settled[$flight->id], $contract);
                    }
                }
            }

            foreach ($contract->flights as $flight) {
                $flight->setRelation('contract', $contract);
                $flights++;
                $payDate = $flight->paymentDate();
                $flightTaxes = (float) $flight->taxes;

                $flightLabel = trim(implode(' ', array_filter([$flight->flight_no, $flight->route])));
                $flightMeta = ['contract_id' => $contract->id, 'flight_id' => $flight->id, 'flight_date' => $flight->flight_date?->toDateString(), 'season' => $contract->season, 'operator' => $flight->operator ?? $contract->operator];

                if ($payDate->gte($this->today)) {
                    $due = (float) $flight->net_value + ($withTaxes ? $flightTaxes : 0.0) - ($settled[$flight->id] ?? 0.0);
                    $key = $incomingContract ? 'incoming' : $bucket;

                    if ($this->put($series[$key], self::CHARTER_LINES[$key], $payDate, $this->charterLei($due, $contract), 'rotation', $contract->name, [
                        'group' => $contract->name,
                        'reference' => $flightLabel !== '' ? $flightLabel : $flight->flight_date?->format('d.m.Y'),
                        'date' => $payDate->toDateString(),
                        'currency' => OmcCashFlowReader::currency((string) $contract->currency),
                        'amount' => $due,
                        'meta' => [...$flightMeta, 'net' => round((float) $flight->net_value, 2), 'taxes' => $withTaxes ? round($flightTaxes, 2) : 0.0, 'deposit_covered' => round($settled[$flight->id] ?? 0.0, 2), 'seats' => $flight->seats],
                    ])) {
                        $claim($contract, $key, $payDate, $this->charterLei($due, $contract), $flightLabel);
                        $row['in_horizon'] += $due;

                        if ($withTaxes) {
                            $row['taxes'] += $flightTaxes;
                        }
                    }
                }

                $taxDate = $withTaxes ? null : $flight->taxesPaymentDate();

                if ($flightTaxes > 0 && $taxDate !== null && $taxDate->gte($this->today)) {
                    $key = $incomingContract ? 'incoming' : 'taxes';

                    if ($this->put($series[$key], self::CHARTER_LINES[$key], $taxDate, $this->charterLei($flightTaxes, $contract), 'airport_taxes', $contract->name, [
                        'group' => $contract->name,
                        'reference' => ($flightLabel !== '' ? $flightLabel.' · ' : '').'taxe aeroport',
                        'date' => $taxDate->toDateString(),
                        'currency' => OmcCashFlowReader::currency((string) $contract->currency),
                        'amount' => $flightTaxes,
                        'meta' => $flightMeta,
                    ])) {
                        $claim($contract, $key, $taxDate, $this->charterLei($flightTaxes, $contract), trim($flightLabel.' taxe'));
                        $row['taxes'] += $flightTaxes;
                    }
                }

                if (! $incomingContract && $factor > 0 && array_key_exists($contract->season, $estimates)) {
                    $shifted = $payDate->copy()->addDays(364);

                    $estimateMeta = [...$flightMeta, 'target_season' => $estimates[$contract->season] ?? null, 'factor' => $factor];

                    if ($shifted->gte($this->today)) {
                        $this->put($estimate, 'C12', $shifted, $this->charterLei((float) $flight->net_value * $factor, $contract), 'estimate', $contract->name, [
                            'group' => $contract->name.' → '.($estimates[$contract->season] ?? 'sezonul următor'),
                            'reference' => ($flightLabel !== '' ? $flightLabel.' · ' : '').'din '.$payDate->format('d.m.Y'),
                            'date' => $shifted->toDateString(),
                            'currency' => OmcCashFlowReader::currency((string) $contract->currency),
                            'amount' => (float) $flight->net_value * $factor,
                            'meta' => $estimateMeta,
                        ]);
                    }

                    $shiftedTax = ($taxDate ?? $payDate)->copy()->addDays(364);

                    if ($flightTaxes > 0 && $shiftedTax->gte($this->today)) {
                        $this->put($estimateTaxes, 'C13', $shiftedTax, $this->charterLei($flightTaxes * $factor, $contract), 'estimate_taxes', $contract->name, [
                            'group' => $contract->name.' → '.($estimates[$contract->season] ?? 'sezonul următor'),
                            'reference' => ($flightLabel !== '' ? $flightLabel.' · ' : '').'taxe din '.($taxDate ?? $payDate)->format('d.m.Y'),
                            'date' => $shiftedTax->toDateString(),
                            'currency' => OmcCashFlowReader::currency((string) $contract->currency),
                            'amount' => $flightTaxes * $factor,
                            'meta' => $estimateMeta,
                        ]);
                    }
                }
            }

            if (! $contract->deposit_paid && $contract->deposit_due_date !== null) {
                $amount = $contract->depositAmount($row['total_net']);
                $key = $incomingContract ? 'incoming' : 'deposit';

                if ($amount > 0 && $contract->deposit_due_date->gte($this->today)
                    && $this->put($series[$key], self::CHARTER_LINES[$key], $contract->deposit_due_date, $this->charterLei($amount, $contract), 'deposit', $contract->name, [
                        'group' => $contract->name,
                        'reference' => 'depozit contract',
                        'date' => $contract->deposit_due_date->toDateString(),
                        'currency' => OmcCashFlowReader::currency((string) $contract->currency),
                        'amount' => $amount,
                        'meta' => ['contract_id' => $contract->id, 'season' => $contract->season],
                    ])) {
                    $claim($contract, $key, $contract->deposit_due_date, $this->charterLei($amount, $contract), 'depozit');
                    $row['deposit'] = $amount;
                }
            }

            $summary[] = array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $row);
        }

        $message = sprintf('%d contracte în flux (din %d), %d rotații.', $counted, $contracts->count(), $flights);

        foreach ($estimates as $base => $target) {
            $message .= sprintf(' Sezonul %s este estimat din programul %s decalat 364 de zile × %.2f.', $target ?? 'următor', $base, $factor);
        }

        foreach ($contracted as $base => $target) {
            $message .= sprintf(' Sezonul %s este contractat; programul %s nu se mai decalează.', $target, $base);
        }

        return [
            'signed' => $series[CharterContract::STATUS_SIGNED],
            'draft' => $series[CharterContract::STATUS_DRAFT],
            'deposit' => $series['deposit'],
            'taxes' => $series['taxes'],
            'incoming' => $series['incoming'],
            'estimate' => $estimate,
            'estimate_taxes' => $estimateTaxes,
            'estimates' => $estimates,
            'contracts' => $summary,
            'claims' => $claims,
            'deposits_in_use' => $depositsInUse,
            '_rows' => $flights,
            '_message' => $message,
            '_skipped' => $counted === 0,
        ];
    }

    /**
     * How much of each rotation the deposit already covers. As the contracts
     * settle it (CTR 317 art. 3.5, CTR 1585 art. 3.5, CTR 281, and so the
     * W26/27 draft), the deposit is regularised at the last rotations: every
     * rotation is due in full, and the ones at the end of the programme are
     * paid, or collected, only for what the deposit leaves.
     *
     * @return array<int, float> the covered amount keyed by rotation id
     */
    private function depositSettlement(CharterContract $contract, bool $withTaxes, float $flightsNet): array
    {
        $remaining = $contract->depositAmount($flightsNet);
        $covered = [];

        foreach ($contract->flights->sortByDesc('flight_date') as $flight) {
            if ($remaining <= 0) {
                break;
            }

            $amount = (float) $flight->net_value + ($withTaxes ? (float) $flight->taxes : 0.0);
            $covered[$flight->id] = min($remaining, $amount);
            $remaining = round($remaining - $covered[$flight->id], 2);
        }

        return $covered;
    }

    /**
     * The contract's terms in one line each, for the report and the Charter tab.
     *
     * @return array<string, string>
     */
    private function charterTerms(CharterContract $contract): array
    {
        $days = (int) $contract->days_before_flight;

        $rotation = match ($contract->payment_basis) {
            CharterContract::BASIS_WEEK => sprintf('cu %d zile înainte de luni, pe săptămâna de operare', $days),
            CharterContract::BASIS_SIGNING => 'integral la semnare',
            default => sprintf('OP cu %d zile înainte de fiecare rotație', $days),
        };

        $taxes = match ($contract->taxes_rule) {
            CharterContract::TAXES_WITH_ROTATION => 'odată cu rotația',
            CharterContract::TAXES_AFTER => sprintf('la %d zile după zbor', (int) ($contract->taxes_days ?? 3)),
            CharterContract::TAXES_BEFORE => sprintf('în avans, cu %d zile înainte, la capacitate maximă', (int) ($contract->taxes_days ?? 14)),
            default => sprintf('reconciliere lunară, ziua %d a lunii următoare', (int) ($contract->taxes_month_day ?? 5)),
        };

        $deposit = match (true) {
            $contract->deposit_amount !== null => number_format((float) $contract->deposit_amount, 0, ',', '.').' '.$contract->currency,
            $contract->deposit_percent !== null => rtrim(rtrim(number_format((float) $contract->deposit_percent, 2, ',', '.'), '0'), ',').'%',
            default => 'fără depozit',
        };

        if ($contract->deposit_due_date !== null) {
            $deposit .= ' scadent '.$contract->deposit_due_date->format('d.m.Y');
        }

        if ($contract->deposit_paid) {
            $deposit .= ' (achitat)';
        }

        return [
            'rotation' => $rotation,
            'taxes' => $taxes,
            'deposit' => $deposit,
            'settlement' => (string) ($contract->deposit_settlement ?? ''),
            'invoicing' => (string) ($contract->invoicing ?? ''),
            'fuel' => (string) ($contract->fuel_rule ?? ''),
            'fx' => (float) $contract->fx_markup_pct > 0
                ? sprintf('%s sau RON la BNR + %s%%', $contract->currency, rtrim(rtrim(number_format((float) $contract->fx_markup_pct, 2, ',', '.'), '0'), ','))
                : (string) $contract->currency,
            'penalty' => $contract->late_penalty_pct_per_day !== null
                ? rtrim(rtrim(number_format((float) $contract->late_penalty_pct_per_day, 3, ',', '.'), '0'), ',').'%/zi'
                : '',
            'cancellation' => (string) ($contract->cancellation_terms ?? ''),
            'source' => (string) ($contract->source ?? ''),
            'confidence' => (string) ($contract->confidence ?? ''),
        ];
    }

    /**
     * A contract amount in lei, at its own currency clause.
     */
    private function charterLei(float $amount, CharterContract $contract): float
    {
        $currency = OmcCashFlowReader::currency((string) $contract->currency);

        return $amount * $contract->rate((float) ($this->fx[$currency] ?? 1.0));
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
                $this->recorder->record('C10', $this->grid->monday($i)->toDateString(), 'manual', 'Sold furnizori introdus în parametri', $total / $weeks, [
                    'group' => 'Parametri',
                    'currency' => 'RON',
                    'amount' => $total,
                    'meta' => ['share' => round(1 / $weeks, 6), 'weeks' => $weeks],
                ]);
            }

            return ['line' => $line, 'total' => round($total, 2), 'overdue' => round($total, 2), 'mode' => 'manual', '_message' => sprintf('Sold introdus manual: %s RON pe %d săptămâni.', number_format($total, 0, ',', '.'), $weeks)];
        }

        $since = $this->today->subYears(max(1, (int) config('omc.open_window_years', 2)));
        $rows = $this->omc->openSupplierInvoiceList($since);
        $overdue = 0.0;
        $overdueRows = [];
        $claims = [];
        $total = 0.0;
        $byCurrency = [];

        foreach ($rows as $row) {
            $total += $row['lei'];
            $byCurrency[$row['currency']] = ($byCurrency[$row['currency']] ?? 0.0) + $row['amount'];
            $due = $this->day($row['due']);
            $detail = [
                'reference' => $row['nr_doc'],
                'date' => $row['due'],
                'currency' => $row['currency'],
                'amount' => $row['amount'],
                'meta' => ['data_doc' => $row['data_doc'], 'tip_doc' => $row['tip_doc'], 'nr_doc' => $row['nr_doc']],
            ];

            if ($due->lt($this->today)) {
                $overdue += $row['lei'];
                $overdueRows[] = [$row, $detail, (int) $due->diffInDays($this->today)];
            } else {
                $index = $this->grid->column($due, carryEarly: true);

                if ($this->put($line, 'C10', $due, $row['lei'], 'invoice', $row['partner'] ?? 'Furnizor', [...$detail, 'group' => 'Scadente în săptămână'], carryEarly: true) && $row['partner'] !== null) {
                    $claims[] = ['key' => ActualCashFlowClassifier::normalize($row['partner']), 'series' => 'line', 'line' => 'C10', 'index' => $index, 'lei' => $row['lei'], 'label' => $row['partner'], 'reference' => $row['nr_doc'], 'priority' => 1];
                }
            }
        }

        for ($i = 0; $i < $weeks; $i++) {
            $line[$i] += $overdue / $weeks;

            foreach ($overdueRows as [$row, $detail, $days]) {
                if ($row['partner'] !== null) {
                    $claims[] = ['key' => ActualCashFlowClassifier::normalize($row['partner']), 'series' => 'line', 'line' => 'C10', 'index' => $i, 'lei' => $row['lei'] / $weeks, 'label' => $row['partner'], 'reference' => $row['nr_doc'], 'priority' => 0];
                }

                $this->recorder->record('C10', $this->grid->monday($i)->toDateString(), 'invoice', $row['partner'] ?? 'Furnizor', $row['lei'] / $weeks, [
                    ...$detail,
                    'group' => 'Restante',
                    'meta' => [...$detail['meta'], 'days_overdue' => $days, 'share' => round(1 / $weeks, 6), 'weeks' => $weeks],
                ]);
            }
        }

        return [
            'line' => $line,
            'claims' => $claims,
            'total' => round($total, 2),
            'overdue' => round($overdue, 2),
            'by_currency' => array_map(fn (float $v) => round($v, 2), $byCurrency),
            'mode' => 'omc',
            '_rows' => count($rows),
            '_message' => sprintf('Facturi furnizori deschise din OMC: %s RON, din care scadente depășite %s RON plătite pe %d săptămâni.', number_format($total, 0, ',', '.'), number_format($overdue, 0, ',', '.'), $weeks),
        ];
    }

    /**
     * Money already paid to a supplier that the forecast would otherwise
     * pay again. Payments OMC has not matched to any invoice settle the
     * supplier's open invoices first (C10); what is left of them, or the
     * advance balance on 409 when larger (never both, so nothing counts
     * twice), covers the supplier's future dues: the charter contracts of
     * the counterparty (unpaid deposits first, then by date), then its eTrip
     * services where the eTrip supplier is surely the same partner. Every
     * amount taken off is kept as a negative piece of its cell.
     *
     * @param  array<string, mixed>  $suppliers
     * @param  array<string, mixed>  $charter
     * @param  array<string, mixed>  $payables
     * @return array<string, mixed>
     */
    private function advances(array $suppliers, array $charter, array $payables): array
    {
        $since = $this->today->subYears(max(1, (int) config('omc.open_window_years', 2)));
        $name = fn (string $partner) => ActualCashFlowClassifier::normalize($partner);
        $unmatched = collect($this->omc->unmatchedSupplierPayments($since))->groupBy(fn (array $row) => $name($row['partner']));
        $ledger = collect($this->omc->supplierAdvances())->groupBy(fn (array $row) => $name($row['partner']));
        $etripPartners = $this->etripPartners();
        $summary = [];

        $take = function (array $claims, float $credit, string $basis, string $partner, array &$applied) use (&$suppliers, &$charter, &$payables): float {
            foreach ($claims as $claim) {
                if ($credit <= 0.005) {
                    break;
                }

                $amount = min($credit, $claim['lei']);

                if ($amount <= 0.005) {
                    continue;
                }

                match (true) {
                    $claim['line'] === 'C10' => $suppliers['line'][$claim['index']] -= $amount,
                    isset($claim['supplier']) => $payables['lines'][$claim['series']][$claim['index']] -= $amount,
                    default => $charter[$claim['series']][$claim['index']] -= $amount,
                };

                $this->recorder->record($claim['line'], $this->grid->monday($claim['index'])->toDateString(), 'advance', 'Avans plătit – '.$partner, -$amount, [
                    'group' => 'Avansuri plătite anterior',
                    'reference' => trim(($claim['label'] ?? '').' '.($claim['reference'] ?? '')),
                    'currency' => 'RON',
                    'amount' => -$amount,
                    'meta' => ['basis' => $basis, 'partner' => $partner, 'covers' => $claim['label'] ?? null, 'covers_reference' => $claim['reference'] ?? null, 'covers_lei' => round($claim['lei'], 2)],
                ]);

                $applied[$claim['line']] = ($applied[$claim['line']] ?? 0.0) + $amount;
                $credit -= $amount;
            }

            return $credit;
        };

        foreach ($unmatched->keys()->merge($ledger->keys())->unique() as $key) {
            $payments = $unmatched->get($key, collect());
            $balances = $ledger->get($key, collect());
            $partner = (string) ($payments->first()['partner'] ?? $balances->first()['partner']);
            $unmatchedLei = round((float) $payments->sum(fn (array $row) => $this->lei($row['amount'], $row['currency'])), 2);
            $advance = $balances->groupBy('currency')->map(fn (Collection $rows) => round((float) $rows->sum('amount'), 2))->filter(fn (float $amount) => $amount > 0.5);
            $advanceLei = round((float) $advance->map(fn (float $amount, string $currency) => $this->lei($amount, $currency))->sum(), 2);
            // A paid deposit the contract already sets against its last rotations is not used twice.
            $inUse = round((float) ($charter['deposits_in_use'][$key] ?? 0.0), 2);
            $available = max(0.0, $advanceLei - $inUse);

            if ($unmatchedLei <= 0.5 && $available <= 0.5) {
                continue;
            }

            $applied = [];
            $claims = fn (array $list, callable $match) => collect($list)->filter($match)->sortBy([['priority', 'asc'], ['index', 'asc']])->values()->all();

            // 1. Paid without an invoice: the supplier's open invoices are settled by it.
            $left = $take($claims($suppliers['claims'] ?? [], fn (array $c) => $c['key'] === $key), $unmatchedLei, 'nealocat', $partner, $applied);

            // 2. The future dues, from the larger of what is left and the 409 balance.
            $future = max($left, $available);
            $basis = $available >= $left ? '409' : 'nealocat';
            $future = $take($claims($charter['claims'] ?? [], fn (array $c) => $c['key'] === $key), $future, $basis, $partner, $applied);
            $future = $take($claims($payables['claims'] ?? [], fn (array $c) => ($etripPartners[$c['supplier']] ?? null) === $key), $future, $basis, $partner, $applied);

            $summary[] = [
                'partner' => $partner,
                'unmatched_lei' => $unmatchedLei,
                'unmatched_payments' => $payments->count(),
                'unmatched_last' => $payments->max('data_doc'),
                'advance' => $advance->all(),
                'advance_lei' => $advanceLei,
                'deposit_in_contract_lei' => $inUse,
                'applied' => array_map(fn (float $v) => round($v, 2), $applied),
                'applied_lei' => round(array_sum($applied), 2),
                'left_lei' => round($future, 2),
            ];
        }

        usort($summary, fn (array $a, array $b) => $b['applied_lei'] <=> $a['applied_lei'] ?: $b['advance_lei'] + $b['unmatched_lei'] <=> $a['advance_lei'] + $a['unmatched_lei']);
        $appliedTotal = array_sum(array_column($summary, 'applied_lei'));
        $withCredit = count($summary);
        // The report keeps the suppliers the forecast was changed for, and the larger unused credits.
        $summary = array_slice(array_values(array_filter($summary, fn (array $row) => $row['applied_lei'] > 0 || $row['left_lei'] >= 10000)), 0, 200);
        $byLine = [];

        foreach ($summary as $row) {
            foreach ($row['applied'] as $line => $lei) {
                $byLine[$line] = ($byLine[$line] ?? 0.0) + $lei;
            }
        }

        ksort($byLine);

        return [
            'suppliers' => $suppliers,
            'charter' => $charter,
            'payables' => $payables,
            'summary' => $summary,
            '_rows' => count($summary),
            '_message' => sprintf(
                '%d furnizori cu plăți nealocate pe facturi sau avans pe 409; %s RON scăzuți din prognoză%s, ca să nu fie plătiți de două ori.',
                $withCredit,
                number_format($appliedTotal, 0, ',', '.'),
                $byLine !== [] ? ' ('.implode(', ', array_map(fn (string $line, float $lei) => $line.' '.number_format($lei, 0, ',', '.'), array_keys($byLine), $byLine)).')' : '',
            ),
        ];
    }

    /**
     * eTrip supplier (connection|code) → the OMC partner it is surely the
     * same company as: linked by hand, or linked with a name that agrees.
     * A VAT number shared by two companies in eTrip must not move an
     * advance to the wrong one.
     *
     * @return array<string, string> normalised partner name by supplier
     */
    private function etripPartners(): array
    {
        $first = fn (string $name) => strtok(ActualCashFlowClassifier::normalize($name), ' ') ?: '';

        return EtripSupplier::query()
            ->whereNotNull('partner_id')
            ->with('partner:id,name')
            ->get(['id', 'etrip_connection', 'code', 'name', 'partner_id', 'match_source'])
            ->filter(fn (EtripSupplier $supplier) => $supplier->partner !== null
                && ($supplier->match_source === EtripSupplier::MATCH_MANUAL || $first($supplier->name) === $first($supplier->partner->name)))
            ->mapWithKeys(fn (EtripSupplier $supplier) => [$supplier->etrip_connection.'|'.$supplier->code => ActualCashFlowClassifier::normalize($supplier->partner->name)])
            ->all();
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
        $accounts = [];
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
                                $accounts[$category['key']][(string) $account] = round((float) $monthly, 2);
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

        foreach (array_values($catalogue) as $index => $category) {
            $lines[$category['key']] = $this->scheduleOpex((float) $category['monthly'], $category['rule']);
            $origin = $category['override'] !== null ? 'parametri' : ($category['computed'] !== null ? 'omc' : 'implicit');

            foreach ($lines[$category['key']] as $i => $lei) {
                $this->recorder->record('D'.($index + 1), $this->grid->monday($i)->toDateString(), 'opex', $category['label'], $lei, [
                    'group' => $this->opexNote($category),
                    'currency' => 'RON',
                    'amount' => (float) $category['monthly'],
                    'meta' => [
                        'origin' => $origin,
                        'rule' => $category['rule'],
                        'basis' => $category['basis'] ?? 'invoices',
                        'accounts' => $origin === 'omc' ? ($accounts[$category['key']] ?? []) : [],
                        'window' => $from->format('m.Y').' – '.$end->subDay()->format('m.Y'),
                    ],
                ]);
            }
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
                $line = 'B11.'.(array_search($segment, array_keys(self::SEGMENT_LINES), true) + 1);
                $scenarioMeta = ['connection' => $connection, 'ly_week' => $row['week'], 'factor' => $factor, 'created_from' => $from->toDateString(), 'created_to' => $to->subDay()->toDateString()];

                if ($this->put($receipts[$segment], $line, CarbonImmutable::parse($row['week'])->addWeeks(52), $lei, 'new_receipts', 'Încasări '.BookingSegments::LABELS[$segment].' – an anterior', [
                    'group' => 'Săptămâna '.CarbonImmutable::parse($row['week'])->format('d.m.Y'),
                    'reference' => ($row['receipts'] ?? 0).' încasări',
                    'currency' => $row['currency'],
                    'amount' => $row['amount'] * $factor,
                    'meta' => [...$scenarioMeta, 'segment_type' => $row['segment_type'] ?? null, 'receipts' => (int) ($row['receipts'] ?? 0), 'ly_amount' => round((float) $row['amount'], 2)],
                ])) {
                    $key = "{$segment}|{$row['currency']}";
                    $receiptsStructure[$key] ??= ['segment' => $segment, 'label' => BookingSegments::LABELS[$segment], 'currency' => $row['currency'], 'amount' => 0.0, 'lei' => 0.0, 'receipts' => 0];
                    $receiptsStructure[$key]['amount'] = round($receiptsStructure[$key]['amount'] + $row['amount'] * $factor, 2);
                    $receiptsStructure[$key]['lei'] = round($receiptsStructure[$key]['lei'] + $lei, 2);
                    $receiptsStructure[$key]['receipts'] += (int) ($row['receipts'] ?? 0);
                }
            }

            foreach ($curve['costs'] as $row) {
                $lei = $this->lei($row['cost'], $row['currency']) * $factor;

                if ($this->put($costs, 'C11', CarbonImmutable::parse($row['week'])->addWeeks(52), $lei, 'new_costs', (self::PAYABLE_LABELS[$row['category']] ?? $row['category']).' – an anterior', [
                    'group' => 'Săptămâna '.CarbonImmutable::parse($row['week'])->format('d.m.Y'),
                    'reference' => ($row['items'] ?? 0).' servicii',
                    'currency' => $row['currency'],
                    'amount' => $row['cost'] * $factor,
                    'meta' => ['connection' => $connection, 'ly_week' => $row['week'], 'factor' => $factor, 'category' => $row['category'], 'items' => (int) ($row['items'] ?? 0), 'ly_amount' => round((float) $row['cost'], 2), 'days_before' => $daysBefore],
                ])) {
                    $this->collect($structure, $row['category'], $row['currency'], $row['cost'] * $factor, $lei, (int) ($row['items'] ?? 0));
                }
            }

            foreach ($curve['tickets'] as $row) {
                $lei = $this->lei($row['cost'], $row['currency']) * $factor;

                if ($this->put($costs, 'C11', CarbonImmutable::parse($row['week'])->addWeeks(52), $lei, 'new_costs', 'Bilete avion – an anterior', [
                    'group' => 'Săptămâna '.CarbonImmutable::parse($row['week'])->format('d.m.Y'),
                    'reference' => ($row['items'] ?? 0).' bilete',
                    'currency' => $row['currency'],
                    'amount' => $row['cost'] * $factor,
                    'meta' => ['connection' => $connection, 'ly_week' => $row['week'], 'factor' => $factor, 'category' => 'flight', 'items' => (int) ($row['items'] ?? 0), 'ly_amount' => round((float) $row['cost'], 2)],
                ])) {
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
        // Two years back: last year's weeks for the forecast, and the year
        // before for the past weeks the report shows as actuals.
        $from = $this->grid->lastYearMonday(0)->subWeeks(self::HISTORY_WEEKS)->subMonth()->startOfMonth();
        // Up to the end of yesterday: the position the forecast starts from.
        $flows = $this->omc->dailyFlows($from, $this->today);
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

        $positionDay = ($this->positionAsOf ?? $this->today->subDay())->toDateString();

        if ($this->anchor !== null && ! isset($anchors[$this->anchor->toDateString()])) {
            // No month-end table row for the anchor month: use the position of yesterday as the last anchor.
            $anchors[$positionDay] = $openingTotal;
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
            // Parsed from the date alone, like the cursor: the grid's Mondays
            // carry the report's timezone and would end the walk a week early.
            $limit = CarbonImmutable::parse($next ?? $monday->toDateString());

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

        $history = $this->history($weekly, $balanceAt, $openingTotal);

        // The same week a year before each full past week, for the year-on-year
        // comparison of the actuals.
        $historyLastYear = array_map(function (array $row) use ($weekly, $balanceAt) {
            if ($row['partial']) {
                return null;
            }

            $monday = CarbonImmutable::parse($row['week'])->subWeeks(52);
            $week = $weekly[$monday->toDateString()] ?? ['in' => 0.0, 'out' => 0.0];
            $closing = $balanceAt($monday);

            return [
                'ly_week' => $monday->toDateString(),
                'ly_in' => round((float) $week['in'], 2),
                'ly_out' => round((float) $week['out'], 2),
                'ly_bal' => $closing !== null ? round($closing, 2) : null,
            ];
        }, $history);

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
            'history' => $history,
            'history_lastyear' => $historyLastYear,
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
     * The actual cash flow of the past weeks as OMC recorded it, oldest
     * first, the current week last (partial: up to yesterday). Receipts and
     * payments are split by class, internal moves left out. The closing
     * balance is the position walked from the closed month-ends; whatever
     * the documents do not explain (FX revaluation, interest, timing) shows
     * as the adjustment, so opening + net + adjustment = closing.
     *
     * @param  array<string, array<string, float>>  $weekly
     * @param  callable(CarbonImmutable): ?float  $balanceAt
     * @return list<array{week: string, partial: bool, opening: ?float, in_partner: float, in_other: float, in: float, out_partner: float, out_salaries: float, out_other: float, out: float, net: float, adjustment: ?float, closing: ?float}>
     */
    private function history(array $weekly, callable $balanceAt, float $currentPosition): array
    {
        $rows = [];
        $opening = $balanceAt($this->grid->start->subWeeks(self::HISTORY_WEEKS + 1));
        $opening = $opening !== null ? round($opening, 2) : null;

        for ($i = self::HISTORY_WEEKS; $i >= 0; $i--) {
            $monday = $this->grid->start->subWeeks($i);
            $week = $weekly[$monday->toDateString()] ?? [];
            $in = (float) ($week['in'] ?? 0.0);
            $inOther = (float) ($week['in_other'] ?? 0.0);
            $out = (float) ($week['out'] ?? 0.0);
            $net = round($in, 2) - round($out, 2);
            // The current week closes on today's position, the end of yesterday.
            $closing = $i === 0 ? $currentPosition : $balanceAt($monday);
            $closing = $closing !== null ? round($closing, 2) : null;

            $rows[] = [
                'week' => $monday->toDateString(),
                'partial' => $i === 0,
                'opening' => $opening !== null ? round($opening, 2) : null,
                'in_partner' => round($in - $inOther, 2),
                'in_other' => round($inOther, 2),
                'in' => round($in, 2),
                'out_partner' => round((float) ($week['out_partner'] ?? 0.0), 2),
                'out_salaries' => round((float) ($week['out_salaries'] ?? 0.0), 2),
                'out_other' => round((float) ($week['out_other'] ?? 0.0), 2),
                'out' => round($out, 2),
                'net' => round($net, 2),
                'adjustment' => $opening !== null && $closing !== null ? round($closing - $opening - $net, 2) : null,
                'closing' => $closing !== null ? round($closing, 2) : null,
            ];

            $opening = $closing;
        }

        return $rows;
    }

    /**
     * The past weeks on the report's own lines: the classified actual flows,
     * their totals, and the balance chain of the history (opening, the
     * adjustment to OMC's month-end positions, closing). Only lines with
     * something in them are kept; the page reads a missing one as zero.
     *
     * @param  list<array<string, mixed>>  $history
     * @param  array<string, list<float>>  $classified  by line code or OPEX key
     * @param  list<array{ly_week: string, ly_in: float, ly_out: float, ly_bal: ?float}|null>  $lastYear
     * @return array{weeks: list<string>, lastyear: list<array<string, mixed>|null>, lines: array<string, list<float|string>>}|null
     */
    private function past(array $history, array $classified, float $minimum, float $comfort, array $lastYear = [], array $details = []): ?array
    {
        if ($history === []) {
            return null;
        }

        $count = count($history);
        $zeros = array_fill(0, $count, 0.0);
        $lines = [];
        $totals = ['B' => $zeros, 'C' => $zeros, 'D' => $zeros];
        $this->recordPast($details);

        foreach ($this->lines as $line) {
            if ($line['kind'] !== 'value' || ! isset($totals[$line['section']])) {
                continue;
            }

            $values = $classified[$line['section'] === 'D' && $line['key'] ? $line['key'] : $line['code']] ?? null;

            if ($values === null || count($values) !== $count) {
                continue;
            }

            $lines[$line['code']] = $values;

            foreach ($values as $i => $value) {
                $totals[$line['section']][$i] += $value;
            }
        }

        $round = fn (array $values) => array_map(fn (float $v) => round($v, 2), $values);
        $closing = array_map(fn (array $week) => $week['closing'] !== null ? (float) $week['closing'] : null, $history);
        $net = $round(array_map(fn (float $b, float $c, float $d) => $b - $c - $d, $totals['B'], $totals['C'], $totals['D']));
        // From the line totals rather than the history's own net: the two OMC
        // reads round at different groupings, and the chain must add up here.
        $adjustment = array_map(fn (array $week, float $flow) => $week['opening'] !== null && $week['closing'] !== null
            ? round((float) $week['closing'] - (float) $week['opening'] - $flow, 2)
            : null, $history, $net);

        return [
            'weeks' => array_column($history, 'week'),
            // Per past week: the same week a year before (null for the current, partial one).
            'lastyear' => array_values($lastYear),
            'lines' => [
                ...$lines,
                'A' => array_map(fn (array $week) => $week['opening'], $history),
                'B' => $round($totals['B']),
                'C' => $round($totals['C']),
                'D' => $round($totals['D']),
                'E1' => $net,
                'EA' => $adjustment,
                'E2' => $closing,
                'E3' => array_fill(0, $count, $minimum),
                'E4' => array_map(fn (?float $v) => $v !== null ? round($v - $minimum, 2) : null, $closing),
                'E5' => array_map(fn (?float $v) => $v === null ? '' : ($v < $minimum ? 'DEFICIT' : ($v < $comfort ? 'ATENȚIE' : 'OK')), $closing),
                'E6' => array_fill(0, $count, 'efectiv'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $opening
     * @param  array<string, mixed>  $receivables
     * @param  array<string, mixed>  $payables
     * @param  array<string, mixed>  $charter
     * @param  array<string, mixed>  $suppliers
     * @param  array<string, mixed>  $opex
     * @param  array<string, mixed>  $scenario
     * @param  array<string, mixed>  $actuals
     * @param  array{lines: array<string, list<float>>, classified: array<string, float>}  $classified
     * @return array<string, mixed>
     */
    private function assemble(array $opening, array $receivables, array $payables, array $charter, array $suppliers, array $opex, array $scenario, array $actuals, array $classified): array
    {
        $weeks = $this->grid->weeks;
        $scenarioOn = (bool) ($this->params['scenario']['enabled'] ?? true);

        $this->line('A', 'Sold inițial de trezorerie (bănci + casierii + depozite)', 'A', array_fill(0, $weeks, 0.0), kind: 'balance', note: 'S+1: poziția de trezorerie din OMC la sfârșitul zilei de ieri; apoi soldul final al săptămânii anterioare');

        $codes = self::SEGMENT_LINES;

        foreach ($codes as $segment => $code) {
            $this->line($code, 'Încasări '.BookingSegments::LABELS[$segment].' – avansuri și solduri conform scadențarului eTrip', 'B', $receivables['lines'][$segment] ?? $this->grid->zeros(), note: 'eTrip: dosare confirmate, scadențe viitoare minus încasat');
        }

        $recovery = $this->recovery($receivables['overdue_recent'] ?? [], (float) ($this->params['overdue']['recent_pct'] ?? 0), (int) ($this->params['overdue']['recent_weeks'] ?? 4));
        $recoveryOld = $this->recovery($receivables['overdue_old'] ?? [], (float) ($this->params['overdue']['old_pct'] ?? 0), (int) ($this->params['overdue']['recent_weeks'] ?? 4));
        $this->recorder->source('receivables');
        $this->recordRecovery('B8', $receivables['overdue_rows']['recent'] ?? [], (float) ($this->params['overdue']['recent_pct'] ?? 0), (int) ($this->params['overdue']['recent_weeks'] ?? 4));
        $this->recordRecovery('B9', $receivables['overdue_rows']['old'] ?? [], (float) ($this->params['overdue']['old_pct'] ?? 0), (int) ($this->params['overdue']['recent_weeks'] ?? 4));
        $this->line('B8', sprintf('Recuperare solduri restante ≤ %d zile (scadență depășită)', (int) ($this->params['overdue']['recent_days'] ?? 60)), 'B', $recovery, note: sprintf('%s%% din restanțe, egal pe %d săptămâni', $this->params['overdue']['recent_pct'] ?? 0, $this->params['overdue']['recent_weeks'] ?? 4));
        $this->line('B9', sprintf('Recuperare solduri restante > %d zile', (int) ($this->params['overdue']['recent_days'] ?? 60)), 'B', $recoveryOld, note: sprintf('%s%% din restanțele vechi', $this->params['overdue']['old_pct'] ?? 0));
        $this->line('B10', 'Încasări din vânzarea de locuri charter (contracte hard block)', 'B', $charter['incoming'], note: 'contracte charter în care CHR vinde locuri; rotația, taxele și depozitul pe termenii contractului');
        $this->line('BX', 'Alte încasări (fără încasare eTrip pe dosar) – doar efectiv', 'B', $this->grid->zeros(), note: 'în trecut: încasările OMC peste cele din eTrip și din charter (nealocate pe dosar, decalaje de înregistrare); în prognoză nu se estimează');

        $newSales = [];

        foreach (array_keys($codes) as $index => $segment) {
            $code = 'B11.'.($index + 1);
            $newSales[] = $code;
            $this->line($code, 'Vânzări noi – '.BookingSegments::LABELS[$segment], 'B', $scenario['receipts'][$segment] ?? $this->grid->zeros(), scenario: true, note: 'eTrip: încasările dosarelor din acest segment create în aceeași săptămână a anului anterior, × factor', parent: 'B11');
        }

        $this->line('B11', 'Încasări din vânzări noi – total (scenariu: curba anului anterior × factor)', 'B', $this->sum($newSales), kind: 'subtotal', scenario: true, note: 'suma liniilor B11.1–B11.7; intră în total doar cu scenariul pornit');
        $this->line('B', 'TOTAL ÎNCASĂRI OPERAȚIONALE', 'B', $this->sum(['B1', 'B2', 'B3', 'B4', 'B5', 'B6', 'B7', 'B8', 'B9', 'B10'], $scenarioOn ? $newSales : []), kind: 'total');

        $this->line('C1', 'Plăți cazare (hoteluri) – rezervări existente', 'C', $payables['lines']['hotel'], note: 'eTrip: cost furnizor net, plată cu N zile înainte de check-in');
        $this->line('C2', 'Plăți transferuri, excursii, autocar, servicii la sol', 'C', $payables['lines']['transfer']);
        $this->line('C3', 'Plăți asigurări', 'C', $payables['lines']['insurance']);
        $this->line('C4', sprintf('Plăți bilete avion linie (comenzi din ultimele %d zile)', (int) ($this->params['payables']['ticket_days'] ?? 7)), 'C', $payables['lines']['flight']);
        $this->line('C5', 'Alte costuri directe de produs', 'C', $payables['lines']['other']);
        $this->line('C6', 'Charter – rotații contracte semnate', 'C', $charter['signed'], note: 'fiecare rotație pe termenul contractului ei (CTR 317: OP cu 10 zile înainte de operare)');
        $this->line('C7', 'Charter – rotații contracte draft', 'C', $charter['draft'], note: 'rotațiile contractelor nesemnate încă, integral; depozitul se regularizează la ultimele rotații');
        $this->line('C8', 'Charter – depozite contracte', 'C', $charter['deposit'], note: 'depozitul fiecărui contract neachitat, la scadența lui');
        $this->line('C9', 'Charter – taxe aeroport', 'C', $charter['taxes'], note: 'pe regula fiecărui contract: reconciliere lunară în prima săptămână a lunii următoare, la N zile după zbor sau în avans; taxele plătite odată cu rotația sunt deja în C6/C7');
        $this->line('C10', 'Furnizori – sold neachitat la data raportului (facturi scadente)', 'C', $suppliers['line'], note: $suppliers['mode'] === 'manual' ? 'parametri' : 'OMC: facturi furnizor deschise, pe scadență');
        $this->line('CX', 'Alte plăți (restituiri clienți, avansuri, OP-uri încă necompletate) – doar efectiv', 'C', $this->grid->zeros(), note: 'în trecut: plățile OMC care nu se potrivesc pe nicio altă linie; în prognoză nu se estimează');
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
        $this->line('EA', 'Ajustări sold (curs valutar, dobânzi, finanțare) – doar efectiv', 'E', $this->grid->zeros(), kind: 'total', note: 'în trecut: diferența până la soldurile OMC; sold inițial + flux net + ajustări = sold final');
        $this->line('E2', 'SOLD FINAL DE TREZORERIE', 'E', $closing, kind: 'balance');
        $this->line('E3', 'Prag minim de siguranță', 'E', array_fill(0, $weeks, $minimum), kind: 'threshold');
        $this->line('E4', 'Marja peste pragul minim (deficit dacă este negativ)', 'E', array_map(fn (float $v) => round($v - $minimum, 2), $closing), kind: 'total');
        $this->line('E5', 'Semnal', 'E', array_map(fn (float $v) => $v < $minimum ? 'DEFICIT' : ($v < $comfort ? 'ATENȚIE' : 'OK'), $closing), kind: 'text');

        $existing = $this->sum(['B1', 'B2', 'B3', 'B4', 'B5', 'B6', 'B7', 'B10', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'C8', 'C9']);
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
            'scenario_receipts' => round(array_sum($this->values('B11')), 2),
            'charter_incoming' => round(array_sum($this->values('B10')), 2),
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
            'past' => $this->past($actuals['history'] ?? [], $classified['lines'] ?? [], $minimum, $comfort, $actuals['history_lastyear'] ?? [], $classified['details'] ?? []),
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
     * The pieces of the past weeks, on the report's line codes (the
     * classifier names OPEX lines by their category key).
     *
     * @param  list<array{line: string, week: string, kind: string, label: string, lei: float, detail: array<string, mixed>}>  $details
     */
    private function recordPast(array $details): void
    {
        $codes = [];

        foreach ($this->lines as $line) {
            $codes[$line['section'] === 'D' && $line['key'] ? $line['key'] : $line['code']] = $line['code'];
        }

        $this->recorder->source('actual_lines', actual: true);

        foreach ($details as $piece) {
            $code = $codes[$piece['line']] ?? null;

            if ($code !== null) {
                $this->recorder->record($code, $piece['week'], $piece['kind'], $piece['label'], $piece['lei'], $piece['detail']);
            }
        }
    }

    /**
     * The overdue tranches behind B8 / B9: each weighs its share of the
     * recovery (pct of it, evenly over the weeks) in every one of them.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function recordRecovery(string $line, array $rows, float $pct, int $weeks): void
    {
        $weeks = max(1, min($weeks, $this->grid->weeks));
        $share = max(0.0, min(100.0, $pct)) / 100 / $weeks;

        if ($share <= 0) {
            return;
        }

        foreach ($rows as $row) {
            for ($i = 0; $i < $weeks; $i++) {
                $this->recorder->record($line, $this->grid->monday($i)->toDateString(), 'overdue', $this->bookingLabel($row), $row['lei'] * $share, [
                    'group' => $row['type'],
                    'reference' => $row['booking'],
                    'date' => $row['due'],
                    'currency' => $row['currency'],
                    'amount' => $row['amount'],
                    'meta' => ['connection' => $row['connection'], 'segment' => $row['segment'], 'channel' => $row['channel'], 'days_overdue' => $row['days'], 'share' => round($share, 6), 'pct' => $pct, 'weeks' => $weeks],
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $piece
     */
    private function bookingLabel(array $piece): string
    {
        return (string) ($piece['client'] ?? '') !== '' ? (string) $piece['client'] : 'Dosar '.$piece['booking'];
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
     * A calendar day from a source, in the report's timezone like today, so
     * the days between them are whole.
     */
    private function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse(substr($date, 0, 10), $this->today->getTimezone())->startOfDay();
    }

    /**
     * Add an amount to the week of a date on a line, as WeekGrid::add()
     * does, and keep what it is so the cell can be opened.
     *
     * @param  list<float>  $series
     * @param  array{group?: ?string, reference?: string|int|null, date?: ?string, currency?: ?string, amount?: ?float, meta?: array<string, mixed>}  $detail
     */
    private function put(array &$series, string $line, CarbonInterface|string|null $date, float $lei, string $kind, string $label, array $detail = [], bool $carryEarly = false): bool
    {
        $index = $this->grid->column($date, $carryEarly);

        if ($index === null) {
            return false;
        }

        $series[$index] += $lei;
        $this->recorder->record($line, $this->grid->monday($index)->toDateString(), $kind, $label, $lei, $detail);

        return true;
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

    /**
     * A treasury amount in lei, at the BNR rate OMC holds for the day the
     * position is stated at; the report's own rates cover what the day has
     * no quote for.
     */
    private function positionLei(float $amount, string $currency): float
    {
        $currency = OmcCashFlowReader::currency($currency);

        return $amount * (float) ($this->positionRates[$currency] ?? $this->fx[$currency] ?? 1.0);
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
