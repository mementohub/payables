<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\CashFlowSnapshot;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Carbon::setTestNow('2026-09-16 10:00:00');
});

/**
 * The dashboard widgets are deferred props: ask for them the way the page
 * does, with an Inertia partial reload.
 *
 * @param  list<string>  $only
 */
function dashboardWidgets(string $url, array $only): TestResponse
{
    return test()->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create($url)),
        'X-Inertia-Partial-Component' => 'dashboard',
        'X-Inertia-Partial-Data' => implode(',', $only),
    ])->get($url);
}

test('guests are redirected to the login page', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

test('authenticated users can visit the dashboard', function () {
    $this->actingAs($user = User::factory()->create());

    $this->get('/dashboard')->assertOk();
});

test('received invoices of every currency are counted, in lei at the document rate', function () {
    $company = Company::factory()->create();
    $partner = Partner::factory()->for($company)->create(['name' => 'Rida International', 'is_furnizor' => true]);
    Invoice::factory()->for($company)->for($partner)->create(['partener_type' => 'furnizor', 'moneda' => 'EUR', 'curs' => 5, 'val_mon' => 1000, 'val_mon_paid' => 0, 'data_doc' => '2026-09-10', 'data_scadenta' => '2026-08-20']);
    Invoice::factory()->for($company)->for($partner)->create(['partener_type' => 'furnizor', 'moneda' => 'Lei', 'curs' => 1, 'val_mon' => 300, 'val_mon_paid' => 300, 'data_doc' => '2026-09-11', 'data_scadenta' => '2026-09-30']);
    Invoice::factory()->for($company)->for($partner)->create(['partener_type' => 'furnizor', 'moneda' => 'Lei', 'curs' => 1, 'val_mon' => 100, 'val_mon_paid' => 0, 'data_doc' => '2026-08-01', 'data_scadenta' => '2026-08-31']);

    $this->actingAs(User::factory()->create())
        ->get('/dashboard?from=2026-09-01&to=2026-09-30')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->where('filters', ['from' => '2026-09-01', 'to' => '2026-09-30'])
        );

    dashboardWidgets('/dashboard?from=2026-09-01&to=2026-09-30', ['paymentBreakdown', 'agingBuckets', 'topOverdueSuppliers'])
        ->assertOk()
        ->assertJsonPath('props.paymentBreakdown.0.state', 'paid')
        ->assertJsonPath('props.paymentBreakdown.0.count', 1)
        ->assertJsonPath('props.paymentBreakdown.0.total', 300)
        ->assertJsonPath('props.paymentBreakdown.3.state', 'overdue')
        ->assertJsonPath('props.paymentBreakdown.3.count', 1)
        ->assertJsonPath('props.paymentBreakdown.3.outstanding', 5000)
        ->assertJsonPath('props.agingBuckets.0.bucket', '0-30')
        ->assertJsonPath('props.agingBuckets.0.outstanding', 5000)
        ->assertJsonPath('props.topOverdueSuppliers.0.name', 'Rida International')
        ->assertJsonPath('props.topOverdueSuppliers.0.outstanding', 5000);
});

test('the weekly cash flow shows the recent OMC weeks and the WCFR forecast', function () {
    $weeks = array_map(fn (int $i) => Carbon::parse('2026-09-14')->addWeeks($i)->toDateString(), range(0, 51));
    $values = fn (float $v) => array_fill(0, 52, $v);
    CashFlowSnapshot::factory()->create([
        'built_at' => '2026-09-16 04:30:00',
        'payload' => [
            'weeks' => $weeks,
            'recent' => [
                ['week' => '2026-08-31', 'in' => 100, 'out' => 40, 'out_partner' => 30, 'out_salaries' => 10, 'balance' => 900],
                ['week' => '2026-09-07', 'in' => 200, 'out' => 50, 'out_partner' => 50, 'out_salaries' => 0, 'balance' => 1050],
            ],
            'lines' => [
                ['code' => 'B', 'values' => $values(500)],
                ['code' => 'C', 'values' => $values(300)],
                ['code' => 'D', 'values' => $values(100)],
                ['code' => 'E2', 'values' => $values(1150)],
            ],
            'kpis' => [],
        ],
    ]);

    $this->actingAs(User::factory()->create());

    dashboardWidgets('/dashboard', ['cashflow'])
        ->assertOk()
        ->assertJsonPath('props.cashflow.built_at', '2026-09-16T04:30:00+00:00')
        ->assertJsonCount(15, 'props.cashflow.points')
        ->assertJsonPath('props.cashflow.points.0', ['week' => '2026-08-31', 'kind' => 'actual', 'incoming' => 100, 'outgoing' => 40, 'balance' => 900])
        ->assertJsonPath('props.cashflow.points.1.week', '2026-09-07')
        ->assertJsonPath('props.cashflow.points.2', ['week' => '2026-09-14', 'kind' => 'forecast', 'incoming' => 500, 'outgoing' => 400, 'balance' => 1150])
        ->assertJsonPath('props.cashflow.points.14.week', '2026-12-07');
});

test('without a snapshot the cash flow card is empty', function () {
    $this->actingAs(User::factory()->create());

    dashboardWidgets('/dashboard', ['cashflow'])
        ->assertOk()
        ->assertJsonPath('props.cashflow', ['built_at' => null, 'points' => []]);
});
