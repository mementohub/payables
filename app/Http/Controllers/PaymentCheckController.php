<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsRemote;
use App\Models\Company;
use App\Models\EtripSupplier;
use App\Services\Etrip\CheckinCostCheckService;
use App\Services\Etrip\EtripReader;
use App\Services\Etrip\EtripSupplierSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PaymentCheckController extends Controller
{
    use ReadsRemote;

    public const EXPECTED_WINDOWS = [2, 7, 14];

    public function index(Request $request): Response
    {
        $companies = $this->etripCompanies();
        $company = $companies->firstWhere('id', $request->integer('company_id'));

        return Inertia::render('payment-checks/index', [
            'companies' => $companies->map(fn (Company $company) => [
                'id' => $company->id,
                'name' => $company->name,
                'etrip' => config('etrip.connections.'.$company->etrip_connection),
                'suppliers_synced_at' => $company->etripSuppliers()->max('synced_at'),
            ])->values(),
            'categories' => CheckinCostCheckService::CATEGORY_LABELS,
            'windows' => self::EXPECTED_WINDOWS,
            'filters' => [
                'company_id' => $company?->id,
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
            'company_id' => ['required', 'integer', Rule::exists('companies', 'id')->whereNotNull('etrip_connection')],
            'supplier' => ['required', 'string', 'max:50'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'category' => ['nullable', Rule::in(CheckinCostCheckService::CATEGORIES)],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:5'],
        ]);

        $company = Company::query()->findOrFail($validated['company_id']);
        abort_if($company->etripConnection() === null, 422, 'Conexiunea eTrip a companiei nu este definită.');

        $supplier = EtripSupplier::query()
            ->where('company_id', $company->id)
            ->where('code', $validated['supplier'])
            ->first()
            ?? $this->readingEtrip(fn () => $sync->remember($company, $validated['supplier']));

        abort_if($supplier === null, 404, 'Furnizorul nu există în eTrip.');

        return $this->readingEtrip(fn () => $check->check(
            $company,
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
            'company_id' => ['required', 'integer', Rule::exists('companies', 'id')->whereNotNull('etrip_connection')],
            'days' => ['nullable', 'integer', Rule::in(self::EXPECTED_WINDOWS)],
            'refresh' => ['nullable', 'boolean'],
        ]);

        $company = Company::query()->findOrFail($validated['company_id']);
        abort_if($company->etripConnection() === null, 422, 'Conexiunea eTrip a companiei nu este definită.');

        $days = (int) ($validated['days'] ?? self::EXPECTED_WINDOWS[0]);
        $from = Carbon::today();
        $to = $from->copy()->addDays($days - 1);
        $key = sprintf('etrip:%s:expected:%d:%s', $company->etripConnection(), $days, $from->toDateString());

        $minutes = (int) config('etrip.expected_cache_minutes', 0);

        if ($request->boolean('refresh') || $minutes <= 0) {
            Cache::forget($key);
        }

        $payload = $this->readingEtrip(fn () => Cache::remember($key, now()->addMinutes(max(1, $minutes)), function () use ($reader, $company, $from, $to) {
            $known = EtripSupplier::query()
                ->where('company_id', $company->id)
                ->get(['code', 'partner_id'])
                ->keyBy('code');

            return [
                'cached_at' => now()->toIso8601String(),
                'suppliers' => array_map(fn (array $row) => [
                    ...$row,
                    'partner_id' => $known->get($row['supplier_code'])?->partner_id,
                ], $reader->expectedCosts($company, $from, $to)),
            ];
        }));

        return ['days' => $days, 'from' => $from->toDateString(), 'to' => $to->toDateString(), ...$payload];
    }

    /**
     * @return Collection<int, Company>
     */
    private function etripCompanies()
    {
        return Company::query()
            ->whereIn('etrip_connection', array_keys((array) config('etrip.connections')))
            ->orderBy('name')
            ->get(['id', 'name', 'etrip_connection']);
    }
}
