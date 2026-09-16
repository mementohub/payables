<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Etrip\EtripSupplierSyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('etrip:sync-suppliers {--company= : Restrict to a single company id}')]
#[Description('Mirror the eTrip suppliers of every company linked to an eTrip database and match them to ERP partners by VAT number or name.')]
class SyncEtripSuppliers extends Command
{
    public function handle(EtripSupplierSyncService $sync): int
    {
        $companyId = (int) $this->option('company');

        $companies = Company::query()
            ->whereNotNull('etrip_connection')
            ->when($companyId > 0, fn ($query) => $query->whereKey($companyId))
            ->orderBy('name')
            ->get();

        if ($companies->isEmpty()) {
            $this->warn('Nicio companie legată de o bază eTrip.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($companies as $company) {
            if ($company->etripConnection() === null) {
                $this->warn("{$company->name}: conexiunea „{$company->etrip_connection}” nu este definită.");

                continue;
            }

            $result = $sync->sync($company);

            $rows[] = [$company->name, $company->etrip_connection, $result['synced'], $result['matched_cui'], $result['matched_name'], $result['unmatched']];
        }

        $this->table(['Companie', 'eTrip', 'Furnizori', 'Potriviți CUI', 'Potriviți nume', 'Nepotriviți'], $rows);

        return self::SUCCESS;
    }
}
