<?php

namespace App\Console\Commands;

use App\Services\Routing\DepartmentAssigner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

#[Signature('invoices:assign {--since= : Also route again every invoice dated from this day on} {--all : Route every supplier invoice again}')]
#[Description('Route supplier invoice lines to departments (cost centre, charter, ticket, eTrip booking, office, supplier, history). Runs after every ERP sync for new and changed invoices; run it with --since after changing the rules.')]
class AssignInvoiceDepartments extends Command
{
    public function handle(DepartmentAssigner $assigner): int
    {
        // The ERP sync routes too; the two never write the same routing at once.
        $startedAt = Carbon::now()->toIso8601String();

        if (! Cache::add(SyncErp::RUNNING_KEY, $startedAt, SyncErp::RUNNING_TTL)) {
            $this->components->warn('O sincronizare sau o rutare rulează deja; reîncercați după ce se termină.');

            return self::SUCCESS;
        }

        try {
            $since = $this->option('all') ? Carbon::parse('1900-01-01') : ($this->option('since') ? Carbon::parse((string) $this->option('since')) : null);

            $result = $assigner->assignPending($since, function (string $line) use ($startedAt): void {
                Cache::put(SyncErp::RUNNING_KEY, $startedAt, SyncErp::RUNNING_TTL);
                $this->line("  {$line}");
            });

            $this->components->info(sprintf('%d facturi rutate, %d linii, %d linii fără departament.', $result['invoices'], $result['lines'], $result['unassigned']));
        } finally {
            Cache::forget(SyncErp::RUNNING_KEY);
        }

        return self::SUCCESS;
    }
}
