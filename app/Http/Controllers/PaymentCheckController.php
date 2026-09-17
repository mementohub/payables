<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsRemote;
use App\Models\EtripSupplier;
use App\Services\Etrip\CheckinCostCheckService;
use App\Services\Etrip\EtripReader;
use App\Services\Etrip\EtripSupplierSyncService;
use App\Services\Omc\OmcReader;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Verificare plăți → Check-in (eTrip): the supplier cost of the services
 * booked in any eTrip base, compared with a payment request.
 */
class PaymentCheckController extends Controller
{
    use ReadsRemote;

    public const EXPECTED_WINDOWS = [2, 7, 14];

    public function index(Request $request, OmcReader $omc): Response
    {
        $bases = $this->bases();
        $requested = $request->string('connection')->toString();

        return Inertia::render('payment-checks/index', [
            'bases' => $bases,
            'company_id' => $omc->company()?->id,
            'categories' => CheckinCostCheckService::CATEGORY_LABELS,
            'windows' => self::EXPECTED_WINDOWS,
            'filters' => [
                'connection' => array_key_exists($requested, (array) config('etrip.connections')) ? $requested : null,
                'supplier' => $request->string('supplier')->toString() ?: null,
                'from' => $request->string('from')->toString() ?: Carbon::today()->toDateString(),
                'to' => $request->string('to')->toString() ?: Carbon::tomorrow()->toDateString(),
                'category' => in_array($request->string('category')->toString(), CheckinCostCheckService::CATEGORIES, true)
                    ? $request->string('category')->toString()
                    : 'hotel',
                'amount' => $request->string('amount')->toString() ?: null,
                'currency' => $request->string('currency')->toString() ?: null,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function check(Request $request, CheckinCostCheckService $check, EtripSupplierSyncService $sync): array
    {
        $validated = $request->validate([
            'connection' => ['required', Rule::in(array_keys((array) config('etrip.connections')))],
            'supplier' => ['required', 'string', 'max:50'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'category' => ['nullable', Rule::in(CheckinCostCheckService::CATEGORIES)],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:5'],
        ]);

        $connection = $validated['connection'];

        $supplier = EtripSupplier::query()
            ->forConnection($connection)
            ->where('code', $validated['supplier'])
            ->first()
            ?? $this->readingEtrip(fn () => $sync->remember($connection, $validated['supplier']));

        abort_if($supplier === null, 404, 'Furnizorul nu există în eTrip.');

        return $this->readingEtrip(fn () => $check->check(
            $connection,
            $supplier,
            Carbon::parse($validated['from'])->startOfDay(),
            Carbon::parse($validated['to'])->startOfDay(),
            $validated['category'] ?? 'hotel',
            isset($validated['amount']) && $validated['amount'] !== '' ? (float) $validated['amount'] : null,
            $validated['currency'] ?? null,
        ));
    }

    /**
     * Suppliers with the largest check-in cost in the coming days, read live
     * from eTrip unless etrip.expected_cache_minutes asks for a cache.
     *
     * @return array{days: int, from: string, to: string, cached_at: string, suppliers: list<array<string, mixed>>}
     */
    public function expected(Request $request, EtripReader $reader): array
    {
        $validated = $request->validate([
            'connection' => ['required', Rule::in(array_keys((array) config('etrip.connections')))],
            'days' => ['nullable', 'integer', Rule::in(self::EXPECTED_WINDOWS)],
            'refresh' => ['nullable', 'boolean'],
        ]);

        $connection = $validated['connection'];
        $days = (int) ($validated['days'] ?? self::EXPECTED_WINDOWS[0]);
        $from = Carbon::today();
        $to = $from->copy()->addDays($days - 1);
        $key = sprintf('etrip:%s:expected:%d:%s', $connection, $days, $from->toDateString());

        $minutes = (int) config('etrip.expected_cache_minutes', 0);

        if ($request->boolean('refresh') || $minutes <= 0) {
            Cache::forget($key);
        }

        $payload = $this->readingEtrip(fn () => Cache::remember($key, now()->addMinutes(max(1, $minutes)), function () use ($reader, $connection, $from, $to) {
            $known = EtripSupplier::query()
                ->forConnection($connection)
                ->get(['code', 'partner_id'])
                ->keyBy('code');

            return [
                'cached_at' => now()->toIso8601String(),
                'suppliers' => array_map(fn (array $row) => [
                    ...$row,
                    'partner_id' => $known->get($row['supplier_code'])?->partner_id,
                ], $reader->expectedCosts($connection, $from, $to)),
            ];
        }));

        return ['days' => $days, 'from' => $from->toDateString(), 'to' => $to->toDateString(), ...$payload];
    }

    /**
     * @return list<array{key: string, label: string, suppliers_synced_at: ?string}>
     */
    private function bases(): array
    {
        $synced = EtripSupplier::query()
            ->selectRaw('etrip_connection, max(synced_at) as synced_at')
            ->groupBy('etrip_connection')
            ->pluck('synced_at', 'etrip_connection');

        $bases = [];

        foreach ((array) config('etrip.connections') as $key => $label) {
            $bases[] = [
                'key' => $key,
                'label' => (string) $label,
                'suppliers_synced_at' => isset($synced[$key]) ? Carbon::parse($synced[$key])->toIso8601String() : null,
            ];
        }

        return $bases;
    }
}
