<?php

namespace App\Services\CashFlow;

use Carbon\CarbonInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Read-only eTrip queries behind the cash-flow report. Every query starts from
 * an indexed booking or item column and is aggregated in SQL, so the report
 * pulls a few hundred rows per base, not the bookings themselves.
 */
class EtripCashFlowReader
{
    /** @var array<string, true> */
    private array $prepared = [];

    public function connection(string $name): ConnectionInterface
    {
        $connection = DB::connection($name);

        if (! isset($this->prepared[$name])) {
            $connection->statement('SET statement_timeout = '.(int) config('cashflow.statement_timeout_ms', 240000));
            $this->prepared[$name] = true;
        }

        return $connection;
    }

    /**
     * Latest BNR rate of every currency (lei per unit).
     *
     * @return array<string, float>
     */
    public function rates(string $name): array
    {
        $rows = $this->connection($name)->select(<<<'SQL'
            select distinct on (hr.currency) hr.currency, hr.rate / hr.multiplier as ron_per_unit
            from settings.historic_rates hr
            where hr.multiplier <> 0
            order by hr.currency, hr.date desc
            SQL);

        $rates = [];

        foreach ($rows as $row) {
            $rates[strtoupper((string) $row->currency)] = (float) $row->ron_per_unit;
        }

        return $rates;
    }

    /**
     * Confirmed bookings with an unpaid client balance (departures within
     * the configured window), with the amounts scheduled in eTrip, the
     * product type of their most expensive root item and the channel.
     *
     * @return list<array{id: int, currency: string, total_due: float, paid: float, balance_due_date: ?string, start_date: ?string, brand: ?int, channel: ?int, segment_type: ?int, due_dates: list<array{date: string, amount: float}>}>
     */
    public function openBookings(string $name, CarbonInterface $from, CarbonInterface $to, float $minBalance = 0.5): array
    {
        $rows = $this->connection($name)->select(<<<'SQL'
            with b as (
                select b.id, b.currency, b.total_amount_due, b.paid_amount, b.balance_due_date, b.start_date, b.brand, b.booked_via
                from bookings.bookings b
                where b.status = 'confirmed'
                  and b.start_date >= ?::timestamp
                  and b.start_date < ?::timestamp
                  and b.total_amount_due - b.paid_amount > ?
            )
            select b.id, b.currency, b.total_amount_due as total_due, b.paid_amount as paid,
                   b.balance_due_date::date as balance_due_date, b.start_date::date as start_date,
                   b.brand, b.booked_via as channel,
                   (select i.product_type
                      from bookings.items i
                     where i.booking = b.id and i.package is null and i.client_status = 'confirmed'
                     order by (i.price).gross desc nulls last, i.id
                     limit 1) as segment_type,
                   (select json_agg(json_build_object('date', dd.date, 'amount', dd.amount) order by dd.date)
                      from bookings.due_dates dd
                     where dd.booking = b.id) as due_dates
            from b
            order by b.id
            SQL, [$from->toDateTimeString(), $to->toDateTimeString(), $minBalance]);

        return array_map(fn ($row) => [
            'id' => (int) $row->id,
            'currency' => strtoupper((string) $row->currency),
            'total_due' => (float) $row->total_due,
            'paid' => (float) $row->paid,
            'balance_due_date' => $row->balance_due_date !== null ? (string) $row->balance_due_date : null,
            'start_date' => $row->start_date !== null ? (string) $row->start_date : null,
            'brand' => $row->brand !== null ? (int) $row->brand : null,
            'channel' => $row->channel !== null ? (int) $row->channel : null,
            'segment_type' => $row->segment_type !== null ? (int) $row->segment_type : null,
            'due_dates' => array_map(fn (array $due) => [
                'date' => (string) $due['date'],
                'amount' => (float) $due['amount'],
            ], is_string($row->due_dates) ? (json_decode($row->due_dates, true) ?: []) : []),
        ], $rows);
    }

    /**
     * Supplier cost of the confirmed services with check-in in the range,
     * net of what eTrip already recorded as paid, by the week the supplier
     * is paid (check-in minus the given days), category and supplier
     * currency. Charter seats (paid per contract) and line tickets (paid
     * at order) are left out.
     *
     * @return list<array{week: string, category: string, currency: string, cost: float, items: int, bookings: int}>
     */
    public function payables(string $name, CarbonInterface $firstCheckin, CarbonInterface $lastCheckin, int $daysBefore): array
    {
        $rows = $this->connection($name)->select(sprintf(<<<'SQL'
            select (date_trunc('week', i.start_date - interval '%1$d days'))::date as week,
                   %2$s as category,
                   i.supplier_currency as currency,
                   sum(%3$s)::numeric(20,2) as cost,
                   count(*) as items,
                   count(distinct i.booking) as bookings
            from bookings.items i
            join bookings.bookings b on b.id = i.booking
            where i.start_date >= ?::timestamp
              and i.start_date < ?::timestamp
              and b.status = 'confirmed'
              and i.client_status = 'confirmed'
              and i.supplier_status <> 'cancelled'
              and %4$s
            group by 1, 2, 3
            having sum(%3$s) <> 0
            order by 1, 2, 3
            SQL, max(0, $daysBefore), $this->categoryCase(), $this->costExpression(), $this->payableTypesClause()),
            [$firstCheckin->toDateTimeString(), $lastCheckin->toDateTimeString()]);

        return array_map(fn ($row) => [
            'week' => (string) $row->week,
            'category' => (string) $row->category,
            'currency' => strtoupper((string) ($row->currency ?? 'RON')),
            'cost' => (float) $row->cost,
            'items' => (int) $row->items,
            'bookings' => (int) $row->bookings,
        ], $rows);
    }

    /**
     * Line flight tickets confirmed in the range: paid to the airline as soon
     * as they are issued.
     *
     * @return list<array{week: string, currency: string, cost: float, items: int}>
     */
    public function ticketsOrdered(string $name, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = $this->connection($name)->select(sprintf(<<<'SQL'
            select (date_trunc('week', i.ctime))::date as week,
                   i.supplier_currency as currency,
                   sum(%1$s)::numeric(20,2) as cost,
                   count(*) as items
            from bookings.items i
            join bookings.bookings b on b.id = i.booking
            where i.ctime >= ?::timestamp
              and i.ctime < ?::timestamp
              and b.status = 'confirmed'
              and i.client_status = 'confirmed'
              and i.supplier_status <> 'cancelled'
              and i.product_type in (%2$s)
            group by 1, 2
            having sum(%1$s) <> 0
            order by 1, 2
            SQL, $this->costExpression(), $this->idList('flight')),
            [$from->toDateTimeString(), $to->toDateTimeString()]);

        return array_map(fn ($row) => [
            'week' => (string) $row->week,
            'currency' => strtoupper((string) ($row->currency ?? 'RON')),
            'cost' => (float) $row->cost,
            'items' => (int) $row->items,
        ], $rows);
    }

    /**
     * What the bookings created in a past period collected and cost, week by
     * week: the booking curve the new-sales scenario is built from. Receipts
     * carry the segment of their booking (the product type of its most
     * expensive root item, as in openBookings()), costs their category.
     *
     * @return array{
     *   receipts: list<array{week: string, segment_type: ?int, currency: string, amount: float, receipts: int}>,
     *   costs: list<array{week: string, category: string, currency: string, cost: float, items: int}>,
     *   tickets: list<array{week: string, currency: string, cost: float, items: int}>,
     *   bookings: int
     * }
     */
    public function bookingCurve(string $name, CarbonInterface $createdFrom, CarbonInterface $createdTo, int $daysBefore): array
    {
        $connection = $this->connection($name);
        $range = [$createdFrom->toDateTimeString(), $createdTo->toDateTimeString()];

        $count = $connection->selectOne(<<<'SQL'
            select count(*) as bookings
            from bookings.bookings b
            where b.ctime >= ?::timestamp and b.ctime < ?::timestamp and b.status = 'confirmed'
            SQL, $range);

        $receipts = $connection->select(<<<'SQL'
            with b as (
                select b.id
                from bookings.bookings b
                where b.ctime >= ?::timestamp and b.ctime < ?::timestamp and b.status = 'confirmed'
            ),
            s as (
                select distinct on (i.booking) i.booking, i.product_type
                from bookings.items i
                join b on b.id = i.booking
                where i.package is null and i.client_status = 'confirmed'
                order by i.booking, (i.price).gross desc nulls last, i.id
            )
            select (date_trunc('week', r.issue_date))::date as week,
                   s.product_type as segment_type,
                   r.currency,
                   sum(rb.receipt_amount)::numeric(20,2) as amount,
                   count(*) as receipts
            from b
            left join s on s.booking = b.id
            join financials.receipt_bookings rb on rb.booking = b.id
            join financials.receipts r on r.id = rb.receipt
            group by 1, 2, 3
            order by 1, 2, 3
            SQL, $range);

        $costs = $connection->select(sprintf(<<<'SQL'
            with b as (
                select b.id
                from bookings.bookings b
                where b.ctime >= ?::timestamp and b.ctime < ?::timestamp and b.status = 'confirmed'
            )
            select (date_trunc('week', i.start_date - interval '%1$d days'))::date as week,
                   %2$s as category,
                   i.supplier_currency as currency,
                   sum(%3$s)::numeric(20,2) as cost,
                   count(*) as items
            from b
            join bookings.items i on i.booking = b.id
            where i.client_status = 'confirmed'
              and i.supplier_status <> 'cancelled'
              and %4$s
            group by 1, 2, 3
            having sum(%3$s) <> 0
            order by 1, 2, 3
            SQL, max(0, $daysBefore), $this->categoryCase(), $this->costExpression(), $this->payableTypesClause()), $range);

        $tickets = $connection->select(sprintf(<<<'SQL'
            with b as (
                select b.id
                from bookings.bookings b
                where b.ctime >= ?::timestamp and b.ctime < ?::timestamp and b.status = 'confirmed'
            )
            select (date_trunc('week', i.ctime))::date as week,
                   i.supplier_currency as currency,
                   sum(%1$s)::numeric(20,2) as cost,
                   count(*) as items
            from b
            join bookings.items i on i.booking = b.id
            where i.client_status = 'confirmed'
              and i.supplier_status <> 'cancelled'
              and i.product_type in (%2$s)
            group by 1, 2
            having sum(%1$s) <> 0
            order by 1, 2
            SQL, $this->costExpression(), $this->idList('flight')), $range);

        return [
            'receipts' => array_map(fn ($row) => [
                'week' => (string) $row->week,
                'segment_type' => $row->segment_type !== null ? (int) $row->segment_type : null,
                'currency' => strtoupper((string) $row->currency),
                'amount' => (float) $row->amount,
                'receipts' => (int) $row->receipts,
            ], $receipts),
            'costs' => array_map(fn ($row) => [
                'week' => (string) $row->week,
                'category' => (string) $row->category,
                'currency' => strtoupper((string) ($row->currency ?? 'RON')),
                'cost' => (float) $row->cost,
                'items' => (int) $row->items,
            ], $costs),
            'tickets' => array_map(fn ($row) => [
                'week' => (string) $row->week,
                'currency' => strtoupper((string) ($row->currency ?? 'RON')),
                'cost' => (float) $row->cost,
                'items' => (int) $row->items,
            ], $tickets),
            'bookings' => (int) ($count->bookings ?? 0),
        ];
    }

    /**
     * Receipts actually issued per week, in lei at the receipt's own rate,
     * by the segment of the booking they were allocated to (the product
     * type of its most expensive root item, as in openBookings()).
     *
     * @return list<array{week: string, segment_type: ?int, lei: float, receipts: int}>
     */
    public function receiptsByWeek(string $name, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = $this->connection($name)->select(<<<'SQL'
            with rc as (
                select (date_trunc('week', r.issue_date))::date as week,
                       rb.booking,
                       rb.receipt_amount * coalesce(nullif(r.exchange_rate, 0), 1) as lei
                from financials.receipts r
                join financials.receipt_bookings rb on rb.receipt = r.id
                where r.issue_date >= ?::date and r.issue_date < ?::date
            ),
            s as (
                select distinct on (i.booking) i.booking, i.product_type
                from bookings.items i
                where i.booking in (select booking from rc) and i.package is null
                order by i.booking, (i.client_status = 'confirmed') desc, (i.price).gross desc nulls last, i.id
            )
            select rc.week, s.product_type as segment_type, sum(rc.lei)::numeric(20,2) as lei, count(*) as receipts
            from rc
            left join s on s.booking = rc.booking
            group by 1, 2
            order by 1, 2
            SQL, [$from->toDateString(), $to->toDateString()]);

        return array_map(fn ($row) => [
            'week' => (string) $row->week,
            'segment_type' => $row->segment_type !== null ? (int) $row->segment_type : null,
            'lei' => (float) $row->lei,
            'receipts' => (int) $row->receipts,
        ], $rows);
    }

    /**
     * What each supplier mostly sells, by the cost of its services that
     * started since the given day: hotel, transfer, insurance, flight,
     * charter or other.
     *
     * @return list<array{code: string, name: string, vat_no: ?string, category: string}>
     */
    public function supplierCategories(string $name, CarbonInterface $since): array
    {
        $rows = $this->connection($name)->select(sprintf(<<<'SQL'
            with c as (
                select i.supplier as code, %1$s as category, sum(abs(coalesce((i.cost).gross, 0))) as cost
                from bookings.items i
                where i.supplier is not null and i.start_date >= ?::date and i.supplier_status <> 'cancelled'
                group by 1, 2
            )
            select distinct on (c.code) c.code, s.name, s.vat_no, c.category
            from c
            join suppliers.suppliers s on s.code = c.code
            order by c.code, c.cost desc
            SQL, $this->categoryCase(['charter', 'hotel', 'transfer', 'insurance', 'flight'])), [$since->toDateString()]);

        return array_map(fn ($row) => [
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'vat_no' => $row->vat_no !== null ? (string) $row->vat_no : null,
            'category' => (string) $row->category,
        ], $rows);
    }

    private function costExpression(): string
    {
        return '(coalesce((i.cost).gross, 0) + coalesce((i.cost).tax, 0) - coalesce((i.cost).commission, 0) - coalesce(i.supplier_paid_amount, 0))';
    }

    /**
     * @param  list<string>  $categories
     */
    private function categoryCase(array $categories = ['hotel', 'transfer', 'insurance', 'flight']): string
    {
        $cases = [];

        foreach ($categories as $category) {
            $ids = $this->idList($category);

            if ($ids !== '') {
                $cases[] = sprintf("when i.product_type in (%s) then '%s'", $ids, $category);
            }
        }

        return $cases === [] ? "'other'" : 'case '.implode(' ', $cases)." else 'other' end";
    }

    /**
     * Services paid to their supplier on the check-in rule: everything except
     * charter seats and line tickets.
     */
    private function payableTypesClause(): string
    {
        $excluded = array_filter([$this->idList('charter'), $this->idList('flight')]);

        return $excluded === [] ? 'true' : sprintf('i.product_type not in (%s)', implode(', ', $excluded));
    }

    private function idList(string $group): string
    {
        $ids = array_map('intval', (array) config("cashflow.etrip.product_types.{$group}", []));

        return implode(', ', array_filter($ids, fn (int $id) => $id > 0));
    }
}
