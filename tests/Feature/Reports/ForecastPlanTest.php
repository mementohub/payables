<?php

use App\Models\BaseEstimate;
use App\Models\Company;
use App\Models\ForecastHistory;
use App\Models\Lease;
use App\Models\Plan;
use App\Models\PlanLine;
use App\Models\User;
use App\Services\Forecast\PlanBuilder;
use App\Services\Forecast\PlanEngine;
use Inertia\Testing\AssertableInertia as Inertia;

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
    PlanLine::query()->create([
        'plan_id' => $plan->id, 'kind' => 'opex', 'key' => '4000', 'label' => 'Chirii · Chirie principala',
        'month' => 1, 'amount' => 10_000, 'manual' => true,
    ]);

    // Și contractul din care se naște retratarea.
    Lease::query()->create([
        'company_id' => $this->company->id, 'lessor' => 'Mall', 'currency' => 'Lei',
        'monthly_payment' => 10_000, 'starts_on' => '2027-01-01', 'ends_on' => '2029-12-31',
        'discount_rate' => 0.08, 'status' => Lease::ACTIVE,
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

    $lines = PlanLine::query()->where('plan_id', $plan->id)->where('kind', 'category')->get()->keyBy('month');

    // Două drumuri, nu unul: întâi decembrie 2026, care n-a existat, se ia
    // din 2025 crescut cu cât a crescut anul (220.000 / 200.000 = 1,1) —
    // adică 50 × 1,1 = 55. Apoi planul lui 2027 pleacă din 2026 cu trendul
    // ultimilor ani, tot 1,1: 55 × 1,1 = 60,5.
    expect($lines)->toHaveCount(2)
        ->and($lines[12]->volume)->toEqual(60.5)
        ->and($lines[1]->volume)->toEqual(121.0)
        ->and($lines[12]->note)->toContain('volumul e cel din 2025')
        ->and($lines[12]->note)->toContain('banii pe unitate sunt media lunilor trăite din 2026')
        ->and($lines[12]->note)->toContain('trendul ultimilor 2 ani')
        ->and($lines[1]->note)->toContain('Pornit din 2026');
});

test('operational keeps the booking month, financial moves the money to the month of departure', function () {
    fakeForecastHistory([
        // S-a vândut tot în ianuarie: 100 de pasageri, 1.000.000 de lei.
        ['year' => 2026, 'kind' => 'category', 'key' => 'Sejur Extern', 'month' => 1, 'volume' => 100, 'net' => 1_000_000, 'margin' => 100_000],
        // Din care un sfert a plecat tot în ianuarie și trei sferturi în iulie.
        ['year' => 2026, 'kind' => 'lag', 'key' => 'Sejur Extern', 'month' => 1, 'channel' => '1', 'net' => 250_000],
        ['year' => 2026, 'kind' => 'lag', 'key' => 'Sejur Extern', 'month' => 1, 'channel' => '7', 'net' => 750_000],
    ]);

    $plan = planFor($this->company);
    app(PlanBuilder::class)->seed($plan);

    $engine = app(PlanEngine::class);
    $operational = $engine->compute($plan, 'year', 'ras', 'operational');
    $financial = $engine->compute($plan, 'year', 'ras', 'financial');

    // Operațional: banii stau în luna rezervării, aia e baza lor.
    expect($operational['months'][1]['net'])->toEqual(1_000_000.0)
        ->and($operational['months'][7]['net'])->toEqual(0.0)
        ->and($operational['shifted'])->toBeFalse()
        // Financiar: se duc în lunile plecării, după tiparul anului de bază.
        ->and($financial['months'][1]['net'])->toEqual(250_000.0)
        ->and($financial['months'][7]['net'])->toEqual(750_000.0)
        ->and($financial['months'][7]['pax'])->toEqual(75.0)
        ->and($financial['shifted'])->toBeTrue()
        // Anul e același pe amândouă: nu se pierde și nu se naște venit.
        ->and($financial['totals']['net'])->toEqual($operational['totals']['net']);
});

test('charter is planned on the month of the flight, so the operational view gives it back to the months of sale', function () {
    fakeForecastHistory([
        // Anul de bază: cursa a dus 100 de oameni, la 4.000 de lei de om.
        ['year' => 2026, 'kind' => 'charter', 'key' => 'OTP→AYT', 'month' => 7, 'capacity' => 100, 'sold' => 100, 'volume' => 100, 'net' => 400_000, 'margin' => 40_000],
        ['year' => 2027, 'kind' => 'charter', 'key' => 'OTP→AYT', 'month' => 7, 'capacity' => 100, 'sold' => 0, 'volume' => 0],
        // Ce a plecat în iulie se vânduse jumătate în martie, jumătate în iulie.
        ['year' => 2026, 'kind' => 'lag', 'key' => 'Charter', 'month' => 3, 'channel' => '7', 'net' => 200_000],
        ['year' => 2026, 'kind' => 'lag', 'key' => 'Charter', 'month' => 7, 'channel' => '7', 'net' => 200_000],
    ]);

    $plan = planFor($this->company);
    app(PlanBuilder::class)->seed($plan);
    PlanLine::query()->where('plan_id', $plan->id)->where('kind', PlanLine::CHARTER)->update(['load' => 1]);

    $engine = app(PlanEngine::class);
    $operational = $engine->compute($plan->fresh(), 'year', 'ras', 'operational');
    $financial = $engine->compute($plan->fresh(), 'year', 'ras', 'financial');

    // Financiar: cursa rămâne în luna în care zboară.
    expect($financial['months'][7]['net'])->toEqual(400_000.0)
        ->and($financial['months'][3]['net'])->toEqual(0.0)
        // Operațional: jumătate din bani se întorc în martie, când s-au vândut.
        ->and($operational['months'][3]['net'])->toEqual(200_000.0)
        ->and($operational['months'][7]['net'])->toEqual(200_000.0)
        ->and($operational['totals']['net'])->toEqual(400_000.0);
});

test('a category without a pattern of its own stays where it is, on both views', function () {
    fakeForecastHistory([
        ['year' => 2026, 'kind' => 'category', 'key' => 'Tina · Ticketing', 'unit' => 'serviciu', 'month' => 4, 'volume' => 500, 'net' => 1_000_000, 'margin' => 350_000],
        // Tiparul e al altei categorii; Tina nu trece prin eTrip.
        ['year' => 2026, 'kind' => 'lag', 'key' => 'Sejur Extern', 'month' => 4, 'channel' => '8', 'net' => 900_000],
    ]);

    $plan = planFor($this->company);
    app(PlanBuilder::class)->seed($plan);

    $financial = app(PlanEngine::class)->compute($plan, 'year', 'ras', 'financial');

    expect($financial['months'][4]['net'])->toEqual(1_000_000.0)
        ->and($financial['months'][8]['net'])->toEqual(0.0);
});

test('the whole table is saved at once, cells and rows together', function () {
    fakeForecastHistory([
        ['year' => 2026, 'kind' => 'category', 'key' => 'Cazare', 'month' => 1, 'volume' => 100, 'net' => 200_000, 'margin' => 20_000],
        ['year' => 2026, 'kind' => 'category', 'key' => 'Cazare', 'month' => 2, 'volume' => 100, 'net' => 200_000, 'margin' => 20_000],
        ['year' => 2026, 'kind' => 'category', 'key' => 'Croaziere', 'month' => 1, 'volume' => 10, 'net' => 100_000, 'margin' => 10_000],
    ]);

    $plan = planFor($this->company);
    app(PlanBuilder::class)->seed($plan);

    $lines = PlanLine::query()->where('plan_id', $plan->id)->get();
    $january = $lines->firstWhere(fn (PlanLine $line) => $line->key === 'Cazare' && (int) $line->month === 1);

    $this->actingAs($this->boss)->put('/reports/forecast/'.$plan->id.'/bulk', [
        // O lună anume, scrisă cu mâna.
        'cells' => [['id' => $january->id, 'field' => 'volume', 'value' => 180]],
        // Și două rânduri întregi: unul pus la o cifră, altul crescut.
        'rows' => [
            ['kind' => PlanLine::CATEGORY, 'key' => 'Cazare', 'field' => 'unit_net', 'value' => 2_500],
            ['kind' => PlanLine::CATEGORY, 'key' => 'Croaziere', 'field' => 'volume', 'growth' => 50],
        ],
    ])->assertRedirect();

    $after = PlanLine::query()->where('plan_id', $plan->id)->get();
    $cazare = $after->where('key', 'Cazare')->keyBy('month');

    // Celula bate rândul: ianuarie are 180, februarie a rămas la 100.
    expect($cazare[1]->volume)->toEqual(180.0)
        ->and($cazare[2]->volume)->toEqual(100.0)
        // Rândul întreg a prins amândouă lunile.
        ->and($cazare[1]->unit_net)->toEqual(2_500.0)
        ->and($cazare[2]->unit_net)->toEqual(2_500.0)
        ->and($after->firstWhere('key', 'Croaziere')->volume)->toEqual(15.0)
        ->and($after->every(fn (PlanLine $line) => $line->manual))->toBeTrue();
});

test('a bulk save only writes into its own plan, and only the fields of the plan', function () {
    fakeForecastHistory([
        ['year' => 2026, 'kind' => 'category', 'key' => 'Cazare', 'month' => 1, 'volume' => 100, 'net' => 200_000, 'margin' => 20_000],
    ]);

    $mine = planFor($this->company);
    $other = planFor($this->company, 2028, 2026);
    $builder = app(PlanBuilder::class);
    $builder->seed($mine);
    $builder->seed($other);

    $stranger = PlanLine::query()->where('plan_id', $other->id)->sole();

    $this->actingAs($this->boss)->put('/reports/forecast/'.$mine->id.'/bulk', [
        'cells' => [['id' => $stranger->id, 'field' => 'volume', 'value' => 999]],
    ])->assertRedirect();

    expect($stranger->fresh()->volume)->toEqual(100.0);

    // Și nu se scrie orice coloană.
    $this->actingAs($this->boss)->put('/reports/forecast/'.$mine->id.'/bulk', [
        'cells' => [['id' => PlanLine::query()->where('plan_id', $mine->id)->value('id'), 'field' => 'manual', 'value' => 0]],
    ])->assertSessionHasErrors('cells.0.field');
});

test('the plan is read on columns too — product categories and sales channels — and every row closes', function () {
    fakeForecastHistory([
        ['year' => 2026, 'kind' => 'category', 'key' => 'Cazare', 'month' => 3, 'volume' => 100, 'net' => 600_000, 'margin' => 60_000],
        ['year' => 2026, 'kind' => 'category', 'key' => 'Croaziere', 'month' => 3, 'volume' => 20, 'net' => 200_000, 'margin' => 20_000],
        ['year' => 2026, 'kind' => 'mix', 'key' => 'Cazare', 'channel' => 'retail', 'month' => 3, 'net' => 450_000],
        ['year' => 2026, 'kind' => 'mix', 'key' => 'Cazare', 'channel' => 'site', 'month' => 3, 'net' => 150_000],
        ['year' => 2026, 'kind' => 'mix', 'key' => 'Croaziere', 'channel' => 'retail', 'month' => 3, 'net' => 200_000],
    ]);

    $plan = planFor($this->company);
    app(PlanBuilder::class)->seed($plan);

    PlanLine::query()->create([
        'plan_id' => $plan->id, 'kind' => 'opex', 'key' => '6210', 'label' => 'Salarii · Salarii',
        'month' => 3, 'amount' => 80_000, 'manual' => true,
    ]);

    $out = app(PlanEngine::class)->compute($plan);

    $product = collect($out['columns']['product'])->keyBy('key');
    $channel = collect($out['columns']['channel'])->keyBy('key');
    $rows = collect($out['statement'])->keyBy('key');

    // Coloanele: venitul fiecărei categorii și al fiecărui canal.
    expect($product['Cazare']['net'])->toEqual(600_000.0)
        ->and($product['Croaziere']['net'])->toEqual(200_000.0)
        ->and($channel['retail']['net'])->toEqual(650_000.0)
        ->and($channel['site']['net'])->toEqual(150_000.0)
        // Aceleași rânduri ca în P&L, citite pe coloane.
        ->and($rows['revenue']['product']['Cazare'])->toEqual(600_000.0)
        // Cheltuiala comună se împarte după venit, cât timp n-are anul de
        // bază construit: 600.000 / 800.000 din 80.000.
        ->and($rows['line:6210']['product']['Cazare'])->toEqual(60_000.0)
        ->and($rows['line:6210']['channel']['site'])->toEqual(15_000.0);

    // Și fiecare rând se închide pe orizontală, nu doar pe verticală.
    foreach (['revenue', 'margin', 'costs', 'ebitda', 'net'] as $key) {
        expect(round(array_sum($rows[$key]['product']), 2))->toEqual($rows[$key]['total'])
            ->and(round(array_sum($rows[$key]['channel']), 2))->toEqual($rows[$key]['total']);
    }
});

test('the period is chosen like on the P&L: one month and the way it is read', function () {
    fakeForecastHistory([
        ['year' => 2026, 'kind' => 'category', 'key' => 'Cazare', 'month' => 2, 'volume' => 100, 'net' => 200_000, 'margin' => 20_000],
        ['year' => 2026, 'kind' => 'category', 'key' => 'Cazare', 'month' => 7, 'volume' => 300, 'net' => 900_000, 'margin' => 90_000],
    ]);

    $plan = planFor($this->company);
    app(PlanBuilder::class)->seed($plan);

    // Implicit: anul întreg, cumulat până în decembrie.
    $this->actingAs($this->boss)->get('/reports/forecast')
        ->assertInertia(fn (Inertia $page) => $page
            ->where('filters.period', 'ytd12')
            ->where('output.totals.net', 1_100_000));

    // Cumulat până în iulie, apoi doar iulie.
    $this->actingAs($this->boss)->get('/reports/forecast?period=ytd7')
        ->assertInertia(fn (Inertia $page) => $page->where('output.totals.net', 1_100_000));

    $this->actingAs($this->boss)->get('/reports/forecast?period=mtd7')
        ->assertInertia(fn (Inertia $page) => $page->where('output.totals.net', 900_000));

    // Trimestrele, care nu mai au de unde fi alese, nu mai sunt primite.
    $this->actingAs($this->boss)->get('/reports/forecast?period=q1')
        ->assertInertia(fn (Inertia $page) => $page->where('filters.period', 'ytd12'));
});

test('the default start is the base year carried forward by the trend of three years', function () {
    fakeForecastHistory([
        // Volumul crește cu 10% pe an, banii pe pasager cu 5%.
        ['year' => 2024, 'kind' => 'category', 'key' => 'Cazare', 'month' => 3, 'volume' => 100, 'net' => 100_000, 'margin' => 10_000],
        ['year' => 2025, 'kind' => 'category', 'key' => 'Cazare', 'month' => 3, 'volume' => 110, 'net' => 115_500, 'margin' => 11_550],
        ['year' => 2026, 'kind' => 'category', 'key' => 'Cazare', 'month' => 3, 'volume' => 121, 'net' => 133_402, 'margin' => 13_340],
    ]);

    $plan = planFor($this->company);
    app(PlanBuilder::class)->seed($plan);

    $line = PlanLine::query()->where('plan_id', $plan->id)->where('kind', PlanLine::CATEGORY)->sole();

    // Volumul: 121 × 1,1 = 133,1. Venitul pe pasager: 1.102,5 × 1,05 = 1.157,6.
    expect($line->volume)->toEqual(133.1)
        ->and(round((float) $line->unit_net))->toEqual(1158.0)
        ->and($line->note)->toContain('trendul ultimilor 3 ani')
        ->and($line->note)->toContain('+10,0%');
});

test('the trend compares only the months the base year has lived', function () {
    fakeForecastHistory([
        // 2025 are tot anul, 2026 doar lunile trăite. Fără tăiere, anul de
        // bază ar părea pe jumătate și trendul ar ieși în scădere.
        ['year' => 2025, 'kind' => 'category', 'key' => 'Cazare', 'month' => 1, 'volume' => 100, 'net' => 100_000, 'margin' => 10_000],
        ['year' => 2025, 'kind' => 'category', 'key' => 'Cazare', 'month' => 11, 'volume' => 900, 'net' => 900_000, 'margin' => 90_000],
        ['year' => 2026, 'kind' => 'category', 'key' => 'Cazare', 'month' => 1, 'volume' => 100, 'net' => 100_000, 'margin' => 10_000],
    ]);

    $plan = planFor($this->company);
    app(PlanBuilder::class)->seed($plan);

    // Ianuarie: la fel ca în 2026, fiindcă ianuarie e la fel ca în 2025.
    expect(PlanLine::query()->where('plan_id', $plan->id)->where('month', 1)->sole()->volume)->toEqual(100.0);
});

test('the months the base year has not lived can be written by hand, and the plan moves under them', function () {
    fakeForecastHistory([
        ['year' => 2025, 'kind' => 'category', 'key' => 'Cazare', 'month' => 11, 'volume' => 100, 'net' => 300_000, 'margin' => 30_000],
        ['year' => 2026, 'kind' => 'category', 'key' => 'Cazare', 'month' => 1, 'volume' => 100, 'net' => 300_000, 'margin' => 30_000],
        ['year' => 2025, 'kind' => 'category', 'key' => 'Cazare', 'month' => 1, 'volume' => 100, 'net' => 300_000, 'margin' => 30_000],
    ]);

    $plan = planFor($this->company);
    app(PlanBuilder::class)->seed($plan);

    // Noiembrie pornește din 2025, socotit de aplicație.
    expect(PlanLine::query()->where('plan_id', $plan->id)->where('month', 11)->sole()->volume)->toEqual(100.0);

    $this->actingAs($this->boss)->put('/reports/forecast/'.$plan->id.'/base', [
        'kind' => PlanLine::CATEGORY, 'key' => 'Cazare', 'month' => 11,
        'volume' => 250, 'unit_net' => 4_000, 'unit_margin' => 400,
    ])->assertRedirect();

    expect(BaseEstimate::query()->sole())
        ->volume->toEqual(250.0)
        ->year->toEqual(2026);

    // Planul s-a reașezat singur: noiembrie 2027 pleacă din noiembrie 2026.
    $november = PlanLine::query()->where('plan_id', $plan->id)->where('month', 11)->sole();

    expect($november->volume)->toEqual(250.0)
        ->and($november->unit_net)->toEqual(4_000.0)
        ->and($november->note)->toContain('scrisă de mână');

    // Și se poate da înapoi la socoteala aplicației.
    $this->actingAs($this->boss)->put('/reports/forecast/'.$plan->id.'/base/reset', [
        'kind' => PlanLine::CATEGORY, 'key' => 'Cazare',
    ])->assertRedirect();

    expect(BaseEstimate::query()->count())->toBe(0)
        ->and(PlanLine::query()->where('plan_id', $plan->id)->where('month', 11)->sole()->volume)->toEqual(100.0);
});

test('a month the base year has already lived cannot be written by hand', function () {
    fakeForecastHistory([
        ['year' => 2026, 'kind' => 'category', 'key' => 'Cazare', 'month' => 1, 'volume' => 100, 'net' => 300_000, 'margin' => 30_000],
    ]);

    $plan = planFor($this->company);
    app(PlanBuilder::class)->seed($plan);

    // Ianuarie e trecut: cifra lui e din ERP, nu se scrie peste ea.
    $this->actingAs($this->boss)->put('/reports/forecast/'.$plan->id.'/base', [
        'kind' => PlanLine::CATEGORY, 'key' => 'Cazare', 'month' => 1, 'volume' => 999,
    ])->assertSessionHasErrors('month');
});

test('the base year column counts the months that have not come yet, so the two years are the same length', function () {
    fakeForecastHistory([
        ['year' => 2025, 'kind' => 'category', 'key' => 'Cazare', 'month' => 12, 'volume' => 100, 'net' => 500_000, 'margin' => 50_000],
        ['year' => 2026, 'kind' => 'category', 'key' => 'Cazare', 'month' => 1, 'volume' => 100, 'net' => 500_000, 'margin' => 50_000],
    ]);

    $plan = planFor($this->company);
    app(PlanBuilder::class)->seed($plan);

    $this->actingAs($this->boss)->put('/reports/forecast/'.$plan->id.'/base', [
        'kind' => PlanLine::CATEGORY, 'key' => 'Cazare', 'month' => 12,
        'volume' => 200, 'unit_net' => 5_000, 'unit_margin' => 500,
    ])->assertRedirect();

    $overlay = app(PlanBuilder::class)->openOverlay($plan->fresh());

    // Decembrie 2026, scris de mână: 200 × 5.000 = 1.000.000.
    expect($overlay['months'])->toContain(12)
        ->and($overlay['revenue'][12])->toEqual(1_000_000.0)
        ->and($overlay['products']['Cazare'][12]['net'])->toEqual(1_000_000.0);
});

test('the profit tax is worked out from the plan, quarterly, and never below the tax on turnover', function () {
    fakeForecastHistory([
        ['year' => 2026, 'kind' => 'category', 'key' => 'Cazare', 'month' => 3, 'volume' => 1_000, 'net' => 10_000_000, 'margin' => 2_000_000],
    ]);

    $plan = planFor($this->company);
    app(PlanBuilder::class)->seed($plan);

    $engine = app(PlanEngine::class);
    $rows = collect($engine->compute($plan)['statement'])->keyBy('key');

    // Profit impozabil 2.000.000 → 16% = 320.000; cifra de afaceri
    // 10.000.000 → 0,5% = 50.000. Se plătește impozitul pe profit.
    expect($rows['below:impozit']['total'])->toEqual(320_000.0)
        // Vărsat în luna de capăt de trimestru, nu împrăștiat pe tot anul.
        ->and($rows['below:impozit']['months'][3])->toEqual(320_000.0)
        ->and($rows['below:impozit']['months'][2])->toEqual(0.0);

    // Pe pierdere, se plătește impozitul minim pe cifra de afaceri.
    PlanLine::query()->create([
        'plan_id' => $plan->id, 'kind' => 'opex', 'key' => '6210', 'label' => 'Salarii · Salarii',
        'month' => 3, 'amount' => 3_000_000, 'manual' => true,
    ]);

    $rows = collect($engine->compute($plan->fresh())['statement'])->keyBy('key');

    expect($rows['ebitda']['total'])->toEqual(-1_000_000.0)
        ->and($rows['below:impozit']['total'])->toEqual(50_000.0);
});

test('a profit tax written by hand is left alone', function () {
    fakeForecastHistory([
        ['year' => 2026, 'kind' => 'category', 'key' => 'Cazare', 'month' => 3, 'volume' => 1_000, 'net' => 10_000_000, 'margin' => 2_000_000],
    ]);

    $plan = planFor($this->company);
    app(PlanBuilder::class)->seed($plan);

    PlanLine::query()->create([
        'plan_id' => $plan->id, 'kind' => 'below', 'key' => 'impozit', 'label' => 'Impozit pe profit',
        'month' => 6, 'amount' => 123_456, 'manual' => true,
    ]);

    $rows = collect(app(PlanEngine::class)->compute($plan->fresh())['statement'])->keyBy('key');

    expect($rows['below:impozit']['total'])->toEqual(123_456.0);
});

test('a line that moves only now and then keeps its shape; one that runs every month keeps its pace', function () {
    fakeForecastHistory([
        ['year' => 2026, 'kind' => 'category', 'key' => 'Cazare', 'month' => 1, 'volume' => 100, 'net' => 200_000, 'margin' => 20_000],
    ]);

    $plan = planFor($this->company);
    app(PlanBuilder::class)->seed($plan);

    $builder = app(PlanBuilder::class);
    $fill = new ReflectionMethod($builder, 'fillYear');
    $open = PlanBuilder::openMonths(2026);
    $lived = array_values(array_diff(range(1, 12), $open));

    // Salarii: mișcare în fiecare lună trăită → ritmul lunii.
    $salaries = array_fill_keys($lived, 100_000.0);
    $filled = $fill->invoke($builder, $salaries, [], $open, [12 => 900_000.0]);

    expect($filled[$open[0]]['amount'])->toEqual(100_000.0)
        ->and($filled[$open[0]]['source'])->toBe('average');

    // Impozit: trei vărsăminte pe an → luna aceeași din anul dinainte.
    $tax = [3 => 300_000.0, 6 => 800_000.0, 9 => 2_600_000.0];
    $filled = $fill->invoke($builder, $tax, [], $open, [12 => 1_300_000.0]);

    expect($filled[12]['source'])->toBe('before')
        // Lunile goale rămân goale, nu iau media celor cu mișcare.
        ->and($filled[2]['amount'])->toEqual(0.0)
        ->and($filled[11]['amount'])->toEqual(0.0);
});

test('a plan can be asked to start from the base year as it is, with no growth on top', function () {
    fakeForecastHistory([
        ['year' => 2024, 'kind' => 'category', 'key' => 'Cazare', 'month' => 3, 'volume' => 100, 'net' => 100_000, 'margin' => 10_000],
        ['year' => 2025, 'kind' => 'category', 'key' => 'Cazare', 'month' => 3, 'volume' => 110, 'net' => 115_500, 'margin' => 11_550],
        ['year' => 2026, 'kind' => 'category', 'key' => 'Cazare', 'month' => 3, 'volume' => 121, 'net' => 133_402, 'margin' => 13_340],
    ]);

    $plan = planFor($this->company);

    $this->actingAs($this->boss)->put('/reports/forecast/'.$plan->id.'/start', ['start' => 'base'])
        ->assertRedirect();

    $line = PlanLine::query()->where('plan_id', $plan->id)->where('kind', PlanLine::CATEGORY)->sole();

    // Exact 2026, fără trend: 121 de pasageri la 1.102,5 lei.
    expect($line->volume)->toEqual(121.0)
        ->and($line->unit_net)->toEqual(1_102.5)
        ->and($line->note)->toContain('anul de bază ca atare');

    // Și înapoi la trend: 121 × 1,1.
    $this->actingAs($this->boss)->put('/reports/forecast/'.$plan->id.'/start', ['start' => 'trend'])
        ->assertRedirect();

    expect(PlanLine::query()->where('plan_id', $plan->id)->where('kind', PlanLine::CATEGORY)->sole()->volume)
        ->toEqual(133.1);
});

test('the plan is read against three years, and an unfinished year says so', function () {
    fakeForecastHistory([
        ['year' => 2026, 'kind' => 'category', 'key' => 'Cazare', 'month' => 1, 'volume' => 100, 'net' => 200_000, 'margin' => 20_000],
    ]);

    $plan = planFor($this->company);
    app(PlanBuilder::class)->seed($plan);

    $out = app(PlanEngine::class)->compute($plan);
    $years = collect($out['years']);

    // Anii se citesc de la cel mai vechi la cel mai nou, ca evoluția să
    // meargă de la stânga la dreapta.
    expect($years->pluck('year')->all())->toBe([2024, 2025, 2026])
        // Anul de bază e neîncheiat: lunile care n-au venit sunt spuse.
        ->and($years->firstWhere('year', 2026)['estimated'])
        ->toBe(PlanBuilder::openMonths(2026))
        // Fiecare rând poartă cifra fiecărui an, nu doar a anului de bază.
        ->and(collect($out['statement'])->firstWhere('key', 'revenue'))
        ->toHaveKeys(['years', 'years_product', 'years_channel']);
});

test('the year in progress is shown whole: what has happened and what is still to come', function () {
    fakeForecastHistory([
        // Ianuarie e trăit, noiembrie nu: planul lui 2027 pornește din anul
        // întreg, nu din lunile trăite.
        ['year' => 2026, 'kind' => 'category', 'key' => 'Cazare', 'month' => 1, 'volume' => 100, 'net' => 300_000, 'margin' => 30_000],
        ['year' => 2025, 'kind' => 'category', 'key' => 'Cazare', 'month' => 1, 'volume' => 100, 'net' => 300_000, 'margin' => 30_000],
        ['year' => 2025, 'kind' => 'category', 'key' => 'Cazare', 'month' => 11, 'volume' => 80, 'net' => 240_000, 'margin' => 24_000],
    ]);

    $plan = planFor($this->company);
    app(PlanBuilder::class)->seed($plan);

    $overlay = app(PlanBuilder::class)->openOverlay($plan);

    // Lunile care n-au venit aduc venit în anul de bază, nu zero.
    expect($overlay['months'])->toBe(PlanBuilder::openMonths(2026))
        ->and($overlay['revenue'][11])->toEqual(80 * 3_000.0);

    // Și planul pornește din ele: noiembrie 2027 are de unde pleca.
    expect(PlanLine::query()->where('plan_id', $plan->id)->where('month', 11)->exists())->toBeTrue();
});

test('a route can be dropped from the plan and the refresh does not bring it back', function () {
    fakeForecastHistory([
        ['year' => 2026, 'kind' => 'charter', 'key' => 'OTP→MIR', 'month' => 7, 'capacity' => 200, 'sold' => 180, 'volume' => 180, 'net' => 504_000, 'margin' => 32_400],
        ['year' => 2027, 'kind' => 'charter', 'key' => 'OTP→MIR', 'month' => 7, 'capacity' => 400, 'sold' => 0, 'volume' => 0],
    ]);

    $plan = planFor($this->company);
    $builder = app(PlanBuilder::class);
    $builder->seed($plan);

    expect(PlanLine::query()->where('plan_id', $plan->id)->where('kind', 'charter')->count())->toBe(1);

    $this->actingAs($this->boss)->delete('/reports/forecast/'.$plan->id.'/rows', [
        'kind' => PlanLine::CHARTER, 'key' => 'OTP→MIR',
    ])->assertRedirect();

    expect(PlanLine::query()->where('plan_id', $plan->id)->where('kind', 'charter')->count())->toBe(0)
        ->and($plan->fresh()->removed)->toBe(['charter|OTP→MIR']);

    // Împrospătarea din istoric n-o aduce înapoi.
    $builder->seed($plan->fresh());

    expect(PlanLine::query()->where('plan_id', $plan->id)->where('kind', 'charter')->count())->toBe(0);

    // Până nu se cere înapoi.
    $this->actingAs($this->boss)->put('/reports/forecast/'.$plan->id.'/rows/restore', [
        'kind' => PlanLine::CHARTER, 'key' => 'OTP→MIR',
    ])->assertRedirect();

    expect(PlanLine::query()->where('plan_id', $plan->id)->where('kind', 'charter')->count())->toBe(1)
        ->and($plan->fresh()->removed)->toBe([]);
});

test('a route that has never flown can be written into the plan by hand', function () {
    fakeForecastHistory([
        ['year' => 2026, 'kind' => 'category', 'key' => 'Charter', 'month' => 7, 'volume' => 100, 'net' => 400_000, 'margin' => 40_000],
    ]);

    $plan = planFor($this->company);
    app(PlanBuilder::class)->seed($plan);

    $this->actingAs($this->boss)->post('/reports/forecast/'.$plan->id.'/rows', [
        'kind' => PlanLine::CHARTER, 'key' => 'OTP→RAK', 'label' => 'OTP→RAK',
        'months' => [6, 7, 8], 'capacity' => 189, 'load' => 0.8,
        'unit_net' => 4_500, 'unit_margin' => 400, 'unit_cost' => 1_500,
    ])->assertRedirect();

    $lines = PlanLine::query()->where('plan_id', $plan->id)->where('key', 'OTP→RAK')->get();

    expect($lines)->toHaveCount(3)
        ->and($lines->every(fn (PlanLine $line) => $line->manual))->toBeTrue()
        ->and($lines->first()->pax())->toEqual(151.2)
        // 189 × 80% × 4.500 de lei, pe fiecare din cele trei luni.
        ->and(round($lines->sum(fn (PlanLine $line) => $line->net())))->toEqual(2_041_200.0);

    // Și nu se poate adăuga de două ori.
    $this->actingAs($this->boss)->post('/reports/forecast/'.$plan->id.'/rows', [
        'kind' => PlanLine::CHARTER, 'key' => 'OTP→RAK', 'months' => [6],
    ])->assertStatus(422);
});
