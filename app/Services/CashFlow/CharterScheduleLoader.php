<?php

namespace App\Services\CashFlow;

use App\Models\CashFlowSetting;
use App\Models\CharterContract;

/**
 * The charter programme handed over with the cash-flow engine
 * (database/data/charter_flights.csv): the S26 annex AA30/ADD7 of the
 * Memento Air contract CTR 317/11.11.2025 and the W26/27 draft annexes,
 * with the payment terms the engine assumed. Loaded once, by the first
 * upgrade that finds no charter contract, so the charter lines of the
 * WCFR report do not wait for a manual import; the contracts and their
 * rotations can be edited or replaced afterwards in the Charter tab.
 */
class CharterScheduleLoader
{
    public const SETTING = 'charter_schedule';

    public const VERSION = 'AA30/ADD7 11.09.2026 + W26/27 draft';

    /** @var list<array<string, mixed>> */
    private const CONTRACTS = [
        [
            'name' => 'CTR 317/11.11.2025 Memento Air – S26',
            'season' => 'S26',
            'status' => CharterContract::STATUS_SIGNED,
            'operator' => 'Memento Air',
            'currency' => 'EUR',
            'days_before_flight' => 10,
            'deposit_percent' => null,
            'deposit_amount' => null,
            'deposit_due_date' => null,
            'deposit_paid' => true,
            'contract_value' => null,
            'notes' => 'Anexa AA30/ADD7 din 11.09.2026 (pachetul de predare). Art. 3.2 b: fiecare rotație se plătește prin OP cu 10 zile înainte de operare; art. 3.2 c: taxele de aeroport se reconciliază în prima săptămână a lunii următoare. Depozit S26: 0.',
        ],
        [
            'name' => 'W26/27 Memento Air – draft',
            'season' => 'W26-27',
            'status' => CharterContract::STATUS_DRAFT,
            'operator' => 'Memento Air',
            'currency' => 'EUR',
            'days_before_flight' => 10,
            'deposit_percent' => 50,
            'deposit_amount' => null,
            'deposit_due_date' => '2026-10-05',
            'deposit_paid' => false,
            'contract_value' => 7257134,
            'notes' => 'Anexele draft CTR1_31DEC27 + CTR1_01JAN27 (DRAFTS W26_27.xlsx). Termeni presupuși ca la CTR 317; depozit 50% din valoarea contractului în prima săptămână din octombrie, după precedentul CTR 281 (W25/26). De înlocuit cu anexa semnată.',
        ],
    ];

    public function __construct(private CharterFlightImporter $importer) {}

    public function path(): string
    {
        return database_path('data/charter_flights.csv');
    }

    public function loaded(): bool
    {
        return CashFlowSetting::query()->where('key', self::SETTING)->exists();
    }

    /**
     * Create the two contracts and import their rotations. Nothing happens
     * when the programme was loaded before or contracts already exist,
     * unless forced; then the contracts of these seasons are reused and
     * their rotations replaced.
     *
     * @return array{contracts: list<string>, imported: int, skipped: int}|null null when nothing was loaded
     */
    public function load(bool $force = false): ?array
    {
        if (! $force && ($this->loaded() || CharterContract::query()->exists())) {
            return null;
        }

        $contracts = [];

        foreach (self::CONTRACTS as $attributes) {
            $contracts[$attributes['season']] = CharterContract::query()->firstOrCreate(['season' => $attributes['season']], $attributes);
        }

        $result = $this->importer->import($this->path(), 'charter_flights.csv', $contracts['S26'], replace: true);

        CashFlowSetting::query()->updateOrCreate(['key' => self::SETTING], ['value' => [
            'version' => self::VERSION,
            'loaded_at' => now()->toIso8601String(),
            'imported' => $result['imported'],
        ]]);

        return ['contracts' => $result['contracts'], 'imported' => $result['imported'], 'skipped' => $result['skipped']];
    }
}
