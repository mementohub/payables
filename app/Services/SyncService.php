<?php

namespace App\Services;

use App\Actions\EInvoices\MatchInvoiceToEInvoice;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\BankStatementLineAllocation;
use App\Models\Company;
use App\Models\CompanyBankAccount;
use App\Models\EInvoice;
use App\Models\Invoice;
use App\Models\InvoiceDetail;
use App\Models\InvoicePayment;
use App\Models\Partner;
use App\Models\PartnerBankAccount;
use App\Services\EInvoices\EInvoiceXmlParser;
use App\Services\EInvoices\PartnerCuiLookup;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The supplier side of OMC, mirrored for the payment workflow: supplier
 * invoices with their lines (account, analytic, cost centre, booking
 * reference), the payments and credit notes that settle them, the
 * suppliers and their bank accounts, bank statements and e-invoices.
 *
 * Documents are read in pages of the `doc` primary key (keyset, never
 * LIMIT/OFFSET, so nothing is skipped between pages) and written in bulk.
 * A window is re-read whole, so documents entered late or edited later are
 * picked up, and a document OMC no longer holds (deleted or cancelled) is
 * marked as removed.
 */
class SyncService
{
    public const array FURNIZOR_DOC_TYPES = ['FactFI', 'FactFE'];

    /** Client documents: no longer mirrored, so any lookup of them finds none. */
    public const array CLIENT_DOC_TYPES = ['FactCI', 'FactCE', 'FactINT'];

    /** Key tuples per `IN (...)` lookup. */
    private const int KEYS = 200;

    private const array DOC_COLUMNS = [
        'data_doc', 'tip_doc', 'nr_doc', 'partener', 'moneda', 'curs',
        'val_mon', 'val_mon_tva', 'val_mon_pl', 'val_mon_dimin_negru', 'val_mon_dimin_rosu',
        'data_scadenta', 'data_inchidere', 'emitent', 'com_int', 'eu_punct_lucru',
        'data_doc_baza', 'tip_doc_baza', 'nr_doc_baza', 'data_anulare', 'ultima_modif_data',
    ];

    private const array LINE_COLUMNS = [
        'data_doc', 'tip_doc', 'nr_doc', 'scv', 'articol', 'detaliu_articol', 'cant', 'um', 'pret', 'proc_tva',
        'conts', 'conta', 'loc', 'com_int', 'nr_obiect', 'furnizor',
    ];

    private const array INCOMING_TYPES = [
        'OP_INC', 'Ch_INC', 'Reg_INC', 'CardINC', 'CredINC', 'BO_INC', 'CEC_INC', 'Cmb_INC',
        'DI_Casa', 'DIV_INC', 'Dob_INC', 'FV_B', 'OCV_INC', 'OVV_INC', 'DocCred',
        'B_Cadou', 'B_CasaF', 'B_Masa', 'BonMasa', 'BordMgz', 'CCredit', 'CEC_N_C',
    ];

    public function __construct(
        private readonly RemoteConnection $remote,
        private readonly EInvoiceXmlParser $xmlParser = new EInvoiceXmlParser,
        private readonly MatchInvoiceToEInvoice $matcher = new MatchInvoiceToEInvoice,
    ) {}

    /**
     * Mirror one window of dates: every supplier invoice dated in it (lines,
     * payments, supplier), then the bank statements and e-invoices of the
     * same days.
     *
     * With `$invoicesOnly` the bank statements and e-invoices are left out,
     * for the history pass: the nightly window keeps those current.
     *
     * @return array{partners: int, invoices: int, details: int, payments: int, removed: int, statements: int, e_invoices: int}
     */
    public function sync(Company $company, ?Carbon $from = null, ?Carbon $to = null, bool $invoicesOnly = false): array
    {
        $remote = $this->connect($company);
        $to ??= Carbon::now()->endOfDay();
        $from ??= $to->copy()->subDays(max(1, (int) config('sync.window_days', 400)) - 1)->startOfDay();

        $counts = $this->syncInvoiceRange($company, $remote, $from, $to);
        $counts['statements'] = $invoicesOnly ? 0 : $this->syncBankStatements($company, $remote, $from, $to);
        $counts['e_invoices'] = $invoicesOnly ? 0 : $this->syncEInvoices($company, $remote, $from, $to);

        $company->forceFill(['last_synced_at' => now()])->save();

        return $counts;
    }

    /**
     * The last few days, then every invoice open in OMC or locally, so a
     * payment made today against an old invoice shows at once.
     *
     * @param  callable(string): void|null  $progress
     * @return array<string, int>
     */
    public function syncRecent(Company $company, ?int $days = null, ?callable $progress = null): array
    {
        $days = max(1, $days ?? (int) config('sync.recent_days', 3));
        $to = Carbon::now()->endOfDay();

        return $this->syncWindow($company, $to->copy()->subDays($days - 1)->startOfDay(), $to, $progress);
    }

    /**
     * A window in slices (a month by default), then the open invoices.
     *
     * @param  callable(string): void|null  $progress
     * @return array<string, int>
     */
    public function syncWindow(Company $company, Carbon $from, Carbon $to, ?callable $progress = null): array
    {
        $totals = $this->walk($company, $from->copy()->startOfDay(), $to->copy()->endOfDay(), $progress);
        $this->syncCompanyBankAccounts($company, $this->connect($company));

        return [...$totals, 'refreshed' => $this->refreshOpenInvoices($company)];
    }

    /**
     * Every supplier invoice from `sync.history_from` (or where a previous
     * run stopped) up to today, remembering the last finished slice so a
     * stopped run continues instead of starting over.
     *
     * @param  callable(string): void|null  $progress
     * @return array<string, int|string>
     */
    public function syncHistory(Company $company, ?callable $progress = null, ?Carbon $restartFrom = null): array
    {
        $key = self::historyKey($company);

        if ($restartFrom !== null) {
            Cache::forget($key);
        }

        $cursor = Cache::get($key);
        $from = $restartFrom?->copy()->startOfDay()
            ?? ($cursor ? Carbon::parse((string) $cursor)->addDay()->startOfDay() : Carbon::parse((string) config('sync.history_from', '2016-01-01'))->startOfDay());
        $to = Carbon::now()->endOfDay();

        $totals = $this->walk($company, $from, $to, $progress, fn (Carbon $end) => Cache::forever($key, $end->toDateString()), invoicesOnly: true);
        $this->syncCompanyBankAccounts($company, $this->connect($company));

        return [...$totals, 'refreshed' => $this->refreshOpenInvoices($company), 'from' => $from->toDateString(), 'to' => $to->toDateString()];
    }

    /**
     * The last day the history pull has completed for the company, if any.
     */
    public static function historyCursor(Company $company): ?string
    {
        $cursor = Cache::get(self::historyKey($company));

        return $cursor ? (string) $cursor : null;
    }

    /**
     * Bring every open supplier invoice up to date: those OMC holds as open
     * (unpaid, partly paid or not yet offset) and those still open locally,
     * which OMC may have settled or removed since. Returns how many were
     * re-read.
     */
    public function refreshOpenInvoices(Company $company): int
    {
        $remote = $this->connect($company);
        $since = Carbon::now()->subYears(max(1, (int) config('omc.open_window_years', 2)))->startOfYear()->toDateString();

        $open = $remote->table('doc')
            ->select(self::DOC_COLUMNS)
            ->whereIn('tip_doc', self::FURNIZOR_DOC_TYPES)
            ->whereNull('data_anulare')
            ->where('data_doc', '>=', $since)
            ->whereRaw('abs(coalesce(val_mon, 0) - coalesce(val_mon_pl, 0) - coalesce(val_mon_dimin_negru, 0) + coalesce(val_mon_dimin_rosu, 0)) > 0.01')
            ->get();

        foreach ($open->chunk($this->page()) as $chunk) {
            $this->store($company, $remote, $chunk->values());
        }

        $openKeys = $open->mapWithKeys(fn (object $row) => [self::key($row->data_doc, $row->tip_doc, $row->nr_doc) => true])->all();

        // Open here but not in OMC's list: paid, offset or gone since.
        $stale = Invoice::query()
            ->where('company_id', $company->id)
            ->whereIn('tip_doc', self::FURNIZOR_DOC_TYPES)
            ->whereNull('omc_removed_at')
            ->whereRaw('abs(val_mon - val_mon_paid - val_mon_storno) > 0.01')
            ->get(['id', 'data_doc', 'tip_doc', 'nr_doc'])
            ->reject(fn (Invoice $invoice) => isset($openKeys[self::key($invoice->data_doc, $invoice->tip_doc, $invoice->nr_doc)]));

        foreach ($stale->chunk(self::KEYS) as $chunk) {
            $rows = $this->whereKeys($remote->table('doc')->select(self::DOC_COLUMNS), ['data_doc', 'tip_doc', 'nr_doc'], $chunk->map(fn (Invoice $i) => [$i->data_doc->toDateString(), $i->tip_doc, $i->nr_doc])->all())->get();
            $live = $rows->whereNull('data_anulare')->values();
            $this->store($company, $remote, $live);

            $found = $live->mapWithKeys(fn (object $row) => [self::key($row->data_doc, $row->tip_doc, $row->nr_doc) => true])->all();
            $gone = $chunk->reject(fn (Invoice $i) => isset($found[self::key($i->data_doc, $i->tip_doc, $i->nr_doc)]))->pluck('id');

            if ($gone->isNotEmpty()) {
                Invoice::query()->whereKey($gone->all())->update(['omc_removed_at' => now()]);
            }
        }

        return $open->count() + $stale->count();
    }

    /**
     * @param  callable(string): void|null  $progress
     * @param  callable(Carbon): void|null  $finished  called with the end of each slice
     * @return array<string, int>
     */
    private function walk(Company $company, Carbon $from, Carbon $to, ?callable $progress, ?callable $finished = null, bool $invoicesOnly = false): array
    {
        $sliceDays = max(1, (int) config('sync.slice_days', 31));
        $totals = [];

        for ($start = $from->copy(); $start->lte($to); $start = $start->copy()->addDays($sliceDays)) {
            $end = $start->copy()->addDays($sliceDays - 1)->endOfDay();
            $end = $end->gt($to) ? $to->copy() : $end;

            $result = $this->sync($company, $start, $end, $invoicesOnly);

            foreach ($result as $name => $count) {
                $totals[$name] = ($totals[$name] ?? 0) + $count;
            }

            if ($finished !== null) {
                $finished($end);
            }

            if ($progress !== null) {
                $progress(sprintf(
                    '%s → %s: %d facturi, %d linii, %d plăți, %d parteneri, %d extrase, %d eFacturi%s',
                    $start->toDateString(), $end->toDateString(), $result['invoices'], $result['details'], $result['payments'],
                    $result['partners'], $result['statements'], $result['e_invoices'],
                    $result['removed'] > 0 ? ", {$result['removed']} scoase din OMC" : '',
                ));
            }
        }

        return $totals;
    }

    /**
     * Every supplier invoice dated in the window, page by page of the OMC
     * primary key, then the local ones OMC no longer holds.
     *
     * @return array{partners: int, invoices: int, details: int, payments: int, removed: int}
     */
    private function syncInvoiceRange(Company $company, ConnectionInterface $remote, Carbon $from, Carbon $to): array
    {
        $totals = ['partners' => 0, 'invoices' => 0, 'details' => 0, 'payments' => 0, 'removed' => 0];
        $seen = [];
        $cursor = null;

        do {
            $query = $remote->table('doc')
                ->select(self::DOC_COLUMNS)
                ->whereIn('tip_doc', self::FURNIZOR_DOC_TYPES)
                ->whereBetween('data_doc', [$from->toDateString(), $to->toDateString()]);

            if ($cursor !== null) {
                $query->whereRaw('(data_doc, tip_doc, nr_doc) > (?, ?, ?)', $cursor);
            }

            $rows = $query->orderBy('data_doc')->orderBy('tip_doc')->orderBy('nr_doc')->limit($this->page())->get();

            if ($rows->isEmpty()) {
                break;
            }

            $first = $rows->first();
            $last = $rows->last();
            $cursor = [self::day($last->data_doc), $last->tip_doc, $last->nr_doc];
            $live = $rows->whereNull('data_anulare')->values();

            foreach ($live as $row) {
                $seen[self::key($row->data_doc, $row->tip_doc, $row->nr_doc)] = true;
            }

            $result = $this->store($company, $remote, $live, [[self::day($first->data_doc), $first->tip_doc, $first->nr_doc], $cursor]);

            foreach ($result as $name => $count) {
                $totals[$name] += $count;
            }
        } while ($rows->count() === $this->page());

        $removed = Invoice::query()
            ->where('company_id', $company->id)
            ->whereIn('tip_doc', self::FURNIZOR_DOC_TYPES)
            ->whereBetween('data_doc', [$from->toDateString(), $to->toDateString()])
            ->whereNull('omc_removed_at')
            ->get(['id', 'data_doc', 'tip_doc', 'nr_doc'])
            ->reject(fn (Invoice $invoice) => isset($seen[self::key($invoice->data_doc, $invoice->tip_doc, $invoice->nr_doc)]))
            ->pluck('id');

        foreach ($removed->chunk(1000) as $chunk) {
            Invoice::query()->whereKey($chunk->all())->update(['omc_removed_at' => now()]);
        }

        $totals['removed'] = $removed->count();

        return $totals;
    }

    /**
     * Write a set of OMC documents with their suppliers, lines and payments.
     * With `$range` (first and last primary key of a page) the lines and
     * payments are read as one key range; without it, by the documents' keys.
     *
     * @param  Collection<int, object>  $docs
     * @param  array{0: array{0: string, 1: string, 2: string}, 1: array{0: string, 1: string, 2: string}}|null  $range
     * @return array{partners: int, invoices: int, details: int, payments: int}
     */
    private function store(Company $company, ConnectionInterface $remote, Collection $docs, ?array $range = null): array
    {
        if ($docs->isEmpty()) {
            return ['partners' => 0, 'invoices' => 0, 'details' => 0, 'payments' => 0];
        }

        $partners = $this->syncPartners($company, $remote, $docs->pluck('partener')->filter()->unique()->values()->all());
        $now = Carbon::now();
        $payload = [];

        foreach ($docs as $row) {
            // The last one read wins when the local collation sees two OMC
            // keys as one ("16" and "16 "), as the database would do anyway.
            $payload[self::key($row->data_doc, $row->tip_doc, $row->nr_doc)] = [
                'company_id' => $company->id,
                'data_doc' => self::day($row->data_doc),
                'tip_doc' => $row->tip_doc,
                'nr_doc' => $row->nr_doc,
                'nr_doc_key' => MatchInvoiceToEInvoice::key($row->nr_doc),
                'partner_id' => $row->partener !== null ? ($partners[self::name($row->partener)] ?? null) : null,
                'partener_type' => 'furnizor',
                'moneda' => $row->moneda,
                'curs' => $row->curs,
                'val_mon' => $row->val_mon ?? 0,
                'val_mon_tva' => $row->val_mon_tva ?? 0,
                'val_mon_paid' => $row->val_mon_pl ?? 0,
                // Offsets on either side: a positive invoice is reduced by the
                // credit notes set against it (negru), a credit note is used
                // up by the invoices it is set against (rosu).
                'val_mon_storno' => (float) ($row->val_mon_dimin_negru ?? 0) - (float) ($row->val_mon_dimin_rosu ?? 0),
                'data_scadenta' => self::day($row->data_scadenta),
                'data_inchidere' => self::day($row->data_inchidere),
                'emitent' => $row->emitent,
                'office' => self::text($row->eu_punct_lucru),
                'omc_modified_at' => $row->ultima_modif_data ?: null,
                'omc_removed_at' => null,
                'com_int' => self::text($row->com_int),
                'data_doc_baza' => self::day($row->data_doc_baza),
                'tip_doc_baza' => self::text($row->tip_doc_baza),
                'nr_doc_baza' => self::text($row->nr_doc_baza),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $update = array_values(array_diff(array_keys(reset($payload)), ['company_id', 'data_doc', 'tip_doc', 'nr_doc', 'created_at']));

        foreach (array_chunk(array_values($payload), 500) as $chunk) {
            Invoice::query()->upsert($chunk, ['company_id', 'data_doc', 'tip_doc', 'nr_doc'], $update);
        }

        $ids = $this->localIds($company, $docs);

        return [
            'partners' => count($partners),
            'invoices' => count($payload),
            'details' => $this->storeLines($remote, $ids, $range),
            'payments' => $this->storePayments($company, $remote, $ids, $range),
        ];
    }

    /**
     * Local id of each document, by normalised key.
     *
     * @param  Collection<int, object>  $docs
     * @return array<string, int>
     */
    private function localIds(Company $company, Collection $docs): array
    {
        $ids = [];

        foreach ($docs->chunk(self::KEYS) as $chunk) {
            $query = Invoice::query()->where('company_id', $company->id)->select(['id', 'data_doc', 'tip_doc', 'nr_doc']);

            $this->whereKeys($query, ['data_doc', 'tip_doc', 'nr_doc'], $chunk->map(fn (object $row) => [self::day($row->data_doc), $row->tip_doc, $row->nr_doc])->all())
                ->get()
                ->each(function (Invoice $invoice) use (&$ids) {
                    $ids[self::key($invoice->data_doc, $invoice->tip_doc, $invoice->nr_doc)] = $invoice->id;
                });
        }

        return $ids;
    }

    /**
     * Replace the lines of the invoices with the ones OMC holds.
     *
     * @param  array<string, int>  $ids
     * @param  array{0: array<int, string>, 1: array<int, string>}|null  $range
     */
    private function storeLines(ConnectionInterface $remote, array $ids, ?array $range): int
    {
        if ($ids === []) {
            return 0;
        }

        $rows = $this->readByKeys($remote, 'doc_poz', self::LINE_COLUMNS, ['data_doc', 'tip_doc', 'nr_doc'], $ids, $range);
        $now = Carbon::now();
        $inserts = [];

        foreach ($rows as $row) {
            $invoiceId = $ids[self::key($row->data_doc, $row->tip_doc, $row->nr_doc)] ?? null;

            if ($invoiceId === null) {
                continue;
            }

            $inserts[$invoiceId.'|'.$row->scv] = [
                'invoice_id' => $invoiceId,
                'scv' => $row->scv,
                'articol' => (string) $row->articol,
                'detaliu_articol' => self::text($row->detaliu_articol, 255),
                'cant' => $row->cant ?? 0,
                'um' => self::text($row->um, 10),
                'pret' => $row->pret ?? 0,
                'proc_tva' => $row->proc_tva,
                'account' => self::text($row->conts, 20),
                'analytic' => self::text($row->conta, 120),
                'loc' => self::text($row->loc, 80),
                'com_int' => self::text($row->com_int, 40),
                'nr_obiect' => self::text($row->nr_obiect, 40),
                'furnizor' => self::text($row->furnizor, 255),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk(array_values($ids), 1000) as $chunk) {
            InvoiceDetail::query()->whereIn('invoice_id', $chunk)->delete();
        }

        foreach (array_chunk(array_values($inserts), 1000) as $chunk) {
            InvoiceDetail::query()->insert($chunk);
        }

        return count($inserts);
    }

    /**
     * Replace the payment allocations of the invoices with OMC's.
     *
     * @param  array<string, int>  $ids
     * @param  array{0: array<int, string>, 1: array<int, string>}|null  $range
     */
    private function storePayments(Company $company, ConnectionInterface $remote, array $ids, ?array $range): int
    {
        if ($ids === []) {
            return 0;
        }

        $rows = $this->readByKeys($remote, 'doc_fin', [
            'data_doc_com', 'tip_doc_com', 'nr_doc_com', 'data_doc_fin', 'tip_doc_fin', 'nr_doc_fin', 'data_repartizare', 'val_fin', 'val_com',
        ], ['data_doc_com', 'tip_doc_com', 'nr_doc_com'], $ids, $range);

        $currencies = $this->paymentCurrencies($remote, $rows);
        $bankLines = $this->bankLineIds($company, $rows);
        $now = Carbon::now();
        $payload = [];

        foreach ($rows as $row) {
            $invoiceId = $ids[self::key($row->data_doc_com, $row->tip_doc_com, $row->nr_doc_com)] ?? null;

            if ($invoiceId === null) {
                continue;
            }

            $paidOn = self::day($row->data_doc_fin);
            $allocatedOn = self::day($row->data_repartizare);
            $finKey = self::key($row->data_doc_fin, $row->tip_doc_fin, $row->nr_doc_fin);

            // The unique index compares as the database does: dates as stored,
            // the document number with trailing spaces ignored.
            $payload[implode('|', [$invoiceId, $finKey, $allocatedOn])] = [
                'invoice_id' => $invoiceId,
                'data_doc' => $paidOn,
                'tip_doc' => $row->tip_doc_fin,
                'nr_doc' => $row->nr_doc_fin,
                'data_repartizare' => $allocatedOn,
                'val_fin' => $row->val_fin ?? 0,
                'val_com' => $row->val_com ?? 0,
                'moneda' => $currencies[$finKey] ?? null,
                'bank_statement_line_id' => $bankLines[$finKey] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk(array_values($ids), 1000) as $chunk) {
            InvoicePayment::query()->whereIn('invoice_id', $chunk)->delete();
        }

        foreach (array_chunk(array_values($payload), 1000) as $chunk) {
            InvoicePayment::query()->insert($chunk);
        }

        return count($payload);
    }

    /**
     * Rows of a child table (lines, allocations) of the given documents: one
     * primary-key range read for a page, or key tuples otherwise.
     *
     * @param  list<string>  $columns
     * @param  array{0: string, 1: string, 2: string}  $keyColumns
     * @param  array<string, int>  $ids
     * @param  array{0: array<int, string>, 1: array<int, string>}|null  $range
     * @return Collection<int, object>
     */
    private function readByKeys(ConnectionInterface $remote, string $table, array $columns, array $keyColumns, array $ids, ?array $range): Collection
    {
        [$date, $type, $number] = $keyColumns;

        if ($range !== null) {
            return $remote->table($table)
                ->select($columns)
                ->whereIn($type, self::FURNIZOR_DOC_TYPES)
                ->whereRaw("({$date}, {$type}, {$number}) >= (?, ?, ?)", $range[0])
                ->whereRaw("({$date}, {$type}, {$number}) <= (?, ?, ?)", $range[1])
                ->get();
        }

        $rows = collect();

        foreach (array_chunk(array_keys($ids), self::KEYS) as $chunk) {
            $keys = array_map(fn (string $key) => explode('|', $key, 3), $chunk);
            $rows = $rows->merge($this->whereKeys($remote->table($table)->select($columns), $keyColumns, $keys)->get());
        }

        return $rows;
    }

    /**
     * Currency of each paying document.
     *
     * @param  Collection<int, object>  $rows  doc_fin rows
     * @return array<string, string>
     */
    private function paymentCurrencies(ConnectionInterface $remote, Collection $rows): array
    {
        $currencies = [];
        $keys = $rows->map(fn (object $row) => [self::day($row->data_doc_fin), $row->tip_doc_fin, $row->nr_doc_fin])
            ->unique(fn (array $key) => implode('|', $key))
            ->values();

        foreach ($keys->chunk(self::KEYS) as $chunk) {
            $this->whereKeys($remote->table('doc')->select(['data_doc', 'tip_doc', 'nr_doc', 'moneda']), ['data_doc', 'tip_doc', 'nr_doc'], $chunk->values()->all())
                ->get()
                ->each(function (object $doc) use (&$currencies) {
                    $currencies[self::key($doc->data_doc, $doc->tip_doc, $doc->nr_doc)] = $doc->moneda;
                });
        }

        return $currencies;
    }

    /**
     * Local bank-statement line of each paying document.
     *
     * @param  Collection<int, object>  $rows  doc_fin rows
     * @return array<string, int>
     */
    private function bankLineIds(Company $company, Collection $rows): array
    {
        $lookup = [];
        $keys = $rows->map(fn (object $row) => [self::day($row->data_doc_fin), $row->tip_doc_fin, $row->nr_doc_fin])
            ->unique(fn (array $key) => implode('|', $key))
            ->values();

        foreach ($keys->chunk(self::KEYS) as $chunk) {
            $query = BankStatementLine::query()
                ->whereHas('statement', fn ($q) => $q->where('company_id', $company->id))
                ->select(['id', 'data_doc', 'tip_doc', 'nr_doc']);

            $this->whereKeys($query, ['data_doc', 'tip_doc', 'nr_doc'], $chunk->values()->all())
                ->get()
                ->each(function (BankStatementLine $line) use (&$lookup) {
                    $lookup[self::key($line->data_doc, $line->tip_doc, $line->nr_doc)] = $line->id;
                });
        }

        return $lookup;
    }

    /**
     * Upsert the suppliers by name, with their bank accounts; returns the
     * local id of each by normalised name.
     *
     * @param  list<string>  $names
     * @return array<string, int>
     */
    private function syncPartners(Company $company, ConnectionInterface $remote, array $names): array
    {
        if ($names === []) {
            return [];
        }

        $now = Carbon::now();
        $rows = collect();

        foreach (array_chunk($names, 500) as $chunk) {
            $rows = $rows->merge($remote->table('partener')
                ->select(['partener', 'cod_cci', 'reg_comert_nr', 'da_nu_platitor_tva', 'tara', 'localit', 'adresa', 'telefon', 'email_adr'])
                ->whereIn('partener', $chunk)
                ->get());
        }

        $payload = $rows->map(fn (object $row) => [
            'company_id' => $company->id,
            'name' => $row->partener,
            'cui' => $row->cod_cci,
            'reg_com' => $row->reg_comert_nr,
            'is_furnizor' => true,
            'is_client' => false,
            'is_vat_payer' => (bool) $row->da_nu_platitor_tva,
            'country' => $row->tara,
            'city' => $row->localit,
            'address' => $row->adresa,
            'phone' => $row->telefon,
            'email' => $row->email_adr,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        foreach (array_chunk($payload, 500) as $chunk) {
            Partner::query()->upsert($chunk, ['company_id', 'name'], ['cui', 'reg_com', 'is_furnizor', 'is_vat_payer', 'country', 'city', 'address', 'phone', 'email', 'updated_at']);
        }

        $ids = [];

        foreach (array_chunk($names, 500) as $chunk) {
            Partner::query()->where('company_id', $company->id)->whereIn('name', $chunk)->get(['id', 'name'])
                ->each(function (Partner $partner) use (&$ids) {
                    $ids[self::name($partner->name)] = $partner->id;
                });
        }

        $this->syncPartnerBankAccounts($remote, $names, $ids);

        return $ids;
    }

    /**
     * @param  list<string>  $names  as OMC holds them
     * @param  array<string, int>  $partners  normalised name → id
     */
    private function syncPartnerBankAccounts(ConnectionInterface $remote, array $names, array $partners): void
    {
        $payload = [];
        $now = Carbon::now();

        foreach (array_chunk($names, 500) as $chunk) {
            $rows = $remote->table('partener_banca as pb')
                ->leftJoin('banca as b', 'b.banca', '=', 'pb.banca')
                ->select(['pb.partener', 'pb.banca', 'pb.cont_banca', 'pb.moneda', 'pb.da_nu_implicit', 'pb.discontinued', 'b.cod_bic', 'b.swift'])
                ->whereIn('pb.partener', $chunk)
                ->get();

            foreach ($rows as $row) {
                $partnerId = $partners[self::name($row->partener)] ?? null;
                $iban = trim((string) $row->cont_banca);

                if ($partnerId === null || $iban === '') {
                    continue;
                }

                $bank = $row->banca !== null ? trim((string) $row->banca) : null;
                $payload[$partnerId.'|'.mb_strtolower($iban)] = [
                    'partner_id' => $partnerId,
                    'iban' => $iban,
                    'bank' => $bank !== null && $bank !== '' && $bank !== '-' ? $bank : null,
                    'bic' => $row->cod_bic ? trim((string) $row->cod_bic) : null,
                    'swift' => $row->swift ? trim((string) $row->swift) : null,
                    'currency' => $row->moneda,
                    'is_default' => (bool) $row->da_nu_implicit,
                    'is_discontinued' => (bool) $row->discontinued,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk(array_values($payload), 500) as $chunk) {
            PartnerBankAccount::query()->upsert($chunk, ['partner_id', 'iban'], ['bank', 'bic', 'swift', 'currency', 'is_default', 'is_discontinued', 'updated_at']);
        }
    }

    private function syncCompanyBankAccounts(Company $company, ConnectionInterface $remote): int
    {
        $rows = $remote->table('eu_banca as eb')
            ->leftJoin('banca as b', 'b.banca', '=', 'eb.banca')
            ->select(['eb.banca', 'eb.cont_banca', 'eb.moneda', 'eb.da_nu_implicit', 'eb.discontinued', 'b.cod_bic', 'b.swift'])
            ->get();

        $count = 0;

        foreach ($rows as $row) {
            $iban = $row->cont_banca !== null ? trim((string) $row->cont_banca) : '';

            if ($iban === '' || ! preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{4,}$/i', $iban)) {
                continue;
            }

            $bank = $row->banca !== null ? trim((string) $row->banca) : null;

            CompanyBankAccount::updateOrCreate(
                ['company_id' => $company->id, 'iban' => $iban],
                [
                    'bank' => $bank !== null && $bank !== '' && $bank !== '-' ? $bank : null,
                    'bic' => $row->cod_bic ? trim((string) $row->cod_bic) : null,
                    'swift' => $row->swift ? trim((string) $row->swift) : null,
                    'currency' => $row->moneda,
                    'is_default' => (bool) $row->da_nu_implicit,
                    'is_discontinued' => (bool) $row->discontinued,
                ]
            );
            $count++;
        }

        return $count;
    }

    /**
     * The bank statements of the window, their lines and what each line
     * settles. Lines are updated in place (payments point at them), and the
     * ones OMC no longer lists are dropped.
     */
    private function syncBankStatements(Company $company, ConnectionInterface $remote, Carbon $from, Carbon $to): int
    {
        $headers = $remote->table('extrasb as e')
            ->leftJoin('eu_banca as b', function ($join) {
                $join->on('b.banca', '=', 'e.banca_eu')->on('b.cont_banca', '=', 'e.cont_banca_eu');
            })
            ->select(['e.data_extras', 'e.banca_eu', 'e.cont_banca_eu', 'e.operator', 'b.moneda'])
            ->whereBetween('e.data_extras', [$from->toDateString(), $to->toDateString()])
            ->orderBy('e.data_extras')
            ->get();

        if ($headers->isEmpty()) {
            return 0;
        }

        $partners = Partner::query()->where('company_id', $company->id)->where('is_furnizor', true)->pluck('id', 'name')
            ->mapWithKeys(fn (int $id, string $name) => [self::name($name) => $id])
            ->all();

        // Every bank document booked in the window, in one read, grouped by
        // the statement (day, bank, account) it belongs to.
        $windowLines = $remote->table('doc')
            ->select(['data_doc', 'tip_doc', 'nr_doc', 'partener', 'moneda', 'val_mon', 'emitent', 'cine_preda', 'cine_primeste', 'obs_txt', 'data_contab', 'banca_eu', 'cont_banca_eu'])
            ->whereBetween('data_contab', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('cont_banca_eu')
            ->whereNull('data_anulare')
            ->orderBy('data_doc')
            ->get()
            ->groupBy(fn (object $line) => self::day($line->data_contab).'|'.$line->banca_eu.'|'.$line->cont_banca_eu);

        $windowAllocations = collect();
        $keys = $windowLines->flatten(1)->map(fn (object $line) => [self::day($line->data_doc), $line->tip_doc, $line->nr_doc])->values();

        foreach ($keys->chunk(self::KEYS) as $chunk) {
            $windowAllocations = $windowAllocations->merge($this->whereKeys(
                $remote->table('doc_fin')->select(['data_doc_fin', 'tip_doc_fin', 'nr_doc_fin', 'data_doc_com', 'tip_doc_com', 'nr_doc_com', 'val_fin', 'val_com']),
                ['data_doc_fin', 'tip_doc_fin', 'nr_doc_fin'],
                $chunk->values()->all(),
            )->get());
        }

        $windowAllocations = $windowAllocations->groupBy(fn (object $row) => self::key($row->data_doc_fin, $row->tip_doc_fin, $row->nr_doc_fin));

        foreach ($headers as $header) {
            $day = self::day($header->data_extras);
            $statement = BankStatement::query()
                ->where('company_id', $company->id)
                ->where('iban', $header->cont_banca_eu)
                ->whereDate('data_extras', $day)
                ->first() ?? new BankStatement(['company_id' => $company->id, 'data_extras' => $day, 'iban' => $header->cont_banca_eu]);

            $statement->forceFill([
                'banca' => $header->banca_eu !== '-' ? $header->banca_eu : null,
                'operator' => $header->operator,
                'moneda' => $header->moneda,
            ])->save();

            $lines = $windowLines->get($day.'|'.$header->banca_eu.'|'.$header->cont_banca_eu, collect());
            $allocations = $lines->flatMap(fn (object $line) => $windowAllocations->get(self::key($line->data_doc, $line->tip_doc, $line->nr_doc), collect()))->values();

            $allocated = [];

            foreach ($allocations as $row) {
                $key = self::key($row->data_doc_fin, $row->tip_doc_fin, $row->nr_doc_fin);
                $allocated[$key] = ($allocated[$key] ?? 0) + (float) ($row->val_fin ?? 0);
            }

            $now = Carbon::now();
            $payload = [];
            $totals = ['lines_count' => 0, 'unallocated_count' => 0, 'total_incoming' => 0.0, 'total_outgoing' => 0.0, 'total_unallocated' => 0.0];

            foreach ($lines as $line) {
                $key = self::key($line->data_doc, $line->tip_doc, $line->nr_doc);
                $incoming = in_array($line->tip_doc, self::INCOMING_TYPES, true);
                $value = (float) ($line->val_mon ?? 0);
                $unallocated = max(0, $value - ($allocated[$key] ?? 0));

                $payload[$key] = [
                    'bank_statement_id' => $statement->id,
                    'data_doc' => self::day($line->data_doc),
                    'tip_doc' => $line->tip_doc,
                    'nr_doc' => $line->nr_doc,
                    'direction' => $incoming ? 'incoming' : 'outgoing',
                    'partener_name' => $line->partener,
                    'partner_id' => $line->partener !== null ? ($partners[self::name($line->partener)] ?? null) : null,
                    'emitent' => $line->emitent,
                    'cine_preda' => $line->cine_preda,
                    'cine_primeste' => $line->cine_primeste,
                    'obs_txt' => $line->obs_txt,
                    'moneda' => $line->moneda,
                    'val_mon' => $value,
                    'val_allocated' => $allocated[$key] ?? 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $totals['lines_count']++;
                $totals[$incoming ? 'total_incoming' : 'total_outgoing'] += $value;

                if ($unallocated > 0.01) {
                    $totals['unallocated_count']++;
                    $totals['total_unallocated'] += $unallocated;
                }
            }

            foreach (array_chunk(array_values($payload), 500) as $chunk) {
                BankStatementLine::query()->upsert($chunk, ['bank_statement_id', 'data_doc', 'tip_doc', 'nr_doc'], [
                    'direction', 'partener_name', 'partner_id', 'emitent', 'cine_preda', 'cine_primeste', 'obs_txt', 'moneda', 'val_mon', 'val_allocated', 'updated_at',
                ]);
            }

            $lineIds = [];

            $statement->lines()->get(['id', 'data_doc', 'tip_doc', 'nr_doc'])->each(function (BankStatementLine $line) use (&$lineIds, $payload) {
                $key = self::key($line->data_doc, $line->tip_doc, $line->nr_doc);

                if (isset($payload[$key])) {
                    $lineIds[$key] = $line->id;
                } else {
                    $line->delete();
                }
            });

            $this->storeAllocations($company, $lineIds, $allocations);
            $statement->forceFill($totals)->save();
        }

        return $headers->count();
    }

    /**
     * @param  array<string, int>  $lineIds
     * @param  Collection<int, object>  $allocations
     */
    private function storeAllocations(Company $company, array $lineIds, Collection $allocations): void
    {
        BankStatementLineAllocation::query()->whereIn('bank_statement_line_id', array_values($lineIds) ?: [0])->delete();

        if ($allocations->isEmpty()) {
            return;
        }

        $invoices = [];
        $keys = $allocations->map(fn (object $row) => [self::day($row->data_doc_com), $row->tip_doc_com, $row->nr_doc_com])
            ->unique(fn (array $key) => implode('|', $key))
            ->values();

        foreach ($keys->chunk(self::KEYS) as $chunk) {
            $this->whereKeys(Invoice::query()->where('company_id', $company->id)->select(['id', 'data_doc', 'tip_doc', 'nr_doc']), ['data_doc', 'tip_doc', 'nr_doc'], $chunk->values()->all())
                ->get()
                ->each(function (Invoice $invoice) use (&$invoices) {
                    $invoices[self::key($invoice->data_doc, $invoice->tip_doc, $invoice->nr_doc)] = $invoice->id;
                });
        }

        $now = Carbon::now();
        $payload = [];

        foreach ($allocations as $row) {
            $lineId = $lineIds[self::key($row->data_doc_fin, $row->tip_doc_fin, $row->nr_doc_fin)] ?? null;

            if ($lineId === null) {
                continue;
            }

            $payload[] = [
                'bank_statement_line_id' => $lineId,
                'invoice_id' => $invoices[self::key($row->data_doc_com, $row->tip_doc_com, $row->nr_doc_com)] ?? null,
                'data_doc_com' => self::day($row->data_doc_com),
                'tip_doc_com' => $row->tip_doc_com,
                'nr_doc_com' => $row->nr_doc_com,
                'val_fin' => $row->val_fin ?? 0,
                'val_com' => $row->val_com ?? 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($payload, 500) as $chunk) {
            BankStatementLineAllocation::query()->insert($chunk);
        }
    }

    /**
     * Received e-invoices of the window. Only messages not stored yet are
     * read in full (their XML gives the totals and the seller, then stays in
     * OMC: the page fetches it when it is opened); the unmatched ones are
     * matched again, as their invoice may have been entered since.
     */
    private function syncEInvoices(Company $company, ConnectionInterface $remote, Carbon $from, Carbon $to): int
    {
        $rows = $remote->table('view_anaf_e_fact_furn_msg')
            ->select([
                'msg_id', 'msg_cif', 'msg_index_incarcare', 'msg_data_creare_d',
                'msg_detalii', 'data_ins_omc', 'err_ins_omc',
                'data_doc_xml', 'tip_doc_xml', 'nr_doc_xml', 'partener_xml', 'cod_cci_xml',
            ])
            ->where('msg_tip', 'FACTURA PRIMITA')
            ->whereBetween('msg_data_creare_d', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->orderBy('msg_data_creare_d')
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        $stored = EInvoice::query()
            ->where('company_id', $company->id)
            ->whereIn('msg_id', $rows->pluck('msg_id')->all())
            ->get()
            ->keyBy('msg_id');

        $new = $rows->reject(fn (object $row) => $stored->has($row->msg_id));
        $xml = [];

        foreach ($new->pluck('msg_id')->chunk(100) as $chunk) {
            $xml += $remote->table('view_anaf_e_fact_furn_msg')->whereIn('msg_id', $chunk->all())->pluck('msg_xml', 'msg_id')->all();
        }

        $partnerLookup = new PartnerCuiLookup($company->id);

        foreach ($rows as $row) {
            $eInvoice = $stored->get($row->msg_id);

            if ($eInvoice === null) {
                $supplierCui = EInvoice::extractEmitentCui($row->msg_detalii);
                $message = $xml[$row->msg_id] ?? null;
                $totals = $this->xmlParser->extractTotals($message);

                $eInvoice = EInvoice::create([
                    'company_id' => $company->id,
                    'msg_id' => $row->msg_id,
                    'partner_id' => $partnerLookup->find($supplierCui)
                        ?? $partnerLookup->find($row->cod_cci_xml)
                        ?? $partnerLookup->find($this->xmlParser->extractSellerTaxId($message)),
                    'msg_cif' => $row->msg_cif,
                    'supplier_cui' => $supplierCui,
                    'msg_index_incarcare' => $row->msg_index_incarcare,
                    'msg_data_creare_d' => $row->msg_data_creare_d,
                    'data_doc_xml' => $row->data_doc_xml,
                    'tip_doc_xml' => $row->tip_doc_xml,
                    'nr_doc_xml' => $row->nr_doc_xml !== null ? trim((string) $row->nr_doc_xml) : null,
                    'partener_xml' => $row->partener_xml,
                    'cod_cci_xml' => $row->cod_cci_xml,
                    'total_amount' => $totals['total_amount'],
                    'total_vat' => $totals['total_vat'],
                    'currency' => $totals['currency'],
                    'msg_detalii' => $row->msg_detalii,
                    'data_ins_omc' => $row->data_ins_omc,
                    'err_ins_omc' => $row->err_ins_omc,
                ]);
            } else {
                $eInvoice->forceFill(['data_ins_omc' => $row->data_ins_omc, 'err_ins_omc' => $row->err_ins_omc]);

                if ($eInvoice->isDirty()) {
                    $eInvoice->save();
                }

                if ($eInvoice->invoice_id !== null) {
                    continue;
                }
            }

            $matchedInvoiceId = $this->matcher->find($eInvoice)?->id;

            if ($eInvoice->invoice_id !== $matchedInvoiceId) {
                $eInvoice->forceFill(['invoice_id' => $matchedInvoiceId])->save();
            }
        }

        return $rows->count();
    }

    /** Documents per page read from OMC. */
    private function page(): int
    {
        return max(1, (int) config('sync.page_size', 1000));
    }

    private function connect(Company $company): ConnectionInterface
    {
        $remote = $this->remote->connection($company);

        try {
            $remote->getPdo();
        } catch (\Throwable $e) {
            throw new \RuntimeException("Cannot reach remote database for company {$company->getKey()}: {$e->getMessage()}", 0, $e);
        }

        return $remote;
    }

    /**
     * Restrict a query to a list of three-part keys.
     *
     * @template T of \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder
     *
     * @param  T  $query
     * @param  array{0: string, 1: string, 2: string}  $columns
     * @param  list<array{0: string, 1: string, 2: string}>  $keys
     * @return T
     */
    private function whereKeys($query, array $columns, array $keys)
    {
        if ($keys === []) {
            return $query->whereRaw('1 = 0');
        }

        $placeholders = implode(', ', array_fill(0, count($keys), '(?, ?, ?)'));

        return $query->whereRaw(sprintf('(%s) in (%s)', implode(', ', $columns), $placeholders), array_merge(...array_map('array_values', $keys)));
    }

    private static function historyKey(Company $company): string
    {
        return "erp:sync:history:{$company->id}";
    }

    /**
     * A document key as both databases agree on it: the date as a day, the
     * number without the trailing spaces the local collation ignores.
     */
    private static function key(mixed $date, mixed $type, mixed $number): string
    {
        return self::day($date).'|'.$type.'|'.rtrim((string) $number);
    }

    /**
     * A partner name as the local collation compares it.
     */
    private static function name(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    private static function day(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return Carbon::parse((string) $value)->toDateString();
    }

    private static function text(mixed $value, int $length = 255): ?string
    {
        $value = $value !== null ? trim((string) $value) : '';

        return $value === '' || $value === '-' ? null : mb_substr($value, 0, $length);
    }
}
