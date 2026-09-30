<?php

use App\Models\Company;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-09-28 09:00:00');
    $this->company = Company::factory()->create();
    $this->partner = Partner::factory()->create(['company_id' => $this->company->id, 'name' => 'HOTEL PARADIS SRL', 'cui' => 'RO123']);
    $this->boss = User::factory()->create(['roles' => [User::ROLE_TOP_MANAGEMENT]]);
});

function dueInvoice(array $attributes = []): Invoice
{
    return Invoice::factory()->create([
        'company_id' => test()->company->id,
        'partner_id' => test()->partner->id,
        'partener_type' => 'furnizor',
        'moneda' => 'RON',
        'curs' => 1,
        'val_mon' => 1000,
        'val_mon_paid' => 0,
        'val_mon_storno' => 0,
        ...$attributes,
    ]);
}

/**
 * Scadențarul spune ce urmează de plătit: facturi cu rest, cu scadența de azi
 * înainte. Ce s-a plătit deja și ce a trecut de scadență n-au ce căuta acolo.
 */
test('the report lists what is still owed and comes due from today on', function () {
    $today = dueInvoice(['nr_doc' => 'AZI', 'data_scadenta' => '2026-09-28']);
    $soon = dueInvoice(['nr_doc' => 'PESTE-5', 'data_scadenta' => '2026-10-03', 'val_mon_paid' => 400]);
    $later = dueInvoice(['nr_doc' => 'PESTE-40', 'data_scadenta' => '2026-11-07', 'moneda' => 'EUR', 'curs' => 5, 'val_mon' => 200]);
    // Nu intră: restanță, achitată, storno și fără scadență.
    dueInvoice(['nr_doc' => 'RESTANTA', 'data_scadenta' => '2026-09-27']);
    dueInvoice(['nr_doc' => 'ACHITATA', 'data_scadenta' => '2026-10-01', 'val_mon_paid' => 1000]);
    dueInvoice(['nr_doc' => 'STORNATA', 'data_scadenta' => '2026-10-01', 'val_mon_storno' => 1000]);
    dueInvoice(['nr_doc' => 'FARA-SCADENTA', 'data_scadenta' => null]);

    $this->actingAs($this->boss)->get('/reports/due')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('reports/due')
            ->has('rows.data', 3)
            ->where('rows.data.0.nr_doc', $today->nr_doc)
            ->where('rows.data.0.days', 0)
            ->where('rows.data.1.nr_doc', $soon->nr_doc)
            // Restul de plată, nu valoarea facturii.
            ->where('rows.data.1.remaining', 600)
            ->where('rows.data.2.nr_doc', $later->nr_doc)
            ->where('rows.data.2.remaining_lei', 1000)
            // 1000 + 600 + 200 EUR × 5
            ->where('totals.lei', 2600)
            ->where('totals.count', 3)
            ->where('totals.by_currency.RON', 1600)
            ->where('totals.by_currency.EUR', 200)
            ->where('totals.buckets.week.count', 2)
            ->where('totals.buckets.later.count', 1));
});

test('the report can be narrowed to a due date, a department and a supplier', function () {
    $department = Department::query()->whereNotNull('code')->first();
    dueInvoice(['nr_doc' => 'A', 'data_scadenta' => '2026-09-30', 'department_id' => $department->id]);
    dueInvoice(['nr_doc' => 'B', 'data_scadenta' => '2026-12-01']);

    $this->actingAs($this->boss)->get('/reports/due?until=2026-10-31')
        ->assertInertia(fn ($page) => $page->has('rows.data', 1)->where('rows.data.0.nr_doc', 'A'));

    $this->actingAs($this->boss)->get("/reports/due?department={$department->id}")
        ->assertInertia(fn ($page) => $page->has('rows.data', 1)->where('rows.data.0.department', $department->name));

    $this->actingAs($this->boss)->get('/reports/due?search=paradis')
        ->assertInertia(fn ($page) => $page->has('rows.data', 2));

    $this->actingAs($this->boss)->get('/reports/due?search=nimeni')
        ->assertInertia(fn ($page) => $page->has('rows.data', 0)->where('totals.count', 0));
});

test('the scadentar is a report, so it follows the reports rule', function () {
    dueInvoice(['data_scadenta' => '2026-10-01']);

    $this->actingAs(User::factory()->create(['roles' => [User::ROLE_ADMIN]]))->get('/reports/due')->assertOk();

    foreach ([User::ROLE_FINANCE, User::ROLE_TREASURY, User::ROLE_OPERATIONAL] as $role) {
        $this->actingAs(User::factory()->create(['roles' => [$role]]))->get('/reports/due')->assertForbidden();
        $this->actingAs(User::factory()->create(['roles' => [$role]]))->get('/reports/due/export')->assertForbidden();
    }
});

test('the report downloads as a spreadsheet', function () {
    dueInvoice(['data_scadenta' => '2026-10-01']);

    $response = $this->actingAs($this->boss)->get('/reports/due/export');

    $response->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    expect(strlen($response->streamedContent()))->toBeGreaterThan(0);
});

/**
 * Scadențarul spune ce urmează, deci lasă restanțele afară — dar întrebarea
 * „cât e de plată până la data X” le vrea înăuntru, ca în lista de aprobări.
 */
test('the overdue invoices can be brought in', function () {
    $restanta = dueInvoice(['nr_doc' => 'RESTANTA', 'data_scadenta' => '2026-09-01']);
    dueInvoice(['nr_doc' => 'AZI', 'data_scadenta' => '2026-09-28']);

    $this->actingAs($this->boss)->get('/reports/due?until=2026-09-29')
        ->assertInertia(fn ($page) => $page
            ->has('rows.data', 1)
            ->where('totals.count', 1)
            ->where('filters.overdue', false));

    $this->actingAs($this->boss)->get('/reports/due?until=2026-09-29&overdue=1')
        ->assertInertia(fn ($page) => $page
            ->has('rows.data', 2)
            ->where('rows.data.0.nr_doc', $restanta->nr_doc)
            // Restanța se numără separat, ca să se vadă cât e întârziat.
            ->where('totals.count', 2)
            ->where('totals.buckets.overdue.count', 1)
            ->where('totals.buckets.week.count', 1)
            ->where('filters.overdue', true));
});
