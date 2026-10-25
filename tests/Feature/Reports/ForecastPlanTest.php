<?php

use App\Models\Company;
use App\Models\ForecastHistory;
use App\Models\Plan;
use App\Models\PlanLine;
use App\Models\User;
use App\Services\Forecast\PlanBuilder;
use App\Services\Forecast\PlanEngine;

/**
 * Istoricul strâns, pus de-a gata: planul citește de acolo, nu din ERP-uri.
 *
 * @param  list<array<string, mixed>>  $rows
 */
function fakeForecastHistory(array $rows): void
{
    foreach ($rows as $row) {
        ForecastHistory::query()->create([
            'company_id' => test()->company->id,
            'unit' => 'pax',
            'volume' => 0,
            'net' => 0,
            'margin' => 0,
            ...$row,
        ]);
    }
}

function planFor(Company $company, int $year = 2027, int $base = 2026): Plan
{
    return Plan::query()->create([
        'company_id' => $company->id, 'year' => $year, 'name' => 'Buget '.$year,
        'base_year' => $base, 'status' => Plan::DRAFT,
    ]);
}

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->boss = User::factory()->withRoles('top_management')->create();
});

test('a plan starts from the passengers of the base year, with their money per head', function () {
    fakeForecastHistory([
        ['year' => 2026, 'kind' => 'category', 'key' => 'Circuite Culturale', 'month' => 5, 'volume' => 200, 'net' => 700_000, 'margin' => 130_000],
        ['year' => 2026, 'kind' => 'category', 'key' => 'Circuite Culturale', 'month' => 6, 'volume' => 100, 'net' => 350_000, 'margin' => 65_000],
        // Charterul se plănuiește pe destinații, nu pe el însuși.
        ['year' => 2026, 'kind' => 'category', 'key' => 'Charter', 'month' => 5, 'volume' => 1_000, 'net' => 4_000_000, 'margin' => 300_000],
    ]);

    $plan = planFor($this->company);
    app(PlanBuilder::class)->seed($plan);

    $may = PlanLine::query()->where('plan_id', $plan->id)->where('kind', PlanLine::CATEGORY)->where('month', 5)->sole();

    expect($may->key)->toBe('Circuite Culturale')
        ->and($may->volume)->toEqual(200.0)
        // 700.000 / 200 de pasageri = 3.500 de lei de pasager.
        ->and($may->unit_net)->toEqual(3_500.0)
        ->and($may->unit_margin)->toEqual(650.0)
        ->and(PlanLine::query()->where('plan_id', $plan->id)->where('key', 'Charter')->exists())->toBeFalse();
});

test('charter is planned on the seats already contracted for the year ahead', function () {
    fakeForecastHistory([
        // Anul de bază: 500 de locuri, 400 vândute → 80% ocupare, 4.000 lei
        // de pasager pe cursa asta.
        ['year' => 2026, 'kind' => 'charter', 'key' => 'OTP→AYT', 'month' => 6, 'capacity' => 500, 'sold' => 400, 'volume' => 400, 'net' => 1_600_000, 'margin' => 120_000],
        // Anul plănuit: s-au contractat deja 800 de locuri.
        ['year' => 2027, 'kind' => 'charter', 'key' => 'OTP→AYT', 'month' => 6, 'capacity' => 800, 'sold' => 0, 'volume' => 0],
    ]);

    $plan = planFor($this->company);
    app(PlanBuilder::class)->seed($plan);

    $line = PlanLine::query()->where('plan_id', $plan->id)->where('kind', PlanLine::CHARTER)->sole();

    expect($line->key)->toBe('OTP→AYT')
        ->and($line->capacity)->toEqual(800.0)
        ->and($line->load)->toEqual(0.8)
        // Banii pe pasager vin din categoria Charter a anului de bază.
        ->and($line->unit_net)->toEqual(4_000.0)
        ->and($line->unit_margin)->toEqual(300.0)
        // 800 de locuri × 80% = 640 de pasageri.
        ->and($line->pax())->toEqual(640.0)
        ->and($line->net())->toEqual(2_560_000.0)
        ->and($line->note)->toContain('Locuri contractate în eTrip pentru 2027');
});

test('the planned P&L is the levers multiplied, month by month', function () {
    fakeForecastHistory([
        ['year' => 2026, 'kind' => 'category', 'key' => 'Croaziere', 'month' => 3, 'volume' => 20, 'net' => 200_000, 'margin' => 30_000],
        ['year' => 2027, 'kind' => 'charter', 'key' => 'OTP→AYT', 'month' => 3, 'capacity' => 300, 'sold' => 0, 'volume' => 0],
    ]);

    $plan = planFor($this->company);
    app(PlanBuilder::class)->seed($plan);

    // Charterul n-are bani pe pasager fără istoric de categorie: i-i punem.
    PlanLine::query()->where('plan_id', $plan->id)->where('kind', PlanLine::CHARTER)
        ->update(['unit_net' => 4_000, 'unit_margin' => 400, 'load' => 0.5]);

    $out = app(PlanEngine::class)->compute($plan->fresh());

    // Croaziere: 20 × 10.000 = 200.000. Charter: 300 × 50% × 4.000 = 600.000.
    expect($out['months'][3]['net'])->toEqual(800_000.0)
        ->and($out['months'][3]['margin'])->toEqual(30_000.0 + 150 * 400)
        ->and($out['totals']['net'])->toEqual(800_000.0)
        ->and($out['totals']['pax'])->toEqual(170.0)
        // Fără cheltuieli scrise, EBITDA e marja întreagă.
        ->and($out['totals']['ebitda'])->toEqual($out['totals']['margin']);
});

test('what the hand writes stays, and the refresh does not step over it', function () {
    fakeForecastHistory([
        ['year' => 2026, 'kind' => 'category', 'key' => 'Croaziere', 'month' => 3, 'volume' => 20, 'net' => 200_000, 'margin' => 30_000],
    ]);

    $plan = planFor($this->company);
    $builder = app(PlanBuilder::class);
    $builder->seed($plan);

    $this->actingAs($this->boss)->put('/reports/forecast/'.$plan->id.'/row', [
        'kind' => PlanLine::CATEGORY, 'key' => 'Croaziere', 'field' => 'unit_net', 'value' => 12_000,
    ])->assertRedirect();

    $line = PlanLine::query()->where('plan_id', $plan->id)->sole();
    expect($line->unit_net)->toEqual(12_000.0)->and($line->manual)->toBeTrue();

    // Pornirea din istoric nu-l mai atinge.
    $builder->seed($plan);
    expect(PlanLine::query()->where('plan_id', $plan->id)->sole()->unit_net)->toEqual(12_000.0);

    // Până nu se cere înapoi la cifrele din istoric.
    $this->actingAs($this->boss)->put('/reports/forecast/'.$plan->id.'/row/reset', [
        'kind' => PlanLine::CATEGORY, 'key' => 'Croaziere',
    ])->assertRedirect();

    expect(PlanLine::query()->where('plan_id', $plan->id)->sole())
        ->unit_net->toEqual(10_000.0)
        ->manual->toBeFalse();
});

test('growth applies to every month of the row, keeping the shape of the year', function () {
    fakeForecastHistory([
        ['year' => 2026, 'kind' => 'category', 'key' => 'Cazare', 'month' => 1, 'volume' => 100, 'net' => 200_000, 'margin' => 20_000],
        ['year' => 2026, 'kind' => 'category', 'key' => 'Cazare', 'month' => 8, 'volume' => 400, 'net' => 800_000, 'margin' => 80_000],
    ]);

    $plan = planFor($this->company);
    app(PlanBuilder::class)->seed($plan);

    $this->actingAs($this->boss)->put('/reports/forecast/'.$plan->id.'/row', [
        'kind' => PlanLine::CATEGORY, 'key' => 'Cazare', 'field' => 'volume', 'growth' => 10,
    ])->assertRedirect();

    $lines = PlanLine::query()->where('plan_id', $plan->id)->orderBy('month')->get();

    // Vârful de august rămâne de patru ori cât ianuarie, dar amândouă cresc.
    expect($lines[0]->volume)->toEqual(110.0)
        ->and($lines[1]->volume)->toEqual(440.0);
});

test('the page is top management only', function () {
    fakeForecastHistory([]);

    $this->actingAs($this->boss)->get('/reports/forecast')->assertOk();

    foreach (['finance', 'treasury', 'operational', 'contract_management'] as $role) {
        $this->actingAs(User::factory()->withRoles($role)->create())
            ->get('/reports/forecast')
            ->assertForbidden();
    }
});

test('the plan is laid out like the P&L, line for line, with the base year beside it', function () {
    fakeForecastHistory([
        ['year' => 2026, 'kind' => 'category', 'key' => 'Croaziere', 'month' => 3, 'volume' => 20, 'net' => 200_000, 'margin' => 30_000],
    ]);

    $plan = planFor($this->company);
    app(PlanBuilder::class)->seed($plan);

    $statement = collect(app(PlanEngine::class)->compute($plan)['statement']);

    // Rândurile raportului, în ordinea lui.
    expect($statement->pluck('key')->take(2)->all())->toBe(['revenue', 'margin'])
        ->and($statement->pluck('key')->contains('costs'))->toBeTrue()
        ->and($statement->pluck('key')->contains('ebitda'))->toBeTrue()
        ->and($statement->last()['key'])->toBe('net')
        // Venitul e cel din pârghii, lună cu lună.
        ->and($statement->firstWhere('key', 'revenue')['months'][3])->toEqual(200_000.0)
        ->and($statement->firstWhere('key', 'revenue')['total'])->toEqual(200_000.0)
        // Fără cheltuieli scrise, EBITDA e marja.
        ->and($statement->firstWhere('key', 'ebitda')['total'])->toEqual(30_000.0);
});

test('Tina sits next to the tourism, counted in services, not in passengers', function () {
    fakeForecastHistory([
        ['year' => 2026, 'kind' => 'category', 'key' => 'Cazare', 'month' => 4, 'volume' => 100, 'net' => 300_000, 'margin' => 20_000],
        ['year' => 2026, 'kind' => 'category', 'key' => 'Tina · Ticketing', 'unit' => 'serviciu', 'month' => 4, 'volume' => 500, 'net' => 1_000_000, 'margin' => 350_000],
    ]);

    $plan = planFor($this->company);
    app(PlanBuilder::class)->seed($plan);

    $out = app(PlanEngine::class)->compute($plan);

    // Banii se adună împreună, volumele nu: un bilet de avion vândut unei
    // firme nu e un pasager plecat în vacanță.
    expect($out['totals']['net'])->toEqual(1_300_000.0)
        ->and($out['totals']['pax'])->toEqual(100.0)
        ->and($out['totals']['services'])->toEqual(500.0)
        ->and(collect($out['categories'])->firstWhere('key', 'Tina · Ticketing')['unit'])->toBe('serviciu')
        // Venitul pe serviciu: 1.000.000 / 500.
        ->and(collect($out['categories'])->firstWhere('key', 'Tina · Ticketing')['net'])->toEqual(1_000_000.0);
});

test('every route carries its own money, not the average of the fleet', function () {
    fakeForecastHistory([
        // Monastir lasă 2.800 de lei de pasager, Antalya 4.100.
        ['year' => 2026, 'kind' => 'charter', 'key' => 'OTP→MIR', 'month' => 7, 'capacity' => 200, 'sold' => 180, 'volume' => 180, 'net' => 504_000, 'margin' => 32_400],
        ['year' => 2026, 'kind' => 'charter', 'key' => 'OTP→AYT', 'month' => 7, 'capacity' => 300, 'sold' => 240, 'volume' => 240, 'net' => 984_000, 'margin' => 72_000],
        ['year' => 2027, 'kind' => 'charter', 'key' => 'OTP→MIR', 'month' => 7, 'capacity' => 400, 'sold' => 0, 'volume' => 0],
        ['year' => 2027, 'kind' => 'charter', 'key' => 'OTP→AYT', 'month' => 7, 'capacity' => 600, 'sold' => 0, 'volume' => 0],
    ]);

    $plan = planFor($this->company);
    app(PlanBuilder::class)->seed($plan);

    $lines = PlanLine::query()->where('plan_id', $plan->id)->where('kind', 'charter')->get()->keyBy('key');

    expect($lines['OTP→MIR']->unit_net)->toEqual(2_800.0)
        ->and($lines['OTP→AYT']->unit_net)->toEqual(4_100.0)
        // Și gradul de ocupare e al cursei: 90% la Monastir, 80% la Antalya.
        ->and($lines['OTP→MIR']->load)->toEqual(0.9)
        ->and($lines['OTP→AYT']->load)->toEqual(0.8);
});

test('the plan reads on periods, like the report: the year, a quarter, YTD or one month', function () {
    fakeForecastHistory([
        ['year' => 2026, 'kind' => 'category', 'key' => 'Cazare', 'month' => 2, 'volume' => 100, 'net' => 200_000, 'margin' => 20_000],
        ['year' => 2026, 'kind' => 'category', 'key' => 'Cazare', 'month' => 7, 'volume' => 300, 'net' => 900_000, 'margin' => 90_000],
    ]);

    $plan = planFor($this->company);
    app(PlanBuilder::class)->seed($plan);

    $engine = app(PlanEngine::class);

    expect($engine->compute($plan, 'year')['totals']['net'])->toEqual(1_100_000.0)
        // Trimestrul I cumulat: numai februarie.
        ->and($engine->compute($plan, 'q1')['totals']['net'])->toEqual(200_000.0)
        // Cumulat până în iulie: amândouă.
        ->and($engine->compute($plan, 'ytd7')['totals']['net'])->toEqual(1_100_000.0)
        // Doar iulie.
        ->and($engine->compute($plan, 'mtd7')['totals']['net'])->toEqual(900_000.0);
});

test('on IFRS 16 the planned rent leaves the costs and comes back as depreciation and interest', function () {
    fakeForecastHistory([
        ['year' => 2026, 'kind' => 'category', 'key' => 'Cazare', 'month' => 1, 'volume' => 100, 'net' => 500_000, 'margin' => 100_000],
    ]);

    $plan = planFor($this->company);
    app(PlanBuilder::class)->seed($plan);

    // O chirie planificată, pe linia de chirii a raportului.
    App\Models\PlanLine::query()->create([
        'plan_id' => $plan->id, 'kind' => 'opex', 'key' => '4000', 'label' => 'Chirii · Chirie principala',
        'month' => 1, 'amount' => 10_000, 'manual' => true,
    ]);

    // Și contractul din care se naște retratarea.
    App\Models\Lease::query()->create([
        'company_id' => $this->company->id, 'lessor' => 'Mall', 'currency' => 'Lei',
        'monthly_payment' => 10_000, 'starts_on' => '2027-01-01', 'ends_on' => '2029-12-31',
        'discount_rate' => 0.08, 'status' => App\Models\Lease::ACTIVE,
    ]);

    $engine = app(PlanEngine::class);
    $statutory = $engine->compute($plan, 'mtd1', 'ras');
    $ifrs = $engine->compute($plan, 'mtd1', 'ifrs16');

    // Statutar: chiria e cheltuială de exploatare.
    expect($statutory['totals']['opex'])->toEqual(10_000.0)
        // IFRS 16: iese din exploatare, deci EBITDA crește cu ea.
        ->and($ifrs['totals']['opex'])->toEqual(0.0)
        ->and($ifrs['totals']['ebitda'])->toEqual($statutory['totals']['ebitda'] + 10_000.0)
        // Și se întoarce sub EBITDA, ca amortizare plus dobândă.
        ->and($ifrs['totals']['below'])->toBeGreaterThan(0.0)
        ->and($ifrs['leases']['leases'])->toBe(1)
        // Profitul se schimbă doar cu diferența de ritm, nu cu toată chiria.
        ->and(abs($ifrs['totals']['net_profit'] - $statutory['totals']['net_profit']))->toBeLessThan(10_000.0);
});

test('the planned revenue is also read on sales channels, after the mix of the base year', function () {
    fakeForecastHistory([
        ['year' => 2026, 'kind' => 'category', 'key' => 'Cazare', 'month' => 3, 'volume' => 100, 'net' => 400_000, 'margin' => 40_000],
        // Anul de bază s-a vândut 75% prin agenții și 25% prin site.
        ['year' => 2026, 'kind' => 'mix', 'key' => 'Cazare', 'channel' => 'retail', 'month' => 3, 'net' => 300_000, 'margin' => 30_000],
        ['year' => 2026, 'kind' => 'mix', 'key' => 'Cazare', 'channel' => 'site', 'month' => 3, 'net' => 100_000, 'margin' => 10_000],
    ]);

    $plan = planFor($this->company);
    app(PlanBuilder::class)->seed($plan);

    $channels = collect(app(PlanEngine::class)->compute($plan)['channels'])->keyBy('channel');

    expect($channels['retail']['net'])->toEqual(300_000.0)
        ->and($channels['site']['net'])->toEqual(100_000.0)
        ->and($channels->sum('net'))->toEqual(400_000.0);
});

test('the months the base year has not lived yet are estimated, not left empty', function () {
    fakeForecastHistory([
        // 2026 are doar ianuarie; 2025 are și decembrie.
        ['year' => 2026, 'kind' => 'category', 'key' => 'Cazare', 'month' => 1, 'volume' => 110, 'net' => 220_000, 'margin' => 22_000],
        ['year' => 2025, 'kind' => 'category', 'key' => 'Cazare', 'month' => 1, 'volume' => 100, 'net' => 200_000, 'margin' => 20_000],
        ['year' => 2025, 'kind' => 'category', 'key' => 'Cazare', 'month' => 12, 'volume' => 50, 'net' => 100_000, 'margin' => 10_000],
    ]);

    $plan = planFor($this->company);
    app(PlanBuilder::class)->seed($plan);

    $lines = App\Models\PlanLine::query()->where('plan_id', $plan->id)->where('kind', 'category')->get()->keyBy('month');

    // Decembrie n-a existat în 2026: se ia din 2025, crescut cu cât a crescut
    // anul (220.000 / 200.000 = 1,1).
    expect($lines)->toHaveCount(2)
        ->and($lines[12]->volume)->toEqual(55.0)
        ->and($lines[12]->note)->toContain('estimat din 2025');
});
