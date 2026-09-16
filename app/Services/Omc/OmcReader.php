<?php

namespace App\Services\Omc;

use App\Models\Company;
use App\Services\SyncService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-only access to the OMC accounting database (the `omc` connection):
 * supplier invoices as the ERP has them right now.
 *
 * `doc.val_mon_pl` and `doc.val_mon_dimin_negru` are kept by triggers as the
 * sums of doc_fin (payments) and doc_dimin (credit notes offset), so the open
 * amount of an invoice is val_mon - val_mon_pl - val_mon_dimin_negru without
 * joining those tables. Cancelled documents (data_anulare) are ignored.
 */
class OmcReader
{
    /**
     * Suppliers with invoices since a date whose name or VAT number contains
     * the term, the most recently invoiced first. The date range is the
     * leading column of the doc primary key, so this is an index range scan.
     */
    private const SUPPLIERS_SQL = <<<'SQL'
        select s.partener as name, p.cod_cci as cui, p.tara as country, p.localit as city,
               s.invoices, s.last_invoice
        from (
            select d.partener, count(*) as invoices, max(d.data_doc) as last_invoice
            from doc d
            where d.data_doc >= ?::date
              and d.tip_doc in (%s)
              and d.partener is not null
              and d.data_anulare is null
            group by d.partener
        ) s
        join partener p on p.partener = s.partener
        where s.partener ilike ? or p.cod_cci ilike ?
        order by s.last_invoice desc, s.partener
        limit ?
        SQL;

    private const SUPPLIER_SQL = <<<'SQL'
        select p.partener as name, p.cod_cci as cui, p.tara as country, p.localit as city,
               p.da_nu_persoana_juridica as is_company
        from partener p
        where p.partener = ?
        SQL;

    /**
     * A supplier's invoices dated since a date plus every older one still
     * open, newest first, with the last payment date, the first line and the
     * expense accounts of each.
     */
    private const SUPPLIER_INVOICES_SQL = <<<'SQL'
        select d.data_doc, d.tip_doc, d.nr_doc, d.moneda, d.curs, d.val_mon, d.val_mon_tva,
               coalesce(d.val_mon_pl, 0) as val_mon_pl,
               coalesce(d.val_mon_dimin_negru, 0) as val_mon_dimin_negru,
               d.data_scadenta,
               (select max(df.data_repartizare) from doc_fin df
                 where df.data_doc_com = d.data_doc and df.tip_doc_com = d.tip_doc and df.nr_doc_com = d.nr_doc) as paid_at,
               (select btrim(coalesce(nullif(btrim(p.detaliu_articol), ''), p.articol)) from doc_poz p
                 where p.data_doc = d.data_doc and p.tip_doc = d.tip_doc and p.nr_doc = d.nr_doc
                 order by p.scv limit 1) as description,
               (select string_agg(distinct p.conts::text, ', ') from doc_poz p
                 where p.data_doc = d.data_doc and p.tip_doc = d.tip_doc and p.nr_doc = d.nr_doc
                   and p.conts is not null) as accounts
        from doc d
        where d.partener = ?
          and d.tip_doc in (%s)
          and d.data_anulare is null
          and (d.data_doc >= ?::date
               or d.val_mon - coalesce(d.val_mon_pl, 0) - coalesce(d.val_mon_dimin_negru, 0) > 0.01)
        order by d.data_doc desc, d.nr_doc desc
        SQL;

    /**
     * Every open supplier invoice dated since a date, with its supplier.
     */
    private const OPEN_INVOICES_SQL = <<<'SQL'
        select d.partener, p.cod_cci, d.data_doc, d.tip_doc, d.nr_doc, d.moneda, d.curs, d.val_mon,
               coalesce(d.val_mon_pl, 0) as val_mon_pl,
               coalesce(d.val_mon_dimin_negru, 0) as val_mon_dimin_negru,
               d.data_scadenta
        from doc d
        left join partener p on p.partener = d.partener
        where d.data_doc >= ?::date
          and d.tip_doc in (%s)
          and d.partener is not null
          and d.data_anulare is null
          and d.val_mon - coalesce(d.val_mon_pl, 0) - coalesce(d.val_mon_dimin_negru, 0) > 0.01
        order by d.partener, d.data_scadenta, d.data_doc
        SQL;

    private bool $prepared = false;

    public function name(): string
    {
        return (string) config('omc.connection', 'omc');
    }

    public function connection(): ConnectionInterface
    {
        $connection = DB::connection($this->name());

        if (! $this->prepared) {
            $connection->statement('SET statement_timeout = '.(int) config('omc.statement_timeout_ms', 45000));
            $this->prepared = true;
        }

        return $connection;
    }

    /**
     * Database and host, for the page header.
     */
    public function label(): string
    {
        $config = (array) config('database.connections.'.$this->name(), []);

        return sprintf('%s @ %s:%s', $config['database'] ?? $this->name(), $config['host'] ?? '?', $config['port'] ?? '?');
    }

    /**
     * The local company mirrored from the OMC database (see config/omc.php).
     */
    public function company(): ?Company
    {
        if ((int) config('omc.company_id') > 0) {
            return Company::query()->find((int) config('omc.company_id'));
        }

        $database = (string) config('database.connections.'.$this->name().'.database');

        try {
            $linked = Company::query()->where('erp_connection', $this->name())->orderBy('id')->first();
        } catch (QueryException) {
            $linked = null; // the column arrives with a migration; keep the page usable until app:upgrade runs
        }

        return $linked
            ?? Company::query()->where('db_database', $database)->orderBy('id')->first()
            ?? Company::query()->where('etrip_connection', 'etrip_chr')->orderBy('id')->first()
            ?? (Company::query()->count() === 1 ? Company::query()->first() : null);
    }

    /**
     * @return list<array{name: string, cui: ?string, country: ?string, city: ?string, invoices: int, last_invoice: ?string}>
     */
    public function searchSuppliers(string $term, int $limit = 30): array
    {
        $since = Carbon::today()->subYears(max(1, (int) config('omc.supplier_years', 3)));
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($term)).'%';

        $rows = $this->connection()->select(
            sprintf(self::SUPPLIERS_SQL, $this->docTypes()),
            [$since->toDateString(), $like, $like, $limit],
        );

        return array_map(fn ($row) => [
            'name' => (string) $row->name,
            'cui' => $this->text($row->cui),
            'country' => $this->text($row->country),
            'city' => $this->text($row->city),
            'invoices' => (int) $row->invoices,
            'last_invoice' => $row->last_invoice ? Carbon::parse((string) $row->last_invoice)->toDateString() : null,
        ], $rows);
    }

    /**
     * @return array{name: string, cui: ?string, country: ?string, city: ?string, is_company: bool}|null
     */
    public function supplier(string $name): ?array
    {
        $row = $this->connection()->selectOne(self::SUPPLIER_SQL, [$name]);

        return $row ? [
            'name' => (string) $row->name,
            'cui' => $this->text($row->cui),
            'country' => $this->text($row->country),
            'city' => $this->text($row->city),
            'is_company' => (bool) $row->is_company,
        ] : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function supplierInvoices(string $name, Carbon $since): array
    {
        $rows = $this->connection()->select(
            sprintf(self::SUPPLIER_INVOICES_SQL, $this->docTypes()),
            [$name, $since->toDateString()],
        );

        return array_map(fn ($row) => (array) $row, $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function openSupplierInvoices(Carbon $since): array
    {
        $rows = $this->connection()->select(
            sprintf(self::OPEN_INVOICES_SQL, $this->docTypes()),
            [$since->toDateString()],
        );

        return array_map(fn ($row) => (array) $row, $rows);
    }

    private function docTypes(): string
    {
        return implode(', ', array_map(fn (string $type) => "'".$type."'", SyncService::FURNIZOR_DOC_TYPES));
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
