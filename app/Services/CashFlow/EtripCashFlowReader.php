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
     * Confirmed bookings with an unpaid client balance, with the amounts
     * scheduled in eTrip and what the report needs to classify them.
     *
     * @return list<array{id: int, currency: string, total_due: float, paid: float, balance_due_date: ?string, start_date: ?string, brand: ?int, channel: ?int, client_type: string, root_types: list<int>, continent: ?string, due_dates: list<array{date: string, amount: float}>}>
     */
    public function openBookings(string $name, CarbonInterface $since): array
    {
        $rows = $this->connection($name)->select(<<<'SQL'
            with b as (
                select b.id, b.currency, b.total_amount_due, b.paid_amount, b.balance_due_date, b.start_date,
                       b.brand, b.booked_via, b.client, b.destination
                from bookings.bookings b
                where b.status = 'confirmed'
                  and b.start_date >= ?::timestamp
                  and b.total_amount_due - b.paid_amount > 1
            )
            select b.id, b.currency, b.total_amount_due as total_due, b.paid_amount as paid,
                   b.balance_due_date::date as balance_due_date, b.start_date::date as start_date,
                   b.brand, b.booked_via as channel,
                   case when exists (select 1 from clients.trade t where t.code = b.client) then 'trade'
                        when exists (select 1 from clients.business bu where bu.code = b.client) then 'business'
                        else 'direct' end as client_type,
                   (select string_agg(distinct i.product_type::text, ',')
                      from bookings.items i
                     where i.booking = b.id and i.package is null and i.client_status = 'confirmed') as root_types,
                   (select c.name
                      from public.geography g
                      join public.geography c on c.tree_level = 1 and c.minval <= g.minval and c.maxval >= g.maxval
                     where g.id = b.destination
                     limit 1) as continent,
                   (select json_agg(json_build_object('date', dd.date, 'amount', dd.amount) order by dd.date)
                      from bookings.due_dates dd
                     where dd.booking = b.id) as due_dates
            from b
            order by b.id
            SQL, [$since->toDateString()]);

        return array_map(fn ($row) => [
            'id' => (int) $row->id,
            'currency' => strtoupper((string) $row->currency),
            'total_due' => (float) $row->total_due,
            'paid' => (float) $row->paid,
            'balance_due_date' => $row->balance_due_date !== null ? (string) $row->balance_due_date : null,
            'start_date' => $row->start_date !== null ? (string) $row->start_date : null,
            'brand' => $row->brand !== null ? (int) $row->brand : null,
            'channel' => $row->channel !== null ? (int) $row->channel : null,
            'client_type' => (string) $row->client_type,
            'root_types' => $row->root_types !== null && $row->root_types !== ''
                ? array_map('intval', explode(',', (string) $row->root_types))
                : [],
            'continent' => $row->continent !== null ? (string) $row->continent : null,
            'due_dates' => array_map(fn (array $due) => [
                'date' => (string) $due['date'],
                'amount' => (float) $due['amount'],
            ], is_string($row->due_dates) ? (json_decode($row->due_dates, true) ?: []) : []),
        ], $rows);
    }

    /**
     * Supplier cost of the confirmed services with check-in in the range,
     * by the week the supplier is paid (check-in minus the given days),
     * category and supplier currency. Packages (price only), charter seats
     * (paid per contract) and line tickets (paid at order) are left out.
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
     * week: the booking curve the new-sales scenario is built from.
     *
     * @return array{
     *   receipts: list<array{week: string, currency: string, amount: float}>,
     *   costs: list<array{week: string, category: string, currency: string, cost: float}>,
     *   tickets: list<array{week: string, currency: string, cost: float}>,
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
                select b.id, b.currency
                from bookings.bookings b
                where b.ctime >= ?::timestamp and b.ctime < ?::timestamp and b.status = 'confirmed'
            )
            select (date_trunc('week', r.issue_date))::date as week,
                   b.currency,
                   sum(rb.booking_amount)::numeric(20,2) as amount
            from b
            join financials.receipt_bookings rb on rb.booking = b.id
            join financials.receipts r on r.id = rb.receipt
            where r.status <> 'cancelled'
            group by 1, 2
            order by 1, 2
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
                   sum(%3$s)::numeric(20,2) as cost
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
                   sum(%1$s)::numeric(20,2) as cost
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
                'currency' => strtoupper((string) $row->currency),
                'amount' => (float) $row->amount,
            ], $receipts),
            'costs' => array_map(fn ($row) => [
                'week' => (string) $row->week,
                'category' => (string) $row->category,
                'currency' => strtoupper((string) ($row->currency ?? 'RON')),
                'cost' => (float) $row->cost,
            ], $costs),
            'tickets' => array_map(fn ($row) => [
                'week' => (string) $row->week,
                'currency' => strtoupper((string) ($row->currency ?? 'RON')),
                'cost' => (float) $row->cost,
            ], $tickets),
            'bookings' => (int) ($count->bookings ?? 0),
        ];
    }

    private function costExpression(): string
    {
        return '(coalesce((i.cost).gross, 0) + coalesce((i.cost).tax, 0) - coalesce((i.cost).commission, 0))';
    }

    private function categoryCase(): string
    {
        $cases = [];

        foreach (['hotel', 'transfer', 'insurance', 'flight'] as $category) {
            $ids = $this->idList($category);

            if ($ids !== '') {
                $cases[] = sprintf("when i.product_type in (%s) then '%s'", $ids, $category);
            }
        }

        return $cases === [] ? "'other'" : 'case '.implode(' ', $cases)." else 'other' end";
    }

    /**
     * Services paid to their supplier on the check-in rule: everything except
     * package parents, charter seats and line tickets.
     */
    private function payableTypesClause(): string
    {
        $excluded = array_filter([$this->idList('package'), $this->idList('charter'), $this->idList('flight')]);

        return $excluded === [] ? 'true' : sprintf('i.product_type not in (%s)', implode(', ', $excluded));
    }

    private function idList(string $group): string
    {
        $ids = array_map('intval', (array) config("cashflow.etrip.product_types.{$group}", []));

        return implode(', ', array_filter($ids, fn (int $id) => $id > 0));
    }
}
