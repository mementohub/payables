<?php

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Models\Partner;
use App\Services\SyncService;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Checks a supplier's payment request against the invoices synced from the ERP:
 * which invoices are still open, whether the requested amount matches one of
 * them (or was already paid), and whether the latest invoice fits the
 * supplier's monthly pattern.
 */
class SupplierPaymentCheckService
{
    /** Two amounts are "the same" below this difference. */
    public const AMOUNT_TOLERANCE = 0.01;

    /** A request this close (relative) to the total open amount is flagged, not rejected. */
    public const NEAR_RATIO = 0.03;

    /** The last invoice is out of pattern above the average invoice by this ratio. */
    public const PATTERN_DEVIATION = 0.30;

    public const PATTERN_MONTHS = 24;

    public const AVERAGE_MONTHS = 12;

    private const MONTH_LABELS = [
        'ian.', 'feb.', 'mar.', 'apr.', 'mai', 'iun.',
        'iul.', 'aug.', 'sep.', 'oct.', 'noi.', 'dec.',
    ];

    /**
     * @return array{
     *   open: list<array<string, mixed>>,
     *   open_totals: list<array{moneda: string, count: int, rest: float}>,
     *   first_due: array{date: string, days: int, overdue: bool}|null,
     *   pattern: list<array{month: string, label: string, total_lei: float, count: int}>,
     *   average_month_lei: float,
     *   average_invoice_lei: float,
     *   invoices_12m: int,
     *   last_invoice: array<string, mixed>|null,
     *   currencies: list<string>,
     *   requested: array<string, mixed>|null
     * }
     */
    public function check(Partner $partner, ?float $requested = null, ?string $currency = null): array
    {
        $today = Carbon::today();
        $patternStart = $today->copy()->startOfMonth()->subMonths(self::PATTERN_MONTHS - 1);

        $recent = $this->supplierInvoices($partner)
            ->where('data_doc', '>=', $patternStart->toDateString())
            ->orderByDesc('data_doc')
            ->orderByDesc('id')
            ->get();

        $open = $this->supplierInvoices($partner)
            ->whereRaw(sprintf('val_mon - val_mon_paid - val_mon_storno > %.2F', self::AMOUNT_TOLERANCE))
            ->get()
            ->sortBy(fn (Invoice $invoice) => ($invoice->data_scadenta ?? $invoice->data_doc)->toDateString())
            ->values();

        $currencies = $recent->merge($open)
            ->map(fn (Invoice $invoice) => $this->currency($invoice->moneda))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        $pattern = $this->pattern($recent, $patternStart);
        $averageStart = $today->copy()->startOfMonth()->subMonths(self::AVERAGE_MONTHS - 1);
        $last12 = $recent->filter(fn (Invoice $invoice) => $invoice->data_doc->gte($averageStart));
        $total12 = round((float) $last12->sum(fn (Invoice $invoice) => $this->lei($invoice)), 2);
        $averageInvoice = $last12->isNotEmpty() ? round($total12 / $last12->count(), 2) : 0.0;

        $requestedCurrency = $this->currency($currency) ?? ($currencies[0] ?? 'RON');

        return [
            'open' => $open->map(fn (Invoice $invoice) => $this->row($invoice, $today))->all(),
            'open_totals' => $this->openTotals($open),
            'first_due' => $this->firstDue($open, $today),
            'pattern' => $pattern,
            'average_month_lei' => round($total12 / self::AVERAGE_MONTHS, 2),
            'average_invoice_lei' => $averageInvoice,
            'invoices_12m' => $last12->count(),
            'last_invoice' => $this->lastInvoice($recent->first(), $averageInvoice, $today),
            'currencies' => $currencies,
            'requested' => $requested === null
                ? null
                : $this->verdict($recent, $open, $requested, $requestedCurrency, $today),
        ];
    }

    /**
     * @return HasMany<Invoice, Partner>
     */
    private function supplierInvoices(Partner $partner)
    {
        return $partner->invoices()->whereIn('tip_doc', SyncService::FURNIZOR_DOC_TYPES);
    }

    /**
     * @param  Collection<int, Invoice>  $recent
     * @param  Collection<int, Invoice>  $open
     * @return array<string, mixed>
     */
    private function verdict(Collection $recent, Collection $open, float $requested, string $currency, Carbon $today): array
    {
        $sameCurrency = fn (Invoice $invoice) => $this->currency($invoice->moneda) === $currency;
        $candidates = $open->filter($sameCurrency)->values();
        $openSum = round((float) $candidates->sum(fn (Invoice $invoice) => $invoice->outstandingAmount()), 2);

        $base = [
            'amount' => round($requested, 2),
            'currency' => $currency,
            'open_sum' => $openSum,
            'open_count' => $candidates->count(),
            'invoice' => null,
        ];

        $exact = $candidates->first(fn (Invoice $invoice) => $this->same($invoice->outstandingAmount(), $requested));

        if ($exact) {
            return [...$base, 'verdict' => 'exact', 'level' => 'ok', 'invoice' => $this->row($exact, $today),
                'message' => "Corespunde facturii {$exact->nr_doc} din {$exact->data_doc->format('d.m.Y')}."];
        }

        if ($candidates->count() > 1 && $this->same($openSum, $requested)) {
            return [...$base, 'verdict' => 'sum', 'level' => 'ok',
                'message' => "Corespunde sumei celor {$candidates->count()} facturi neachitate."];
        }

        $paid = $recent->filter($sameCurrency)->first(fn (Invoice $invoice) => $this->same((float) $invoice->val_mon, $requested)
            && $invoice->outstandingAmount() <= self::AMOUNT_TOLERANCE);

        if ($paid) {
            return [...$base, 'verdict' => 'paid', 'level' => 'crit', 'invoice' => $this->row($paid, $today),
                'message' => "Atenție: factura {$paid->nr_doc} din {$paid->data_doc->format('d.m.Y')} are această valoare și este deja plătită."];
        }

        if ($candidates->isNotEmpty() && $requested > 0 && abs($openSum - $requested) / $requested < self::NEAR_RATIO) {
            return [...$base, 'verdict' => 'near', 'level' => 'warn',
                'message' => sprintf('Aproape de restul total de plată (%s %s).', number_format($openSum, 2, ',', '.'), $currency)];
        }

        return [...$base, 'verdict' => 'missing', 'level' => 'crit',
            'message' => 'Nu se regăsește printre facturile neachitate. Factura nu a fost încă înregistrată sau a fost deja plătită.'];
    }

    /**
     * @param  Collection<int, Invoice>  $recent
     * @return list<array{month: string, label: string, total_lei: float, count: int}>
     */
    private function pattern(Collection $recent, Carbon $start): array
    {
        $byMonth = $recent->groupBy(fn (Invoice $invoice) => $invoice->data_doc->format('Y-m'));
        $months = [];

        for ($i = 0; $i < self::PATTERN_MONTHS; $i++) {
            $date = $start->copy()->addMonths($i);
            $group = $byMonth->get($date->format('Y-m'), collect());

            $months[] = [
                'month' => $date->format('Y-m'),
                'label' => self::MONTH_LABELS[$date->month - 1].' '.substr((string) $date->year, -2),
                'total_lei' => round((float) $group->sum(fn (Invoice $invoice) => $this->lei($invoice)), 2),
                'count' => $group->count(),
            ];
        }

        return $months;
    }

    /**
     * @param  Collection<int, Invoice>  $open
     * @return list<array{moneda: string, count: int, rest: float}>
     */
    private function openTotals(Collection $open): array
    {
        return $open
            ->groupBy(fn (Invoice $invoice) => $this->currency($invoice->moneda) ?? '')
            ->map(fn (Collection $group, string $moneda) => [
                'moneda' => $moneda,
                'count' => $group->count(),
                'rest' => round((float) $group->sum(fn (Invoice $invoice) => $invoice->outstandingAmount()), 2),
            ])
            ->sortBy('moneda')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Invoice>  $open
     * @return array{date: string, days: int, overdue: bool}|null
     */
    private function firstDue(Collection $open, Carbon $today): ?array
    {
        $first = $open->first(fn (Invoice $invoice) => $invoice->data_scadenta !== null);

        if ($first === null) {
            return null;
        }

        $days = (int) $today->diffInDays($first->data_scadenta->startOfDay(), false);

        return ['date' => $first->data_scadenta->toDateString(), 'days' => $days, 'overdue' => $days < 0];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastInvoice(?Invoice $last, float $averageInvoice, Carbon $today): ?array
    {
        if ($last === null) {
            return null;
        }

        $lei = $this->lei($last);
        $deviation = $averageInvoice > 0 ? round(($lei - $averageInvoice) / $averageInvoice * 100, 1) : null;

        return [
            ...$this->row($last, $today),
            'total_lei' => round($lei, 2),
            'deviation_pct' => $deviation,
            'level' => $deviation === null ? null : ($deviation > self::PATTERN_DEVIATION * 100 ? 'warn' : 'ok'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Invoice $invoice, Carbon $today): array
    {
        return [
            'id' => $invoice->id,
            'tip_doc' => $invoice->tip_doc,
            'nr_doc' => $invoice->nr_doc,
            'data_doc' => $invoice->data_doc->toDateString(),
            'data_scadenta' => $invoice->data_scadenta?->toDateString(),
            'days_to_due' => $invoice->data_scadenta
                ? (int) $today->diffInDays($invoice->data_scadenta->startOfDay(), false)
                : null,
            'moneda' => $this->currency($invoice->moneda),
            'val_mon' => round((float) $invoice->val_mon, 2),
            'val_mon_paid' => round((float) $invoice->val_mon_paid, 2),
            'val_mon_storno' => round((float) $invoice->val_mon_storno, 2),
            'rest' => $invoice->outstandingAmount(),
            'payment_status' => $invoice->payment_status,
        ];
    }

    private function lei(Invoice $invoice): float
    {
        return (float) $invoice->val_mon * ((float) ($invoice->curs ?? 0) ?: 1.0);
    }

    private function same(float $left, float $right): bool
    {
        return abs($left - $right) < self::AMOUNT_TOLERANCE;
    }

    /**
     * The ERP writes the leu as "Lei"; treat it as RON so requests typed either way match.
     */
    private function currency(?string $value): ?string
    {
        $normalized = strtoupper(trim((string) $value));

        if ($normalized === '') {
            return null;
        }

        return $normalized === 'LEI' ? 'RON' : $normalized;
    }
}
