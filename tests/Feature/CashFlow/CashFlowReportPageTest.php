<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\CashFlowSetting;
use App\Models\CashFlowSnapshot;
use App\Models\CharterContract;
use App\Models\CharterFlight;
use App\Models\User;
use App\Services\Maintenance\ArtisanRunner;
use App\Services\Xlsx\XlsxWriter;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Inertia\Support\SessionKey;

beforeEach(function () {
    Carbon::setTestNow('2026-09-16 10:00:00');
    Cache::flush();

    $this->user = User::factory()->create(['name' => 'Bogdan']);
    $this->dir = sys_get_temp_dir().'/payables-cashflow-'.uniqid();
    $this->app->instance(ArtisanRunner::class, new ArtisanRunner($this->dir));
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

function cashFlowToast(): array
{
    return session(SessionKey::FLASH_DATA)['toast'];
}

test('guests are redirected to the login page', function () {
    $this->get('/reports/cash-flow')->assertRedirect('/login');
});

test('the page shows the last snapshot, the parameters and the charter contracts', function () {
    CashFlowSnapshot::factory()->create(['built_at' => '2026-09-15 04:30:00', 'status' => 'partial', 'error' => 'eTrip down']);
    $latest = CashFlowSnapshot::factory()->create(['built_at' => '2026-09-16 04:30:00', 'built_by' => 'programat', 'payload' => ['weeks' => ['2026-09-14'], 'lines' => [], 'kpis' => ['opening' => 5], 'opex' => [['key' => 'chirii', 'computed' => 120000]]]]);
    $contract = CharterContract::factory()->create(['name' => 'CTR 317', 'season' => 'S26']);
    CharterFlight::factory()->for($contract, 'contract')->create(['flight_date' => '2026-10-15', 'net_value' => 1000, 'taxes' => 100]);

    $this->actingAs($this->user)
        ->get('/reports/cash-flow')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('reports/cash-flow')
            ->where('snapshot.id', $latest->id)
            ->where('snapshot.status', 'ok')
            ->where('snapshot.payload.kpis.opening', 5)
            ->where('run.running', false)
            ->where('parameters.fx.mode', 'auto')
            ->where('parameters.thresholds.minimum', 3000000)
            ->where('opex.4.key', 'chirii')
            ->where('opex.4.computed', 120000)
            ->where('opex.4.monthly', 120000)
            ->where('opex.0.monthly', 574000)
            ->has('connections', 2)
            ->has('contracts', 1)
            ->where('contracts.0.name', 'CTR 317')
            ->where('contracts.0.flights_count', 1)
            ->where('contracts.0.flights_net', 1000)
            ->where('schedule.nightly', '04:30')
            // The programme is deferred, so it is not on the first render.
            ->missing('flights')
        );

    // Inertia asks for it right after, and it carries the dates the contract settles on.
    $this->actingAs($this->user)
        ->get('/reports/cash-flow', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/reports/cash-flow')),
            'X-Inertia-Partial-Component' => 'reports/cash-flow',
            'X-Inertia-Partial-Data' => 'flights',
        ])
        ->assertOk()
        ->assertJsonPath('props.flights.0.operator', 'ANIMA WINGS')
        ->assertJsonPath('props.flights.0.payment_date', '2026-10-05')
        ->assertJsonPath('props.flights.0.taxes_payment_date', '2026-11-05')
        ->assertJsonCount(1, 'props.flights');
});

test('a rotation whose contract settles the taxes with it carries no separate tax date', function () {
    $contract = CharterContract::factory()->create([
        'name' => 'CTR 1585', 'season' => 'S26',
        'direction' => CharterContract::DIRECTION_IN,
        'taxes_rule' => CharterContract::TAXES_WITH_ROTATION,
    ]);
    CharterFlight::factory()->for($contract, 'contract')->create(['flight_date' => '2026-10-15', 'net_value' => 1000, 'taxes' => 100]);

    $this->actingAs($this->user)
        ->get('/reports/cash-flow', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/reports/cash-flow')),
            'X-Inertia-Partial-Component' => 'reports/cash-flow',
            'X-Inertia-Partial-Data' => 'flights',
        ])
        ->assertOk()
        ->assertJsonPath('props.flights.0.payment_date', '2026-10-05')
        ->assertJsonPath('props.flights.0.taxes_payment_date', null);
});

test('the page works before the first snapshot', function () {
    $this->actingAs($this->user)
        ->get('/reports/cash-flow')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('snapshot', null)->has('contracts', 0));
});

test('recalculating starts cashflow:build in the background', function () {
    Process::fake(['*' => Process::result('4242')]);

    $this->actingAs($this->user)
        ->from('/reports/cash-flow')
        ->post('/reports/cash-flow/build')
        ->assertRedirect('/reports/cash-flow');

    Process::assertRan(fn ($process) => str_contains($process->command, 'artisan cashflow:build') && str_contains($process->command, '--by=Bogdan'));
    expect(cashFlowToast()['type'])->toBe('success')
        ->and(app(ArtisanRunner::class)->status(ArtisanRunner::CASHFLOW))->toMatchArray(['running' => true, 'started_by' => 'Bogdan', 'pid' => 4242]);
});

test('parameters are validated, saved and trigger a rebuild', function () {
    Process::fake(['*' => Process::result('99')]);

    $payload = [
        'etrip_connections' => ['etrip_chr', 'etrip_vcz'],
        'fx' => ['mode' => 'manual', 'EUR' => 5.1, 'USD' => 4.4],
        'thresholds' => ['minimum' => 3000000, 'comfort' => 6000000],
        'overdue' => ['recent_days' => 60, 'recent_pct' => 80, 'recent_weeks' => 4, 'old_pct' => 0],
        'payables' => ['days_before_checkin' => 7, 'prepaid_pct' => 0, 'ticket_days' => 7, 'supplier_balance' => '', 'supplier_balance_weeks' => 2],
        'scenario' => ['enabled' => true, 'factor' => 0.95, 'charter_factor' => 1, 'charter_base_season' => 'S26', 'charter_target_season' => 'S27'],
        'opex' => ['salarii_nete' => 574000, 'chirii' => '', 'capex' => 0],
    ];

    $this->actingAs($this->user)
        ->from('/reports/cash-flow')
        ->put('/reports/cash-flow/parameters', $payload)
        ->assertRedirect('/reports/cash-flow');

    $saved = CashFlowSetting::query()->where('key', 'parameters')->value('value');

    expect($saved['etrip_connections'])->toBe(['etrip_chr', 'etrip_vcz'])
        ->and($saved['fx'])->toEqual(['mode' => 'manual', 'EUR' => 5.1, 'USD' => 4.4])
        ->and($saved['payables']['supplier_balance'])->toBeNull()
        ->and($saved['opex']['chirii'])->toBeNull()
        ->and($saved['opex']['salarii_nete'])->toEqual(574000)
        ->and($saved['opex']['capex'])->toEqual(0)
        ->and($saved['scenario']['charter_base_season'])->toBe('S26')
        ->and(cashFlowToast()['message'])->toContain('recalculează în fundal');

    Process::assertRan(fn ($process) => str_contains($process->command, 'artisan cashflow:build'));

    $this->actingAs($this->user)
        ->put('/reports/cash-flow/parameters', [...$payload, 'fx' => ['mode' => 'auto', 'EUR' => 0, 'USD' => 4], 'etrip_connections' => ['nope']])
        ->assertSessionHasErrors(['fx.EUR', 'etrip_connections.0']);
});

test('charter contracts and flights can be managed from the page', function () {
    $this->actingAs($this->user)
        ->post('/reports/cash-flow/contracts', [
            'name' => 'CTR 317/11.11.2025', 'season' => 'S26', 'status' => 'signed', 'operator' => 'Memento Air', 'currency' => 'eur',
            'counterparty' => 'Memento Air S.R.L.', 'direction' => 'out', 'in_cash_flow' => true,
            'days_before_flight' => 10, 'payment_basis' => 'flight', 'taxes_rule' => 'monthly_first_week', 'taxes_month_day' => 5, 'fx_markup_pct' => 2,
            'deposit_percent' => null, 'deposit_amount' => null, 'deposit_due_date' => null, 'deposit_paid' => false, 'contract_value' => null, 'notes' => null,
        ])
        ->assertRedirect();

    $contract = CharterContract::query()->sole();
    expect($contract->currency)->toBe('EUR');

    $this->actingAs($this->user)
        ->post('/reports/cash-flow/flights', ['charter_contract_id' => $contract->id, 'operator' => 'Anima Wings', 'route' => 'OTP AYT OTP', 'flight_no' => 'A2 4238', 'flight_date' => '2026-10-15', 'seats' => 180, 'price_per_seat' => 161.58, 'net_value' => 29084.4, 'taxes' => 7300.8, 'pay_date' => null, 'taxes_pay_date' => null])
        ->assertRedirect();

    $flight = CharterFlight::query()->sole();
    expect($flight->paymentDate()->toDateString())->toBe('2026-10-05')
        ->and($flight->operator)->toBe('Anima Wings');

    $this->actingAs($this->user)
        ->put('/reports/cash-flow/flights/'.$flight->id, ['charter_contract_id' => $contract->id, 'route' => 'OTP AYT OTP', 'flight_no' => 'A2 4238', 'flight_date' => '2026-10-15', 'seats' => 180, 'price_per_seat' => 161.58, 'net_value' => 30000, 'taxes' => 7300.8, 'pay_date' => '2026-10-01', 'taxes_pay_date' => null])
        ->assertRedirect();

    expect($flight->fresh()->paymentDate()->toDateString())->toBe('2026-10-01')
        ->and((float) $flight->fresh()->net_value)->toBe(30000.0);

    $this->actingAs($this->user)
        ->put('/reports/cash-flow/contracts/'.$contract->id, [
            'name' => 'W26/27', 'season' => 'W26-27', 'status' => 'draft', 'operator' => null, 'currency' => 'EUR',
            'direction' => 'out', 'in_cash_flow' => true, 'days_before_flight' => 10, 'payment_basis' => 'flight',
            'taxes_rule' => 'days_after_flight', 'taxes_days' => 3, 'taxes_month_day' => 5, 'fx_markup_pct' => 2,
            'deposit_percent' => 50, 'deposit_amount' => null, 'deposit_due_date' => '2026-10-05', 'deposit_paid' => false,
            'contract_value' => 7257134, 'notes' => 'draft',
        ])
        ->assertRedirect();

    // Editing the terms moves the rotations the contract already has.
    expect($contract->fresh()->status)->toBe('draft')
        ->and($contract->fresh()->taxes_rule)->toBe('days_after_flight')
        ->and($flight->fresh()->taxesPaymentDate()->toDateString())->toBe('2026-10-18');

    $this->actingAs($this->user)->delete('/reports/cash-flow/flights/'.$flight->id)->assertRedirect();
    $this->actingAs($this->user)->delete('/reports/cash-flow/contracts/'.$contract->id)->assertRedirect();

    expect(CharterFlight::query()->count())->toBe(0)->and(CharterContract::query()->count())->toBe(0);
});

test('a flight programme is imported from xlsx, one contract per season', function () {
    $contract = CharterContract::factory()->create(['season' => 'S26', 'days_before_flight' => 10]);
    CharterFlight::factory()->for($contract, 'contract')->create(['route' => 'OLD', 'flight_date' => '2026-09-20']);

    $path = $this->dir.'/program.xlsx';
    File::ensureDirectoryExists($this->dir);
    XlsxWriter::write($path, ['Sezon', 'Status', 'Operator', 'Ruta', 'Nr zbor', 'Data zbor', 'Locuri', 'Pret/loc EUR', 'Valoare neta EUR', 'Taxe est. EUR', 'Data plata rotatie', 'Data plata taxe'], [
        ['S26', 'semnat (AA30)', 'ANIMA WINGS', 'OTP HER OTP', 'A2 4132', new DateTimeImmutable('2026-10-15'), 110, 169.68, 18665.19, 3170.2, new DateTimeImmutable('2026-10-05'), new DateTimeImmutable('2026-11-05')],
        ['W26-27', 'draft', 'MEMENTO AIR', 'OTP HRG OTP', 'MMA 101', '2026-12-01', 180, 200, 36000, 0, null, null],
        [null, null, null, null, null, null, null, null, null, null, null, null],
    ]);

    $this->actingAs($this->user)
        ->from('/reports/cash-flow')
        ->post('/reports/cash-flow/flights/import', [
            'charter_contract_id' => $contract->id,
            'file' => new UploadedFile($path, 'program.xlsx', null, null, true),
            'replace' => true,
        ])
        ->assertRedirect('/reports/cash-flow');

    expect(cashFlowToast())->toMatchArray(['type' => 'success'])
        ->and(CharterFlight::query()->where('route', 'OLD')->exists())->toBeFalse()
        ->and($contract->flights()->count())->toBe(1)
        ->and($contract->flights()->first())->toMatchArray(['route' => 'OTP HER OTP', 'flight_no' => 'A2 4132', 'seats' => 110])
        ->and($contract->flights()->first()->pay_date->toDateString())->toBe('2026-10-05')
        ->and((float) $contract->flights()->first()->net_value)->toBe(18665.19);

    $draft = CharterContract::query()->where('season', 'W26-27')->sole();
    expect($draft->status)->toBe('draft')
        ->and($draft->operator)->toBe('MEMENTO AIR')
        ->and($draft->flights()->count())->toBe(1)
        ->and($draft->flights()->first()->paymentDate()->toDateString())->toBe('2026-11-21');
});

test('a csv programme without seasons goes to the chosen contract', function () {
    $contract = CharterContract::factory()->create(['season' => 'S26']);
    $path = $this->dir.'/program.csv';
    File::ensureDirectoryExists($this->dir);
    File::put($path, "Ruta;Data zbor;Locuri;Valoare neta EUR;Taxe\nOTP AYT OTP;15.10.2026;180;29.084,40;7300,8\nfara data;;1;2;3\n");

    $this->actingAs($this->user)
        ->post('/reports/cash-flow/flights/import', [
            'charter_contract_id' => $contract->id,
            'file' => new UploadedFile($path, 'program.csv', 'text/csv', null, true),
            'replace' => false,
        ])
        ->assertRedirect();

    expect(cashFlowToast()['message'])->toContain('1 rotații importate')->toContain('1 rânduri')
        ->and($contract->flights()->count())->toBe(1)
        ->and((float) $contract->flights()->first()->net_value)->toBe(29084.4)
        ->and((float) $contract->flights()->first()->taxes)->toBe(7300.8)
        ->and($contract->flights()->first()->flight_date->toDateString())->toBe('2026-10-15');
});

test('a contract annex is expanded into weekly rotations paid per the contract terms', function () {
    $contract = CharterContract::factory()->create(['season' => 'S26', 'days_before_flight' => 10]);
    $path = $this->dir.'/AA30.xlsx';
    File::ensureDirectoryExists($this->dir);
    XlsxWriter::write($path, ['Anexa AA30 - program S26', null, null, null, null, null, null, null, null, null, null], [
        [null, null, null, null, null, null, null, null, null, null, null],
        ['Companie Aeriana', 'Ruta Zbor', 'Nr. Zbor', 'Primul Zbor', 'Ultimul Zbor', 'Numar Total Zboruri', 'Zi de Operare', 'Numar locuri/ zbor', 'PRET / LOC /RT', 'Taxe RO', 'Taxe Destinatie'],
        ['ANIMA WINGS', 'OTP AYT OTP', 'A2 4238/4239', new DateTimeImmutable('2026-10-06'), new DateTimeImmutable('2026-10-27'), 4, 'D2', 180, 161.58, 20.5, 20.06],
        ['CORENDON', 'SBZ AYT SBZ', 'CAI9014/9013', new DateTimeImmutable('2026-10-05'), new DateTimeImmutable('2026-10-19'), 2, 'D1', 149, 185.28, 21.5, 0],
        ['TOTAL', null, null, null, null, null, null, null, null, null, null],
    ]);

    $this->actingAs($this->user)
        ->post('/reports/cash-flow/flights/import', [
            'charter_contract_id' => $contract->id,
            'file' => new UploadedFile($path, 'AA30.xlsx', null, null, true),
            'replace' => true,
        ])
        ->assertRedirect();

    $flights = $contract->flights()->orderBy('flight_date')->orderBy('id')->get();

    expect(cashFlowToast()['type'])->toBe('success')
        ->and($flights)->toHaveCount(7)
        ->and($flights->where('operator', 'ANIMA WINGS')->pluck('flight_date')->map->toDateString()->all())->toBe(['2026-10-06', '2026-10-13', '2026-10-20', '2026-10-27'])
        ->and((float) $flights->where('operator', 'ANIMA WINGS')->first()->net_value)->toBe(29084.4)
        ->and((float) $flights->where('operator', 'ANIMA WINGS')->first()->taxes)->toBe(round(180 * 40.56, 2))
        // No dates on the rows: the rotation follows the contract, 10 days before the flight, taxes on the 5th of the next month.
        ->and($flights->where('operator', 'ANIMA WINGS')->first()->pay_date)->toBeNull()
        ->and($flights->where('operator', 'ANIMA WINGS')->first()->paymentDate()->toDateString())->toBe('2026-09-26')
        ->and($flights->where('operator', 'ANIMA WINGS')->first()->taxesPaymentDate()->toDateString())->toBe('2026-11-05')
        ->and($flights->where('operator', 'CORENDON')->count())->toBe(3)
        // 2 rotations announced over 3 Mondays: the value per flight is scaled by 2/3 so the total matches the annex.
        ->and(round((float) $flights->where('operator', 'CORENDON')->sum('net_value'), 2))->toBe(round(2 * 149 * 185.28, 2));
});
