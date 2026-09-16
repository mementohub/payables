<?php

namespace App\Services\Etrip;

use App\Models\Company;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Read-only access to the eTrip reservation database a company is linked to.
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

    private const RATE_SQL = <<<'SQL'
        select hr.rate / hr.multiplier as ron_per_unit
        from settings.historic_rates hr
        where hr.currency = ? and hr.date <= ?::date
        order by hr.date desc
        limit 1
        SQL;

    /** @var array<string, true> */
    private array $prepared = [];

    public function connection(Company $company): ConnectionInterface
    {
        $name = $company->etripConnection()
            ?? throw new RuntimeException("Compania {$company->name} nu este legată de o bază eTrip.");

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
    public function suppliers(Company $company): array
    {
        return array_map(fn ($row) => [
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'vat_no' => $row->vat_no !== null ? (string) $row->vat_no : null,
            'company_no' => $row->company_no !== null ? (string) $row->company_no : null,
            'currency' => $row->currency !== null ? (string) $row->currency : null,
            'country' => $row->country !== null ? (string) $row->country : null,
            'active' => (bool) $row->active,
        ], $this->connection($company)->select(self::SUPPLIERS_SQL));
    }

    /**
     * Product type labels keyed by id, cached per eTrip database.
     *
     * @return array<int, string>
     */
    public function productTypes(Company $company): array
    {
        $name = $company->etripConnection() ?? 'none';

        return Cache::remember("etrip:{$name}:product-types", now()->addDay(), function () use ($company) {
            $labels = [];

            foreach ($this->connection($company)->select(self::PRODUCT_TYPES_SQL) as $row) {
                $labels[(int) $row->id] = (string) $row->label;
            }

            return $labels;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function costLines(Company $company, string $supplierCode, Carbon $from, Carbon $to): array
    {
        $rows = $this->connection($company)->select(self::COST_LINES_SQL, [
            $supplierCode,
            $from->toDateString(),
            $to->copy()->addDay()->toDateString(),
        ]);

        return array_map(fn ($row) => (array) $row, $rows);
    }

    /**
     * @return list<array{supplier_code: string, supplier_name: ?string, currency: ?string, cost: float, bookings: int, items: int}>
     */
    public function expectedCosts(Company $company, Carbon $from, Carbon $to, int $limit = 25): array
    {
        $rows = $this->connection($company)->select(self::EXPECTED_SQL, [
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
     * BNR rate (lei per one unit of the currency) on or before the date; 1 for lei.
     */
    public function ronPerUnit(Company $company, string $currency, Carbon $date): ?float
    {
        if (in_array(strtoupper($currency), ['RON', 'LEI'], true)) {
            return 1.0;
        }

        $row = $this->connection($company)->selectOne(self::RATE_SQL, [strtoupper($currency), $date->toDateString()]);

        return $row && $row->ron_per_unit !== null ? (float) $row->ron_per_unit : null;
    }
}
