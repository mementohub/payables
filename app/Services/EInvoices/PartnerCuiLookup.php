<?php

namespace App\Services\EInvoices;

use App\Models\Partner;

class PartnerCuiLookup
{
    /** @var array<string, int> */
    private array $exact = [];

    /** @var array<string, list<int>> */
    private array $base = [];

    public function __construct(int $companyId)
    {
        $partners = Partner::query()
            ->where('company_id', $companyId)
            ->whereNotNull('cui')
            ->orderBy('cui')
            ->get(['id', 'cui']);

        foreach ($partners as $partner) {
            $exactKey = self::normalize($partner->cui);
            if ($exactKey !== '') {
                $this->exact[$exactKey] ??= $partner->id;
            }

            $baseKey = self::normalizeBase($partner->cui);
            if ($baseKey !== '') {
                $this->base[$baseKey][] = $partner->id;
            }
        }
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
