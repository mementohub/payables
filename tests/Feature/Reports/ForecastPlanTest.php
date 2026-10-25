<?php

use App\Models\Company;
use App\Models\Plan;
use App\Models\PlanLine;
use App\Models\User;
use App\Services\Forecast\ForecastCharterReader;
use App\Services\Forecast\ForecastEtripReader;
use App\Services\Forecast\PlanBuilder;
use App\Services\Forecast\PlanEngine;
use Mockery\MockInterface;

/**
 * Istoricul din care pornește planul, înlocuit cu ce-i dăm noi.
 */
function fakeForecastSources(array $products = [], array $capacity = []): void
{
    test()->mock(ForecastEtripReader::class, fn (MockInterface $mock) => $mock->shouldReceive('byProduct')->andReturn($products));
    test()->mock(ForecastCharterReader::class, function (MockInterface $mock) use ($capacity) {
        $mock->shouldReceive('capacity')->andReturnUsing(fn (int $year) => $capacity[$year] ?? []);
    });
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
    fakeForecastSources([
        ['product' => 'Circuite Culturale', 'month' => 5, 'bookings' => 100, 'pax' => 200, 'net' => 700_000.0, 'margin' => 130_000.0],
        ['product' => 'Circuite Culturale', 'month' => 6, 'bookings' => 50, 'pax' => 100, 'net' => 350_000.0, 'margin' => 65_000.0],
        // Charterul se plănuiește pe destinații, nu pe el însuși.
        ['product' => 'Charter', 'month' => 5, 'bookings' => 500, 'pax' => 1_000, 'net' => 4_000_000.0, 'margin' => 300_000.0],
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
    fakeForecastSources(
        [['product' => 'Charter', 'month' => 6, 'bookings' => 500, 'pax' => 1_000, 'net' => 4_000_000.0, 'margin' => 300_000.0]],
        [
            // Anul de bază: 500 de locuri, 400 vândute → 80% grad de ocupare.
            2026 => [['destination' => 'AYT', 'home' => 'OTP', 'month' => 6, 'departures' => 4, 'seats' => 500, 'sold' => 400]],
            // Anul plănuit: s-au contractat deja 800.
            2027 => [['destination' => 'AYT', 'home' => 'OTP', 'month' => 6, 'departures' => 6, 'seats' => 800, 'sold' => 0]],
        ],
    );

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
        ->and($line->note)->toContain('6 plecări contractate');
});

test('the planned P&L is the levers multiplied, month by month', function () {
    fakeForecastSources(
        [['product' => 'Croaziere', 'month' => 3, 'bookings' => 10, 'pax' => 20, 'net' => 200_000.0, 'margin' => 30_000.0]],
        [2027 => [['destination' => 'AYT', 'home' => 'OTP', 'month' => 3, 'departures' => 2, 'seats' => 300, 'sold' => 0]]],
    );

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
    fakeForecastSources([
        ['product' => 'Croaziere', 'month' => 3, 'bookings' => 10, 'pax' => 20, 'net' => 200_000.0, 'margin' => 30_000.0],
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
    fakeForecastSources([
        ['product' => 'Cazare', 'month' => 1, 'bookings' => 10, 'pax' => 100, 'net' => 200_000.0, 'margin' => 20_000.0],
        ['product' => 'Cazare', 'month' => 8, 'bookings' => 40, 'pax' => 400, 'net' => 800_000.0, 'margin' => 80_000.0],
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
    fakeForecastSources();

    $this->actingAs($this->boss)->get('/reports/forecast')->assertOk();

    foreach (['finance', 'treasury', 'operational', 'contract_management'] as $role) {
        $this->actingAs(User::factory()->withRoles($role)->create())
            ->get('/reports/forecast')
            ->assertForbidden();
    }
});
