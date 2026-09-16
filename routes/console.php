<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => Cache::forever('scheduler:heartbeat', now()->toIso8601String()))->everyMinute()->name('scheduler-heartbeat');
Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('erp:sync')->everyTenMinutes()->withoutOverlapping(30)->runInBackground();
Schedule::command('erp:sync', ['--days' => (int) config('sync.window_days', 45)])->dailyAt('02:15')->withoutOverlapping(180)->runInBackground();
Schedule::command('etrip:sync-suppliers')->dailyAt('03:30')->withoutOverlapping();
