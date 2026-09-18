<?php

namespace App\Services\Etrip;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Read-only access to an eTrip reservation database (config/etrip.php).
 *
 * Every query here runs against a hot-standby replica; keep them filtered on
 * indexed columns (supplier, start_date) and bounded by the statement timeout.
 */
class EtripReader
{
    private const SUPPLIERS_SQL = <<<'SQL'
        select s.code, s.name, s.vat_no, s.company_no, s.currency, s.country, s.active
        from suppliers.suppliers s
        order by s.name
        SQL;

    private const PRODUCT_TYPES_SQL = 'select id, label from public.product_types order by id';

    private const SUPPLIER_SQL = <<<'SQL'
        select s.code, s.name, s.vat_no, s.company_no, s.currency, s.country, s.active
        from suppliers.suppliers s
        where s.code = ?
        SQL;

    /**
     * One row per sold service of the supplier with check-in in the range:
     * cost in the supplier currency (gross + tax - commission), plus the
     * hotel / room / meal or transfer detail used for the breakdown.
     */
    private const COST_LINES_SQL = <<<'SQL'
        select i.booking,
               i.id as item,
               i.product_type,
               i.start_date::date as start_date,
               i.end_date::date as end_date,
               i.supplier_currency as currency,
               (coalesce((i.cost).gross, 0) + coalesce((i.cost).tax, 0) - coalesce((i.cost).commission, 0))::numeric(20,2) as cost,
               coalesce(h.name, ai.hotel_name) as hotel,
               (select string_agg(r.room_name, ', ' order by r.id) from accommodation.rooms r where r.item = i.id) as room,
               coalesce(ai.meal_basis, ai.meal_basis_code) as meal,
               ti.transfer_name as transfer,
               i.short_description as description,
               (select count(*) from bookings.item_pax ip where ip.item = i.id) as pax,
               (select btrim(coalesce(p.fname, '') || ' ' || coalesce(p.lname, ''))
                  from bookings.pax p where p.booking = b.id and p.lead order by p.id limit 1) as lead
        from bookings.items i
        join bookings.bookings b on b.id = i.booking
        left join accommodation.items ai on ai.item = i.id
        left join settings.hotels h on h.id = ai.hotel
        left join transfer.items ti on ti.item = i.id
        where i.supplier = ?
          and i.start_date >= ?::timestamp
          and i.start_date < ?::timestamp
          and b.status = 'confirmed'
          and i.client_status = 'confirmed'
          and i.supplier_status <> 'cancelled'
          and (coalesce((i.cost).gross, 0) + coalesce((i.cost).tax, 0) - coalesce((i.cost).commission, 0)) <> 0
        order by i.start_date, i.booking, i.id
        SQL;

    /**
     * Suppliers with the largest check-in cost in a date range, across all
     * products, in the supplier currency.
     */
    private const EXPECTED_SQL = <<<'SQL'
        select i.supplier as supplier_code,
               s.name as supplier_name,
               i.supplier_currency as currency,
               sum(coalesce((i.cost).gross, 0) + coalesce((i.cost).tax, 0) - coalesce((i.cost).commission, 0))::numeric(20,2) as cost,
               count(distinct i.booking) as bookings,
               count(*) as items
        from bookings.items i
        join bookings.bookings b on b.id = i.booking
        left join suppliers.suppliers s on s.code = i.supplier
        where i.start_date >= ?::timestamp
          and i.start_date < ?::timestamp
          and b.status = 'confirmed'
          and i.client_status = 'confirmed'
          and i.supplier_status <> 'cancelled'
        group by i.supplier, s.name, i.supplier_currency
        having sum(coalesce((i.cost).gross, 0) + coalesce((i.cost).tax, 0) - coalesce((i.cost).commission, 0)) <> 0
        order by abs(sum(coalesce((i.cost).gross, 0) + coalesce((i.cost).tax, 0) - coalesce((i.cost).commission, 0))) desc
        limit ?
        SQL;

    /**
     * How the supplier works with us: payment term, self-billing, extranet
     * access and whether its invoices go to the second OMC base.
     */
    private const SUPPLIER_PROFILE_SQL = <<<'SQL'
        select s.code, s.name, s.country, s.currency, s.active,
               s.balance_due::text as balance_due,
               (extract(epoch from s.balance_due) / 86400)::int as balance_due_days,
               s.self_billing, s.vat_rate, s.vat_no, s.company_no, s.web_access,
               s.use_secondary_omc_connection, s.ctime::date as created_at
        from suppliers.suppliers s
        where s.code = ?
        SQL;

    /**
     * The supplier's invoices dated in the range, each with the services its
     * lines point to and what the payments allocated to it add up to.
     */
    private const SUPPLIER_INVOICES_SQL = <<<'SQL'
        with si as (
            select * from financials.supplier_invoices
            where supplier = ? and date >= ?::date and date < ?::date
        ),
        l as (
            select ii.invoice,
                   sum(ii.amount * coalesce(ii.quantity, 1))::numeric(20,2) as lines_value,
                   count(*) as lines,
                   count(distinct i.booking) as bookings,
                   min(i.start_date)::date as checkin_from,
                   max(i.start_date)::date as checkin_to,
                   sum(coalesce((i.cost).gross, 0) + coalesce((i.cost).tax, 0) - coalesce((i.cost).commission, 0))::numeric(20,2) as etrip_cost
            from financials.supplier_invoice_items ii
            join si on si.id = ii.invoice
            left join bookings.items i on i.id = ii.item
            group by 1
        ),
        p as (
            select pi.invoice, sum(pi.invoice_amount)::numeric(20,2) as paid
            from financials.payment_invoices pi
            join si on si.id = pi.invoice
            where not coalesce(pi.deleted, false)
            group by 1
        )
        select si.id, si.number, si.date, si.currency, si.amount::numeric(20,2) as amount,
               si.due_date::text as due_date, si.finalized, si.good_for_payment,
               coalesce(l.lines, 0) as lines, coalesce(l.bookings, 0) as bookings,
               l.checkin_from, l.checkin_to, l.etrip_cost,
               coalesce(p.paid, 0) as paid
        from si
        left join l on l.invoice = si.id
        left join p on p.invoice = si.id
        order by si.date desc, si.id desc
        SQL;

    /**
     * The supplier's services (same perimeter as the check-in check, but
     * keeping services the supplier has not confirmed yet) from the start of
     * the range, done up to the end of it or still to come, against what its
     * invoice lines bill on each of them; grouped by check-in month, product
     * type, currency and booking source.
     */
    private const SERVICE_COVERAGE_SQL = <<<'SQL'
        with it as (
            select i.id, i.supplier_currency as currency, i.start_date::date as checkin,
                   i.product_type, i.source,
                   (coalesce((i.cost).gross, 0) + coalesce((i.cost).tax, 0) - coalesce((i.cost).commission, 0))::numeric as cost
            from bookings.items i
            join bookings.bookings b on b.id = i.booking
            where i.supplier = ?
              and i.start_date >= ?::timestamp
              and (i.start_date < ?::timestamp or i.start_date >= ?::timestamp)
              and b.status = 'confirmed'
              and i.client_status = 'confirmed'
              and coalesce(i.supplier_status, '') <> 'cancelled'
        ),
        inv as (
            select ii.item, sum(ii.amount * coalesce(ii.quantity, 1))::numeric as invoiced
            from financials.supplier_invoice_items ii
            join financials.supplier_invoices si on si.id = ii.invoice
            where si.supplier = ?
            group by 1
        )
        select to_char(it.checkin, 'YYYY-MM') as month,
               it.product_type,
               it.currency,
               it.checkin < ?::date as done,
               it.source,
               count(*) as services,
               sum(it.cost)::numeric(20,2) as cost,
               sum(coalesce(inv.invoiced, 0))::numeric(20,2) as invoiced,
               count(*) filter (where inv.item is null) as unbilled_services,
               coalesce(sum(it.cost) filter (where inv.item is null), 0)::numeric(20,2) as unbilled_cost
        from it
        left join inv on inv.item = it.id
        group by 1, 2, 3, 4, 5
        order by 1, 3, 2
        SQL;

    private const SUPPLIER_PAYMENTS_SQL = <<<'SQL'
        select p.currency, count(*) as payments, sum(p.amount)::numeric(20,2) as total,
               min(p.date) as first, max(p.date) as last
        from financials.payments p
        where p.supplier = ? and p.date >= ?::date and p.date < ?::date
        group by 1
        order by 1
        SQL;

    private const RATE_SQL = <<<'SQL'
        select hr.rate / hr.multiplier as ron_per_unit
        from settings.historic_rates hr
        where hr.currency = ? and hr.date <= ?::date
        order by hr.date desc
        limit 1
        SQL;

    /** @var array<string, true> */
    private array $prepared = [];

    public function connection(string $name): ConnectionInterface
    {
        if (! array_key_exists($name, (array) config('etrip.connections'))) {
            throw new RuntimeException("Baza eTrip „{$name}” nu este definită.");
        }

        $connection = DB::connection($name);

        if (! isset($this->prepared[$name])) {
            $connection->statement('SET statement_timeout = '.(int) config('etrip.statement_timeout_ms', 30000));
            $this->prepared[$name] = true;
        }

        return $connection;
    }

    /**
     * @return list<array{code: string, name: string, vat_no: ?string, company_no: ?string, currency: ?string, country: ?string, active: bool}>
     */
    public function suppliers(string $connection): array
    {
        return array_map(fn ($row) => $this->supplierRow($row), $this->connection($connection)->select(self::SUPPLIERS_SQL));
    }

    /**
     * @return array{code: string, name: string, vat_no: ?string, company_no: ?string, currency: ?string, country: ?string, active: bool}
     */
    private function supplierRow(object $row): array
    {
        return [
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'vat_no' => $row->vat_no !== null ? (string) $row->vat_no : null,
            'company_no' => $row->company_no !== null ? (string) $row->company_no : null,
            'currency' => $row->currency !== null ? (string) $row->currency : null,
            'country' => $row->country !== null ? (string) $row->country : null,
            'active' => (bool) $row->active,
        ];
    }

    /**
     * @return array{code: string, name: string, vat_no: ?string, company_no: ?string, currency: ?string, country: ?string, active: bool}|null
     */
    public function supplier(string $connection, string $code): ?array
    {
        $row = $this->connection($connection)->selectOne(self::SUPPLIER_SQL, [$code]);

        return $row ? $this->supplierRow($row) : null;
    }

    /**
     * Product type labels keyed by id, cached per eTrip database.
     *
     * @return array<int, string>
     */
    public function productTypes(string $connection): array
    {
        return Cache::remember("etrip:{$connection}:product-types", now()->addDay(), function () use ($connection) {
            $labels = [];

            foreach ($this->connection($connection)->select(self::PRODUCT_TYPES_SQL) as $row) {
                $labels[(int) $row->id] = (string) $row->label;
            }

            return $labels;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function costLines(string $connection, string $supplierCode, Carbon $from, Carbon $to): array
    {
        $rows = $this->connection($connection)->select(self::COST_LINES_SQL, [
            $supplierCode,
            $from->toDateString(),
            $to->copy()->addDay()->toDateString(),
        ]);

        return array_map(fn ($row) => (array) $row, $rows);
    }

    /**
     * @return list<array{supplier_code: string, supplier_name: ?string, currency: ?string, cost: float, bookings: int, items: int}>
     */
    public function expectedCosts(string $connection, Carbon $from, Carbon $to, int $limit = 25): array
    {
        $rows = $this->connection($connection)->select(self::EXPECTED_SQL, [
            $from->toDateString(),
            $to->copy()->addDay()->toDateString(),
            $limit,
        ]);

        return array_map(fn ($row) => [
            'supplier_code' => (string) $row->supplier_code,
            'supplier_name' => $row->supplier_name !== null ? (string) $row->supplier_name : null,
            'currency' => $row->currency !== null ? (string) $row->currency : null,
            'cost' => round((float) $row->cost, 2),
            'bookings' => (int) $row->bookings,
            'items' => (int) $row->items,
        ], $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function supplierProfile(string $connection, string $code): ?array
    {
        $row = $this->connection($connection)->selectOne(self::SUPPLIER_PROFILE_SQL, [$code]);

        return $row ? (array) $row : null;
    }

    /**
     * Invoices dated from `$from` up to and including `$to`.
     *
     * @return list<array<string, mixed>>
     */
    public function supplierInvoices(string $connection, string $code, Carbon $from, Carbon $to): array
    {
        $rows = $this->connection($connection)->select(self::SUPPLIER_INVOICES_SQL, [
            $code,
            $from->toDateString(),
            $to->copy()->addDay()->toDateString(),
        ]);

        return array_map(fn ($row) => (array) $row, $rows);
    }

    /**
     * Services with check-in from `$from` up to `$to` and no later than
     * `$today` (done), plus every service with check-in after `$today` (to
     * come).
     *
     * @return list<array<string, mixed>>
     */
    public function serviceCoverage(string $connection, string $code, Carbon $from, Carbon $to, Carbon $today): array
    {
        $tomorrow = $today->copy()->addDay();
        $doneUntil = $to->copy()->addDay()->min($tomorrow);

        $rows = $this->connection($connection)->select(self::SERVICE_COVERAGE_SQL, [
            $code,
            $from->toDateString(),
            $doneUntil->toDateString(),
            $tomorrow->toDateString(),
            $code,
            $tomorrow->toDateString(),
        ]);

        return array_map(fn ($row) => (array) $row, $rows);
    }

    /**
     * Payments made to the supplier from `$from` up to and including `$to`.
     *
     * @return list<array<string, mixed>>
     */
    public function supplierPayments(string $connection, string $code, Carbon $from, Carbon $to): array
    {
        $rows = $this->connection($connection)->select(self::SUPPLIER_PAYMENTS_SQL, [
            $code,
            $from->toDateString(),
            $to->copy()->addDay()->toDateString(),
        ]);

        return array_map(fn ($row) => (array) $row, $rows);
    }

    /**
     * BNR rate (lei per one unit of the currency) on or before the date; 1 for lei.
     */
    public function ronPerUnit(string $connection, string $currency, Carbon $date): ?float
    {
        if (in_array(strtoupper($currency), ['RON', 'LEI'], true)) {
            return 1.0;
        }

        $row = $this->connection($connection)->selectOne(self::RATE_SQL, [strtoupper($currency), $date->toDateString()]);

        return $row && $row->ron_per_unit !== null ? (float) $row->ron_per_unit : null;
    }
}
