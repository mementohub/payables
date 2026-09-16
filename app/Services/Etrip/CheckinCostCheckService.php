<?php

namespace App\Services\Etrip;

use App\Models\Company;
use App\Models\EtripSupplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Compares a product supplier's payment request with the cost of the services
 * booked with that supplier in eTrip for a check-in interval: confirmed
 * bookings, services confirmed with the client and not cancelled with the
 * supplier, cost net of the supplier commission, in the supplier currency.
 */
class CheckinCostCheckService
{
    public const CATEGORIES = ['hotel', 'all', 'transfer', 'other'];

    public const CATEGORY_LABELS = [
        'hotel' => 'Cazare / pachete',
        'all' => 'Toate serviciile',
        'transfer' => 'Transferuri',
        'other' => 'Altele (zbor, excursii, asigurări)',
    ];

    public const MAX_LINES = 600;

    public function __construct(private EtripReader $reader) {}

    /**
     * @return array{
     *   supplier: array{id: int, code: string, name: string, currency: ?string},
     *   from: string, to: string, category: string,
     *   totals: list<array{currency: string, cost: float, items: int, bookings: int}>,
     *   items: int, bookings: int,
     *   by_hotel: list<array{name: string, currency: string, cost: float, bookings: int}>,
     *   by_day: list<array{date: string, currency: string, cost: float, bookings: int}>,
     *   by_product: list<array{product_type: int, label: string, currency: string, cost: float, bookings: int}>,
     *   lines: list<array<string, mixed>>, lines_total: int,
     *   requested: array<string, mixed>|null
     * }
     */
    public function check(
        Company $company,
        EtripSupplier $supplier,
        Carbon $from,
        Carbon $to,
        string $category = 'hotel',
        ?float $requested = null,
        ?string $currency = null,
    ): array {
        $labels = $this->reader->productTypes($company);

        $lines = collect($this->reader->costLines($company, $supplier->code, $from, $to))
            ->map(fn (array $row) => $this->line($row, $labels))
            ->filter(fn (array $line) => $category === 'all' || $line['category'] === $category)
            ->values();

        $totals = $this->totals($lines);

        return [
            'supplier' => ['id' => $supplier->id, 'code' => $supplier->code, 'name' => $supplier->name, 'currency' => $supplier->currency],
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'category' => $category,
            'totals' => $totals->values()->all(),
            'items' => $lines->count(),
            'bookings' => $lines->pluck('booking')->unique()->count(),
            'by_hotel' => $this->breakdown($lines, fn (array $line) => ['name' => $line['service']]),
            'by_day' => $this->breakdown($lines, fn (array $line) => ['date' => $line['start_date']], sortByKey: 'date'),
            'by_product' => $this->breakdown($lines, fn (array $line) => ['product_type' => $line['product_type'], 'label' => $line['product_label']]),
            'lines' => $lines->take(self::MAX_LINES)->all(),
            'lines_total' => $lines->count(),
            'requested' => $requested === null ? null : $this->verdict($company, $totals, $requested, $currency),
        ];
    }

    /**
     * Which category a product type falls in, per config/etrip.php.
     */
    public static function categoryOf(int $productType): string
    {
        if (in_array($productType, (array) config('etrip.product_types.hotel'), true)) {
            return 'hotel';
        }

        if (in_array($productType, (array) config('etrip.product_types.transfer'), true)) {
            return 'transfer';
        }

        return 'other';
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $labels
     * @return array<string, mixed>
     */
    private function line(array $row, array $labels): array
    {
        $productType = (int) $row['product_type'];
        $start = Carbon::parse((string) $row['start_date']);
        $end = ! empty($row['end_date']) ? Carbon::parse((string) $row['end_date']) : null;
        $hotel = $this->text($row['hotel'] ?? null);
        $transfer = $this->text($row['transfer'] ?? null);
        $label = $labels[$productType] ?? (string) $productType;

        return [
            'booking' => (int) $row['booking'],
            'item' => (int) $row['item'],
            'lead' => $this->text($row['lead'] ?? null),
            'product_type' => $productType,
            'product_label' => $label,
            'category' => self::categoryOf($productType),
            'start_date' => $start->toDateString(),
            'nights' => $end ? max(0, (int) $start->diffInDays($end)) : null,
            'hotel' => $hotel,
            'room' => $this->text($row['room'] ?? null),
            'meal' => $this->text($row['meal'] ?? null),
            'transfer' => $transfer,
            'service' => $hotel ?? ($transfer ? "{$transfer} (transfer)" : ($this->text($row['description'] ?? null) ?? $label)),
            'pax' => (int) ($row['pax'] ?? 0),
            'currency' => strtoupper((string) ($row['currency'] ?? '')),
            'cost' => round((float) $row['cost'], 2),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lines
     * @return Collection<string, array{currency: string, cost: float, items: int, bookings: int}>
     */
    private function totals(Collection $lines): Collection
    {
        return $lines
            ->groupBy('currency')
            ->map(fn (Collection $group, string $currency) => [
                'currency' => $currency,
                'cost' => round((float) $group->sum('cost'), 2),
                'items' => $group->count(),
                'bookings' => $group->pluck('booking')->unique()->count(),
            ])
            ->sortBy('currency');
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lines
     * @param  callable(array<string, mixed>): array<string, mixed>  $key
     * @return list<array<string, mixed>>
     */
    private function breakdown(Collection $lines, callable $key, ?string $sortByKey = null): array
    {
        $groups = $lines
            ->groupBy(fn (array $line) => json_encode($key($line)).'|'.$line['currency'])
            ->map(fn (Collection $group) => [
                ...$key($group->first()),
                'currency' => $group->first()['currency'],
                'cost' => round((float) $group->sum('cost'), 2),
                'bookings' => $group->pluck('booking')->unique()->count(),
            ])
            ->values();

        $sorted = $sortByKey
            ? $groups->sortBy($sortByKey)
            : $groups->sortByDesc(fn (array $group) => abs($group['cost']));

        return $sorted->values()->all();
    }

    /**
     * Requested amount against the eTrip cost in the same currency; a request in
     * another currency is converted through today's BNR rates when available.
     *
     * @param  Collection<string, array{currency: string, cost: float, items: int, bookings: int}>  $totals
     * @return array<string, mixed>
     */
    private function verdict(Company $company, Collection $totals, float $requested, ?string $currency): array
    {
        $currency = strtoupper(trim((string) $currency)) ?: null;
        $main = $totals->has((string) $currency) ? $currency : ($totals->keys()->first() ?? $currency ?? 'EUR');
        $etrip = (float) ($totals->get($main)['cost'] ?? 0);

        $result = [
            'amount' => round($requested, 2),
            'currency' => $currency ?? $main,
            'compared_amount' => round($requested, 2),
            'compared_currency' => $main,
            'rate' => null,
            'etrip' => $etrip,
            'diff' => null,
            'diff_pct' => null,
            'level' => 'warn',
            'message' => '',
        ];

        if ($currency !== null && $currency !== $main) {
            $today = Carbon::today();
            $fromRon = $this->reader->ronPerUnit($company, $currency, $today);
            $toRon = $this->reader->ronPerUnit($company, $main, $today);

            if (! $fromRon || ! $toRon) {
                return [...$result, 'compared_amount' => null,
                    'message' => "Cererea este în {$currency}, iar costul eTrip în {$main}; nu există curs BNR pentru conversie."];
            }

            $result['compared_amount'] = round($requested * $fromRon / $toRon, 2);
            $result['rate'] = ['from' => $currency, 'to' => $main, 'value' => round($fromRon / $toRon, 4), 'date' => $today->toDateString()];
        }

        $diff = round($result['compared_amount'] - $etrip, 2);
        $pct = $etrip != 0.0 ? round($diff / $etrip * 100, 2) : null;
        $ratio = $pct === null ? INF : abs($pct) / 100;
        $tolerance = (array) config('etrip.tolerance');

        $level = $ratio <= (float) $tolerance['ok'] ? 'ok' : ($ratio <= (float) $tolerance['warn'] ? 'warn' : 'crit');

        return [...$result, 'diff' => $diff, 'diff_pct' => $pct, 'level' => $level, 'message' => match ($level) {
            'ok' => sprintf('Concordă cu costul eTrip (sub %s%%).', $this->percent($tolerance['ok'])),
            'warn' => sprintf('Diferență mică față de costul eTrip (sub %s%%), de explicat.', $this->percent($tolerance['warn'])),
            default => 'Diferență semnificativă față de costul eTrip.',
        }];
    }

    private function percent(mixed $ratio): string
    {
        return rtrim(rtrim(number_format((float) $ratio * 100, 2, ',', ''), '0'), ',');
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
