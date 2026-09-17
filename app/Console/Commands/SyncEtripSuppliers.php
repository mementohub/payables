<?php

namespace App\Console\Commands;

use App\Services\Etrip\EtripSupplierSyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('etrip:sync-suppliers {--connection= : Restrict to a single eTrip base (config/etrip.php key)}')]
#[Description('Mirror the suppliers of every eTrip base and match them to the ERP partners by VAT number or name.')]
class SyncEtripSuppliers extends Command
{
    public function handle(EtripSupplierSyncService $sync): int
    {
        $only = (string) $this->option('connection');
        $connections = array_keys((array) config('etrip.connections'));

        if ($only !== '') {
            $connections = array_values(array_filter($connections, fn (string $name) => $name === $only));
        }

        if ($connections === []) {
            $this->warn('Nicio bază eTrip de sincronizat.');

            return self::SUCCESS;
        }

        $rows = [];
        $failed = false;

        foreach ($connections as $connection) {
            try {
                $result = $sync->sync($connection);
            } catch (Throwable $e) {
                $failed = true;
                $this->components->error(config('etrip.connections.'.$connection, $connection).': '.trim(($e->getPrevious() ?? $e)->getMessage()));

                continue;
            }

            $rows[] = [config('etrip.connections.'.$connection, $connection), $connection, $result['synced'], $result['matched_cui'], $result['matched_name'], $result['unmatched']];
        }

        if ($rows !== []) {
            $this->table(['Bază eTrip', 'Conexiune', 'Furnizori', 'Potriviți CUI', 'Potriviți nume', 'Nepotriviți'], $rows);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
