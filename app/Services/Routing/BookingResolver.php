<?php

namespace App\Services\Routing;

use App\Services\CashFlow\EtripCashFlowReader;
use Throwable;

/**
 * What an eTrip booking says about a cost bought for it: the product
 * category that owns it (from the booking's main root item: its internal
 * supplier, the brand, or the product type) and the sales channel it was
 * sold through (a partner agency is B2B; otherwise the branch: the web
 * shop, a franchise or one of the retail agencies).
 */
class BookingResolver
{
    private const array CHANNELS = [
        'B2B' => 'sales_b2b',
        'Site' => 'b2c_site',
        'Franchise' => 'b2c_franchise',
        'Retail' => 'b2c_retail',
    ];

    public function __construct(private EtripCashFlowReader $etrip) {}

    /**
     * The eTrip database a booking id belongs to.
     */
    public static function connectionOf(int $bookingId): string
    {
        return $bookingId >= (int) config('routing.etrip.vacanza_from', 10_000_000) ? 'etrip_vcz' : 'etrip_chr';
    }

    /**
     * A booking id read from an OMC reference ("1234567.", "1234567~~"), or
     * null when the reference is not one (a rotation code, a settlement
     * label, a Tina ticket).
     */
    public static function bookingId(?string $reference): ?int
    {
        $digits = preg_replace('/\D+$/', '', trim((string) $reference));

        return $digits !== null && preg_match('/^\d{6,8}$/', $digits) === 1 ? (int) $digits : null;
    }

    /**
     * @param  list<int>  $bookingIds
     * @return array<int, array{department: ?string, channel: ?string, detail: string}>
     */
    public function resolve(array $bookingIds): array
    {
        $byConnection = [];

        foreach (array_unique($bookingIds) as $id) {
            $byConnection[self::connectionOf($id)][] = $id;
        }

        $resolved = [];

        foreach ($byConnection as $connection => $ids) {
            foreach (array_chunk($ids, 500) as $chunk) {
                foreach (collect($this->read($connection, $chunk))->groupBy('id') as $id => $roots) {
                    $resolved[(int) $id] = $this->describe($connection, $roots->all());
                }
            }
        }

        return $resolved;
    }

    /**
     * @param  list<int>  $ids
     * @return list<object>
     */
    private function read(string $connection, array $ids): array
    {
        $db = $this->etrip->connection($connection);

        try {
            return $db->select($this->sql(true, count($ids)), $ids);
        } catch (Throwable) {
            // A database without the client and branch tables still names the
            // product; its channel is left to the rules.
            return $db->select($this->sql(false, count($ids)), $ids);
        }
    }

    private function sql(bool $withChannel, int $count): string
    {
        $placeholders = implode(', ', array_fill(0, $count, '?'));
        $site = implode(', ', array_map('intval', (array) config('routing.etrip.site_branches', [])) ?: [0]);

        $channel = $withChannel
            ? "case when t.code is not null then 'B2B'
                    when b.branch in ({$site}) then 'Site'
                    when bo.name ilike 'Franciza%' or o.label ilike '%Franciza%' then 'Franchise'
                    when bo.id is null or bo.name ~ '^[0-9]\\.' then null
                    else 'Retail' end"
            : 'null';

        $joins = $withChannel
            ? 'left join clients.trade t on t.code = b.client
               left join settings.branch_offices bo on bo.id = b.branch
               left join omc.offices o on o.id = bo.omc_office'
            : '';

        // Every root item of the booking, the confirmed ones first, the most
        // valuable first: a cancellation fee (the only confirmed item left on
        // a cancelled booking) belongs to the package that was cancelled.
        return <<<SQL
            with b as (
                select id, brand, branch, client from bookings.bookings where id in ({$placeholders})
            )
            select b.id, b.brand, r.product_type, r.supplier, s.name as supplier_name, {$channel} as channel
            from b
            left join bookings.items r on r.booking = b.id and r.package is null
            left join suppliers.suppliers s on s.code = r.supplier
            {$joins}
            order by b.id, (r.client_status = 'confirmed') desc, (r.price).gross desc nulls last, r.id
            SQL;
    }

    /**
     * @param  list<object>  $roots  the booking's root items, best first
     * @return array{department: ?string, channel: ?string, detail: string}
     */
    private function describe(string $connection, array $roots): array
    {
        $rules = (array) config("routing.etrip.{$connection}", []);
        $first = $roots[0];
        $department = null;
        $why = null;
        $root = $first;

        foreach ((array) ($rules['brands'] ?? []) as $code => $brands) {
            if ($first->brand !== null && in_array((int) $first->brand, $brands, true)) {
                [$department, $why] = [$code, 'brand'];
                break;
            }
        }

        foreach ($department === null ? $roots : [] as $candidate) {
            [$department, $why] = $this->categoryOf($rules, $candidate);

            if ($department !== null) {
                $root = $candidate;
                break;
            }
        }

        $channel = $first->channel !== null ? (self::CHANNELS[$first->channel] ?? null) : null;
        $source = $connection === 'etrip_vcz' ? 'Vacanza' : 'eTrip';

        return [
            'department' => $department,
            'channel' => $channel,
            'detail' => mb_substr(sprintf('Rezervarea %s %d: %s%s', $source, $first->id, trim((string) ($root->supplier_name ?? 'fără serviciu principal')), $why ? " ({$why})" : ''), 0, 255),
        ];
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array{0: ?string, 1: ?string}
     */
    private function categoryOf(array $rules, object $root): array
    {
        foreach ((array) ($rules['suppliers'] ?? []) as $code => $suppliers) {
            if ($root->supplier !== null && in_array((string) $root->supplier, $suppliers, true)) {
                return [$code, 'furnizor intern'];
            }
        }

        foreach ((array) ($rules['product_types'] ?? []) as $code => $types) {
            if ($root->product_type !== null && in_array((int) $root->product_type, $types, true)) {
                return [$code, 'tip produs '.$root->product_type];
            }
        }

        return [null, null];
    }
}
