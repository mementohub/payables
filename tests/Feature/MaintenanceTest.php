<?php

use App\Models\Company;
use App\Models\User;
use App\Services\Maintenance\ArtisanRunner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Inertia\Support\SessionKey;

beforeEach(function () {
    Carbon::setTestNow('2026-09-16 10:00:00');
    Cache::flush();

    $this->user = User::factory()->create(['name' => 'Bogdan']);
    $this->dir = sys_get_temp_dir().'/payables-runs-'.uniqid();
    $this->log = $this->dir.'/app:upgrade.log';
    $this->app->instance(ArtisanRunner::class, new ArtisanRunner($this->dir));
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

function lastToast(): array
{
    return session(SessionKey::FLASH_DATA)['toast'];
}

test('guests are redirected to the login page', function () {
    $this->get('/maintenance')->assertRedirect('/login');
});

test('the page shows the pending migrations, the last runs and the scheduler state', function () {
    Company::factory()->create(['name' => 'Christian Tour', 'erp_connection' => 'omc', 'last_synced_at' => '2026-09-16 09:50:00']);
    Cache::forever('scheduler:heartbeat', '2026-09-16T09:59:30+00:00');
    Cache::forever('erp:sync:last_run', ['at' => '2026-09-16T09:52:00+00:00', 'ok' => true, 'summary' => 'Christian Tour: 3 facturi, 1 plăți']);

    $this->actingAs($this->user)
        ->get('/maintenance')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('maintenance/index')
            ->where('pendingMigrations', [])
            ->where('upgrade.running', false)
            ->where('upgrade.exit_code', null)
            ->where('syncRun.running', false)
            ->where('scheduler.alive', true)
            ->where('sync.summary', 'Christian Tour: 3 facturi, 1 plăți')
            ->where('companies.0.source', 'OMC Christian Tour (live)')
        );
});

test('the scheduler is reported down without a recent heartbeat', function () {
    Cache::forever('scheduler:heartbeat', '2026-09-16T09:40:00+00:00');

    $this->actingAs($this->user)
        ->get('/maintenance')
        ->assertInertia(fn ($page) => $page->where('scheduler.alive', false));
});

test('app:upgrade starts detached in the background and the page follows its log', function () {
    Process::fake();

    $this->actingAs($this->user)
        ->from('/maintenance')
        ->post('/maintenance/upgrade')
        ->assertRedirect('/maintenance');

    Process::assertRan(fn ($process) => str_contains($process->command, 'artisan app:upgrade --no-interaction')
        && str_contains($process->command, 'nohup')
        && str_contains($process->command, '__EXIT:'));

    expect(lastToast()['type'])->toBe('success');

    $status = app(ArtisanRunner::class)->status(ArtisanRunner::UPGRADE);
    expect($status['running'])->toBeTrue()
        ->and($status['started_by'])->toBe('Bogdan')
        ->and($status['mode'])->toBe('background');

    File::append($this->log, "Migrări\n  DONE\nGata.\n__EXIT:0\n");

    $status = app(ArtisanRunner::class)->status(ArtisanRunner::UPGRADE);
    expect($status['running'])->toBeFalse()
        ->and($status['exit_code'])->toBe(0)
        ->and($status['log'])->toBe("Migrări\n  DONE\nGata.");

    // a second click while it runs does not start another one
    File::put($this->log, '');
    $this->actingAs($this->user)->post('/maintenance/upgrade');
    Process::assertRanTimes(fn ($process) => str_contains($process->command, 'app:upgrade'), 1);
});

test('a background start that fails is reported instead of thrown', function () {
    Process::fake(['*' => Process::result(exitCode: 127, errorOutput: 'sh: nohup: not found')]);

    $this->actingAs($this->user)
        ->post('/maintenance/upgrade')
        ->assertRedirect();

    expect(lastToast()['type'])->toBe('error')
        ->and(lastToast()['message'])->toContain('nohup: not found')
        ->and(app(ArtisanRunner::class)->status(ArtisanRunner::UPGRADE)['running'])->toBeFalse();
});

test('the migrations can be run inline and their output is kept in the log', function () {
    $this->actingAs($this->user)
        ->post('/maintenance/migrate')
        ->assertRedirect();

    expect(lastToast()['type'])->toBe('success');

    $status = app(ArtisanRunner::class)->status(ArtisanRunner::UPGRADE);
    expect($status['running'])->toBeFalse()
        ->and($status['exit_code'])->toBe(0)
        ->and($status['mode'])->toBe('inline')
        ->and($status['log'])->toContain('Nothing to migrate');
});

test('a run without an end is reported as lost after three hours', function () {
    Cache::forever('maintenance:app:upgrade', ['started_at' => '2026-09-16T06:00:00+00:00', 'mode' => 'background', 'by' => null]);
    File::ensureDirectoryExists($this->dir);
    File::put($this->log, "Migrări\n");

    $status = app(ArtisanRunner::class)->status(ArtisanRunner::UPGRADE);

    expect($status['running'])->toBeFalse()
        ->and($status['stale'])->toBeTrue()
        ->and($status['exit_code'])->toBeNull();
});
