<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:upgrade {--skip-etrip : Do not refresh the eTrip supplier mirror} {--skip-sync : Do not pull the recent ERP documents}')]
#[Description('Bring a deployed installation up to date: run pending migrations, pull the recent ERP documents and refresh the eTrip supplier mirror. Safe to run on every deploy.')]
class UpgradeApp extends Command
{
    public function handle(): int
    {
        $this->components->info('Migrări');
        $this->call('migrate', ['--force' => true]);

        $failed = false;

        if (! $this->option('skip-sync')) {
            $this->components->info('Documente ERP (ultimele '.(int) config('sync.window_days', 45).' zile)');
            $failed = $this->call('erp:sync', ['--days' => (int) config('sync.window_days', 45)]) !== self::SUCCESS || $failed;
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
