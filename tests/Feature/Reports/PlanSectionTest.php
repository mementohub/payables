<?php

use App\Models\Company;
use App\Models\Department;
use App\Models\ForecastHistory;
use App\Models\Plan;
use App\Models\PlanLine;
use App\Models\PlanSection;
use App\Models\User;
use App\Services\Forecast\PlanBuilder;
use App\Services\Forecast\PlanEngine;
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

/**
 * Șeful departamentului — coroana din fila Departamente. El ține bucata de
 * buget, nu oricine e trecut în departament.
 */
function personOf(string $department): User
{
    $user = User::factory()->withRoles('operational')->create();

    $one = Department::query()->firstOrCreate(
        ['name' => $department],
        ['group' => Department::GROUP_PRODUCT, 'is_active' => true],
    );

    $one->update(['head_user_id' => $user->getKey()]);
    $user->departments()->attach($one);

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

test('the piece belongs to the head of the department, not to everyone in it', function () {
    $sef = personOf('Senior Voyage');

    // Un om din echipă, fără coroană: vede departamentul pe dinăuntru, dar
    // bugetul îl scrie șeful.
    $om = User::factory()->withRoles('operational')->create();
    $om->departments()->attach(Department::query()->where('name', 'Senior Voyage')->firstOrFail());

    expect(app(PlanSections::class)->mine($sef, $this->plan))->toContain('category:Senior Voyage')
        ->and(app(PlanSections::class)->mine($om, $this->plan))->toBe([]);

    $this->actingAs($om)->get('/reports/forecast')->assertForbidden();
});

test('a shop costs its people plus the bonus on what it sells', function () {
    $plan = $this->plan;

    // Agenția Plaza, cu oamenii și bonusul ei.
    $line = PlanLine::query()->create([
        'plan_id' => $plan->id, 'kind' => PlanLine::RETAIL, 'key' => 'Plaza', 'label' => 'Plaza',
        'month' => 7, 'volume' => 4, 'unit_cost' => 6_500, 'load' => 0.02,
        'unit_net' => 1_000_000, 'amount' => 50_000, 'manual' => true,
    ]);

    // Patru oameni × 6.500 + 2% din 1.000.000 de lei vânduți.
    expect($line->shop(1_000_000))->toEqual(46_000.0)
        // Fără oameni scriși, rămâne cifra purtată din istoric.
        ->and((new PlanLine(['kind' => PlanLine::RETAIL, 'volume' => 0, 'amount' => 50_000]))->shop(1_000_000))
        ->toEqual(50_000.0);

    $out = app(PlanEngine::class)->compute($plan->fresh());
    $shop = collect($out['retail'])->firstWhere('key', 'Plaza');

    expect($shop)->not->toBeNull()
        ->and($shop['section'])->toBe('cost:retail')
        ->and($shop['fte'])->toEqual(4.0);
});

test('the retail network is written by the retail people, nobody else', function () {
    PlanLine::query()->create([
        'plan_id' => $this->plan->id, 'kind' => PlanLine::RETAIL, 'key' => 'Plaza', 'label' => 'Plaza',
        'month' => 7, 'volume' => 0, 'unit_cost' => 6_500, 'load' => 0.01, 'amount' => 10_000,
    ]);

    $this->actingAs(personOf('Agenții retail'))->put('/reports/forecast/'.$this->plan->id.'/row', [
        'kind' => PlanLine::RETAIL, 'key' => 'Plaza', 'field' => 'volume', 'value' => 5,
    ])->assertRedirect();

    expect(PlanLine::query()->where('kind', PlanLine::RETAIL)->sole()->volume)->toEqual(5.0);

    $this->actingAs(personOf('Senior Voyage'))->put('/reports/forecast/'.$this->plan->id.'/row', [
        'kind' => PlanLine::RETAIL, 'key' => 'Plaza', 'field' => 'volume', 'value' => 99,
    ])->assertForbidden();
});

test('an agency can be dropped from the plan and a new one written in', function () {
    foreach ([6, 7] as $month) {
        PlanLine::query()->create([
            'plan_id' => $this->plan->id, 'kind' => PlanLine::RETAIL, 'key' => 'Plaza', 'label' => 'Plaza',
            'month' => $month, 'volume' => 3, 'unit_cost' => 6_500, 'load' => 0.01,
            'unit_net' => 1_000_000, 'amount' => 20_000,
        ]);
    }

    $retail = personOf('Agenții retail');

    // O agenție pe care n-o mai ținem deschisă.
    $this->actingAs($retail)->delete('/reports/forecast/'.$this->plan->id.'/rows', [
        'kind' => PlanLine::RETAIL, 'key' => 'Plaza',
    ])->assertRedirect();

    expect(PlanLine::query()->where('kind', PlanLine::RETAIL)->count())->toBe(0)
        ->and($this->plan->fresh()->removed)->toBe(['retail|Plaza']);

    // Și una care se deschide la anul: n-are istoric, se scrie de mână.
    $this->actingAs($retail)->post('/reports/forecast/'.$this->plan->id.'/rows', [
        'kind' => PlanLine::RETAIL, 'key' => 'Oradea Lotus', 'label' => 'Oradea Lotus',
        'months' => [4, 5, 6], 'volume' => 2, 'unit_cost' => 7_000,
        'load' => 0.015, 'unit_net' => 400_000,
    ])->assertRedirect();

    $new = PlanLine::query()->where('key', 'Oradea Lotus')->get();

    expect($new)->toHaveCount(3)
        ->and($new->first()->volume)->toEqual(2.0)
        // Doi oameni × 7.000 + 1,5% din 400.000 de lei vânduți.
        ->and($new->first()->shop(400_000))->toEqual(20_000.0);

    // Pornirea din istoric n-o aduce înapoi pe cea scoasă.
    app(PlanBuilder::class)->seed($this->plan->fresh());

    expect(PlanLine::query()->where('key', 'Plaza')->count())->toBe(0);
});

test('the salary line left after the shops are taken out belongs to administration', function () {
    PlanLine::query()->create([
        'plan_id' => $this->plan->id, 'kind' => PlanLine::OPEX, 'key' => '20200',
        'label' => 'Salarii si Contributii · Cheltuieli salarii angajati',
        'month' => 7, 'amount' => 1_000_000, 'manual' => true,
    ]);

    $sections = app(PlanSections::class);

    // Partea magazinelor s-a mutat pe fila rețelei; ce rămâne în linie sunt
    // oamenii din centrală, deci e al administrativului.
    expect($sections->forLine(PlanLine::OPEX, '20200', 'Salarii si Contributii · Cheltuieli salarii angajati'))
        ->toBe('cost:admin')
        ->and($sections->forLine(PlanLine::RETAIL, 'Plaza'))->toBe('cost:retail');

    // Iar retailul nu umblă la salariile centralei.
    $this->actingAs(personOf('Agenții retail'))->put('/reports/forecast/'.$this->plan->id.'/row', [
        'kind' => PlanLine::OPEX, 'key' => '20200', 'field' => 'amount', 'value' => 1,
    ])->assertForbidden();
});

test('a new product category is opened with the department that will hold it', function () {
    $boss = $this->boss;
    $sef = personOf('Croaziere fluviale');

    $this->actingAs($boss)->post('/reports/forecast/'.$this->plan->id.'/rows', [
        'kind' => PlanLine::CATEGORY, 'key' => 'Croaziere Dunăre', 'label' => 'Croaziere Dunăre',
        'department' => 'Croaziere fluviale',
        'months' => [5, 6, 7], 'volume' => 40, 'unit_net' => 6_000, 'unit_margin' => 900,
    ])->assertRedirect();

    expect(PlanLine::query()->where('key', 'Croaziere Dunăre')->count())->toBe(3);

    // Categoria nouă n-are cum să-și știe stăpânul din configurație, deci
    // și-l poartă în plan — iar șeful departamentului o are de-acum a lui.
    $sections = app(PlanSections::class);

    expect($sections->department('category:Croaziere Dunăre', $this->plan->fresh()))->toBe('Croaziere fluviale')
        ->and($sections->mine($sef, $this->plan->fresh()))->toContain('category:Croaziere Dunăre');

    // Și scrie în ea, fiindcă e a lui.
    $this->actingAs($sef)->put('/reports/forecast/'.$this->plan->id.'/row', [
        'kind' => PlanLine::CATEGORY, 'key' => 'Croaziere Dunăre', 'field' => 'volume', 'value' => 55,
    ])->assertRedirect();
});

test('a product category can be dropped from the plan, the costs and the shops cannot', function () {
    $sef = personOf('Senior Voyage');

    // O sub-linie a categoriei: iese și ea, odată cu categoria.
    PlanLine::query()->create([
        'plan_id' => $this->plan->id, 'kind' => PlanLine::CATEGORY,
        'key' => 'Senior Voyage · Grecia', 'label' => 'Senior Voyage · Grecia',
        'month' => 7, 'volume' => 10, 'unit_net' => 3_000, 'manual' => true,
    ]);

    $this->actingAs($sef)->delete('/reports/forecast/'.$this->plan->id.'/sections', [
        'key' => 'category:Senior Voyage',
    ])->assertRedirect();

    expect(PlanLine::query()->where('key', 'Senior Voyage')->count())->toBe(0)
        ->and(PlanLine::query()->where('key', 'Senior Voyage · Grecia')->count())->toBe(0)
        ->and($this->plan->fresh()->removed)->toContain('category|Senior Voyage')
        // Cealaltă categorie rămâne neatinsă.
        ->and(PlanLine::query()->where('key', 'Cazare')->count())->toBeGreaterThan(0);

    // Pornirea din istoric n-o aduce înapoi.
    app(PlanBuilder::class)->seed($this->plan->fresh());

    expect(PlanLine::query()->where('key', 'Senior Voyage')->count())->toBe(0);

    // Cheltuielile și rețeaua de agenții nu se scot nicicum.
    $this->actingAs($this->boss)->delete('/reports/forecast/'.$this->plan->id.'/sections', [
        'key' => 'cost:retail',
    ])->assertStatus(422);
});

test('a category held by someone else does not go out at a stranger s hand', function () {
    $this->actingAs(personOf('Marketing'))->delete('/reports/forecast/'.$this->plan->id.'/sections', [
        'key' => 'category:Senior Voyage',
    ])->assertForbidden();

    expect(PlanLine::query()->where('key', 'Senior Voyage')->count())->toBeGreaterThan(0);
});
