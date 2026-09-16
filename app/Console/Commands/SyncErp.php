<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\SyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

#[Signature('erp:sync {--company= : Restrict to a single company id} {--days= : Days back from today (default: sync.recent_days)} {--from= : First document date} {--to= : Last document date}')]
#[Description('Pull the recent documents of every company from its ERP database, inline, and refresh the invoices still open. Scheduled every ten minutes; run it by hand after a deploy or when the scheduler is off.')]
class SyncErp extends Command
{
    public function handle(SyncService $sync): int
    {
        $companyId = (int) $this->option('company');
        $days = $this->option('days') !== null ? max(1, (int) $this->option('days')) : null;
        $from = $this->option('from') ? Carbon::parse($this->option('from'))->startOfDay() : null;
        $to = $this->option('to') ? Carbon::parse($this->option('to'))->endOfDay() : null;

        $companies = Company::query()
            ->when($companyId > 0, fn ($query) => $query->whereKey($companyId))
            ->orderBy('name')
            ->get();

        if ($companies->isEmpty()) {
            $this->warn('Nicio companie de sincronizat.');

            return self::SUCCESS;
        }

        $rows = [];
        $summary = [];
        $failed = false;

        foreach ($companies as $company) {
            $source = $company->erpConnection()
                ? config('omc.connections.'.$company->erpConnection())
                : "{$company->db_host}/{$company->db_database}";

            $progress = fn (string $line) => $this->line("  {$company->name}: {$line}");

            try {
                $result = $from || $to
                    ? $sync->syncWindow($company, $from ?? ($to ?? Carbon::now())->copy()->subDays(max(1, (int) config('sync.window_days', 45)) - 1), $to ?? Carbon::now(), $progress)
                    : $sync->syncRecent($company, $days, $progress);

                $rows[] = [
                    $company->name,
                    $source,
                    $result['invoices'],
                    $result['payments'],
                    $result['partners'],
                    $result['statements'],
                    $result['refreshed'] ?? '–',
                ];
                $summary[] = sprintf('%s: %d facturi, %d plăți%s', $company->name, $result['invoices'], $result['payments'],
                    isset($result['refreshed']) ? ", {$result['refreshed']} deschise actualizate" : '');
            } catch (Throwable $e) {
                $failed = true;
                $message = trim(($e->getPrevious() ?? $e)->getMessage());
                $summary[] = "{$company->name}: eroare – {$message}";
                $this->components->error("{$company->name} ({$source}): {$message}");
            }
        }

        Cache::forever('erp:sync:last_run', ['at' => now()->toIso8601String(), 'ok' => ! $failed, 'summary' => implode(' · ', $summary)]);

        if ($rows !== []) {
            $this->table(['Companie', 'Sursă', 'Facturi', 'Plăți', 'Parteneri', 'Extrase', 'Facturi deschise actualizate'], $rows);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
