<?php

namespace App\Actions\EInvoices;

use App\Models\EInvoice;
use App\Models\Invoice;
use App\Services\EInvoices\PartnerCuiLookup;
use App\Services\SyncService;
use Illuminate\Support\Facades\DB;

class MatchInvoiceToEInvoice
{
    /** @var array<int, PartnerCuiLookup> */
    private array $cuiLookupCache = [];

    /** @var array<int, array<string, list<int>>> */
    private array $duplicateNamesCache = [];

    /** @var array<string, list<int>> */
    private array $resolvedCache = [];

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
        // The same supplier shows up on e-invoice after e-invoice, so the
        // answer is worked out once per CUI and not once per document.
        $memo = $companyId.'|'.PartnerCuiLookup::normalize($supplierCui);

        if (isset($this->resolvedCache[$memo])) {
            return $this->resolvedCache[$memo];
        }

        $lookup = $this->cuiLookupCache[$companyId] ??= new PartnerCuiLookup($companyId);
        $ids = $lookup->findAll($supplierCui);

        if ($ids === []) {
            return $this->resolvedCache[$memo] = [];
        }

        $duplicates = $this->duplicateNames($companyId);

        foreach ($this->nameKeys($ids) as $key) {
            foreach ($duplicates[$key] ?? [] as $id) {
                if (! in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        }

        return $this->resolvedCache[$memo] = $ids;
    }

    /**
     * The normalized names of the given partners.
     *
     * @param  list<int>  $ids
     * @return list<string>
     */
    private function nameKeys(array $ids): array
    {
        $keys = [];

        foreach (DB::table('partners')->whereIn('id', $ids)->pluck('name') as $name) {
            $key = $this->normalizeName($name);

            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * Partners of a company that share a name with another partner once the
     * punctuation is taken out — SeniorERP keeps the same company under
     * "SC ALFA SRL" and "S.C. ALFA S.R.L.", one of them without a CUI.
     *
     * A company here has hundreds of thousands of partners, so the rows are
     * read as rows and only the names that actually repeat are kept: a name
     * held by a single partner adds nothing to a CUI match, and keeping the
     * other hundreds of thousands is what used to exhaust a sync run.
     *
     * @return array<string, list<int>>
     */
    private function duplicateNames(int $companyId): array
    {
        if (isset($this->duplicateNamesCache[$companyId])) {
            return $this->duplicateNamesCache[$companyId];
        }

        /** @var array<string, int> $seen */
        $seen = [];
        /** @var array<string, list<int>> $duplicates */
        $duplicates = [];

        DB::table('partners')
            ->where('company_id', $companyId)
            ->select(['id', 'name'])
            ->orderBy('id')
            ->cursor()
            ->each(function (object $row) use (&$seen, &$duplicates): void {
                $key = $this->normalizeName($row->name);

                if ($key === '') {
                    return;
                }

                $id = (int) $row->id;

                if (isset($duplicates[$key])) {
                    $duplicates[$key][] = $id;
                } elseif (isset($seen[$key])) {
                    $duplicates[$key] = [$seen[$key], $id];
                } else {
                    $seen[$key] = $id;
                }
            });

        return $this->duplicateNamesCache[$companyId] = $duplicates;
    }

    private function normalizeName(?string $name): string
    {
        if ($name === null) {
            return '';
        }

        return strtolower(preg_replace('/[^a-z0-9]/i', '', trim($name)) ?? '');
    }
}
