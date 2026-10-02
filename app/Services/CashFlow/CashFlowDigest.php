<?php

namespace App\Services\CashFlow;

use App\Models\CashFlowSnapshot;
use Carbon\CarbonImmutable;

/**
 * Raportul de trezorerie, strâns cât încape într-un mail.
 *
 * Nu recalculează nimic: ia snapshotul construit noaptea și scoate din el
 * exact ce se citește dimineața pe telefon — câți bani sunt acum, ce intră
 * și ce iese în săptămânile apropiate și unde ajunge soldul. Restul rămâne
 * în fișierul atașat.
 */
class CashFlowDigest
{
    /**
     * @return array<string, mixed>
     */
    public function for(CashFlowSnapshot $snapshot, int $weeks = 8): array
    {
        $payload = $snapshot->payload ?? [];
        $opening = $payload['opening'] ?? [];
        $lines = collect($payload['lines'] ?? [])->keyBy(fn (array $line) => (string) ($line['code'] ?? ''));
        $allWeeks = array_map('strval', $payload['weeks'] ?? []);
        $horizon = array_slice($allWeeks, 0, max(1, $weeks), true);

        $value = fn (string $code, int $index) => (float) ($lines[$code]['values'][$index] ?? 0.0);

        $rows = [];

        foreach ($horizon as $index => $week) {
            $in = $value('B', $index);
            $out = $value('C', $index) + $value('D', $index);

            $rows[] = [
                'index' => $index + 1,
                'week' => CarbonImmutable::parse($week)->format('d.m.Y'),
                'in' => $in,
                'out' => $out,
                'net' => $value('E1', $index),
                'closing' => $value('E2', $index),
            ];
        }

        $kpis = $payload['kpis'] ?? [];
        $minimum = $kpis['min_closing'] ?? null;

        return [
            'built_at' => $snapshot->built_at,
            'status' => $snapshot->status,
            'as_of' => $opening['as_of'] ?? null,
            'currency' => (string) ($payload['currency'] ?? 'RON'),
            // Poziția de azi, pe fiecare monedă în care chiar sunt bani.
            'position' => collect($opening['by_currency'] ?? [])
                ->filter(fn ($amount) => abs((float) $amount) >= 0.01)
                ->map(fn ($amount) => (float) $amount)
                ->all(),
            'position_total' => (float) ($opening['total'] ?? 0.0),
            'weeks' => $rows,
            'closing_13' => isset($kpis['closing_13']) ? (float) $kpis['closing_13'] : null,
            'in_13' => isset($kpis['in_13']) ? (float) $kpis['in_13'] : null,
            'out_13' => isset($kpis['out_13']) ? (float) $kpis['out_13'] : null,
            'min_closing' => $minimum !== null ? [
                'week' => CarbonImmutable::parse((string) $minimum['week'])->format('d.m.Y'),
                'value' => (float) $minimum['value'],
            ] : null,
            // Ce n-a răspuns la construire: cifrele de mai sus sunt întregi doar
            // dacă lista asta e goală.
            'problems' => collect($snapshot->sources ?? [])
                ->filter(fn ($source) => ! empty($source['error']) || ($source['status'] ?? 'ok') !== 'ok')
                ->map(fn ($source) => trim((string) ($source['label'] ?? $source['key'] ?? 'sursă')).': '
                    .trim((string) ($source['error'] ?? $source['message'] ?? 'nu a răspuns')))
                ->values()
                ->all(),
        ];
    }
}
