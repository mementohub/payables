<?php

namespace App\Actions\EInvoices;

use App\Models\EInvoice;
use App\Models\Invoice;
use App\Services\SyncService;

class MatchInvoiceToEInvoice
{
    /**
     * Find the best Invoice candidate for a given EInvoice using:
     *  - same company
     *  - same supplier (partner_id)
     *  - normalized nr_doc match (strips dashes, spaces, underscores, dots, slashes; uppercase)
     *
     * Returns null if there's no confident match.
     */
    public function find(EInvoice $eInvoice): ?Invoice
    {
        $normalized = $this->normalize($eInvoice->nr_doc_xml);

        if ($normalized === '' || $eInvoice->partner_id === null) {
            return null;
        }

        return Invoice::query()
            ->where('company_id', $eInvoice->company_id)
            ->where('partner_id', $eInvoice->partner_id)
            ->whereIn('tip_doc', SyncService::FURNIZOR_DOC_TYPES)
            ->whereRaw(
                "UPPER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(nr_doc, '-', ''), ' ', ''), '_', ''), '.', ''), '/', '')) = ?",
                [$normalized],
            )
            ->orderByDesc('data_doc')
            ->first();
    }

    public function normalize(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim($value)) ?? '');
    }
}
