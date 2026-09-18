<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\CashFlowOverride;
use App\Models\CashFlowSnapshot;
use App\Models\User;
use App\Services\CashFlow\CashFlowOverrides;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Support\SessionKey;

beforeEach(function () {
    Carbon::setTestNow('2026-09-16 10:00:00');

    $this->user = User::factory()->create(['name' => 'Bogdan']);
});

/**
 * A three-week report: receipts B1 and the scenario lines B11.1 under B11,
 * product payments C1 and C11 (scenario), one OPEX line keyed "chirii".
 *
 * @return array<string, mixed>
 */
function overridablePayload(bool $scenarioOn = false): array
{
    $line = fn (string $code, string $section, array $values, string $kind = 'value', bool $scenario = false, ?string $key = null, ?string $parent = null) => [
        'code' => $code, 'key' => $key, 'parent' => $parent, 'label' => $code, 'section' => $section,
        'kind' => $kind, 'scenario' => $scenario, 'note' => null, 'values' => $values, 'total' => array_sum($values),
    ];

    return [
        'weeks' => ['2026-09-14', '2026-09-21', '2026-09-28'],
        'params' => ['scenario' => ['enabled' => $scenarioOn], 'thresholds' => ['minimum' => 900, 'comfort' => 1200]],
        'lines' => [
            $line('A', 'A', [1000, 1100, 1200], 'balance'),
            $line('B1', 'B', [300, 300, 300]),
            $line('B11.1', 'B', [50, 50, 50], scenario: true, parent: 'B11'),
            $line('B11', 'B', [50, 50, 50], 'subtotal', scenario: true),
            $line('B', 'B', [300, 300, 300], 'total'),
            $line('C1', 'C', [100, 100, 100]),
            $line('C11', 'C', [20, 20, 20], scenario: true),
            $line('C', 'C', [100, 100, 100], 'total'),
            $line('D1', 'D', [100, 100, 100], key: 'chirii'),
            $line('D', 'D', [100, 100, 100], 'total'),
            $line('E1', 'E', [100, 100, 100], 'total'),
            $line('E2', 'E', [1100, 1200, 1300], 'balance'),
            $line('E4', 'E', [200, 300, 400], 'total'),
            ['code' => 'E5', 'section' => 'E', 'kind' => 'text', 'values' => ['ATENȚIE', 'OK', 'OK']],
        ],
    ];
}

test('a cell set by hand is stored and handed to the page', function () {
    $this->actingAs($this->user)
        ->put('/reports/cash-flow/overrides', ['line' => 'C1', 'week' => '2026-09-21', 'amount' => '250000.5'])
        ->assertRedirect();

    $this->actingAs($this->user)
        ->get('/reports/cash-flow')
        ->assertInertia(fn ($page) => $page
            ->has('overrides', 1)
            ->where('overrides.0.line', 'C1')
            ->where('overrides.0.week', '2026-09-21')
            ->where('overrides.0.amount', 250000.5)
            ->where('overrides.0.updated_by', 'Bogdan'));
});

test('setting the same cell again replaces the value instead of adding one', function () {
    $this->actingAs($this->user)->put('/reports/cash-flow/overrides', ['line' => 'B1', 'week' => '2026-09-21', 'amount' => 10]);
    $this->actingAs($this->user)->put('/reports/cash-flow/overrides', ['line' => 'B1', 'week' => '2026-09-21', 'amount' => 20]);

    expect(CashFlowOverride::query()->count())->toBe(1)
        ->and(CashFlowOverride::query()->value('amount'))->toEqual(20);
});

test('an empty amount sends the cell back to the automated value', function () {
    CashFlowOverride::query()->create(['line' => 'chirii', 'week' => '2026-09-21', 'amount' => 5]);

    $this->actingAs($this->user)
        ->put('/reports/cash-flow/overrides', ['line' => 'chirii', 'week' => '2026-09-21', 'amount' => null])
        ->assertRedirect();

    expect(CashFlowOverride::query()->count())->toBe(0);
});

test('only receipt, payment and OPEX lines can be set, on a Monday', function (array $input, string $field) {
    $this->actingAs($this->user)
        ->put('/reports/cash-flow/overrides', [...['line' => 'B1', 'week' => '2026-09-21', 'amount' => 1], ...$input])
        ->assertSessionHasErrors($field);

    expect(CashFlowOverride::query()->count())->toBe(0);
})->with([
    'the closing balance' => [['line' => 'E2'], 'line'],
    'a section total' => [['line' => 'B'], 'line'],
    'an unknown OPEX key' => [['line' => 'bonusuri'], 'line'],
    'a Tuesday' => [['week' => '2026-09-22'], 'week'],
    'text as amount' => [['amount' => 'mult'], 'amount'],
]);

test('the page only carries the overrides from this week on', function () {
    CashFlowOverride::query()->create(['line' => 'B1', 'week' => '2026-09-07', 'amount' => 1]);
    CashFlowOverride::query()->create(['line' => 'B1', 'week' => '2026-09-14', 'amount' => 2]);

    $this->actingAs($this->user)
        ->get('/reports/cash-flow')
        ->assertInertia(fn ($page) => $page->has('overrides', 1)->where('overrides.0.week', '2026-09-14'));
});

test('resetting sends every cell back to the automated value', function () {
    CashFlowOverride::query()->create(['line' => 'B1', 'week' => '2026-09-21', 'amount' => 1]);
    CashFlowOverride::query()->create(['line' => 'C1', 'week' => '2026-09-21', 'amount' => 2]);

    $this->actingAs($this->user)->delete('/reports/cash-flow/overrides')->assertRedirect();

    expect(CashFlowOverride::query()->count())->toBe(0)
        ->and(session(SessionKey::FLASH_DATA)['toast']['message'])->toContain('Cele 2 valori');
});

test('the overrides replace their cells and the totals and balances follow', function () {
    $payload = app(CashFlowOverrides::class)->apply(overridablePayload(), [
        ['line' => 'B1', 'week' => '2026-09-21', 'amount' => 0.0],
        ['line' => 'chirii', 'week' => '2026-09-28', 'amount' => 400.0],
        ['line' => 'B11.1', 'week' => '2026-09-14', 'amount' => 80.0],
        ['line' => 'C1', 'week' => '2026-08-31', 'amount' => 999.0],
    ]);
    $lines = collect($payload['lines'])->keyBy('code');

    expect($lines['B1']['values'])->toEqual([300, 0, 300])
        ->and($lines['D1']['values'])->toEqual([100, 100, 400])
        ->and($lines['B11']['values'])->toEqual([80, 50, 50])
        // The scenario is off, so B11 stays out of the receipts.
        ->and($lines['B']['values'])->toEqual([300, 0, 300])
        ->and($lines['C1']['values'])->toEqual([100, 100, 100])
        ->and($lines['E1']['values'])->toEqual([100, -200, -200])
        ->and($lines['A']['values'])->toEqual([1000, 1100, 900])
        ->and($lines['E2']['values'])->toEqual([1100, 900, 700])
        ->and($lines['E4']['values'])->toEqual([200, 0, -200])
        ->and($lines['E5']['values'])->toBe(['ATENȚIE', 'ATENȚIE', 'DEFICIT'])
        ->and($lines['B']['total'])->toEqual(600);
});

test('with the scenario on the scenario lines count, overridden or not', function () {
    $payload = app(CashFlowOverrides::class)->apply(overridablePayload(scenarioOn: true), [
        ['line' => 'C11', 'week' => '2026-09-14', 'amount' => 70.0],
    ]);
    $lines = collect($payload['lines'])->keyBy('code');

    expect($lines['B']['values'])->toEqual([350, 350, 350])
        ->and($lines['C']['values'])->toEqual([170, 120, 120])
        ->and($lines['E2']['values'])->toEqual([1080, 1210, 1340]);
});

test('the dashboard forecast carries the values set by hand', function () {
    CashFlowSnapshot::factory()->create(['built_at' => '2026-09-16 04:30:00', 'payload' => [...overridablePayload(), 'recent' => [], 'kpis' => []]]);
    CashFlowOverride::query()->create(['line' => 'C1', 'week' => '2026-09-14', 'amount' => 600]);

    $this->actingAs($this->user)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/dashboard')),
            'X-Inertia-Partial-Component' => 'dashboard',
            'X-Inertia-Partial-Data' => 'cashflow',
        ])
        ->get('/dashboard')
        ->assertOk()
        ->assertJsonPath('props.cashflow.points.0', ['week' => '2026-09-14', 'kind' => 'forecast', 'incoming' => 300, 'outgoing' => 700, 'balance' => 600]);
});
