<?php

use App\Models\Company;
use App\Models\User;
use App\Services\Maintenance\ArtisanRunner;
use App\Services\SyncService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Inertia\Support\SessionKey;
use Mockery\MockInterface;

/**
 * @param  array<string, int>  $overrides
 * @return array<string, int>
 */
function syncResult(array $overrides = []): array
{
    return [
        'partners' => 2, 'invoices' => 5, 'details' => 9, 'bank_accounts' => 0, 'company_bank_accounts' => 0,
        'payments' => 3, 'statements' => 1, 'e_invoices' => 0, ...$overrides,
    ];
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-16 10:00:00');
    Cache::flush();

    $this->user = User::factory()->withRoles('admin')->create(['name' => 'Bogdan']);
    $this->company = Company::factory()->create(['name' => 'Christian Tour']);
    $this->dir = sys_get_temp_dir().'/payables-sync-'.uniqid();
    $this->app->instance(ArtisanRunner::class, new ArtisanRunner($this->dir));
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

/**
 * @return array<string, mixed>
 */
function lastSyncToast(): array
{
    return session(SessionKey::FLASH_DATA)['toast'];
}

test('a sync started from the browser runs erp:sync in the background', function () {
    Process::fake();

    $this->actingAs($this->user)
        ->from('/invoices/received')
        ->post("/companies/{$this->company->id}/sync")
        ->assertRedirect('/invoices/received');

    Process::assertRan(fn ($process) => str_contains($process->command, 'nohup')
        && str_contains($process->command, 'artisan erp:sync')
        && str_contains($process->command, "--company={$this->company->id}")
        && str_contains($process->command, '--no-interaction --no-ansi'));

    expect(lastSyncToast()['type'])->toBe('success')
        ->and(app(ArtisanRunner::class)->isRunning(ArtisanRunner::SYNC))->toBeTrue()
        ->and(app(ArtisanRunner::class)->status(ArtisanRunner::SYNC)['started_by'])->toBe('Bogdan');
});

test('a date range is handed to the background run', function () {
    Process::fake();

    $this->actingAs($this->user)
        ->post("/companies/{$this->company->id}/sync", ['from' => '2026-01-01', 'to' => '2026-03-31'])
        ->assertRedirect();

    Process::assertRan(fn ($process) => str_contains($process->command, '--from=2026-01-01') && str_contains($process->command, '--to=2026-03-31'));
});

test('a second sync is refused while one is running', function () {
    Process::fake();
    Cache::forever('maintenance:erp:sync', ['started_at' => now()->toIso8601String(), 'mode' => 'background', 'by' => null, 'arguments' => []]);

    $this->actingAs($this->user)
        ->post("/companies/{$this->company->id}/sync")
        ->assertRedirect();

    Process::assertNothingRan();
    expect(lastSyncToast()['type'])->toBe('info');
});

test('when the process cannot start, the recent days still run inline', function () {
    Process::fake(['*' => Process::result(exitCode: 127, errorOutput: 'proc_open disabled')]);

    $this->mock(SyncService::class, function (MockInterface $mock) {
        $mock->shouldReceive('syncRecent')
            ->withArgs(fn (Company $company) => $company->is($this->company))
            ->once()
            ->andReturn(syncResult(['refreshed' => 1]));
    });

    $this->actingAs($this->user)
        ->post("/companies/{$this->company->id}/sync")
        ->assertRedirect();

    expect(lastSyncToast()['type'])->toBe('success')
        ->and(lastSyncToast()['message'])->toContain('a rulat pe loc')
        ->and(lastSyncToast()['message'])->toContain('proc_open disabled');
});

test('when the process cannot start, a long range is refused rather than run in the request', function () {
    Process::fake(['*' => Process::result(exitCode: 127, errorOutput: 'proc_open disabled')]);

    $this->mock(SyncService::class, function (MockInterface $mock) {
        $mock->shouldNotReceive('syncWindow');
    });

    $this->actingAs($this->user)
        ->post("/companies/{$this->company->id}/sync", ['from' => '2026-01-01', 'to' => '2026-03-31'])
        ->assertRedirect();

    expect(lastSyncToast()['type'])->toBe('error');
});

test('sync-all starts the background run for every company, optionally days back', function () {
    Process::fake();

    $this->actingAs($this->user)->post('/companies/sync')->assertRedirect();
    Process::assertRan(fn ($process) => str_contains($process->command, 'artisan erp:sync --no-interaction'));

    Cache::flush();
    $this->actingAs($this->user)->post('/companies/sync', ['days' => 45])->assertRedirect();
    Process::assertRan(fn ($process) => str_contains($process->command, '--days=45') && str_contains($process->command, 'artisan erp:sync'));
});

test('the erp:sync command syncs every company and keeps going after a failure', function () {
    $broken = Company::factory()->create(['name' => 'Broken']);

    $this->mock(SyncService::class, function (MockInterface $mock) use ($broken) {
        $mock->shouldReceive('syncRecent')
            ->twice()
            ->andReturnUsing(function (Company $company) use ($broken) {
                if ($company->is($broken)) {
                    throw new RuntimeException('no route to host');
                }

                return syncResult(['refreshed' => 4]);
            });
    });

    $this->artisan('erp:sync')
        ->expectsOutputToContain('no route to host')
        ->assertFailed();
});

test('the erp:sync command accepts an explicit range', function () {
    $this->mock(SyncService::class, function (MockInterface $mock) {
        $mock->shouldReceive('syncWindow')
            ->withArgs(fn (Company $company, Carbon $from, Carbon $to) => $from->toDateString() === '2026-08-01' && $to->toDateString() === '2026-08-31')
            ->once()
            ->andReturn(syncResult(['refreshed' => 0]));
    });

    $this->artisan('erp:sync', ['--company' => $this->company->id, '--from' => '2026-08-01', '--to' => '2026-08-31'])->assertSuccessful();
});

test('the full history can be started from the page and by the command', function () {
    Process::fake();

    $this->actingAs($this->user)->post('/companies/sync', ['history' => 1])->assertRedirect();
    Process::assertRan(fn ($process) => str_contains($process->command, 'artisan erp:sync') && str_contains($process->command, '--history'));
    expect(lastSyncToast()['message'])->toContain('istoric');

    $this->mock(SyncService::class, function (MockInterface $mock) {
        $mock->shouldReceive('syncHistory')
            ->withArgs(fn (Company $company, ?callable $progress, ?Carbon $from) => $company->is($this->company) && $from?->toDateString() === '2024-01-01')
            ->once()
            ->andReturn([...syncResult(['refreshed' => 2]), 'from' => '2024-01-01', 'to' => '2026-09-16']);
    });

    $this->artisan('erp:sync', ['--company' => $this->company->id, '--history' => true, '--from' => '2024-01-01'])->assertSuccessful();
});

test('a second sync stands down while one is already running', function () {
    // A history run holds the floor; the scheduler's ten-minute pass must not
    // rewrite the same payment rows underneath it.
    Cache::put('erp:sync:running', Carbon::parse('2026-09-16 09:40:00')->toIso8601String(), 900);

    $this->mock(SyncService::class, function (MockInterface $mock) {
        $mock->shouldNotReceive('syncRecent');
    });

    $this->artisan('erp:sync')
        ->expectsOutputToContain('rulează deja')
        ->assertSuccessful();
});

test('a sync that finishes leaves the floor to the next one', function () {
    $this->mock(SyncService::class, function (MockInterface $mock) {
        $mock->shouldReceive('syncRecent')->once()->andReturn(syncResult(['refreshed' => 0]));
    });

    $this->artisan('erp:sync')->assertSuccessful();

    expect(Cache::has('erp:sync:running'))->toBeFalse();
});

test('a sync that blows up still leaves the floor to the next one', function () {
    $this->mock(SyncService::class, function (MockInterface $mock) {
        $mock->shouldReceive('syncRecent')->once()->andThrow(new RuntimeException('no route to host'));
    });

    $this->artisan('erp:sync')->assertFailed();

    expect(Cache::has('erp:sync:running'))->toBeFalse();
});
