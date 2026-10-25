<?php

use App\Models\Company;
use App\Models\ForecastHistory;
use App\Models\Plan;
use App\Models\PlanLine;
use App\Models\PlanVersion;
use App\Models\User;
use App\Services\Exports\PlanExport;
use App\Services\Forecast\PlanBuilder;
use App\Services\Forecast\PlanEngine;

/**
 * Variantele planului și cele trei feluri în care pleacă din aplicație.
 */
function planWithLevers(Company $company): Plan
{
    foreach ([
        ['year' => 2026, 'kind' => 'category', 'key' => 'Cazare', 'month' => 3, 'volume' => 100, 'net' => 600_000, 'margin' => 60_000],
        ['year' => 2026, 'kind' => 'category', 'key' => 'Charter', 'month' => 7, 'volume' => 200, 'net' => 800_000, 'margin' => 80_000],
        ['year' => 2026, 'kind' => 'charter', 'key' => 'OTP→AYT', 'month' => 7, 'capacity' => 250, 'sold' => 200, 'volume' => 200, 'net' => 800_000, 'margin' => 80_000],
        ['year' => 2027, 'kind' => 'charter', 'key' => 'OTP→AYT', 'month' => 7, 'capacity' => 300, 'sold' => 0, 'volume' => 0],
        ['year' => 2026, 'kind' => 'mix', 'key' => 'Cazare', 'channel' => 'retail', 'month' => 3, 'net' => 600_000],
    ] as $row) {
        ForecastHistory::query()->create(['company_id' => $company->id, 'unit' => 'pax', 'volume' => 0, 'net' => 0, 'margin' => 0, ...$row]);
    }

    $plan = Plan::query()->create([
        'company_id' => $company->id, 'year' => 2027, 'name' => 'Buget 2027',
        'base_year' => 2026, 'status' => Plan::DRAFT,
    ]);

    app(PlanBuilder::class)->seed($plan);

    PlanLine::query()->create([
        'plan_id' => $plan->id, 'kind' => 'opex', 'key' => '6210', 'label' => 'Salarii · Salarii',
        'month' => 3, 'amount' => 90_000, 'manual' => true,
    ]);

    return $plan;
}

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->boss = User::factory()->withRoles('top_management')->create();
    $this->plan = planWithLevers($this->company);
});

test('a version keeps the levers whole, not just the totals', function () {
    $this->actingAs($this->boss)->post('/reports/forecast/'.$this->plan->id.'/versions', [
        'label' => 'Buget inițial',
        'note' => 'Charterul crescut cu locurile contractate, restul lăsat ca anul trecut.',
        'period' => 'ytd12', 'basis' => 'ras', 'mode' => 'operational',
    ])->assertRedirect();

    $version = PlanVersion::query()->sole();

    expect($version->label)->toBe('Buget inițial')
        ->and($version->created_by_id)->toBe($this->boss->id)
        ->and($version->lines)->toHaveCount($this->plan->lines()->count())
        // Pârghiile, nu doar cifrele: din ele se poate reface planul.
        ->and($version->lines[0])->toHaveKeys(['kind', 'key', 'month', 'volume', 'unit_net', 'manual'])
        ->and($version->summary['net'])->toBeGreaterThan(0)
        ->and($version->summary['base']['year'])->toBe(2026);
});

test('loading a version puts the plan back on its numbers', function () {
    $this->actingAs($this->boss)->post('/reports/forecast/'.$this->plan->id.'/versions', [
        'label' => 'Cum era la început',
    ])->assertRedirect();

    $version = PlanVersion::query()->sole();
    $before = $this->plan->lines()->where('kind', 'category')->sole()->unit_net;

    // Se schimbă planul...
    $this->actingAs($this->boss)->put('/reports/forecast/'.$this->plan->id.'/row', [
        'kind' => 'category', 'key' => 'Cazare', 'field' => 'unit_net', 'value' => 9_999,
    ])->assertRedirect();

    expect($this->plan->lines()->where('kind', 'category')->sole()->unit_net)->toEqual(9_999.0);

    // ... și se dă înapoi la variantă.
    $this->actingAs($this->boss)->post('/reports/forecast/'.$this->plan->id.'/versions/'.$version->id.'/restore')
        ->assertRedirect();

    expect($this->plan->lines()->where('kind', 'category')->sole()->unit_net)->toEqual($before)
        ->and($this->plan->lines()->count())->toBe(count($version->lines));
});

test('a version of one plan cannot be loaded into another', function () {
    $this->actingAs($this->boss)->post('/reports/forecast/'.$this->plan->id.'/versions', ['label' => 'A mea'])
        ->assertRedirect();

    $other = Plan::query()->create([
        'company_id' => $this->company->id, 'year' => 2028, 'name' => 'Alt plan',
        'base_year' => 2026, 'status' => Plan::DRAFT,
    ]);

    $this->actingAs($this->boss)
        ->post('/reports/forecast/'.$other->id.'/versions/'.PlanVersion::query()->sole()->id.'/restore')
        ->assertNotFound();
});

test('the plan leaves the application as Excel, as paper and as slides', function () {
    foreach ([
        ['xlsx', 'spreadsheetml.sheet', 'PK'],
        ['pdf', 'application/pdf', '%PDF'],
        ['pptx', 'presentationml.presentation', 'PK'],
    ] as [$format, $type, $magic]) {
        $response = $this->actingAs($this->boss)
            ->get('/reports/forecast/'.$this->plan->id.'/export?format='.$format);

        $response->assertOk();

        expect($response->headers->get('content-type'))->toContain($type)
            ->and($response->headers->get('content-disposition'))->toContain('plan-2027-buget-2027')
            ->and(substr($format === 'xlsx' ? $response->streamedContent() : $response->getContent(), 0, 4))->toStartWith($magic);
    }
});

test('the exported version carries its own levers, not the ones on the plan now', function () {
    $this->actingAs($this->boss)->post('/reports/forecast/'.$this->plan->id.'/versions', [
        'label' => 'Prudentă', 'note' => 'Ocupare lăsată la cât a fost.',
    ])->assertRedirect();

    $version = PlanVersion::query()->sole();

    // Planul de acum se umflă; varianta rămâne cum era.
    $this->actingAs($this->boss)->put('/reports/forecast/'.$this->plan->id.'/row', [
        'kind' => 'category', 'key' => 'Cazare', 'field' => 'unit_net', 'value' => 99_999,
    ])->assertRedirect();

    $pdf = $this->actingAs($this->boss)
        ->get('/reports/forecast/'.$this->plan->id.'/export?format=pdf&version='.$version->id);

    $pdf->assertOk();

    expect($pdf->headers->get('content-disposition'))->toContain('prudenta')
        // Planul rămâne neatins: exportul unei variante nu scrie nimic.
        ->and($this->plan->lines()->where('kind', 'category')->sole()->unit_net)->toEqual(99_999.0)
        ->and(PlanVersion::query()->sole()->lines)->toHaveCount(count($version->lines));
});

test('the exports say on what the numbers stand', function () {
    $output = app(PlanEngine::class)->compute($this->plan);
    $export = app(PlanExport::class);
    $version = PlanVersion::query()->create([
        'plan_id' => $this->plan->id, 'label' => 'Varianta cu charter plin',
        'note' => 'Presupunem 95% ocupare pe Antalya.', 'period' => 'ytd12',
        'basis' => 'ras', 'mode' => 'operational', 'lines' => [], 'summary' => [],
    ]);

    $deck = $export->deck($this->plan, $output, $version);
    $assumptions = implode(' | ', $deck['assumptions']);

    expect($deck['subtitle'])->toContain('Varianta cu charter plin')
        ->and($deck['subtitle'])->toContain('venit la data rezervării')
        ->and($assumptions)->toContain('Presupunem 95% ocupare pe Antalya.')
        ->and($assumptions)->toContain('locurile contractate în eTrip')
        ->and($assumptions)->toContain('2026')
        // Fiecare secțiune are cap de tabel și rânduri.
        ->and(collect($deck['sections'])->pluck('title')->all())->toContain('Charter · cursele contractate')
        ->and(collect($deck['sections'])->every(fn (array $section) => $section['columns'] !== []))->toBeTrue()
        // Și prezentarea are o filă de titlu plus una de ipoteze.
        ->and(collect($export->slides($this->plan, $output, $version))->pluck('title')->last())
        ->toContain('Pe ce se sprijină cifrele');
});

test('only top management can save, load or export a plan', function () {
    $clerk = User::factory()->withRoles('finance')->create();

    $this->actingAs($clerk)->post('/reports/forecast/'.$this->plan->id.'/versions', ['label' => 'A mea'])
        ->assertForbidden();

    $this->actingAs($clerk)->get('/reports/forecast/'.$this->plan->id.'/export?format=pdf')
        ->assertForbidden();
});
