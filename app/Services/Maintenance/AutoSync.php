<?php

namespace App\Services\Maintenance;

use App\Http\Controllers\MaintenanceController;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Keeps the ERP copy fresh on servers where the Laravel scheduler is not
 * running: page views start the background sync when the last run is older
 * than the interval, and a 45-day pass once a day. When the scheduler's
 * heartbeat is alive, the cron does this instead and page views do nothing.
 */
class AutoSync
{
    /** The full check runs at most this often, whatever the traffic. */
    public const CHECK_EVERY_SECONDS = 60;

    public const MODE_SCHEDULER = 'scheduler';

    public const MODE_WEB = 'web';

    public const MODE_OFF = 'off';

    public function __construct(private ArtisanRunner $runner) {}

    public function kick(): void
    {
        if (! config('sync.auto_enabled', true)) {
            return;
        }

        if (! Cache::add('erp:sync:auto:checked', now()->toIso8601String(), self::CHECK_EVERY_SECONDS)) {
            return;
        }

        if ($this->schedulerAlive()
            || $this->runner->isRunning(ArtisanRunner::SYNC)
            || $this->runner->isRunning(ArtisanRunner::UPGRADE)) {
            return;
        }

        $nightly = $this->nightlyDue();

        if (! $nightly && ! $this->recentDue()) {
            return;
        }

        try {
            $this->runner->start(
                ArtisanRunner::SYNC,
                $nightly ? ['--days='.max(1, (int) config('sync.window_days', 45))] : [],
                'automat',
            );

            if ($nightly) {
                Cache::forever('erp:sync:nightly', Carbon::today()->toDateString());
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function mode(): string
    {
        if (! config('sync.auto_enabled', true)) {
            return self::MODE_OFF;
        }

        return $this->schedulerAlive() ? self::MODE_SCHEDULER : self::MODE_WEB;
    }

    public function schedulerAlive(): bool
    {
        $beat = Cache::get('scheduler:heartbeat');

        return $beat !== null && Carbon::parse($beat)->gt(now()->subMinutes(MaintenanceController::HEARTBEAT_MINUTES));
    }

    private function recentDue(): bool
    {
        $last = Cache::get('erp:sync:last_run');
        $at = isset($last['at']) ? Carbon::parse($last['at']) : null;

        return $at === null || $at->lt(now()->subMinutes(max(1, (int) config('sync.auto_minutes', 10))));
    }

    private function nightlyDue(): bool
    {
        return now()->hour >= (int) config('sync.nightly_hour', 2)
            && Cache::get('erp:sync:nightly') !== Carbon::today()->toDateString();
    }
}
