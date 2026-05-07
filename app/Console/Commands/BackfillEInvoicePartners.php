<?php

namespace App\Console\Commands;

use App\Actions\EInvoices\MatchInvoiceToEInvoice;
use App\Models\Company;
use App\Models\EInvoice;
use App\Services\EInvoices\EInvoiceXmlParser;
use App\Services\EInvoices\PartnerCuiLookup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('e-invoices:backfill-partners {--company= : Restrict to a single company id} {--dry : Do not write changes}')]
#[Description('Re-resolve supplier_cui, partner_id, and invoice_id for stored e-invoices. Useful after lookup logic changes.')]
class BackfillEInvoicePartners extends Command
{
    public function handle(EInvoiceXmlParser $parser, MatchInvoiceToEInvoice $matcher): int
    {
        $companyId = (int) $this->option('company');
        $dry = (bool) $this->option('dry');

        $companyIds = $companyId > 0
            ? [$companyId]
            : Company::query()->pluck('id')->all();

        $totalChecked = 0;
        $supplierSet = 0;
        $partnerSet = 0;
        $invoiceSet = 0;

        foreach ($companyIds as $cid) {
            $lookup = new PartnerCuiLookup($cid);

            EInvoice::query()
                ->where('company_id', $cid)
                ->where(fn ($q) => $q->whereNull('partner_id')
                    ->orWhereNull('supplier_cui')
                    ->orWhereNull('invoice_id'))
                ->orderBy('id')
                ->chunkById(500, function ($chunk) use ($lookup, $parser, $matcher, $dry, &$totalChecked, &$supplierSet, &$partnerSet, &$invoiceSet) {
                    foreach ($chunk as $eInvoice) {
                        $totalChecked++;

                        $supplierCui = EInvoice::extractEmitentCui($eInvoice->msg_detalii);

                        if ($supplierCui !== null && $eInvoice->supplier_cui !== $supplierCui) {
                            $supplierSet++;
                            $eInvoice->supplier_cui = $supplierCui;

                            if (! $dry) {
                                $eInvoice->save();
                            }
                        }

                        if ($eInvoice->partner_id === null) {
                            $partnerId = $lookup->find($eInvoice->supplier_cui)
                                ?? $lookup->find($eInvoice->cod_cci_xml)
                                ?? $lookup->find($parser->extractSellerTaxId($eInvoice->msg_xml));

                            if ($partnerId !== null) {
                                $partnerSet++;
                                $eInvoice->partner_id = $partnerId;

                                if (! $dry) {
                                    $eInvoice->save();
                                }
                            }
                        }

                        if ($eInvoice->invoice_id === null) {
                            $matched = $matcher->find($eInvoice);

                            if ($matched !== null) {
                                $invoiceSet++;

                                if (! $dry) {
                                    $eInvoice->forceFill(['invoice_id' => $matched->id])->save();
                                }
                            }
                        }
                    }
                });
        }

        $prefix = $dry ? '[dry-run] ' : '';
        $this->info("{$prefix}Checked: {$totalChecked} | supplier_cui set: {$supplierSet} | partner_id set: {$partnerSet} | invoice_id set: {$invoiceSet}");

        return self::SUCCESS;
    }
}
