<?php

use App\Models\CashFlowSetting;
use App\Models\CharterContract;
use App\Models\CharterFlight;
use App\Services\CashFlow\CharterScheduleLoader;

test('the handed-over charter programme is loaded once into its two contracts', function () {
    $loader = app(CharterScheduleLoader::class);

    $result = $loader->load();

    $signed = CharterContract::query()->where('season', 'S26')->firstOrFail();
    $draft = CharterContract::query()->where('season', 'W26-27')->firstOrFail();

    expect($result)->toMatchArray(['imported' => 1628, 'skipped' => 0])
        ->and($result['contracts'])->toHaveCount(2)
        ->and(CharterContract::query()->count())->toBe(2)
        ->and($signed)->toMatchArray(['status' => 'signed', 'operator' => 'Memento Air', 'currency' => 'EUR', 'days_before_flight' => 10, 'deposit_paid' => true])
        ->and($signed->flights()->count())->toBe(1458)
        ->and((float) $signed->flights()->sum('net_value'))->toEqualWithDelta(31312718, 1)
        ->and($draft)->toMatchArray(['status' => 'draft', 'days_before_flight' => 10, 'deposit_paid' => false])
        ->and((float) $draft->deposit_percent)->toBe(50.0)
        ->and($draft->deposit_due_date->toDateString())->toBe('2026-10-05')
        ->and((float) $draft->contract_value)->toBe(7257134.0)
        ->and($draft->flights()->count())->toBe(170)
        ->and($draft->flights()->orderBy('flight_date')->first()->flight_date->toDateString())->toBe('2026-09-26')
        ->and(CashFlowSetting::query()->where('key', CharterScheduleLoader::SETTING)->value('value')['version'])->toBe(CharterScheduleLoader::VERSION)
        ->and($loader->loaded())->toBeTrue();

    // A rotation keeps the dates of the handover: paid 10 days before the flight, taxes on the 5th of the next month.
    $flight = CharterFlight::query()->where('charter_contract_id', $signed->id)->where('route', 'CLJ HRG CLJ')->orderBy('flight_date')->firstOrFail();
    expect($flight->flight_date->toDateString())->toBe('2026-03-29')
        ->and($flight->operator)->toBe('ANIMA WINGS')
        ->and($flight->paymentDate()->toDateString())->toBe('2026-03-19')
        ->and($flight->taxesPaymentDate()->toDateString())->toBe('2026-04-05');

    // Loaded once: the next call does nothing, even after the contracts were removed by hand.
    expect($loader->load())->toBeNull();
    CharterContract::query()->delete();
    expect($loader->load())->toBeNull()
        ->and(CharterContract::query()->count())->toBe(0);
});

test('contracts entered by hand keep the programme from loading', function () {
    CharterContract::factory()->create(['season' => 'S26']);

    expect(app(CharterScheduleLoader::class)->load())->toBeNull()
        ->and(CharterContract::query()->count())->toBe(1)
        ->and(CharterFlight::query()->count())->toBe(0);
});

test('loading again by force replaces the rotations of the same seasons', function () {
    $loader = app(CharterScheduleLoader::class);
    $loader->load();
    CharterFlight::query()->where('charter_contract_id', CharterContract::query()->where('season', 'S26')->value('id'))->limit(10)->delete();

    $result = $loader->load(force: true);

    expect($result['imported'])->toBe(1628)
        ->and(CharterContract::query()->count())->toBe(2)
        ->and(CharterFlight::query()->count())->toBe(1628);
});

test('app:upgrade loads the programme when no contract exists yet', function () {
    $this->artisan('app:upgrade', ['--skip-sync' => true, '--skip-etrip' => true])
        ->expectsOutputToContain('programul din pachetul de predare')
        ->assertSuccessful();

    expect(CharterContract::query()->count())->toBe(2)
        ->and(CharterFlight::query()->count())->toBe(1628);

    $this->artisan('app:upgrade', ['--skip-sync' => true, '--skip-etrip' => true])
        ->doesntExpectOutputToContain('programul din pachetul de predare')
        ->assertSuccessful();

    expect(CharterFlight::query()->count())->toBe(1628);
});
