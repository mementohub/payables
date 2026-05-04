<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\Invoices\InvoicePresenter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('invoices:inspect-baza {--company= : Company id} {--nr= : Invoice nr_doc}')]
#[Description('Diagnose nr_doc_baza → nr_doc matching for a given invoice. Useful when the real-supplier hint does not appear in the UI.')]
class InspectBazaInvoice extends Command
{
    public function handle(InvoicePresenter $presenter): int
    {
        $companyId = (int) $this->option('company');
        $nr = trim((string) $this->option('nr'));

        if ($companyId <= 0 || $nr === '') {
            $this->error('Provide --company=<id> and --nr=<nr_doc>.');

            return self::FAILURE;
        }

        $invoice = Invoice::query()
            ->where('company_id', $companyId)
            ->where('nr_doc', $nr)
            ->with('partner:id,name,cui', 'company:id,name')
            ->first();

        if ($invoice === null) {
            $this->error("No invoice found with company_id={$companyId} and nr_doc={$nr}.");

            return self::FAILURE;
        }

        $this->line("Invoice #{$invoice->id} ({$invoice->tip_doc} {$invoice->nr_doc}, ".($invoice->data_doc?->toDateString() ?? '—').')');
        $this->line('  company       : '.($invoice->company?->name ?? '?').' (id '.$invoice->company_id.')');
        $this->line('  partener_type : '.($invoice->partener_type ?? '—'));
        $this->line('  partner       : '.($invoice->partner?->name ?? '—').' (id '.($invoice->partner?->id ?? '—').')');
        $this->line('  nr_doc_baza   : '.var_export($invoice->nr_doc_baza, true));
        $this->line('  tip_doc_baza  : '.var_export($invoice->tip_doc_baza, true));

        if ($invoice->nr_doc_baza === null || trim((string) $invoice->nr_doc_baza) === '') {
            $this->warn('No nr_doc_baza on this invoice — nothing to match.');

            return self::SUCCESS;
        }

        $presenter->preloadBazaInvoices([$invoice]);

        if (! $invoice->relationLoaded('bazaInvoice') || $invoice->bazaInvoice === null) {
            $this->warn('No same-company invoice has nr_doc matching nr_doc_baza="'.$invoice->nr_doc_baza.'".');
            $this->line('Candidates with similar nr_doc in this company:');

            $needle = strtolower(trim($invoice->nr_doc_baza));

            $similar = Invoice::query()
                ->where('company_id', $invoice->company_id)
                ->whereRaw('LOWER(nr_doc) LIKE ?', ['%'.$needle.'%'])
                ->limit(10)
                ->get(['id', 'tip_doc', 'nr_doc', 'partner_id']);

            foreach ($similar as $row) {
                $this->line("  - #{$row->id} {$row->tip_doc} \"{$row->nr_doc}\"");
            }

            if ($similar->isEmpty()) {
                $this->line('  (none)');
            }

            return self::SUCCESS;
        }

        $base = $invoice->bazaInvoice;
        $this->info('Match found:');
        $this->line('  base invoice  : #'.$base->id.' '.$base->tip_doc.' '.$base->nr_doc);
        $this->line('  real supplier : '.($base->partner?->name ?? '—').' (id '.($base->partner?->id ?? '—').')');

        return self::SUCCESS;
    }
}
