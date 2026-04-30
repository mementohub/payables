<?php

namespace App\Console\Commands;

use App\Jobs\SyncCompanyDayJob;
use App\Models\Company;
use App\Models\Department;
use App\Models\Partner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

#[Signature('app:demo {--from=2026-01-01 : Sync start date} {--to= : Sync end date (defaults to today)}')]
#[Description('Bootstrap a demo environment: seed companies from omc.json, sync history, and attach demo furnizori to departments.')]
class DemoCommand extends Command
{
    /**
     * @var array<string, array<int, string>>
     */
    private const DEPARTMENT_PARTNERS = [
        'Turism Intern' => [
            '7082954',
            'RO15490210',
            'RO27526695',
        ],
        'Ticketing' => [
            'WIZZ AIR',
            'RYANAIR',
            'AMADEUS MARKETING ROMANIA SRL',
            'RO8287958',
            'RO41404510',
        ],
        'Bookings' => [
            'EXPEDIA',
            'HOTELBEDS',
        ],
        'Sediul Central' => [
            'RO28909028',
            'RO6562512',
        ],
    ];

    public function handle(): int
    {
        if (! $this->confirm('This will WIPE the local database and re-seed it. Continue?', false)) {
            $this->warn('Aborted.');

            return self::FAILURE;
        }

        $this->copyCompaniesFile();
        $this->freshDatabase();
        $this->syncCompanies();
        $this->attachPartnersToDepartments();

        $this->newLine();
        $this->info('Demo bootstrap complete.');

        return self::SUCCESS;
    }

    private function freshDatabase(): void
    {
        $this->info('Running migrate:fresh…');
        $this->call('migrate:fresh', ['--force' => true]);

        $this->info('Seeding database…');
        $this->call('db:seed', ['--force' => true]);
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

    private function syncCompanies(): void
    {
        $from = Carbon::parse((string) $this->option('from'))->startOfDay();
        $to = $this->option('to')
            ? Carbon::parse((string) $this->option('to'))->startOfDay()
            : Carbon::now()->startOfDay();

        if ($from->greaterThan($to)) {
            $this->warn('Sync skipped: from date is after to date.');

            return;
        }

        $companies = Company::all();
        $totalDays = $from->diffInDays($to) + 1;

        $this->info("Syncing {$companies->count()} companies × {$totalDays} days ({$from->toDateString()} → {$to->toDateString()})…");

        foreach ($companies as $company) {
            $this->line("  • {$company->name}");

            $bar = $this->output->createProgressBar($totalDays);
            $bar->start();

            for ($day = $from->copy(); $day->lessThanOrEqualTo($to); $day->addDay()) {
                dispatch_sync(new SyncCompanyDayJob($company, $day->toDateString()));
                $bar->advance();
            }

            $bar->finish();
            $this->newLine();
        }
    }

    private function attachPartnersToDepartments(): void
    {
        $this->info('Attaching demo furnizori to departments…');

        foreach (self::DEPARTMENT_PARTNERS as $departmentName => $identifiers) {
            $department = Department::where('name', $departmentName)->first();

            if ($department === null) {
                $this->warn("  Department not found: {$departmentName}");

                continue;
            }

            $partnerIds = $this->resolvePartnerIds($identifiers);

            if (empty($partnerIds)) {
                $this->warn("  No partners matched for {$departmentName}");

                continue;
            }

            $department->partners()->syncWithoutDetaching($partnerIds);
            $this->line("  • {$departmentName}: ".count($partnerIds).' partners attached');
        }
    }

    /**
     * @param  array<int, string>  $identifiers
     * @return array<int, int>
     */
    private function resolvePartnerIds(array $identifiers): array
    {
        $ids = [];

        foreach ($identifiers as $identifier) {
            $digits = preg_replace('/\D+/', '', $identifier) ?? '';

            $query = Partner::query()->where('is_furnizor', true);

            if ($digits !== '' && mb_strlen($digits) >= 6) {
                $query->where('cui', 'like', "%{$digits}%");
            } else {
                $query->where('name', 'like', "%{$identifier}%");
            }

            $matched = $query->pluck('id')->all();

            if (empty($matched)) {
                $this->warn("    No match for: {$identifier}");

                continue;
            }

            $ids = array_merge($ids, $matched);
        }

        return array_values(array_unique($ids));
    }
}
