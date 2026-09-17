<?php

use App\Models\Company;
use App\Models\User;
use App\Services\Maintenance\ApplicationLog;
use App\Services\Maintenance\ArtisanRunner;
use App\Services\Maintenance\MemoryLimit;
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

test('a running upgrade can be stopped from the page', function () {
    Process::fake(['*' => Process::result(output: "4242\n")]);

    $this->actingAs($this->user)->post('/maintenance/upgrade')->assertRedirect();

    expect(app(ArtisanRunner::class)->status(ArtisanRunner::UPGRADE)['pid'])->toBe(4242);

    $this->actingAs($this->user)
        ->post('/maintenance/stop/upgrade')
        ->assertRedirect();

    Process::assertRan(fn ($process) => str_contains($process->command, 'pkill -TERM -P 4242; kill -TERM 4242;')
        && str_contains($process->command, "pkill -TERM -f 'artisan app:upgrad[e]'"));

    $status = app(ArtisanRunner::class)->status(ArtisanRunner::UPGRADE);

    expect($status['running'])->toBeFalse()
        ->and($status['exit_code'])->toBe(143)
        ->and($status['log'])->toContain('Oprită de Bogdan')
        ->and(lastToast()['type'])->toBe('success');
});

test('a sync started before the pid was recorded is still stopped by name', function () {
    Process::fake();
    Cache::forever('maintenance:erp:sync', ['started_at' => now()->toIso8601String(), 'mode' => 'background', 'by' => null, 'arguments' => []]);

    $this->actingAs($this->user)->post('/maintenance/stop/sync')->assertRedirect();

    Process::assertRan(fn ($process) => ! str_contains($process->command, 'kill -TERM 0')
        && str_contains($process->command, "pkill -TERM -f 'artisan erp:syn[c]'"));

    expect(app(ArtisanRunner::class)->isRunning(ArtisanRunner::SYNC))->toBeFalse();
});

test('only known runs can be stopped', function () {
    $this->actingAs($this->user)->post('/maintenance/stop/rm-rf')->assertNotFound();
});

test('stopping a run that already ended keeps its real outcome', function () {
    Process::fake();
    Cache::forever('maintenance:erp:sync', ['started_at' => now()->toIso8601String(), 'mode' => 'background', 'by' => null, 'arguments' => [], 'pid' => 77]);
    File::ensureDirectoryExists($this->dir);
    File::put($this->dir.'/erp:sync.log', "Gata.\n__EXIT:0\n");

    $this->actingAs($this->user)->post('/maintenance/stop/sync')->assertRedirect();

    expect(app(ArtisanRunner::class)->status(ArtisanRunner::SYNC)['exit_code'])->toBe(0);
});

test('the page shows the end of the application log', function () {
    File::ensureDirectoryExists($this->dir);
    File::put($this->dir.'/laravel.log', implode("\n", [
        '[2026-09-17 09:00:00] production.INFO: ceva banal',
        '[2026-09-17 09:30:00] production.ERROR: Allowed memory size of 134217728 bytes exhausted',
        'Stack trace:',
        '#0 /var/www/app.php(12): boom()',
    ]));

    $log = (new ApplicationLog($this->dir))->tail();

    expect($log['path'])->toBe('laravel.log')
        ->and($log['entries'])->toHaveCount(2)
        // Newest first, with the trace kept on the line that raised it.
        ->and($log['entries'][0]['level'])->toBe('error')
        ->and($log['entries'][0]['message'])->toBe('Allowed memory size of 134217728 bytes exhausted')
        ->and($log['entries'][0]['body'])->toContain('#0 /var/www/app.php(12)')
        ->and($log['entries'][1]['level'])->toBe('info');
});

test('a log too big to read is only read from the end', function () {
    File::ensureDirectoryExists($this->dir);
    $filler = str_repeat("[2026-09-17 08:00:00] production.INFO: vechi\n", 20000);
    File::put($this->dir.'/laravel.log', $filler."[2026-09-17 09:30:00] production.ERROR: ultima\n");

    $log = (new ApplicationLog($this->dir))->tail();

    expect($log['size'])->toBeGreaterThan(262144)
        ->and($log['entries'][0]['message'])->toBe('ultima')
        ->and($log['entries'])->toHaveCount(25);
});

test('a background run asks PHP for the memory a decade of documents needs', function () {
    Process::fake(['*' => Process::result('4242')]);
    config(['sync.run_memory_limit' => '512M']);

    $this->actingAs($this->user)->post('/maintenance/upgrade')->assertRedirect();

    // The whole script is quoted again for `sh -c`, so look for the setting
    // itself rather than for the quoting around it.
    Process::assertRan(fn ($process) => str_contains($process->command, 'memory_limit=512M')
        && str_contains($process->command, 'artisan app:upgrade'));
});

test('an empty memory limit leaves the server setting alone', function () {
    Process::fake(['*' => Process::result('4242')]);
    config(['sync.run_memory_limit' => '']);

    $this->actingAs($this->user)->post('/maintenance/upgrade')->assertRedirect();

    Process::assertRan(fn ($process) => ! str_contains($process->command, 'memory_limit'));
});

test('the application raises a server memory limit lower than its heaviest page needs', function () {
    $limit = new MemoryLimit;
    $server = ini_get('memory_limit');

    try {
        ini_set('memory_limit', '128M');
        expect($limit->raiseTo('256M'))->toBe('256M');

        // A more generous setting is never pulled back down.
        expect($limit->raiseTo('64M'))->toBe('256M');

        ini_set('memory_limit', '-1');
        expect($limit->raiseTo('256M'))->toBe('-1');
    } finally {
        ini_set('memory_limit', $server);
    }
});

test('ini sizes are read the way PHP writes them', function () {
    $limit = new MemoryLimit;

    expect($limit->bytes('256M'))->toBe(268435456)
        ->and($limit->bytes('1G'))->toBe(1073741824)
        ->and($limit->bytes('134217728'))->toBe(134217728)
        ->and($limit->bytes('512k'))->toBe(524288)
        ->and($limit->bytes('-1'))->toBe(-1)
        ->and($limit->bytes(''))->toBe(0);
});
