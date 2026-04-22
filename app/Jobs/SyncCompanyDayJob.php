<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\SyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

class SyncCompanyDayJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public Company $company,
        public string $date,
    ) {
        $this->onQueue('long');
    }

    public function handle(SyncService $sync): void
    {
        $day = Carbon::parse($this->date);

        $sync->sync($this->company, $day->copy()->startOfDay(), $day->copy()->endOfDay());
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'sync',
            'sync-day',
            'company:'.$this->company->id,
            'date:'.$this->date,
        ];
    }
}
