<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:upgrade {--skip-etrip : Do not refresh the eTrip supplier mirror}')]
#[Description('Bring a deployed installation up to date: run pending migrations, then refresh the eTrip supplier mirror. Safe to run on every deploy.')]
class UpgradeApp extends Command
{
    public function handle(): int
    {
        $this->components->info('Migrări');
        $this->call('migrate', ['--force' => true]);

        if (! $this->option('skip-etrip')) {
            $this->components->info('Furnizori eTrip');
            $this->call('etrip:sync-suppliers');
        }

        $this->components->info('Gata. Sincronizarea facturilor se pornește din aplicație (Companii → Sincronizează); furnizorii eTrip se reîmprospătează și noaptea, automat.');

        return self::SUCCESS;
    }
}
