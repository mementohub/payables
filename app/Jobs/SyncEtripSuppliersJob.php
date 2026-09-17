<?php

namespace App\Jobs;

use App\Services\Etrip\EtripSupplierSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

class SyncEtripSuppliersJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public string $connection)
    {
        $this->onQueue('long');
    }

    public function handle(EtripSupplierSyncService $sync): void
    {
        if (! array_key_exists($this->connection, (array) config('etrip.connections'))) {
            return;
        }

        $sync->sync($this->connection);
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['etrip', 'etrip-suppliers', 'connection:'.$this->connection];
    }
}
