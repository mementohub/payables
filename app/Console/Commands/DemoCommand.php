<?php

namespace App\Console\Commands;

use App\Jobs\SeedDemoActivityJob;
use App\Jobs\SyncCompanyDayJob;
use App\Models\Company;
use Illuminate\Bus\Batch;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

#[Signature('app:demo {--from=2026-01-01 : Sync start date} {--to= : Sync end date (defaults to today)}')]
#[Description('Bootstrap a demo environment: seed companies from omc.json, dispatch the sync batch, and queue the demo activity seeding to run after the batch completes.')]
class DemoCommand extends Command
{
    public function handle(): int
    {
        if (! $this->confirm('This will WIPE the local database and re-seed it. Continue?', false)) {
            $this->warn('Aborted.');

            return self::FAILURE;
        }

        $this->copyCompaniesFile();
        $this->freshDatabase();
        $dispatched = $this->syncCompanies();

        if (! $dispatched) {
            $this->info('Sync skipped — dispatching demo activity seeding directly.');
            SeedDemoActivityJob::dispatch();
        }

        $this->newLine();
        $this->info('Demo bootstrap dispatched. Watch progress in Horizon — partners and demo activity will seed automatically once the sync batch finishes.');

        return self::SUCCESS;
    }

    private function freshDatabase(): void
    {
        DB::prohibitDestructiveCommands(false);

        try {
            $this->info('Running migrate:fresh…');
            $this->call('migrate:fresh', ['--force' => true]);

            $this->info('Seeding database…');
            $this->call('db:seed', ['--force' => true]);
        } finally {
            DB::prohibitDestructiveCommands(app()->isProduction());
        }
    }

    private function copyCompaniesFile(): void
    {
        $filename = app()->isLocal() ? 'omc.local.json' : 'omc.json';
        $source = base_path($filename);
        $target = storage_path('app/private/companies.json');

        if (! is_file($source)) {
            throw new RuntimeException("Source file not found: {$source}");
        }

        if (! copy($source, $target)) {
            throw new RuntimeException("Failed to copy {$source} to {$target}");
        }

        $this->info("Copied {$filename} → companies.json");
    }

    private function syncCompanies(): bool
    {
        $from = Carbon::parse((string) $this->option('from'))->startOfDay();
        $to = $this->option('to')
            ? Carbon::parse((string) $this->option('to'))->startOfDay()
            : Carbon::now()->startOfDay();

        if ($from->greaterThan($to)) {
            $this->warn('Sync skipped: from date is after to date.');

            return false;
        }

        $companies = Company::all();

        if ($companies->isEmpty()) {
            $this->warn('No companies to sync.');

            return false;
        }

        $totalDays = $from->diffInDays($to) + 1;
        $jobs = [];

        foreach ($companies as $company) {
            for ($day = $from->copy(); $day->lessThanOrEqualTo($to); $day->addDay()) {
                $jobs[] = new SyncCompanyDayJob($company, $day->toDateString());
            }
        }

        $this->info("Dispatching {$companies->count()} companies × {$totalDays} days = ".count($jobs)." jobs ({$from->toDateString()} → {$to->toDateString()})…");
        $this->warn('Horizon must be running on the `long` queue (php artisan horizon).');

        Bus::batch($jobs)
            ->name('demo:sync')
            ->allowFailures()
            ->then(function (Batch $batch): void {
                SeedDemoActivityJob::dispatch();
            })
            ->catch(function (Batch $batch, Throwable $e): void {
                SeedDemoActivityJob::dispatch();
            })
            ->dispatch();

        return true;
    }
}
