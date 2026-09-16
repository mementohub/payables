<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\Etrip\EtripSupplierSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

class SyncEtripSuppliersJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public Company $company)
    {
        $this->onQueue('long');
    }

    public function handle(EtripSupplierSyncService $sync): void
    {
        if ($this->company->etripConnection() === null) {
            return;
        }

        $sync->sync($this->company);
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['etrip', 'etrip-suppliers', 'company:'.$this->company->id, 'company:'.$this->company->name];
    }
}
