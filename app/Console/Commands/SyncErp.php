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

#[Signature('erp:sync {--company= : Restrict to a single company id} {--days= : Days back from today (default: sync.recent_days)} {--from= : First document date} {--to= : Last document date} {--history : Everything from sync.history_from (or --from) to today, resuming where a previous run stopped}')]
#[Description('Pull the recent documents of every company from its ERP database, inline, and refresh the invoices still open. Scheduled every ten minutes; run it by hand after a deploy or when the scheduler is off.')]
class SyncErp extends Command
{
    /**
     * Only one ERP sync at a time, whoever starts it: the scheduler every ten
     * minutes, a button in Întreținere, a history run that lasts hours. Two of
     * them on one company delete and rewrite the same payment rows, so the
     * second one lands on keys the first has just written.
     */
    private const RUNNING_KEY = 'erp:sync:running';

    /** A run without a sign of life for this long counts as gone. */
    private const RUNNING_TTL = 900;

    public function handle(SyncService $sync): int
    {
        $startedAt = Carbon::now()->toIso8601String();

        if (! Cache::add(self::RUNNING_KEY, $startedAt, self::RUNNING_TTL)) {
            $this->components->warn(sprintf(
                'O sincronizare pornită la %s rulează deja; aceasta se oprește, ca să nu scrie amândouă aceleași documente.',
                Carbon::parse((string) Cache::get(self::RUNNING_KEY, $startedAt))->format('d.m.Y H:i'),
            ));

            return self::SUCCESS;
        }

        try {
            return $this->pull($sync, $startedAt);
        } finally {
            Cache::forget(self::RUNNING_KEY);
        }
    }

    private function pull(SyncService $sync, string $startedAt): int
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

            $progress = function (string $line) use ($company, $startedAt): void {
                // Each slice says the run is still alive, so a history pass of
                // many hours keeps the others out while a killed one does not.
                Cache::put(self::RUNNING_KEY, $startedAt, self::RUNNING_TTL);
                $this->line("  {$company->name}: {$line}");
            };

            try {
                $result = $this->option('history')
                    ? $sync->syncHistory($company, $progress, $from)
                    : ($from || $to
                    ? $sync->syncWindow($company, $from ?? ($to ?? Carbon::now())->copy()->subDays(max(1, (int) config('sync.window_days', 45)) - 1), $to ?? Carbon::now(), $progress)
                    : $sync->syncRecent($company, $days, $progress));

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
