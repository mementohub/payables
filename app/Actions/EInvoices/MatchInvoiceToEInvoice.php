<?php

namespace App\Actions\EInvoices;

use App\Models\EInvoice;
use App\Models\Invoice;
use App\Models\Partner;
use App\Services\EInvoices\PartnerCuiLookup;
use App\Services\SyncService;

class MatchInvoiceToEInvoice
{
    /** @var array<int, PartnerCuiLookup> */
    private array $cuiLookupCache = [];

    /** @var array<int, list<array{id: int, normName: string}>> */
    private array $partnersCache = [];

    /**
     * Find the best Invoice candidate for a given EInvoice using:
     *  - same company
     *  - supplier identified by EInvoice::supplier_cui (matched against partners.cui,
     *    accepting the SeniorERP duplicate-CUI suffix convention; falls back to
     *    same-name partner duplicates that have a NULL/different cui)
     *  - tip_doc among the FURNIZOR set
     *  - normalized nr_doc match (strips dashes, spaces, underscores, dots, slashes; uppercase)
     *
     * Returns null if there's no confident match.
     */
    public function find(EInvoice $eInvoice): ?Invoice
    {
        $normalized = $this->normalize($eInvoice->nr_doc_xml);

        if ($normalized === '' || $eInvoice->supplier_cui === null) {
            return null;
        }

        $partnerIds = $this->resolvePartnerIds($eInvoice->company_id, $eInvoice->supplier_cui);

        if ($partnerIds === []) {
            return null;
        }

        return Invoice::query()
            ->where('company_id', $eInvoice->company_id)
            ->whereIn('partner_id', $partnerIds)
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

    /**
     * @return list<int>
     */
    private function resolvePartnerIds(int $companyId, string $supplierCui): array
    {
        $lookup = $this->cuiLookupCache[$companyId] ??= new PartnerCuiLookup($companyId);
        $cuiIds = $lookup->findAll($supplierCui);

        if ($cuiIds === []) {
            return [];
        }

        $partners = $this->partnersCache[$companyId] ??= Partner::query()
            ->where('company_id', $companyId)
            ->get(['id', 'name'])
            ->map(fn ($p) => ['id' => $p->id, 'normName' => $this->normalizeName($p->name)])
            ->all();

        $names = [];
        foreach ($partners as $p) {
            if (in_array($p['id'], $cuiIds, true) && $p['normName'] !== '') {
                $names[$p['normName']] = true;
            }
        }

        $ids = $cuiIds;
        foreach ($partners as $p) {
            if (isset($names[$p['normName']]) && ! in_array($p['id'], $ids, true)) {
                $ids[] = $p['id'];
            }
        }

        return $ids;
    }

    private function normalizeName(?string $name): string
    {
        if ($name === null) {
            return '';
        }

        return strtolower(preg_replace('/[^a-z0-9]/i', '', trim($name)) ?? '');
    }
}
