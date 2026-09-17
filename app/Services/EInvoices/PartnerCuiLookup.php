<?php

namespace App\Services\EInvoices;

use Illuminate\Support\Facades\DB;

class PartnerCuiLookup
{
    /** @var array<string, int> */
    private array $exact = [];

    /** @var array<string, list<int>> */
    private array $base = [];

    /**
     * A company here has hundreds of thousands of partners, so the index is
     * built straight from the rows: hydrating them as models is what used to
     * exhaust the memory of a sync run.
     */
    public function __construct(int $companyId)
    {
        DB::table('partners')
            ->where('company_id', $companyId)
            ->whereNotNull('cui')
            ->select(['id', 'cui'])
            ->orderBy('cui')
            ->orderBy('id')
            ->cursor()
            ->each(function (object $row): void {
                $id = (int) $row->id;
                $cui = (string) $row->cui;

                $exactKey = self::normalize($cui);
                if ($exactKey !== '') {
                    $this->exact[$exactKey] ??= $id;
                }

                $baseKey = self::normalizeBase($cui);
                if ($baseKey !== '') {
                    $this->base[$baseKey][] = $id;
                }
            });
    }

    public function find(?string $cui): ?int
    {
        if ($cui === null) {
            return null;
        }

        $key = self::normalize($cui);
        if ($key === '') {
            return null;
        }

        return $this->exact[$key] ?? $this->base[$key][0] ?? null;
    }

    /**
     * Return every partner_id whose CUI matches the given value,
     * counting both exact matches and SeniorERP suffix variants.
     *
     * @return list<int>
     */
    public function findAll(?string $cui): array
    {
        if ($cui === null) {
            return [];
        }

        $key = self::normalize($cui);
        if ($key === '') {
            return [];
        }

        $ids = [];
        if (isset($this->exact[$key])) {
            $ids[] = $this->exact[$key];
        }
        foreach ($this->base[$key] ?? [] as $id) {
            if (! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public static function normalize(string $cui): string
    {
        $cleaned = preg_replace('/\s+/', '', trim($cui)) ?? '';
        $upper = strtoupper($cleaned);

        return str_starts_with($upper, 'RO') ? substr($upper, 2) : $upper;
    }

    /**
     * Normalize and strip the SeniorERP duplicate-CUI suffix
     * (a single trailing lowercase letter, e.g. "RO1243237a" -> "1243237").
     */
    public static function normalizeBase(string $cui): string
    {
        $stripped = preg_replace('/[a-z]$/', '', trim($cui)) ?? trim($cui);

        return self::normalize($stripped);
    }
}
