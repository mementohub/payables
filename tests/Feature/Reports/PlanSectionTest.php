<?php

use App\Models\Company;
use App\Models\Department;
use App\Models\ForecastHistory;
use App\Models\Plan;
use App\Models\PlanLine;
use App\Models\PlanSection;
use App\Models\User;
use App\Services\Forecast\PlanBuilder;
use App\Services\Forecast\PlanSections;

/**
 * Bugetul se face în mai multe mâini: fiecare scrie în bucata lui, o trimite
 * spre consolidare, iar după aceea nu se mai umblă la ea.
 */
function planWithSections(Company $company): Plan
{
    foreach ([
        ['year' => 2026, 'kind' => 'category', 'key' => 'Senior Voyage', 'month' => 7, 'volume' => 100, 'net' => 500_000, 'margin' => 50_000],
        ['year' => 2026, 'kind' => 'category', 'key' => 'Cazare', 'month' => 7, 'volume' => 80, 'net' => 200_000, 'margin' => 20_000],
    ] as $row) {
        ForecastHistory::query()->create(['company_id' => $company->id, 'unit' => 'pax', 'volume' => 0, 'net' => 0, 'margin' => 0, ...$row]);
    }

    $plan = Plan::query()->create([
        'company_id' => $company->id, 'year' => 2027, 'name' => 'Buget 2027',
        'base_year' => 2026, 'status' => Plan::DRAFT,
    ]);

    app(PlanBuilder::class)->seed($plan);

    PlanLine::query()->create([
        'plan_id' => $plan->id, 'kind' => 'opex', 'key' => '16950',
        'label' => 'Marketing si promovare · Cheltuieli promovare Google',
        'month' => 7, 'amount' => 50_000, 'manual' => true,
    ]);

    return $plan;
}

function personOf(string $department): User
{
    $user = User::factory()->withRoles('operational')->create();
    $user->departments()->attach(Department::query()->firstOrCreate(
        ['name' => $department],
        ['group' => Department::GROUP_PRODUCT, 'is_active' => true],
    ));

    return $user;
}

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->boss = User::factory()->withRoles('top_management')->create();
    $this->plan = planWithSections($this->company);
});

test('each category and each block of costs has the department that writes it', function () {
    $sections = collect(app(PlanSections::class)->all($this->plan))->keyBy('key');

    expect($sections['category:Senior Voyage']['department'])->toBe('Senior Voyage')
        ->and($sections['category:Cazare']['department'])->toBe('Cazări Individuale')
        ->and($sections['cost:marketing']['department'])->toBe('Marketing')
        ->and($sections['cost:retail']['department'])->toBe('Agenții retail')
        ->and($sections['cost:admin']['department'])->toBe('Administrativ')
        // Fiecare bucată își poartă cifra, ca să se vadă ce s-a strâns.
        ->and($sections['category:Senior Voyage']['net'])->toBeGreaterThan(0)
        ->and($sections['cost:marketing']['amount'])->toEqual(50_000.0);
});

test('a department writes in its own piece and nowhere else', function () {
    $senior = personOf('Senior Voyage');

    // În categoria lui, da.
    $this->actingAs($senior)->put('/reports/forecast/'.$this->plan->id.'/row', [
        'kind' => PlanLine::CATEGORY, 'key' => 'Senior Voyage', 'field' => 'unit_net', 'value' => 6_000,
    ])->assertRedirect();

    expect(PlanLine::query()->where('plan_id', $this->plan->id)->where('key', 'Senior Voyage')->first()->unit_net)
        ->toEqual(6_000.0);

    // În a altuia, nu.
    $this->actingAs($senior)->put('/reports/forecast/'.$this->plan->id.'/row', [
        'kind' => PlanLine::CATEGORY, 'key' => 'Cazare', 'field' => 'unit_net', 'value' => 9_999,
    ])->assertForbidden();

    // Nici în cheltuielile marketingului.
    $this->actingAs($senior)->put('/reports/forecast/'.$this->plan->id.'/row', [
        'kind' => PlanLine::OPEX, 'key' => '16950', 'field' => 'amount', 'value' => 1,
    ])->assertForbidden();
});

test('a sub-line written by its owner stays in the owner s category', function () {
    $senior = personOf('Senior Voyage');

    $this->actingAs($senior)->post('/reports/forecast/'.$this->plan->id.'/rows', [
        'kind' => PlanLine::CATEGORY, 'key' => 'Senior Voyage · Grecia', 'months' => [6, 7],
        'volume' => 120, 'unit_net' => 4_200, 'unit_margin' => 500,
    ])->assertRedirect();

    $sections = app(PlanSections::class);

    expect($sections->forLine(PlanLine::CATEGORY, 'Senior Voyage · Grecia'))->toBe('category:Senior Voyage')
        ->and(PlanLine::query()->where('plan_id', $this->plan->id)->where('key', 'Senior Voyage · Grecia')->count())->toBe(2);

    // Dar nu-și poate deschide o sub-linie la altcineva.
    $this->actingAs($senior)->post('/reports/forecast/'.$this->plan->id.'/rows', [
        'kind' => PlanLine::CATEGORY, 'key' => 'Croaziere · Mediterana', 'months' => [6],
        'volume' => 10, 'unit_net' => 1_000,
    ])->assertForbidden();
});

test('once sent for consolidation the piece is locked, until it is sent back', function () {
    $marketing = personOf('Marketing');

    $this->actingAs($marketing)->post('/reports/forecast/'.$this->plan->id.'/sections/submit', [
        'key' => 'cost:marketing', 'note' => 'Bugetul de promovare pentru 2027.',
    ])->assertRedirect();

    expect(PlanSection::query()->sole())
        ->status->toBe(PlanSection::SUBMITTED)
        ->submitted_by_id->toBe($marketing->id);

    // Acum nu mai scrie nici el în ea.
    $this->actingAs($marketing)->put('/reports/forecast/'.$this->plan->id.'/row', [
        'kind' => PlanLine::OPEX, 'key' => '16950', 'field' => 'amount', 'value' => 60_000,
    ])->assertForbidden();

    // Conducerea o dă înapoi, cu un cuvânt.
    $this->actingAs($this->boss)->post('/reports/forecast/'.$this->plan->id.'/sections/review', [
        'key' => 'cost:marketing', 'status' => PlanSection::DRAFT, 'note' => 'Prea mult pe Google; mai vedem.',
    ])->assertRedirect();

    $this->actingAs($marketing)->put('/reports/forecast/'.$this->plan->id.'/row', [
        'kind' => PlanLine::OPEX, 'key' => '16950', 'field' => 'amount', 'value' => 60_000,
    ])->assertRedirect();

    // Și o primește.
    $this->actingAs($this->boss)->post('/reports/forecast/'.$this->plan->id.'/sections/review', [
        'key' => 'cost:marketing', 'status' => PlanSection::ACCEPTED,
    ])->assertRedirect();

    expect(PlanSection::query()->sole())
        ->status->toBe(PlanSection::ACCEPTED)
        ->accepted_by_id->toBe($this->boss->id);
});

test('only top management receives or sends back a piece', function () {
    $marketing = personOf('Marketing');

    $this->actingAs($marketing)->post('/reports/forecast/'.$this->plan->id.'/sections/review', [
        'key' => 'cost:marketing', 'status' => PlanSection::ACCEPTED,
    ])->assertForbidden();
});

test('someone with no piece of the budget does not get through the door', function () {
    $stranger = User::factory()->withRoles('treasury')->create();

    $this->actingAs($stranger)->get('/reports/forecast')->assertForbidden();

    // Dar cel care ține o bucată, da — chiar dacă nu e din conducere.
    $this->actingAs(personOf('Charters'))->get('/reports/forecast')->assertOk();
});
