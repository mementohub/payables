<?php

use App\Models\User;
use App\Services\Maintenance\ArtisanRunner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    Carbon::setTestNow('2026-09-16 10:00:00');
    Cache::flush();
    Process::fake();

    $this->user = User::factory()->create();
    $this->dir = sys_get_temp_dir().'/payables-auto-'.uniqid();
    $this->app->instance(ArtisanRunner::class, new ArtisanRunner($this->dir));
    Cache::forever('erp:sync:nightly', '2026-09-16');
    Cache::forever('cashflow:nightly', '2026-09-16');
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

test('a page view starts the background sync when the scheduler is silent and the last run is old', function () {
    Cache::forever('erp:sync:last_run', ['at' => '2026-09-16T09:40:00+00:00', 'ok' => true, 'summary' => '']);

    $this->actingAs($this->user)->get('/companies')->assertOk();

    Process::assertRan(fn ($process) => str_contains($process->command, 'artisan erp:sync --no-interaction'));
    expect(app(ArtisanRunner::class)->status(ArtisanRunner::SYNC)['started_by'])->toBe('automat');
});

test('a recent run, a live scheduler or a run in progress keep page views from starting one', function () {
    Cache::forever('erp:sync:last_run', ['at' => '2026-09-16T09:55:00+00:00', 'ok' => true, 'summary' => '']);
    $this->actingAs($this->user)->get('/companies')->assertOk();
    Process::assertNothingRan();

    Cache::forget('erp:sync:auto:checked');
    Cache::forever('erp:sync:last_run', ['at' => '2026-09-16T09:00:00+00:00', 'ok' => true, 'summary' => '']);
    Cache::forever('scheduler:heartbeat', '2026-09-16T09:59:30+00:00');
    $this->actingAs($this->user)->get('/companies')->assertOk();
    Process::assertNothingRan();

    Cache::forget('erp:sync:auto:checked');
    Cache::forget('scheduler:heartbeat');
    Cache::forever('maintenance:erp:sync', ['started_at' => now()->toIso8601String(), 'mode' => 'background', 'by' => null, 'arguments' => []]);
    $this->actingAs($this->user)->get('/companies')->assertOk();
    Process::assertNothingRan();
});

test('the check itself is throttled to once a minute', function () {
    Cache::forever('erp:sync:last_run', ['at' => '2026-09-16T09:00:00+00:00', 'ok' => true, 'summary' => '']);

    $this->actingAs($this->user)->get('/companies')->assertOk();
    Process::assertRanTimes(fn ($process) => str_contains($process->command, 'erp:sync'), 1);

    File::put($this->dir.'/erp:sync.log', "done\n__EXIT:0\n");
    $this->actingAs($this->user)->get('/companies')->assertOk();
    Process::assertRanTimes(fn ($process) => str_contains($process->command, 'erp:sync'), 1);
});

test('once a day from the configured hour the full window is pulled instead', function () {
    Cache::forget('erp:sync:nightly');
    Cache::forever('erp:sync:last_run', ['at' => '2026-09-16T09:55:00+00:00', 'ok' => true, 'summary' => '']);

    $this->actingAs($this->user)->get('/companies')->assertOk();

    Process::assertRan(fn ($process) => str_contains($process->command, '--days=45'));
    expect(Cache::get('erp:sync:nightly'))->toBe('2026-09-16');
});

test('guests and failed responses never start a sync', function () {
    Cache::forever('erp:sync:last_run', ['at' => '2026-09-16T09:00:00+00:00', 'ok' => true, 'summary' => '']);

    $this->get('/companies')->assertRedirect('/login');
    $this->actingAs($this->user)->get('/no-such-page')->assertNotFound();

    Process::assertNothingRan();
});

test('the maintenance page names how the data is kept fresh', function () {
    $this->actingAs($this->user)
        ->get('/maintenance')
        ->assertInertia(fn ($page) => $page->where('autoSync.mode', 'web')->where('autoSync.minutes', 10));

    Cache::forever('scheduler:heartbeat', now()->toIso8601String());

    $this->actingAs($this->user)
        ->get('/maintenance')
        ->assertInertia(fn ($page) => $page->where('autoSync.mode', 'scheduler'));
});

test('the daily pass waits for the configured hour in Bucharest time', function () {
    Cache::forget('erp:sync:nightly');
    Cache::forever('erp:sync:last_run', ['at' => '2026-09-16T09:55:00+00:00', 'ok' => true, 'summary' => '']);

    // 00:30 UTC is 03:30 in Bucharest: before 04:00, only the recent-days rule applies (and it is not due)
    Carbon::setTestNow('2026-09-16 00:30:00');
    Cache::forever('erp:sync:last_run', ['at' => '2026-09-16T00:25:00+00:00', 'ok' => true, 'summary' => '']);
    $this->actingAs($this->user)->get('/companies')->assertOk();
    Process::assertNothingRan();

    // 01:30 UTC is 04:30 in Bucharest: the 45-day pass runs once
    Cache::forget('erp:sync:auto:checked');
    Carbon::setTestNow('2026-09-16 01:30:00');
    Cache::forever('erp:sync:last_run', ['at' => '2026-09-16T01:25:00+00:00', 'ok' => true, 'summary' => '']);
    $this->actingAs($this->user)->get('/companies')->assertOk();
    Process::assertRan(fn ($process) => str_contains($process->command, '--days=45'));
    expect(Cache::get('erp:sync:nightly'))->toBe('2026-09-16');
});

test('the cash-flow snapshot is rebuilt once a day after the nightly sync, from the same page views', function () {
    Cache::forget('cashflow:nightly');
    Cache::forever('erp:sync:last_run', ['at' => '2026-09-16T09:55:00+00:00', 'ok' => true, 'summary' => '']);

    $this->actingAs($this->user)->get('/companies')->assertOk();

    Process::assertRan(fn ($process) => str_contains($process->command, 'artisan cashflow:build') && str_contains($process->command, '--by=automat'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'erp:sync'));
    expect(Cache::get('cashflow:nightly'))->toBe('2026-09-16');

    Cache::forget('erp:sync:auto:checked');
    $this->actingAs($this->user)->get('/companies')->assertOk();
    Process::assertRanTimes(fn ($process) => str_contains($process->command, 'cashflow:build'), 1);
});

test('the cash-flow rebuild waits for the nightly sync of the day', function () {
    Cache::forget('cashflow:nightly');
    Cache::forever('erp:sync:nightly', '2026-09-15');
    Cache::forever('erp:sync:last_run', ['at' => '2026-09-16T09:55:00+00:00', 'ok' => true, 'summary' => '']);
    Carbon::setTestNow('2026-09-16 00:30:00'); // 03:30 Bucharest: nightly sync not due yet

    $this->actingAs($this->user)->get('/companies')->assertOk();

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'cashflow:build'));
});
