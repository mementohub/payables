<?php

namespace App\Services\Etrip;

use App\Models\EtripSupplier;
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Verificare plăți → Facturi vs. servicii: a supplier's invoices in eTrip
 * against the services it actually delivered (check-in done), per check-in
 * month and per product type, never adding up across currencies.
 *
 * The invoice lines point at the services they bill, so they, not the
 * invoice totals, say how much of each service was billed.
 */
class SupplierReconciliationService
{
    /** A closed month is flagged above this share of its cost. */
    public const MONTH_TOLERANCE = 0.02;

    /** A product type is flagged below this share billed… */
    public const TYPE_MIN_BILLED = 0.5;

    /** …over this many closed months… */
    public const TYPE_WINDOW_MONTHS = 3;

    /** …when it weighs at least this much of the supplier's cost. */
    public const TYPE_MIN_SHARE = 0.01;

    public const MAX_INVOICES = 500;

    private const CORRECTION_PATTERN = '/correct|inv-?diff|-dif\b|\.c$|storn/i';

    public function __construct(private EtripReader $reader) {}

    /**
     * @return array<string, mixed>
     */
    public function reconcile(string $connection, EtripSupplier $supplier, Carbon $from, Carbon $to): array
    {
        $today = Carbon::today();
        $profile = $this->reader->supplierProfile($connection, $supplier->code) ?? [];
        $labels = $this->reader->productTypes($connection);
        $coverage = collect($this->reader->serviceCoverage($connection, $supplier->code, $from, $to, $today))
            ->map(fn (array $row) => [
                'month' => (string) $row['month'],
                'product_type' => (int) $row['product_type'],
                'label' => $labels[(int) $row['product_type']] ?? (string) $row['product_type'],
                'currency' => strtoupper((string) $row['currency']),
                'done' => (bool) $row['done'],
                'source' => $this->text($row['source'] ?? null),
                'services' => (int) $row['services'],
                'cost' => (float) $row['cost'],
                'invoiced' => (float) $row['invoiced'],
                'unbilled_services' => (int) $row['unbilled_services'],
                'unbilled_cost' => (float) $row['unbilled_cost'],
            ]);
        $done = $coverage->where('done', true);
        $term = isset($profile['balance_due_days']) ? (int) $profile['balance_due_days'] : 0;
        $invoices = collect($this->reader->supplierInvoices($connection, $supplier->code, $from, $to))
            ->map(fn (array $row) => $this->invoice($row, $today, $term));
        $payments = collect($this->reader->supplierPayments($connection, $supplier->code, $from, $to));
        $secondaryOmc = (bool) ($profile['use_secondary_omc_connection'] ?? false);

        // Suppliers booked in the main OMC base are paid there, not in eTrip.
        $paymentsSource = $secondaryOmc ? 'etrip' : 'omc';

        if ($paymentsSource === 'omc') {
            $invoices = $this->withOmcStatus($invoices, $supplier, $from, $to, $today);
        }

        $months = $this->months($done, $today);
        $types = $this->types($done, $today);

        return [
            'supplier' => $this->profile($supplier, $profile, $coverage),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'today' => $today->toDateString(),
            'kpis' => $this->kpis($done, $coverage->where('done', false), $invoices),
            'months' => $months,
            'types' => $types,
            'future' => $this->future($coverage->where('done', false)),
            'invoices' => $invoices->take(self::MAX_INVOICES)->values()->all(),
            'invoices_total' => $invoices->count(),
            'invoice_totals' => $this->invoiceTotals($invoices),
            'payments_source' => $paymentsSource,
            'payments' => $this->payments($payments, $invoices),
            'omc' => $secondaryOmc ? null : $this->omc($supplier, $from, $to),
            'alerts' => $this->alerts($months, $types, $invoices, $paymentsSource, $supplier),
            'thresholds' => [
                'month_pct' => self::MONTH_TOLERANCE * 100,
                'type_min_billed_pct' => self::TYPE_MIN_BILLED * 100,
                'type_window_months' => self::TYPE_WINDOW_MONTHS,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  Collection<int, array<string, mixed>>  $coverage
     * @return array<string, mixed>
     */
    private function profile(EtripSupplier $supplier, array $profile, Collection $coverage): array
    {
        $sources = $coverage
            ->groupBy(fn (array $row) => $row['source'] ?? '')
            ->map(fn (Collection $group, string $source) => [
                'source' => $source === '' ? null : $source,
                'services' => $group->sum('services'),
            ])
            ->sortByDesc('services')
            ->values();

        return [
            'id' => $supplier->id,
            'code' => $supplier->code,
            'name' => (string) ($profile['name'] ?? $supplier->name),
            'country' => $profile['country'] ?? $supplier->country,
            'currency' => $profile['currency'] ?? $supplier->currency,
            'active' => (bool) ($profile['active'] ?? $supplier->is_active),
            'balance_due' => $profile['balance_due'] ?? null,
            'balance_due_days' => isset($profile['balance_due_days']) ? (int) $profile['balance_due_days'] : null,
            'self_billing' => (bool) ($profile['self_billing'] ?? false),
            'vat_no' => $profile['vat_no'] ?? $supplier->vat_no,
            'web_access' => (bool) ($profile['web_access'] ?? false),
            'secondary_omc' => (bool) ($profile['use_secondary_omc_connection'] ?? false),
            'created_at' => $profile['created_at'] ?? null,
            'partner_id' => $supplier->partner_id,
            'sources' => $sources->all(),
            // No booking source on any service: contract / manual, no API.
            'manual' => $sources->isNotEmpty() && $sources->every(fn (array $row) => $row['source'] === null),
        ];
    }

    /**
     * An invoice's due date is the one entered in eTrip when it is valid and
     * later than the invoice date; otherwise the supplier's payment term
     * (balance_due) counted from the invoice date, when it has one.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function invoice(array $row, Carbon $today, int $term): array
    {
        $amount = round((float) $row['amount'], 2);
        $paid = round((float) $row['paid'], 2);
        $open = round($amount - $paid, 2);
        $date = Carbon::parse((string) $row['date']);
        $due = $this->text($row['due_date'] ?? null);
        $dueDate = $due !== null ? Carbon::parse($due) : null;
        // eTrip holds dates such as 0206-07-25 for 2026-07-25.
        $invalidDue = $dueDate !== null && ($dueDate->year < 2000 || $dueDate->lt($date) || $dueDate->gt($date->copy()->addYears(2)));
        $number = trim((string) $row['number']);
        [$effectiveDue, $dueSource] = match (true) {
            $dueDate !== null && ! $invalidDue && $dueDate->gt($date) => [$dueDate, 'invoice'],
            $term > 0 => [$date->copy()->addDays($term), 'term'],
            $dueDate !== null && ! $invalidDue => [$dueDate, 'invoice'],
            default => [null, null],
        };

        return [
            'id' => (int) $row['id'],
            'number' => $number,
            'date' => $date->toDateString(),
            'currency' => strtoupper((string) $row['currency']),
            'amount' => $amount,
            'paid' => $paid,
            'open' => $open,
            'due_date' => $due,
            'due' => $effectiveDue?->toDateString(),
            'due_source' => $dueSource,
            'days_overdue' => $open > 0.01 && $effectiveDue?->lt($today) ? (int) $effectiveDue->diffInDays($today) : 0,
            'finalized' => (bool) $row['finalized'],
            'good_for_payment' => (bool) $row['good_for_payment'],
            'lines' => (int) $row['lines'],
            'bookings' => (int) $row['bookings'],
            'checkin_from' => $row['checkin_from'] ?? null,
            'checkin_to' => $row['checkin_to'] ?? null,
            'etrip_cost' => $row['etrip_cost'] !== null ? round((float) $row['etrip_cost'], 2) : null,
            'correction' => $amount < 0 || preg_match(self::CORRECTION_PATTERN, $number) === 1,
            'invalid_due' => $invalidDue,
            'omc' => null,
        ];
    }

    /**
     * Paid, open and due as the OMC mirror has them, matching each eTrip
     * invoice to the partner's OMC invoice with the same number (spaces and
     * case aside), the closest in date when the number repeats. An invoice
     * OMC does not have is left without a payment status.
     *
     * @param  Collection<int, array<string, mixed>>  $invoices
     * @return Collection<int, array<string, mixed>>
     */
    private function withOmcStatus(Collection $invoices, EtripSupplier $supplier, Carbon $from, Carbon $to, Carbon $today): Collection
    {
        $unknown = fn (array $invoice) => [...$invoice, 'paid' => null, 'open' => null, 'days_overdue' => 0, 'due' => null, 'due_source' => null];

        if ($supplier->partner_id === null) {
            return $invoices->map($unknown);
        }

        $mirror = Invoice::query()
            ->where('partner_id', $supplier->partner_id)
            ->whereNull('omc_removed_at')
            ->whereBetween('data_doc', [$from->copy()->subDays(90)->toDateString(), $to->copy()->addDays(90)->toDateString()])
            ->get(['id', 'nr_doc', 'data_doc', 'data_scadenta', 'val_mon', 'val_mon_paid', 'val_mon_storno', 'moneda', 'approval_status'])
            ->groupBy(fn (Invoice $invoice) => $this->numberKey($invoice->nr_doc));

        return $invoices->map(function (array $invoice) use ($mirror, $unknown, $today) {
            $date = Carbon::parse($invoice['date']);
            $match = $mirror->get($this->numberKey($invoice['number']))
                ?->sortBy(fn (Invoice $candidate) => abs($candidate->data_doc->diffInDays($date)))
                ->first();

            if ($match === null) {
                return $unknown($invoice);
            }

            $open = round(max(0, $match->outstandingAmount()), 2);
            $due = Carbon::parse(($match->data_scadenta ?? $match->data_doc)->toDateString());

            return [
                ...$invoice,
                'paid' => round($invoice['amount'] - $open, 2),
                'open' => $open,
                'due' => $due->toDateString(),
                'due_source' => 'omc',
                'days_overdue' => $open > 0.01 && $due->lt($today) ? (int) $due->diffInDays($today) : 0,
                'omc' => [
                    'id' => $match->id,
                    'nr_doc' => $match->nr_doc,
                    'amount' => round((float) $match->val_mon, 2),
                    'approval_status' => $match->approval_status,
                ],
            ];
        });
    }

    private function numberKey(?string $number): string
    {
        return (string) preg_replace('/\s+/u', '', mb_strtolower(trim((string) $number)));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $done
     * @param  Collection<int, array<string, mixed>>  $future
     * @param  Collection<int, array<string, mixed>>  $invoices
     * @return list<array<string, mixed>>
     */
    private function kpis(Collection $done, Collection $future, Collection $invoices): array
    {
        $currencies = $done->pluck('currency')
            ->merge($future->pluck('currency'))
            ->merge($invoices->pluck('currency'))
            ->unique()
            ->sort()
            ->values();

        return $currencies->map(function (string $currency) use ($done, $future, $invoices) {
            $services = $done->where('currency', $currency);
            $issued = $invoices->where('currency', $currency);
            $cost = round((float) $services->sum('cost'), 2);
            $billed = round((float) $services->sum('invoiced'), 2);

            return [
                'currency' => $currency,
                'services' => (int) $services->sum('services'),
                'cost' => $cost,
                'billed' => $billed,
                'diff' => round($billed - $cost, 2),
                'diff_pct' => $this->pct($billed - $cost, $cost),
                'unbilled_cost' => round((float) $services->sum('unbilled_cost'), 2),
                'invoiced' => round((float) $issued->sum('amount'), 2),
                'invoices' => $issued->count(),
                'open' => round((float) $issued->sum('open'), 2),
                'open_unknown' => $issued->whereNull('open')->count(),
                'overdue' => round((float) $issued->where('days_overdue', '>', 0)->sum('open'), 2),
                'future_cost' => round((float) $future->where('currency', $currency)->sum('cost'), 2),
                'future_services' => (int) $future->where('currency', $currency)->sum('services'),
            ];
        })->all();
    }

    /**
     * Cost against billed per check-in month; the current month is still
     * being billed (DMCs bill in arrears), so a gap there is expected.
     *
     * @param  Collection<int, array<string, mixed>>  $done
     * @return list<array<string, mixed>>
     */
    private function months(Collection $done, Carbon $today): array
    {
        $current = $today->format('Y-m');

        return $done
            ->groupBy(fn (array $row) => $row['month'].'|'.$row['currency'])
            ->map(function (Collection $group) use ($current) {
                $first = $group->first();
                $cost = round((float) $group->sum('cost'), 2);
                $billed = round((float) $group->sum('invoiced'), 2);
                $closed = $first['month'] < $current;
                $pct = $this->pct($billed - $cost, $cost);

                return [
                    'month' => $first['month'],
                    'currency' => $first['currency'],
                    'services' => (int) $group->sum('services'),
                    'cost' => $cost,
                    'billed' => $billed,
                    'diff' => round($billed - $cost, 2),
                    'diff_pct' => $pct,
                    'unbilled_services' => (int) $group->sum('unbilled_services'),
                    'unbilled_cost' => round((float) $group->sum('unbilled_cost'), 2),
                    'status' => $closed ? 'closed' : 'billing',
                    'alert' => $closed && abs($billed - $cost) > 0.01
                        && ($pct === null || abs($pct) > self::MONTH_TOLERANCE * 100),
                ];
            })
            ->sortBy(fn (array $row) => $row['month'].$row['currency'])
            ->values()
            ->all();
    }

    /**
     * Cost against billed per product type over the whole range; a type the
     * supplier barely bills over the last closed months is either included
     * in another one (and then counted twice in eTrip) or not billed yet.
     *
     * @param  Collection<int, array<string, mixed>>  $done
     * @return list<array<string, mixed>>
     */
    private function types(Collection $done, Carbon $today): array
    {
        $windowStart = $today->copy()->startOfMonth()->subMonths(self::TYPE_WINDOW_MONTHS)->format('Y-m');
        $current = $today->format('Y-m');
        $recent = $done->filter(fn (array $row) => $row['month'] >= $windowStart && $row['month'] < $current);
        $recentCost = $recent->groupBy('currency')->map(fn (Collection $group) => (float) $group->sum('cost'));

        return $done
            ->groupBy(fn (array $row) => $row['product_type'].'|'.$row['currency'])
            ->map(function (Collection $group) use ($recent, $recentCost) {
                $first = $group->first();
                $cost = round((float) $group->sum('cost'), 2);
                $billed = round((float) $group->sum('invoiced'), 2);
                $window = $recent->where('product_type', $first['product_type'])->where('currency', $first['currency']);
                $windowCost = (float) $window->sum('cost');
                $windowBilled = (float) $window->sum('invoiced');
                $share = ($recentCost[$first['currency']] ?? 0) > 0 ? $windowCost / $recentCost[$first['currency']] : 0;

                return [
                    'product_type' => $first['product_type'],
                    'label' => $first['label'],
                    'currency' => $first['currency'],
                    'services' => (int) $group->sum('services'),
                    'cost' => $cost,
                    'billed' => $billed,
                    'diff' => round($billed - $cost, 2),
                    'billed_pct' => $this->pct($billed, $cost),
                    'unbilled_services' => (int) $group->sum('unbilled_services'),
                    'unbilled_cost' => round((float) $group->sum('unbilled_cost'), 2),
                    'window_cost' => round($windowCost, 2),
                    'window_billed_pct' => $this->pct($windowBilled, $windowCost),
                    'alert' => $windowCost > 0 && $share >= self::TYPE_MIN_SHARE
                        && $windowBilled < $windowCost * self::TYPE_MIN_BILLED,
                ];
            })
            ->sortByDesc(fn (array $row) => abs($row['cost']))
            ->values()
            ->all();
    }

    /**
     * Cost committed for check-ins still to come, per month.
     *
     * @param  Collection<int, array<string, mixed>>  $future
     * @return list<array<string, mixed>>
     */
    private function future(Collection $future): array
    {
        return $future
            ->groupBy(fn (array $row) => $row['month'].'|'.$row['currency'])
            ->map(fn (Collection $group) => [
                'month' => $group->first()['month'],
                'currency' => $group->first()['currency'],
                'services' => (int) $group->sum('services'),
                'cost' => round((float) $group->sum('cost'), 2),
                'billed' => round((float) $group->sum('invoiced'), 2),
            ])
            ->sortBy(fn (array $row) => $row['month'].$row['currency'])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $invoices
     * @return list<array<string, mixed>>
     */
    private function invoiceTotals(Collection $invoices): array
    {
        return $invoices
            ->groupBy('currency')
            ->map(fn (Collection $group, string $currency) => [
                'currency' => $currency,
                'invoices' => $group->count(),
                'amount' => round((float) $group->sum('amount'), 2),
                'paid' => round((float) $group->sum('paid'), 2),
                'open' => round((float) $group->sum('open'), 2),
                'unpaid' => $group->where('open', '>', 0.01)->count(),
                'unknown' => $group->whereNull('open')->count(),
                'etrip_cost' => round((float) $group->sum('etrip_cost'), 2),
            ])
            ->sortKeys()
            ->values()
            ->all();
    }

    /**
     * Payments made in the range against what is allocated to the invoices
     * of the range: a payment of one year may settle invoices of another,
     * so the two differ without anything being wrong.
     *
     * @param  Collection<int, array<string, mixed>>  $payments
     * @param  Collection<int, array<string, mixed>>  $invoices
     * @return list<array<string, mixed>>
     */
    private function payments(Collection $payments, Collection $invoices): array
    {
        $allocated = $invoices->groupBy('currency')->map(fn (Collection $group) => round((float) $group->sum('paid'), 2));
        $currencies = $payments->pluck('currency')->map(fn ($value) => strtoupper((string) $value))
            ->merge($allocated->keys())
            ->unique()
            ->sort()
            ->values();
        $byCurrency = $payments->keyBy(fn (array $row) => strtoupper((string) $row['currency']));

        return $currencies->map(function (string $currency) use ($byCurrency, $allocated) {
            $row = $byCurrency->get($currency);
            $paid = round((float) ($row['total'] ?? 0), 2);
            $onInvoices = (float) ($allocated[$currency] ?? 0);

            return [
                'currency' => $currency,
                'payments' => (int) ($row['payments'] ?? 0),
                'paid' => $paid,
                'first' => $row['first'] ?? null,
                'last' => $row['last'] ?? null,
                'allocated' => $onInvoices,
                'difference' => round($onInvoices - $paid, 2),
            ];
        })->all();
    }

    /**
     * The same supplier's invoices in the local OMC mirror, when eTrip ties
     * it to an OMC partner.
     *
     * @return array<string, mixed>
     */
    private function omc(EtripSupplier $supplier, Carbon $from, Carbon $to): array
    {
        if ($supplier->partner_id === null) {
            return ['partner_id' => null, 'totals' => []];
        }

        $totals = Invoice::query()
            ->where('partner_id', $supplier->partner_id)
            ->whereNull('omc_removed_at')
            ->whereBetween('data_doc', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('moneda, count(*) as invoices, sum(val_mon) as amount, sum(case when val_mon > 0 and val_mon - val_mon_paid - val_mon_storno > 0.01 then val_mon - val_mon_paid - val_mon_storno else 0 end) as open')
            ->groupBy('moneda')
            ->orderBy('moneda')
            ->get()
            ->map(fn (Invoice $row) => [
                'currency' => in_array($row->moneda, ['Lei', 'RON', null], true) ? 'RON' : (string) $row->moneda,
                'invoices' => (int) $row->getAttribute('invoices'),
                'amount' => round((float) $row->getAttribute('amount'), 2),
                'open' => round((float) $row->getAttribute('open'), 2),
            ])
            ->all();

        return ['partner_id' => $supplier->partner_id, 'totals' => $totals];
    }

    /**
     * @param  list<array<string, mixed>>  $months
     * @param  list<array<string, mixed>>  $types
     * @param  Collection<int, array<string, mixed>>  $invoices
     * @return list<array{kind: string, level: string, count: int, detail: string}>
     */
    private function alerts(array $months, array $types, Collection $invoices, string $paymentsSource, EtripSupplier $supplier): array
    {
        $alerts = [];

        if ($paymentsSource === 'omc' && $supplier->partner_id === null) {
            $alerts[] = ['kind' => 'unlinked', 'level' => 'warn', 'count' => 1, 'detail' => 'Furnizorul eTrip nu este legat de un partener OMC, deci plățile facturilor nu se pot citi.'];
        } elseif ($paymentsSource === 'omc') {
            $missing = $invoices->whereNull('open');

            if ($missing->isNotEmpty()) {
                $alerts[] = ['kind' => 'omc_missing', 'level' => 'warn', 'count' => $missing->count(), 'detail' => $missing->pluck('number')->take(8)->implode(', ')];
            }
        }

        $monthAlerts = array_values(array_filter($months, fn (array $row) => $row['alert']));
        $typeAlerts = array_values(array_filter($types, fn (array $row) => $row['alert']));
        $overdue = $invoices->where('days_overdue', '>', 0);
        $draft = $invoices->where('finalized', false);
        $invalid = $invoices->where('invalid_due', true);

        if ($typeAlerts !== []) {
            $alerts[] = ['kind' => 'types', 'level' => 'crit', 'count' => count($typeAlerts), 'detail' => collect($typeAlerts)
                ->map(fn (array $row) => sprintf('%s (%s %%, %s)', $row['label'], $this->number($row['window_billed_pct'] ?? 0), $row['currency']))
                ->implode(', ')];
        }

        if ($monthAlerts !== []) {
            $alerts[] = ['kind' => 'months', 'level' => 'warn', 'count' => count($monthAlerts), 'detail' => collect($monthAlerts)
                ->map(fn (array $row) => sprintf('%s %s %s %%', $row['month'], $row['currency'], $row['diff_pct'] === null ? '–' : $this->number($row['diff_pct'], true)))
                ->implode(', ')];
        }

        if ($overdue->isNotEmpty()) {
            $alerts[] = ['kind' => 'overdue', 'level' => 'crit', 'count' => $overdue->count(), 'detail' => $overdue
                ->groupBy('currency')
                ->map(fn (Collection $group, string $currency) => $this->number($group->sum('open')).' '.$currency)
                ->implode(' + ')];
        }

        if ($draft->isNotEmpty()) {
            $alerts[] = ['kind' => 'draft', 'level' => 'warn', 'count' => $draft->count(), 'detail' => $draft->pluck('number')->take(8)->implode(', ')];
        }

        if ($invalid->isNotEmpty()) {
            $alerts[] = ['kind' => 'invalid_due', 'level' => 'warn', 'count' => $invalid->count(), 'detail' => $invalid
                ->map(fn (array $row) => "{$row['number']}: {$row['due_date']}")
                ->take(8)
                ->implode(', ')];
        }

        return $alerts;
    }

    private function pct(float $part, float $whole): ?float
    {
        return abs($whole) < 0.01 ? null : round($part / $whole * 100, 2);
    }

    private function number(float $value, bool $signed = false): string
    {
        $text = number_format($value, abs($value) < 100 ? 1 : 0, ',', '.');

        return $signed && $value > 0 ? "+{$text}" : $text;
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
