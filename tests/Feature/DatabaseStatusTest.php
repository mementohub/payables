<?php

use App\Models\Company;
use App\Models\User;
use App\Services\DatabaseStatusService;

/**
 * @return array<string, mixed>|null
 */
function databaseStatusFor(string $name): ?array
{
    return collect(app(DatabaseStatusService::class)->statuses())
        ->firstWhere('name', $name);
}

beforeEach(function () {
    config()->set('database.status.connections', []);
    config()->set('database.status.timeout', 2);
});

test('guests are redirected to the login page', function () {
    $this->get('/database-status')->assertRedirect('/login');
});

test('authenticated users can view the database status page', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/database-status')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('database-status/index'));
});

test('the application connection is reported as connected', function () {
    $status = databaseStatusFor((string) config('database.default'));

    expect($status)->not->toBeNull()
        ->and($status['group'])->toBe(DatabaseStatusService::GROUP_APPLICATION)
        ->and($status['connected'])->toBeTrue()
        ->and($status['error'])->toBeNull()
        ->and($status['latency_ms'])->toBeGreaterThanOrEqual(0);
});

test('an unreachable connection reports the driver error', function () {
    config()->set('database.status.connections', ['omc' => 'OMC']);
    config()->set('database.connections.omc', [
        'driver' => 'pgsql',
        'host' => '127.0.0.1',
        'port' => '1',
        'database' => 'omc',
        'username' => 'omc_user',
        'password' => 'secret',
        'charset' => 'utf8',
        'prefix' => '',
        'prefix_indexes' => true,
        'search_path' => 'public',
        'sslmode' => 'prefer',
    ]);

    $status = databaseStatusFor('omc');

    expect($status['label'])->toBe('OMC')
        ->and($status['group'])->toBe(DatabaseStatusService::GROUP_EXTERNAL)
        ->and($status['host'])->toBe('127.0.0.1')
        ->and($status['database'])->toBe('omc')
        ->and($status['connected'])->toBeFalse()
        ->and($status['error'])->not->toBeEmpty()
        ->and($status['error_class'])->not->toBeEmpty()
        ->and($status['latency_ms'])->toBeNull();
});

test('a connection missing from the database config is reported as undefined', function () {
    config()->set('database.status.connections', ['etrip_chr' => 'eTrip Christian Tour']);
    config()->set('database.connections.etrip_chr', null);

    $status = databaseStatusFor('etrip_chr');

    expect($status['connected'])->toBeFalse()
        ->and($status['host'])->toBeNull()
        ->and($status['error'])->toContain('nu este definită');
});

test('every company gets its own connection status', function () {
    $company = Company::factory()->create([
        'name' => 'Christian Tour',
        'db_host' => '127.0.0.1',
        'db_port' => '1',
    ]);

    $status = databaseStatusFor("company_{$company->id}");

    expect($status['label'])->toBe('Christian Tour')
        ->and($status['group'])->toBe(DatabaseStatusService::GROUP_COMPANIES)
        ->and($status['connected'])->toBeFalse()
        ->and($status['error'])->not->toBeEmpty();
});

test('the probe connection is cleaned up after checking', function () {
    config()->set('database.status.connections', ['omc' => 'OMC']);

    app(DatabaseStatusService::class)->statuses();

    expect(config('database.connections'))->not->toHaveKey('database_status_probe');
});

test('statuses never expose connection passwords', function () {
    config()->set('database.status.connections', ['omc' => 'OMC']);
    Company::factory()->create(['db_password' => 'super-secret']);

    $statuses = app(DatabaseStatusService::class)->statuses();

    $keys = collect($statuses)->flatMap(fn (array $status) => array_keys($status))->unique()->all();

    expect(json_encode($statuses))->not->toContain('super-secret')
        ->and($keys)->not->toContain('password');
});
