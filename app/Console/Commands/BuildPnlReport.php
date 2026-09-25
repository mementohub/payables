<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Reports\PnlReportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('pnl:build {--company= : Compania; implicit toate} {--year= : Anul; implicit cel curent}')]
#[Description('Construiește contul de profit și pierdere (venit și marjă din eTrip, cheltuieli din registrul OMC) și îl pune în cache. Interogarea trece de un minut, deci nu poate fi făcută într-o cerere web.')]
class BuildPnlReport extends Command
{
    public function handle(PnlReportService $service): int
    {
        $year = (int) ($this->option('year') ?: now()->year);

        $companies = $this->option('company')
            ? Company::query()->whereKey((int) $this->option('company'))->get()
            : Company::query()->orderBy('name')->get();

        if ($companies->isEmpty()) {
            $this->error('Nicio companie de construit.');

            return self::FAILURE;
        }

        $failed = 0;

        foreach ($companies as $company) {
            $this->info(sprintf('Se construiește P&L %d pentru %s…', $year, $company->name));

            $startedAt = microtime(true);
            $built = $service->report($company, $year, forceRefresh: true);
            $ms = (int) ((microtime(true) - $startedAt) * 1000);

            if (isset($built['error'])) {
                $this->error(sprintf('  eșuat în %d ms: %s', $ms, $built['error']));
                $failed++;

                continue;
            }

            // Raportul se ține pe lună; rezumatul e anul întreg.
            $report = $service->view($built, 'year');

            $this->line(sprintf(
                '  gata în %d ms: venit %s lei, marjă %s lei, cheltuieli %s lei, EBITDA %s lei%s',
                $ms,
                number_format($report['revenue']['total']['net'], 0, ',', '.'),
                number_format($report['revenue']['total']['margin'], 0, ',', '.'),
                number_format($report['totals']['total'], 0, ',', '.'),
                number_format($report['revenue']['total']['margin'] - $report['totals']['total'], 0, ',', '.'),
                $report['meta']['reconciles'] ? '' : ' — ATENȚIE: vederile nu închid pe același total',
            ));
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
