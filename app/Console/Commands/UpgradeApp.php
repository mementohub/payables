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

        if (! $this->option('skip-sync')) {
            $this->components->info('Documente ERP (ultimele '.(int) config('sync.window_days', 45).' zile)');
            $this->call('erp:sync', ['--days' => (int) config('sync.window_days', 45)]);
        }

        if (! $this->option('skip-etrip')) {
            $this->components->info('Furnizori eTrip');
            $this->call('etrip:sync-suppliers');
        }

        $this->components->info('Gata. Documentele ERP se sincronizează automat la 10 minute prin scheduler (schedule:run); fără scheduler, rulează `php artisan erp:sync` sau apasă „Sincronizează acum” în aplicație.');

        return self::SUCCESS;
    }
}
