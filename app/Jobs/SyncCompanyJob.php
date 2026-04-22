<?php

namespace App\Jobs;

use App\Models\Company;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

class SyncCompanyJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public Company $company,
        public ?string $from = null,
        public ?string $to = null,
    ) {
        $this->onQueue('long');
    }

    public function handle(): void
    {
        $from = $this->from ? Carbon::parse($this->from)->startOfDay() : Carbon::now()->subMonth()->startOfDay();
        $to = $this->to ? Carbon::parse($this->to)->startOfDay() : Carbon::now()->startOfDay();

        if ($from->greaterThan($to)) {
            return;
        }

        $jobs = [];
        for ($day = $from->copy(); $day->lessThanOrEqualTo($to); $day->addDay()) {
            $jobs[] = new SyncCompanyDayJob($this->company, $day->toDateString());
        }

        foreach ($jobs as $job) {
            dispatch($job);
        }
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['sync', 'sync-orchestrator', 'company:'.$this->company->id, 'company:'.$this->company->name];
    }
}
