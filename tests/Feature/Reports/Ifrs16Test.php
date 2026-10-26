<?php

use App\Models\Company;
use App\Models\Lease;
use App\Models\LeaseRate;
use App\Models\User;
use App\Services\Reports\Ifrs16Engine;
use Illuminate\Support\Carbon;

/**
 * IFRS 16: dreptul de utilizare și datoria de leasing, așa cum le cere
 * standardul și cum le ține calculul societății.
 */
function leaseLine(Company $company, array $fields): Lease
{
    return Lease::query()->create([
        'company_id' => $company->id,
        'lessor' => 'Proprietar',
        'currency' => 'EUR',
        'in_advance' => false,
        'status' => Lease::ACTIVE,
        'exempt' => false,
        'source' => 'test',
        ...$fields,
    ]);
}

test('a lease carries its own interest, its own depreciation and the balance that is left', function () {
    $company = Company::factory()->create();

    // Doisprezece mii de euro pe an, un an, la 6%.
    $lease = leaseLine($company, [
        'line_no' => 1, 'group_no' => 1, 'monthly_payment' => 1000,
        'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'term_months' => 12,
        'discount_rate' => 0.06, 'present_value' => 11618.93,
    ]);

    $out = app(Ifrs16Engine::class)->rollForward(
        collect([$lease]), Carbon::parse('2026-01-01'), Carbon::parse('2026-06-30'),
    );

    $totals = $out['totals'];

    // Jumătate din an: șase rate plătite, șase luni de amortizare.
    expect($totals['payments'])->toEqual(6000.0)
        ->and(round($totals['depreciation'], 2))->toEqual(round(11618.93 / 12 * 6, 2))
        // Dobânda scade pe măsură ce datoria se stinge.
        ->and($totals['interest'])->toBeGreaterThan(250.0)
        ->and($totals['interest'])->toBeLessThan(300.0)
        // Ce rămâne de plătit e valoarea actuală a celor șase rate rămase.
        ->and($totals['liability_close'])->toBeGreaterThan(5800.0)
        ->and($totals['liability_close'])->toBeLessThan(5950.0);
});

test('a remeasurement moves the asset and the debt to the new line, without touching the profit', function () {
    $company = Company::factory()->create();

    // Linia veche, cu solduri auditate la sfârșitul anului trecut.
    $old = leaseLine($company, [
        'line_no' => 10, 'group_no' => 10, 'monthly_payment' => 1000,
        'starts_on' => '2024-01-01', 'ends_on' => '2027-12-31', 'term_months' => 48,
        'discount_rate' => 0.05, 'opening_on' => '2025-12-31',
        'opening_rou' => 24000, 'opening_liability' => 22000, 'monthly_depreciation' => 1000,
        'event' => Lease::REMEASURE, 'event_on' => '2026-03-31', 'successor_no' => 11,
    ]);

    // Linia nouă, din actul adițional: valoarea actuală a chiriilor de acum.
    $new = leaseLine($company, [
        'line_no' => 11, 'group_no' => 11, 'monthly_payment' => 1200,
        'starts_on' => '2026-04-01', 'ends_on' => '2029-03-31', 'term_months' => 36,
        'discount_rate' => 0.05, 'present_value' => 40000, 'category' => 'extension',
    ]);

    $out = app(Ifrs16Engine::class)->rollForward(
        collect([$old, $new]), Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'),
    );

    $before = $out['lines']['10'];
    $after = $out['lines']['11'];

    // Linia veche iese cu ce i-a rămas, fără câștig sau pierdere.
    expect($before['rou_out'])->toEqual(21000.0)
        ->and($before['gain'])->toEqual(0.0)
        ->and($before['rou_close'])->toEqual(0.0)
        ->and($before['liability_close'])->toEqual(0.0)
        // Diferența dintre activ și datorie trece pe linia nouă: activul ei
        // e valoarea actuală plus diferența preluată.
        ->and(round($after['base'], 0))->toEqual(round(40000 + 21000 - $before['liability_out'], 0));
});

test('a lease that ends early leaves behind the difference between the debt and the asset', function () {
    $company = Company::factory()->create();

    $lease = leaseLine($company, [
        'line_no' => 20, 'group_no' => 20, 'monthly_payment' => 1000,
        'starts_on' => '2024-01-01', 'ends_on' => '2027-12-31', 'term_months' => 48,
        'discount_rate' => 0.05, 'opening_on' => '2025-12-31',
        'opening_rou' => 10000, 'opening_liability' => 12000, 'monthly_depreciation' => 500,
        'event' => Lease::DERECOGNISE, 'event_on' => '2026-02-28',
    ]);

    $out = app(Ifrs16Engine::class)->rollForward(
        collect([$lease]), Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'),
    );

    $row = $out['lines']['20'];

    // Două luni de amortizare, pe urmă iese: câștigul e datoria rămasă
    // minus activul rămas.
    expect($row['depreciation'])->toEqual(1000.0)
        ->and($row['rou_out'])->toEqual(9000.0)
        ->and($row['gain'])->toEqual(round($row['liability_out'] - 9000.0, 6))
        ->and($out['totals']['rou_close'])->toEqual(0.0);
});

test('the rate comes from the bulletin of the month, on the maturity of the lease', function () {
    LeaseRate::query()->create(['currency' => 'EUR', 'month' => '2026-09', 'bucket' => '1-5y', 'rate' => 5.2]);
    LeaseRate::query()->create(['currency' => 'EUR', 'month' => '2026-09', 'bucket' => '>5y', 'rate' => 4.77]);

    expect(LeaseRate::bucket(12))->toBe('<1y')
        ->and(LeaseRate::bucket(60))->toBe('1-5y')
        ->and(LeaseRate::bucket(61))->toBe('>5y')
        ->and(LeaseRate::resolve('EUR', '2026-09', 54)?->rate)->toEqual(5.2)
        ->and(LeaseRate::resolve('EUR', '2026-09', 72)?->rate)->toEqual(4.77)
        // Luna fără buletin ia ultima lună publicată, provizoriu.
        ->and(LeaseRate::resolve('EUR', '2026-11', 54)?->rate)->toEqual(5.2);
});

test('the register rebuilds the figures of the company IFRS 16 file', function () {
    $company = Company::factory()->create();
    $path = base_path('database/data/ifrs16-2026.json');

    $this->artisan('leases:import', ['file' => 'database/data/ifrs16-2026.json', '--company' => $company->id])
        ->assertSuccessful();

    $control = json_decode((string) file_get_contents($path), true)['control'];
    $leases = Lease::query()->where('company_id', $company->id)->counted()->get();

    expect($leases)->toHaveCount(166);

    $out = app(Ifrs16Engine::class)->rollForward($leases, Carbon::parse('2026-01-01'), Carbon::parse('2026-09-30'));
    $totals = $out['totals'];

    // Soldurile de pornire sunt cele auditate, la leu.
    expect(round($totals['opening_rou'], 0))->toEqual(round($control['opening_rou'], 0))
        ->and(round($totals['opening_liability'], 0))->toEqual(round($control['opening_liability'], 0))
        // Amortizarea celor nouă luni: aceeași cu a fișierului, până în 0,1%.
        ->and(abs($totals['depreciation'] - ($control['depreciation_h1'] + $control['depreciation_q3'])))->toBeLessThan(1500.0)
        // Transferurile din remăsurări și ieșirile din încetări, la fel.
        ->and(abs($totals['rou_transferred'] - $control['rou_transferred']))->toBeLessThan(2000.0)
        ->and(abs($totals['rou_derecognised'] - $control['rou_derecognised']))->toBeLessThan(1000.0);

    // Datoria calculată de noi e mai mare decât cea din fișier, fiindcă
    // fișierul n-are grafic de datorie pentru liniile deschise în 2026
    // (R 10.4 se oprește la linia 174). Diferența e cunoscută și se vede.
    expect($totals['liability_close'])->toBeGreaterThan($control['liability_close'])
        ->and($totals['liability_close'])->toBeLessThan(7_000_000.0);
});

test('the register page shows the movement of the year, not just a list', function () {
    $company = Company::factory()->create();
    $user = User::factory()->withRoles('top_management')->create();

    leaseLine($company, [
        'line_no' => 1, 'group_no' => 1, 'monthly_payment' => 1000,
        'starts_on' => '2024-01-01', 'ends_on' => '2027-12-31', 'term_months' => 48,
        'discount_rate' => 0.05, 'opening_on' => '2025-12-31',
        'opening_rou' => 24000, 'opening_liability' => 22000, 'monthly_depreciation' => 1000,
    ]);

    $this->actingAs($user)
        ->get('/reports/leases?company_id='.$company->id.'&year=2026')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('summary.opening_rou', 24000)
            ->where('summary.opening_liability', 22000)
            ->where('summary.depreciation', 12000)
            // Datoria de la sfârșitul anului, nu de la capătul contractului.
            ->where('summary.liability', fn ($value) => $value > 10000 && $value < 11500)
            ->where('leases.0.rou_close', 12000)
        );
});
