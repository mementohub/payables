<?php

use App\Models\CashFlowSetting;
use App\Models\CharterContract;
use App\Models\CharterFlight;
use App\Services\CashFlow\CharterScheduleLoader;

function contractNamed(string $name): CharterContract
{
    return CharterContract::query()->where('name', $name)->firstOrFail();
}

test('the contract pack is loaded once, with the terms of every contract', function () {
    $loader = app(CharterScheduleLoader::class);

    $result = $loader->load();

    expect($result)->toMatchArray(['contracts' => 17])
        ->and(CharterContract::query()->inCashFlow()->count())->toBe(3)
        ->and(CashFlowSetting::query()->where('key', CharterScheduleLoader::SETTING)->value('value')['version'])->toBe(CharterScheduleLoader::VERSION)
        ->and($loader->loaded())->toBeTrue();

    // CTR 317: the signed S26 programme, rotations 10 days ahead, taxes on the 5th of the next month, no deposit.
    $s26 = contractNamed('CTR 317 Memento Air – S26');
    expect($s26)->toMatchArray([
        'direction' => CharterContract::DIRECTION_OUT,
        'status' => CharterContract::STATUS_SIGNED,
        'in_cash_flow' => true,
        'contract_no' => '317/11.11.2025',
        'days_before_flight' => 10,
        'payment_basis' => CharterContract::BASIS_FLIGHT,
        'taxes_rule' => CharterContract::TAXES_MONTHLY,
        'taxes_month_day' => 5,
        'deposit_paid' => true,
    ])
        ->and($s26->deposit_amount)->toBeNull()
        ->and($s26->deposit_percent)->toBeNull()
        ->and((float) $s26->fx_markup_pct)->toBe(2.0)
        ->and($s26->flights()->count())->toBe(1458)
        ->and(round((float) $s26->flights()->sum('net_value')))->toBe(31312718.0)
        // The annex adds up to 21.835,69 EUR less taxes than the contract; the contract value governs.
        ->and(round((float) $s26->flights()->sum('taxes'), 2))->toBe(5632124.96)
        ->and(round((float) $s26->flights()->sum('net_value') + (float) $s26->flights()->sum('taxes'), 2))->toBe(36944842.79);

    $flight = $s26->flights()->where('route', 'CLJ HRG CLJ')->orderBy('flight_date')->firstOrFail();
    expect($flight->flight_date->toDateString())->toBe('2026-03-29')
        ->and($flight->pay_date)->toBeNull()
        ->and($flight->paymentDate()->toDateString())->toBe('2026-03-19')
        ->and($flight->taxesPaymentDate()->toDateString())->toBe('2026-04-05')
        ->and((float) $flight->taxes)->toBe(round(7522.84 * 5632124.96 / 5610289.27, 2));

    // W26/27: draft, the 50 % deposit of the CTR 281 precedent due in the first week of October.
    $draft = contractNamed('W26/27 Memento Air – draft');
    expect($draft)->toMatchArray(['status' => CharterContract::STATUS_DRAFT, 'in_cash_flow' => true, 'deposit_paid' => false])
        ->and((float) $draft->deposit_percent)->toBe(50.0)
        ->and($draft->deposit_due_date->toDateString())->toBe('2026-10-05')
        ->and($draft->depositAmount())->toBe(3628566.80)
        ->and($draft->flights()->count())->toBe(170)
        ->and(round((float) $draft->flights()->sum('net_value')))->toBe(7257134.0)
        ->and(round((float) $draft->flights()->sum('taxes'), 2))->toBe(1364180.04);

    // CTR 1585: CHR sells the seats, so it is money coming in, taxes included in the rotation.
    $hardBlock = contractNamed('CTR 1585 Anima Wings – hard block S26');
    expect($hardBlock)->toMatchArray(['direction' => CharterContract::DIRECTION_IN, 'in_cash_flow' => true])
        ->and($hardBlock->isIncoming())->toBeTrue()
        ->and($hardBlock->taxesRideWithRotation())->toBeTrue()
        ->and(round((float) $hardBlock->flights()->sum('net_value'), 2))->toBe(1120403.95)
        ->and(round((float) $hardBlock->flights()->sum('taxes'), 2))->toBe(21157.95)
        ->and($hardBlock->flights()->min('flight_date'))->toBeGreaterThanOrEqual('2026-07-11')
        ->and($hardBlock->flights()->max('flight_date'))->toBeLessThanOrEqual('2026-09-30 23:59:59');

    expect($hardBlock->flights()->orderBy('flight_date')->first()->taxesPaymentDate())->toBeNull();

    // CTR 281 is closed and the airline contracts belong to Memento Air: terms only, no cash.
    $w2526 = contractNamed('CTR 281 Memento Air – W25/26');
    expect($w2526)->toMatchArray(['in_cash_flow' => false, 'taxes_rule' => CharterContract::TAXES_AFTER, 'taxes_days' => 3])
        ->and((float) $w2526->deposit_amount)->toBe(4882741.68)
        ->and(contractNamed('Anima Wings – C7 full charter S26'))->toMatchArray([
            'in_cash_flow' => false,
            'buyer' => 'Memento Air S.R.L.',
            'days_before_flight' => 7,
            'taxes_rule' => CharterContract::TAXES_BEFORE,
            'taxes_days' => 14,
        ])
        ->and(contractNamed('Corendon Airlines – S26')->payment_basis)->toBe(CharterContract::BASIS_WEEK)
        ->and(contractNamed('Nesma Airlines – W25/26')->currency)->toBe('USD');

    // Loaded once: the next call does nothing, even after the contracts were removed by hand.
    expect($loader->load())->toBeNull();
    CharterContract::query()->delete();
    expect($loader->load())->toBeNull()
        ->and(CharterContract::query()->count())->toBe(0);
});

test('contracts entered by hand keep the pack from loading', function () {
    CharterContract::factory()->create(['season' => 'S26']);

    expect(app(CharterScheduleLoader::class)->load())->toBeNull()
        ->and(CharterContract::query()->count())->toBe(1)
        ->and(CharterFlight::query()->count())->toBe(0);
});

test('loading again by force refreshes the contracts and their rotations', function () {
    $loader = app(CharterScheduleLoader::class);
    $loader->load();

    contractNamed('CTR 317 Memento Air – S26')->flights()->limit(10)->delete();
    contractNamed('W26/27 Memento Air – draft')->update(['days_before_flight' => 3]);

    $result = $loader->load(force: true);

    expect($result['contracts'])->toBe(17)
        ->and(CharterContract::query()->count())->toBe(17)
        ->and(contractNamed('CTR 317 Memento Air – S26')->flights()->count())->toBe(1458)
        ->and(contractNamed('W26/27 Memento Air – draft')->days_before_flight)->toBe(10);
});

test('app:upgrade loads the pack when no contract exists yet', function () {
    $this->artisan('app:upgrade', ['--skip-sync' => true, '--skip-etrip' => true])
        ->expectsOutputToContain('Contracte charter')
        ->assertSuccessful();

    expect(CharterContract::query()->count())->toBe(17)
        ->and(CharterFlight::query()->count())->toBeGreaterThan(1600);

    $this->artisan('app:upgrade', ['--skip-sync' => true, '--skip-etrip' => true])
        ->doesntExpectOutputToContain('Contracte charter')
        ->assertSuccessful();
});

test('an earlier pack is replaced by this one on the next upgrade, without a second CTR 317', function () {
    // What the handover of 11.09.2026 left: CTR 317 under its old name and the W26/27 draft, neither with the contract terms.
    $old = CharterContract::factory()->create(['name' => 'CTR 317/11.11.2025 Memento Air – S26', 'season' => 'S26', 'fx_markup_pct' => 0]);
    CharterFlight::factory()->for($old, 'contract')->count(3)->create();
    CharterContract::factory()->draft()->create(['name' => 'W26/27 Memento Air – draft', 'season' => 'W26-27', 'fx_markup_pct' => 0]);
    CashFlowSetting::query()->create(['key' => CharterScheduleLoader::SETTING, 'value' => ['version' => 'AA30/ADD7 11.09.2026 + W26/27 draft', 'imported' => 1628]]);

    $loader = app(CharterScheduleLoader::class);

    expect($loader->load())->toMatchArray(['contracts' => 17])
        ->and(CharterContract::query()->count())->toBe(17)
        ->and(CharterContract::query()->where('season', 'S26')->where('name', 'like', 'CTR 317%')->count())->toBe(1)
        ->and(contractNamed('CTR 317 Memento Air – S26')->id)->toBe($old->id)
        ->and(contractNamed('CTR 317 Memento Air – S26')->flights()->count())->toBe(1458)
        ->and((float) contractNamed('CTR 317 Memento Air – S26')->fx_markup_pct)->toBe(2.0)
        ->and(contractNamed('CTR 281 Memento Air – W25/26')->deposit_settlement)->toStartWith('regularizare la ultima rotație; stornat')
        ->and($loader->loadedVersion())->toBe(CharterScheduleLoader::VERSION)
        ->and($loader->load())->toBeNull();
});
