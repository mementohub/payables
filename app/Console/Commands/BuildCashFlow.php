<?php

namespace App\Console\Commands;

use App\Models\CashFlowSnapshot;
use App\Services\CashFlow\CashFlowReportBuilder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('cashflow:build {--by= : Who asked for the run, shown on the report}')]
#[Description('Rebuild the WCFR 52 Weeks cash-flow snapshot from eTrip, OMC and the charter contracts. Scheduled nightly after the OMC sync; run it after changing the parameters.')]
class BuildCashFlow extends Command
{
    public function handle(CashFlowReportBuilder $builder): int
    {
        $this->info('Se construiește raportul WCFR 52 Weeks…');

        $snapshot = $builder->build($this->option('by') ?: 'programat');

        foreach ((array) $snapshot->sources as $source) {
            $line = sprintf('[%s] %s — %s (%d ms)', strtoupper($source['status']), $source['label'], $source['message'] ?? '', $source['ms']);

            match ($source['status']) {
                'error' => $this->error($line),
                'skipped' => $this->warn($line),
                default => $this->line($line),
            };
        }

        Cache::forever('cashflow:last_run', [
            'at' => $snapshot->built_at->toIso8601String(),
            'status' => $snapshot->status,
            'id' => $snapshot->id,
        ]);

        if ($snapshot->status === CashFlowSnapshot::STATUS_FAILED) {
            $this->error('Raportul nu a putut fi construit: '.$snapshot->error);

            return self::FAILURE;
        }

        $kpis = $snapshot->payload['kpis'] ?? [];
        $this->info(sprintf(
            'Snapshot #%d (%s) în %d ms: sold inițial %s RON, sold final S+52 %s RON, minim %s RON în săptămâna %s.',
            $snapshot->id,
            $snapshot->status,
            $snapshot->duration_ms,
            number_format((float) ($kpis['opening'] ?? 0), 0, ',', '.'),
            number_format((float) ($kpis['closing_52'] ?? 0), 0, ',', '.'),
            number_format((float) ($kpis['min_closing']['value'] ?? 0), 0, ',', '.'),
            $kpis['min_closing']['week'] ?? '-',
        ));

        return self::SUCCESS;
    }
}
