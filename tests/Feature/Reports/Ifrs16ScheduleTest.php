<?php

use App\Models\Company;
use App\Models\Lease;
use App\Models\User;
use App\Services\Reports\Ifrs16Schedule;
use App\Services\Reports\PnlReportService;

function lease(array $attributes = []): Lease
{
    return Lease::query()->create([
        'company_id' => Company::factory()->create()->id,
        'lessor' => 'Iulius Mall',
        'location' => 'Cluj',
        'currency' => 'Lei',
        'monthly_payment' => 10_000,
        'in_advance' => true,
        'starts_on' => '2026-01-01',
        'ends_on' => '2028-12-31',
        'discount_rate' => 0.08,
        'status' => Lease::ACTIVE,
        ...$attributes,
    ]);
}

test('the liability is the present value of the rents, and it is gone when the lease ends', function () {
    $schedule = app(Ifrs16Schedule::class)->build(lease());

    // 36 de chirii de 10.000, plătite la început de lună, actualizate la 8%:
    // valoarea actuală e sub 360.000, fiindcă banii de peste trei ani fac
    // azi mai puțin.
    $first = $schedule['2026-01'];

    // Valoarea actuală a celor 36 de chirii, la 8% pe an, plătite la început
    // de lună: 321.245,64 lei, adică 8.923,49 pe lună de amortizare.
    expect(count($schedule))->toBe(36)
        ->and($first['depreciation'])->toEqual(8_923.49)
        ->and(round($first['depreciation'] * 36, 0))->toEqual(321_246.0)
        // Prima lună: se plătește, apoi curge dobânda din ce a rămas.
        ->and($first['interest'])->toBeGreaterThan(2_000.0)
        ->and($first['interest'])->toBeLessThan(2_100.0)
        // La capăt nu mai rămâne nici datorie, nici drept de utilizare.
        ->and($schedule['2028-12']['liability'])->toEqual(0.0)
        ->and($schedule['2028-12']['rou'])->toEqual(0.0);
});

test('what the standard puts in the P&L adds up to the rent plus the interest', function () {
    $schedule = app(Ifrs16Schedule::class)->build(lease());

    $rents = 36 * 10_000;
    $depreciation = array_sum(array_column($schedule, 'depreciation'));
    $interest = array_sum(array_column($schedule, 'interest'));

    // Pe toată durata, amortizarea plus dobânda dau exact chiria plătită:
    // IFRS 16 mută cheltuiala în timp, nu o schimbă.
    expect(round($depreciation + $interest, 0))->toEqual((float) $rents)
        // Dar în primul an dobânda e mai mare, deci cheltuiala e mai mare.
        ->and(round($interest, 0))->toBeGreaterThan(30_000.0);
});

test('a year takes only its own months, and a lease paid in arrears carries interest on the whole rent', function () {
    $engine = app(Ifrs16Schedule::class);

    $year = $engine->forYear([lease()], 2027);

    expect($year['months'])->toHaveCount(12)
        ->and($year['leases'])->toBe(1)
        ->and(round($year['totals']['depreciation'], 0))->toEqual(round(8_923.49 * 12, 0));

    // Plătită la sfârșit de lună, aceeași chirie are datoria mai mare la
    // început, deci și dobânda mai mare.
    $arrears = $engine->build(lease(['in_advance' => false, 'lessor' => 'Altul']));

    expect($arrears['2026-01']['interest'])->toBeGreaterThan($engine->build(lease(['lessor' => 'Al treilea']))['2026-01']['interest']);
});

test('a lease in euro keeps the right of use at the rate it started with', function () {
    $euro = lease(['currency' => 'EUR', 'monthly_payment' => 2_000, 'ends_on' => '2026-12-31']);

    $year = app(Ifrs16Schedule::class)->forYear([$euro], 2026, function (string $currency, string $month) {
        // Cursul crește pe parcursul anului.
        return $currency === 'EUR' ? 5.0 + (int) substr($month, 5, 2) * 0.01 : 1.0;
    });

    // Amortizarea stă la cursul de la început (5,01), dobânda urmează luna.
    $depreciationPerMonth = $year['months'][6]['depreciation'];

    expect(round($depreciationPerMonth, 2))->toEqual(round($year['months'][1]['depreciation'], 2))
        ->and($year['months'][6]['payment'])->toBeGreaterThan($year['months'][1]['payment']);
});

test('with contracts written down, the P&L uses them instead of the estimate', function () {
    $company = Company::factory()->create(['name' => 'Christian Tour']);

    // Chirie de 10.000 pe lună în contabilitate, pe trei luni.
    $rents = [
        ['account' => '612', 'note' => 'Chirie agentie', 'sediu' => 'Cluj', 'partner' => 'Iulius Mall', 'month' => 1, 'lei' => 10_000.0],
        ['account' => '612', 'note' => 'Chirie agentie', 'sediu' => 'Cluj', 'partner' => 'Iulius Mall', 'month' => 2, 'lei' => 10_000.0],
        ['account' => '612', 'note' => 'Chirie agentie', 'sediu' => 'Cluj', 'partner' => 'Iulius Mall', 'month' => 3, 'lei' => 10_000.0],
    ];

    test()->company = $company;
    fakeEvolutionSources(evolutionRevenue(), $rents);

    $service = app(PnlReportService::class);

    // Fără contract scris, retratarea e estimarea de dinainte.
    $estimated = $service->slice($service->report($company, 2026, forceRefresh: true), [1, 2, 3], 'q1', 'ifrs16');

    expect($estimated['below']['amortizare']['total'])->toBeGreaterThan(0.0);

    // Cu contractul scris, cifrele vin din graficul lui.
    $lease = lease([
        'company_id' => $company->id,
        'lessor' => 'Iulius Mall',
        'location' => 'Cluj',
        'monthly_payment' => 10_000,
        'starts_on' => '2026-01-01',
        'ends_on' => '2028-12-31',
        'status' => Lease::ACTIVE,
    ]);

    $booked = $service->slice($service->report($company, 2026, forceRefresh: true), [1, 2, 3], 'q1', 'ifrs16');
    $schedule = app(Ifrs16Schedule::class)->build($lease);

    $depreciation = $schedule['2026-01']['depreciation'] + $schedule['2026-02']['depreciation'] + $schedule['2026-03']['depreciation'];
    $interest = $schedule['2026-01']['interest'] + $schedule['2026-02']['interest'] + $schedule['2026-03']['interest'];

    expect(round($booked['below']['amortizare']['total'], 2))->toEqual(round($depreciation, 2))
        ->and(round($booked['below']['financiar']['total'], 2))->toEqual(round($interest, 2));

    // Iar pe baza statutară nu se schimbă nimic: chiria rămâne cheltuială.
    $statutory = $service->slice($service->cached($company, 2026), [1, 2, 3], 'q1', 'ras');

    expect(round($statutory['totals']['total'], 2))->toEqual(30_000.0)
        ->and(round($booked['totals']['total'], 2))->toEqual(0.0);
});

test('the register page shows what the contracts cover, and only finance and top management reach it', function () {
    $company = Company::factory()->create();
    $boss = User::factory()->withRoles('top_management')->create();

    lease(['company_id' => $company->id, 'status' => Lease::ACTIVE, 'monthly_payment' => 10_000]);
    lease(['company_id' => $company->id, 'lessor' => 'Altul', 'status' => Lease::DRAFT]);

    $this->actingAs($boss)
        ->get('/reports/leases?company_id='.$company->id.'&year=2026')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('reports/leases')
            ->has('leases', 2)
            // Ciorna nu se socotește până nu e confirmată.
            ->where('summary.counted', 1)
            ->where('summary.drafts', 1)
            ->where('summary.covered', 120_000));

    // Financiarul ține registrul: el scrie durata și rata din contract.
    $this->actingAs(User::factory()->withRoles('finance')->create())
        ->get('/reports/leases?company_id='.$company->id)
        ->assertOk();

    // Restul companiei n-are ce căuta în el, nici măcar cine ține contractele.
    foreach (['operational', 'treasury', 'contract_management'] as $role) {
        $this->actingAs(User::factory()->withRoles($role)->create())
            ->get('/reports/leases')
            ->assertForbidden();
    }
});

test('a lease is written down from the page, and the draft becomes a counted contract', function () {
    $company = Company::factory()->create();
    $boss = User::factory()->withRoles('top_management')->create();
    $draft = lease(['company_id' => $company->id, 'status' => Lease::DRAFT]);

    $this->actingAs($boss)->put('/reports/leases/'.$draft->id, [
        'monthly_payment' => 12_500,
        'currency' => 'Lei',
        'starts_on' => '2026-01-01',
        'ends_on' => '2030-12-31',
        'discount_rate' => 0.065,
        'in_advance' => true,
        'exempt' => false,
        'status' => Lease::ACTIVE,
        'note' => 'Durata din contract, cu opțiunea de prelungire.',
    ])->assertRedirect();

    $draft->refresh();

    expect($draft->status)->toBe(Lease::ACTIVE)
        ->and((float) $draft->monthly_payment)->toEqual(12_500.0)
        ->and($draft->months())->toBe(60)
        ->and($draft->source)->toBe('manual');

    // Datele strâmbe nu intră: un contract care se termină înainte să înceapă
    // ar da o durată negativă și un grafic fără sens.
    $this->actingAs($boss)->put('/reports/leases/'.$draft->id, [
        'monthly_payment' => 100, 'currency' => 'Lei', 'starts_on' => '2026-05-01', 'ends_on' => '2026-01-01',
        'discount_rate' => 0.08, 'in_advance' => true, 'exempt' => false, 'status' => Lease::ACTIVE,
    ])->assertSessionHasErrors('ends_on');
});
