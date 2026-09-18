<?php

namespace App\Console\Commands;

use App\Services\Routing\DepartmentAssigner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('invoices:assign {--since= : Also route again every invoice dated from this day on} {--all : Route every supplier invoice again}')]
#[Description('Route supplier invoice lines to departments (cost centre, charter, ticket, eTrip booking, office, supplier, history). Runs after every ERP sync for new and changed invoices; run it with --since after changing the rules.')]
class AssignInvoiceDepartments extends Command
{
    public function handle(DepartmentAssigner $assigner): int
    {
        $since = $this->option('all') ? Carbon::parse('1900-01-01') : ($this->option('since') ? Carbon::parse((string) $this->option('since')) : null);

        $result = $assigner->assignPending($since, fn (string $line) => $this->line("  {$line}"));

        $this->components->info(sprintf('%d facturi rutate, %d linii, %d linii fără departament.', $result['invoices'], $result['lines'], $result['unassigned']));

        return self::SUCCESS;
    }
}
