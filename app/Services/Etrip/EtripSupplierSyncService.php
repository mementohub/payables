<?php

namespace App\Services\Etrip;

use App\Models\Company;
use App\Models\EtripSupplier;
use App\Models\Partner;
use App\Services\EInvoices\PartnerCuiLookup;

/**
 * Mirrors the suppliers of a company's eTrip database locally and ties each
 * one to the ERP partner with the same VAT number or, failing that, the same
 * name. Links made by hand are never overwritten.
 */
class EtripSupplierSyncService
{
    public function __construct(private EtripReader $reader) {}

    /**
     * @return array{synced: int, matched_cui: int, matched_name: int, unmatched: int}
     */
    public function sync(Company $company): array
    {
        $now = now();
        $rows = $this->reader->suppliers($company);

        foreach (array_chunk($rows, 500) as $chunk) {
            EtripSupplier::upsert(
                array_map(fn (array $row) => [
                    'company_id' => $company->id,
                    'code' => mb_substr($row['code'], 0, 50),
                    'name' => mb_substr($row['name'], 0, 120),
                    'vat_no' => $row['vat_no'] !== null ? mb_substr($row['vat_no'], 0, 40) : null,
                    'company_no' => $row['company_no'] !== null ? mb_substr($row['company_no'], 0, 40) : null,
                    'currency' => $row['currency'] !== null ? mb_substr($row['currency'], 0, 5) : null,
                    'country' => $row['country'] !== null ? mb_substr($row['country'], 0, 80) : null,
                    'is_active' => $row['active'],
                    'synced_at' => $now,
                ], $chunk),
                ['company_id', 'code'],
                ['name', 'vat_no', 'company_no', 'currency', 'country', 'is_active', 'synced_at'],
            );
        }

        return [
            'synced' => count($rows),
            ...$this->match($company),
            'unmatched' => EtripSupplier::query()->where('company_id', $company->id)->unmatched()->count(),
        ];
    }

    /**
     * Link unmatched eTrip suppliers to partners by VAT number, then by name.
     *
     * @return array{matched_cui: int, matched_name: int}
     */
    public function match(Company $company): array
    {
        $taken = EtripSupplier::query()
            ->where('company_id', $company->id)
            ->whereNotNull('partner_id')
            ->pluck('partner_id')
            ->flip();

        $byCui = [];
        $byName = [];

        Partner::query()
            ->where('company_id', $company->id)
            ->furnizori()
            ->get(['id', 'name', 'cui'])
            ->reject(fn (Partner $partner) => $taken->has($partner->id))
            ->each(function (Partner $partner) use (&$byCui, &$byName) {
                $cui = $partner->cui ? PartnerCuiLookup::normalize($partner->cui) : '';

                if ($cui !== '') {
                    $byCui[$cui] ??= $partner->id;
                }

                $byName[$this->nameKey($partner->name)] ??= $partner->id;
            });

        $matched = ['matched_cui' => 0, 'matched_name' => 0];

        EtripSupplier::query()
            ->where('company_id', $company->id)
            ->unmatched()
            ->orderBy('id')
            ->each(function (EtripSupplier $supplier) use (&$byCui, &$byName, &$matched) {
                $cui = $supplier->vat_no ? PartnerCuiLookup::normalize($supplier->vat_no) : '';

                if ($cui !== '' && isset($byCui[$cui])) {
                    $this->link($supplier, $byCui[$cui], EtripSupplier::MATCH_CUI, $byCui, $byName);
                    $matched['matched_cui']++;

                    return;
                }

                $name = $this->nameKey($supplier->name);

                if (isset($byName[$name])) {
                    $this->link($supplier, $byName[$name], EtripSupplier::MATCH_NAME, $byCui, $byName);
                    $matched['matched_name']++;
                }
            });

        return $matched;
    }

    /**
     * @param  array<string, int>  $byCui
     * @param  array<string, int>  $byName
     */
    private function link(EtripSupplier $supplier, int $partnerId, string $source, array &$byCui, array &$byName): void
    {
        $supplier->update(['partner_id' => $partnerId, 'match_source' => $source]);

        $byCui = array_filter($byCui, fn (int $id) => $id !== $partnerId);
        $byName = array_filter($byName, fn (int $id) => $id !== $partnerId);
    }

    private function nameKey(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? $name));
    }
}
