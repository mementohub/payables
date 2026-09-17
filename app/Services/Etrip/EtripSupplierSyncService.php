<?php

namespace App\Services\Etrip;

use App\Models\Company;
use App\Models\EtripSupplier;
use App\Models\Partner;
use App\Services\EInvoices\PartnerCuiLookup;
use App\Services\Omc\OmcReader;
use RuntimeException;

/**
 * Mirrors the suppliers of an eTrip base locally and ties each one to the
 * ERP partner with the same VAT number or, failing that, the same name.
 * Links made by hand are never overwritten.
 */
class EtripSupplierSyncService
{
    public function __construct(private EtripReader $reader, private OmcReader $omc) {}

    /**
     * The company whose partners the eTrip suppliers are matched to: the
     * one whose books are read live from OMC.
     */
    public function partnersCompany(): Company
    {
        return $this->omc->company()
            ?? Company::query()->orderBy('id')->first()
            ?? throw new RuntimeException('Nicio companie în aplicație: furnizorii eTrip nu au parteneri de potrivit.');
    }

    /**
     * @return array{synced: int, matched_cui: int, matched_name: int, unmatched: int}
     */
    public function sync(string $connection, ?Company $company = null): array
    {
        $company ??= $this->partnersCompany();
        $now = now();
        $rows = $this->reader->suppliers($connection);

        foreach (array_chunk($rows, 500) as $chunk) {
            EtripSupplier::upsert(
                array_map(fn (array $row) => [
                    'etrip_connection' => $connection,
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
                ['etrip_connection', 'code'],
                ['company_id', 'name', 'vat_no', 'company_no', 'currency', 'country', 'is_active', 'synced_at'],
            );
        }

        return [
            'synced' => count($rows),
            ...$this->match($connection, $company),
            'unmatched' => EtripSupplier::query()->forConnection($connection)->unmatched()->count(),
        ];
    }

    /**
     * Mirror a single supplier read live from eTrip, keeping any partner link it
     * already has. Null when eTrip does not know the code.
     */
    public function remember(string $connection, string $code, ?Company $company = null): ?EtripSupplier
    {
        $row = $this->reader->supplier($connection, $code);

        if ($row === null) {
            return null;
        }

        return EtripSupplier::query()->updateOrCreate(
            ['etrip_connection' => $connection, 'code' => mb_substr($row['code'], 0, 50)],
            [
                'company_id' => ($company ?? $this->partnersCompany())->id,
                'name' => mb_substr($row['name'], 0, 120),
                'vat_no' => $row['vat_no'] !== null ? mb_substr($row['vat_no'], 0, 40) : null,
                'company_no' => $row['company_no'] !== null ? mb_substr($row['company_no'], 0, 40) : null,
                'currency' => $row['currency'] !== null ? mb_substr($row['currency'], 0, 5) : null,
                'country' => $row['country'] !== null ? mb_substr($row['country'], 0, 80) : null,
                'is_active' => $row['active'],
                'synced_at' => now(),
            ],
        );
    }

    /**
     * Link unmatched eTrip suppliers to partners by VAT number, then by name.
     *
     * @return array{matched_cui: int, matched_name: int}
     */
    public function match(string $connection, Company $company): array
    {
        $taken = EtripSupplier::query()
            ->forConnection($connection)
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
            ->forConnection($connection)
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
