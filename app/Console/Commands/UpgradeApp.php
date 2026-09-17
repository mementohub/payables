<?php

namespace App\Console\Commands;

use App\Services\CashFlow\CharterScheduleLoader;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('app:upgrade {--skip-etrip : Do not refresh the eTrip supplier mirror} {--skip-sync : Do not pull the recent ERP documents}')]
#[Description('Bring a deployed installation up to date: run pending migrations, load the handed-over charter programme when no contract exists yet, pull the last few days of ERP documents and refresh the eTrip supplier mirror. Safe to run on every deploy.')]
class UpgradeApp extends Command
{
    public function handle(CharterScheduleLoader $charter): int
    {
        $this->components->info('Migrări');
        $this->call('migrate', ['--force' => true]);

        $failed = false;

        try {
            if (($loaded = $charter->load()) !== null) {
                $this->components->info(sprintf(
                    'Contracte charter: %d contracte și %d rotații încărcate din %s. Raportul WCFR le folosește de la următoarea reconstrucție.',
                    $loaded['contracts'],
                    $loaded['flights'],
                    CharterScheduleLoader::VERSION,
                ));
            }
        } catch (Throwable $e) {
            report($e);
            $this->components->warn('Contracte charter: pachetul nu a putut fi încărcat ('.$e->getMessage().'); contractele se pot adăuga din Rapoarte → WCFR 52 Weeks → Charter.');
        }

        if (! $this->option('skip-sync')) {
            $this->components->info('Documente ERP (ultimele '.(int) config('sync.recent_days', 3).' zile; restul le aduce sincronizarea de noapte sau butonul din Întreținere)');
            $failed = $this->call('erp:sync') !== self::SUCCESS || $failed;
        }

        if (! $this->option('skip-etrip')) {
            $this->components->info('Furnizori eTrip');
            $failed = $this->call('etrip:sync-suppliers') !== self::SUCCESS || $failed;
        }

        if ($failed) {
            $this->components->error('Încheiat cu erori (vezi mai sus). Migrările au rulat; datele se pot aduce din nou cu „Sincronizează acum” după rezolvarea cauzei.');

            return self::FAILURE;
        }

        $this->components->info('Gata. Documentele ERP se sincronizează automat la 10 minute prin scheduler (schedule:run); fără scheduler, rulează `php artisan erp:sync` sau apasă „Sincronizează acum” în aplicație.');

        return self::SUCCESS;
    }
}
