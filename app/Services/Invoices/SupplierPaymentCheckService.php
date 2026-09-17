<?php

namespace App\Services\Invoices;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Partner;
use App\Services\Omc\OmcReader;
use App\Services\SyncService;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Checks a supplier's payment request against its invoices in the ERP: which
 * invoices are still open, whether the requested amount matches one of them
 * (or was already paid), and whether the latest invoice fits the supplier's
 * monthly pattern. The invoices come live from OMC for the company mirrored
 * from it, and from the synced copy for the others.
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

    public const RECENT_LIMIT = 5;

    /** How many document numbers one lookup asks the database about. */
    private const LOOKUP_CHUNK = 1000;

    private const MONTH_LABELS = [
        'ian.', 'feb.', 'mar.', 'apr.', 'mai', 'iun.',
        'iul.', 'aug.', 'sep.', 'oct.', 'noi.', 'dec.',
    ];

    public function __construct(private OmcReader $omc) {}

    /**
     * The check on the invoices synced locally for a partner.
     *
     * @return array<string, mixed>
     */
    public function check(Partner $partner, ?float $requested = null, ?string $currency = null): array
    {
        $today = Carbon::today();

        $recent = $this->supplierInvoices($partner)
            ->where('data_doc', '>=', $this->patternStart($today)->toDateString())
            ->orderByDesc('data_doc')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Invoice $invoice) => InvoiceRow::fromModel($invoice));

        $open = $this->supplierInvoices($partner)
            ->whereRaw(sprintf('val_mon - val_mon_paid - val_mon_storno > %.2F', self::AMOUNT_TOLERANCE))
            ->get()
            ->map(fn (Invoice $invoice) => InvoiceRow::fromModel($invoice));

        return [
            ...$this->evaluate($recent, $open, $requested, $currency, $today),
            'source' => 'local',
            'supplier' => [
                'name' => $partner->name,
                'cui' => $partner->cui,
                'country' => $partner->country,
                'city' => $partner->city,
                'partner_id' => $partner->id,
                'accounts' => null,
            ],
        ];
    }

    /**
     * The same check straight from OMC for a supplier named as in `partener`;
     * null when OMC does not know the name. Invoices that were also synced
     * locally carry their local id so they can be linked to a request.
     *
     * @return array<string, mixed>|null
     */
    public function checkLive(string $supplier, ?float $requested = null, ?string $currency = null): ?array
    {
        $details = $this->omc->supplier($supplier);

        if ($details === null) {
            return null;
        }

        $today = Carbon::today();
        $patternStart = $this->patternStart($today);
        $company = $this->omc->company();
        $partner = $company
            ? Partner::query()->where('company_id', $company->id)->where('name', $supplier)->first(['id', 'name', 'cui'])
            : null;

        $rows = $this->withLocalIds(collect($this->omc->supplierInvoices($supplier, $patternStart)), $company);

        $recent = $rows->filter(fn (InvoiceRow $row) => $row->dataDoc->gte($patternStart))->values();
        $open = $rows->filter(fn (InvoiceRow $row) => $row->outstanding() > self::AMOUNT_TOLERANCE)->values();

        $accounts = $rows->pluck('accounts')
            ->filter()
            ->flatMap(fn (string $accounts) => explode(', ', $accounts))
            ->unique()
            ->sort()
            ->values();

        return [
            ...$this->evaluate($recent, $open, $requested, $currency, $today),
            'source' => 'omc',
            'supplier' => [
                'name' => $details['name'],
                'cui' => $details['cui'] ?? $partner?->cui,
                'country' => $details['country'],
                'city' => $details['city'],
                'partner_id' => $partner?->id,
                'accounts' => $accounts->isNotEmpty() ? $accounts->implode(', ') : null,
            ],
        ];
    }

    /**
     * Suppliers with invoices still open in OMC, earliest due date first: the
     * queue of what is likely to be requested next.
     *
     * @return array{since: string, suppliers: list<array{
     *   name: string, cui: ?string, invoices: int, first_due: ?string, overdue: bool,
     *   rest: list<array{moneda: string, rest: float}>, rest_lei: float
     * }>}
     */
    public function openSuppliersLive(): array
    {
        $today = Carbon::today();
        $since = $today->copy()->subYears(max(1, (int) config('omc.open_window_years', 2)));

        $suppliers = collect($this->omc->openSupplierInvoices($since))
            ->map(fn (array $row) => InvoiceRow::fromOmc($row))
            ->filter(fn (InvoiceRow $row) => $row->partner !== null && $row->outstanding() > self::AMOUNT_TOLERANCE)
            ->groupBy(fn (InvoiceRow $row) => $row->partner)
            ->map(function (Collection $invoices, string $partner) use ($today) {
                $invoices = $this->byDueDate($invoices);
                $firstDue = $this->firstDue($invoices, $today);

                return [
                    'name' => $partner,
                    'cui' => $invoices->first()->partnerCui,
                    'invoices' => $invoices->count(),
                    'first_due' => $firstDue['date'] ?? null,
                    'overdue' => $firstDue['overdue'] ?? false,
                    'rest' => array_map(fn (array $total) => ['moneda' => $total['moneda'], 'rest' => $total['rest']], $this->openTotals($invoices)),
                    'rest_lei' => round((float) $invoices->sum(fn (InvoiceRow $row) => $row->outstanding() * $row->curs), 2),
                ];
            })
            ->sortBy(fn (array $supplier) => [$supplier['first_due'] ?? '9999-12-31', mb_strtolower($supplier['name'])])
            ->values()
            ->all();

        return ['since' => $since->toDateString(), 'suppliers' => $suppliers];
    }

    /**
     * @param  Collection<int, InvoiceRow>  $recent  newest first, dated within the pattern window
     * @param  Collection<int, InvoiceRow>  $open  every invoice with an open amount
     * @return array{
     *   open: list<array<string, mixed>>,
     *   open_totals: list<array{moneda: string, count: int, rest: float}>,
     *   first_due: array{date: string, days: int, overdue: bool}|null,
     *   recent: list<array<string, mixed>>,
     *   pattern: list<array{month: string, label: string, total_lei: float, count: int}>,
     *   average_month_lei: float,
     *   average_invoice_lei: float,
     *   invoices_12m: int,
     *   last_invoice: array<string, mixed>|null,
     *   currencies: list<string>,
     *   requested: array<string, mixed>|null
     * }
     */
    private function evaluate(Collection $recent, Collection $open, ?float $requested, ?string $currency, Carbon $today): array
    {
        $patternStart = $this->patternStart($today);
        $open = $this->byDueDate($open);

        $currencies = $recent->merge($open)
            ->map(fn (InvoiceRow $row) => $this->currency($row->moneda))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        $averageStart = $today->copy()->startOfMonth()->subMonths(self::AVERAGE_MONTHS - 1);
        $last12 = $recent->filter(fn (InvoiceRow $row) => $row->dataDoc->gte($averageStart));
        $total12 = round((float) $last12->sum(fn (InvoiceRow $row) => $row->lei()), 2);
        $averageInvoice = $last12->isNotEmpty() ? round($total12 / $last12->count(), 2) : 0.0;

        $requestedCurrency = $this->currency($currency) ?? ($currencies[0] ?? 'RON');

        return [
            'open' => $open->map(fn (InvoiceRow $row) => $this->row($row, $today))->all(),
            'open_totals' => $this->openTotals($open),
            'first_due' => $this->firstDue($open, $today),
            'recent' => $recent->take(self::RECENT_LIMIT)->map(fn (InvoiceRow $row) => $this->row($row, $today))->values()->all(),
            'pattern' => $this->pattern($recent, $patternStart),
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

    private function patternStart(Carbon $today): Carbon
    {
        return $today->copy()->startOfMonth()->subMonths(self::PATTERN_MONTHS - 1);
    }

    /**
     * @return HasMany<Invoice, Partner>
     */
    private function supplierInvoices(Partner $partner)
    {
        return $partner->invoices()->whereIn('tip_doc', SyncService::FURNIZOR_DOC_TYPES);
    }

    /**
     * OMC rows as InvoiceRow, carrying the id of the locally synced copy when
     * there is one (same company, same document key).
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, InvoiceRow>
     */
    private function withLocalIds(Collection $rows, ?Company $company): Collection
    {
        $local = $this->localIds($rows, $company);

        // Once per row, not twice: the key is worked out from the row itself,
        // so looking up the local copy costs nothing to throw away.
        return $rows->map(fn (array $row) => InvoiceRow::fromOmc(
            $row,
            $local[InvoiceRow::keyFor($row['data_doc'], $row['tip_doc'], $row['nr_doc'])] ?? null,
        ))->values();
    }

    /**
     * The id of the locally synced copy of each document, by document key.
     *
     * Read as rows and asked for in batches: two years of a busy supplier run
     * to tens of thousands of documents, and neither one `in` list that long
     * nor a model per match is something a page view can carry.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function localIds(Collection $rows, ?Company $company): array
    {
        if ($company === null || $rows->isEmpty()) {
            return [];
        }

        $ids = [];

        foreach ($rows->pluck('nr_doc')->unique()->chunk(self::LOOKUP_CHUNK) as $chunk) {
            DB::table('invoices')
                ->where('company_id', $company->id)
                ->whereIn('tip_doc', SyncService::FURNIZOR_DOC_TYPES)
                ->whereIn('nr_doc', $chunk->values()->all())
                ->select(['id', 'data_doc', 'tip_doc', 'nr_doc'])
                ->cursor()
                ->each(function (object $row) use (&$ids): void {
                    $ids[InvoiceRow::keyFor($row->data_doc, $row->tip_doc, $row->nr_doc)] = (int) $row->id;
                });
        }

        return $ids;
    }

    /**
     * @param  Collection<int, InvoiceRow>  $rows
     * @return Collection<int, InvoiceRow>
     */
    private function byDueDate(Collection $rows): Collection
    {
        return $rows
            ->sortBy(fn (InvoiceRow $row) => ($row->dataScadenta ?? $row->dataDoc)->toDateString())
            ->values();
    }

    /**
     * @param  Collection<int, InvoiceRow>  $recent
     * @param  Collection<int, InvoiceRow>  $open
     * @return array<string, mixed>
     */
    private function verdict(Collection $recent, Collection $open, float $requested, string $currency, Carbon $today): array
    {
        $sameCurrency = fn (InvoiceRow $row) => $this->currency($row->moneda) === $currency;
        $candidates = $open->filter($sameCurrency)->values();
        $openSum = round((float) $candidates->sum(fn (InvoiceRow $row) => $row->outstanding()), 2);

        $base = [
            'amount' => round($requested, 2),
            'currency' => $currency,
            'open_sum' => $openSum,
            'open_count' => $candidates->count(),
            'invoice' => null,
        ];

        $exact = $candidates->first(fn (InvoiceRow $row) => $this->same($row->outstanding(), $requested));

        if ($exact) {
            return [...$base, 'verdict' => 'exact', 'level' => 'ok', 'invoice' => $this->row($exact, $today),
                'message' => "Corespunde facturii {$exact->nrDoc} din {$exact->dataDoc->format('d.m.Y')}."];
        }

        if ($candidates->count() > 1 && $this->same($openSum, $requested)) {
            return [...$base, 'verdict' => 'sum', 'level' => 'ok',
                'message' => "Corespunde sumei celor {$candidates->count()} facturi neachitate."];
        }

        $paid = $recent->filter($sameCurrency)->first(fn (InvoiceRow $row) => $this->same($row->valMon, $requested)
            && $row->outstanding() <= self::AMOUNT_TOLERANCE);

        if ($paid) {
            return [...$base, 'verdict' => 'paid', 'level' => 'crit', 'invoice' => $this->row($paid, $today),
                'message' => "Atenție: factura {$paid->nrDoc} din {$paid->dataDoc->format('d.m.Y')} are această valoare și este deja plătită."];
        }

        if ($candidates->isNotEmpty() && $requested > 0 && abs($openSum - $requested) / $requested < self::NEAR_RATIO) {
            return [...$base, 'verdict' => 'near', 'level' => 'warn',
                'message' => sprintf('Aproape de restul total de plată (%s %s).', number_format($openSum, 2, ',', '.'), $currency)];
        }

        return [...$base, 'verdict' => 'missing', 'level' => 'crit',
            'message' => 'Nu se regăsește printre facturile neachitate. Factura nu a fost încă înregistrată sau a fost deja plătită.'];
    }

    /**
     * @param  Collection<int, InvoiceRow>  $recent
     * @return list<array{month: string, label: string, total_lei: float, count: int}>
     */
    private function pattern(Collection $recent, Carbon $start): array
    {
        $byMonth = $recent->groupBy(fn (InvoiceRow $row) => $row->dataDoc->format('Y-m'));
        $months = [];

        for ($i = 0; $i < self::PATTERN_MONTHS; $i++) {
            $date = $start->copy()->addMonths($i);
            $group = $byMonth->get($date->format('Y-m'), collect());

            $months[] = [
                'month' => $date->format('Y-m'),
                'label' => self::MONTH_LABELS[$date->month - 1].' '.substr((string) $date->year, -2),
                'total_lei' => round((float) $group->sum(fn (InvoiceRow $row) => $row->lei()), 2),
                'count' => $group->count(),
            ];
        }

        return $months;
    }

    /**
     * @param  Collection<int, InvoiceRow>  $open
     * @return list<array{moneda: string, count: int, rest: float}>
     */
    private function openTotals(Collection $open): array
    {
        return $open
            ->groupBy(fn (InvoiceRow $row) => $this->currency($row->moneda) ?? '')
            ->map(fn (Collection $group, string $moneda) => [
                'moneda' => $moneda,
                'count' => $group->count(),
                'rest' => round((float) $group->sum(fn (InvoiceRow $row) => $row->outstanding()), 2),
            ])
            ->sortBy('moneda')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, InvoiceRow>  $open  ordered by due date
     * @return array{date: string, days: int, overdue: bool}|null
     */
    private function firstDue(Collection $open, Carbon $today): ?array
    {
        $first = $open->first(fn (InvoiceRow $row) => $row->dataScadenta !== null);

        if ($first === null) {
            return null;
        }

        $days = (int) $today->diffInDays($first->dataScadenta, false);

        return ['date' => $first->dataScadenta->toDateString(), 'days' => $days, 'overdue' => $days < 0];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastInvoice(?InvoiceRow $last, float $averageInvoice, Carbon $today): ?array
    {
        if ($last === null) {
            return null;
        }

        $lei = $last->lei();
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
    private function row(InvoiceRow $row, Carbon $today): array
    {
        return [
            'id' => $row->id,
            'key' => $row->key(),
            'tip_doc' => $row->tipDoc,
            'nr_doc' => $row->nrDoc,
            'data_doc' => $row->dataDoc->toDateString(),
            'data_scadenta' => $row->dataScadenta?->toDateString(),
            'days_to_due' => $row->dataScadenta ? (int) $today->diffInDays($row->dataScadenta, false) : null,
            'moneda' => $this->currency($row->moneda),
            'val_mon' => round($row->valMon, 2),
            'val_mon_paid' => round($row->valMonPaid, 2),
            'val_mon_storno' => round($row->valMonStorno, 2),
            'rest' => $row->outstanding(),
            'payment_status' => $row->status(),
            'description' => $row->description,
            'paid_at' => $row->paidAt?->toDateString(),
            'accounts' => $row->accounts,
        ];
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
