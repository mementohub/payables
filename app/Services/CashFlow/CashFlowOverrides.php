<?php

namespace App\Services\CashFlow;

use App\Models\CashFlowOverride;

/**
 * The values set by hand on the report's receipts (B), product payments (C)
 * and OPEX (D). The snapshot keeps what the automation worked out; these
 * are laid over it when the report is read, so a nightly rebuild never
 * undoes a decision and a reset brings the automated value straight back.
 */
class CashFlowOverrides
{
    /** @var list<string> */
    public const SECTIONS = ['B', 'C', 'D'];

    /**
     * What an override is stored against: the OPEX category for D lines, whose
     * codes follow the catalogue order, the line code for everything else.
     *
     * @param  array{code: string, key?: ?string, section: string}  $line
     */
    public static function lineId(array $line): string
    {
        return $line['section'] === 'D' && ! empty($line['key']) ? (string) $line['key'] : $line['code'];
    }

    /**
     * Every override from the given week on, with who set it.
     *
     * @return list<array{line: string, week: string, amount: float, note: ?string, updated_by: ?string, updated_at: ?string}>
     */
    public function from(string $week): array
    {
        return CashFlowOverride::query()
            ->with('updatedBy:id,name')
            ->where('week', '>=', $week)
            ->orderBy('week')
            ->orderBy('line')
            ->get()
            ->map(fn (CashFlowOverride $override) => [
                'line' => $override->line,
                'week' => substr((string) $override->week, 0, 10),
                'amount' => $override->amount,
                'note' => $override->note,
                'updated_by' => $override->updatedBy?->name,
                'updated_at' => $override->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * The payload with the overrides in place and every total, the net flow
     * and the balance chain worked out again from them.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<array{line: string, week: string, amount: float}>  $overrides
     * @return array<string, mixed>
     */
    public function apply(array $payload, array $overrides): array
    {
        $weeks = array_values((array) ($payload['weeks'] ?? []));
        $lines = array_values((array) ($payload['lines'] ?? []));

        if ($overrides === [] || $weeks === [] || $lines === []) {
            return $payload;
        }

        $column = array_flip($weeks);
        $byLine = [];

        foreach ($overrides as $override) {
            if (isset($column[$override['week']])) {
                $byLine[$override['line']][$column[$override['week']]] = (float) $override['amount'];
            }
        }

        foreach ($lines as &$line) {
            if (($line['kind'] ?? null) !== 'value' || ! in_array($line['section'] ?? null, self::SECTIONS, true)) {
                continue;
            }

            foreach ($byLine[self::lineId($line)] ?? [] as $index => $amount) {
                $line['values'][$index] = $amount;
            }
        }
        unset($line);

        $count = count($weeks);
        $scenarioOn = (bool) ($payload['params']['scenario']['enabled'] ?? true);
        $sum = function (callable $include) use ($lines, $count): array {
            $result = array_fill(0, $count, 0.0);

            foreach ($lines as $line) {
                if ($include($line)) {
                    foreach (array_slice((array) ($line['values'] ?? []), 0, $count) as $i => $value) {
                        $result[$i] += (float) $value;
                    }
                }
            }

            return array_map(fn (float $v) => round($v, 2), $result);
        };

        $totals = [];

        foreach (self::SECTIONS as $section) {
            $totals[$section] = $sum(fn (array $line) => ($line['section'] ?? null) === $section && ($line['kind'] ?? null) === 'value' && ($scenarioOn || empty($line['scenario'])));
        }

        $subtotals = [];

        foreach ($lines as $line) {
            if (($line['kind'] ?? null) === 'subtotal') {
                $subtotals[$line['code']] = $sum(fn (array $child) => ($child['parent'] ?? null) === $line['code'] && ($child['kind'] ?? null) === 'value');
            }
        }

        $minimum = (float) ($payload['params']['thresholds']['minimum'] ?? 0);
        $comfort = (float) ($payload['params']['thresholds']['comfort'] ?? 0);
        $opening = collect($lines)->firstWhere('code', 'A');
        $balance = (float) ($opening['values'][0] ?? 0);
        $open = $net = $closing = [];

        for ($i = 0; $i < $count; $i++) {
            $open[$i] = round($balance, 2);
            $net[$i] = round($totals['B'][$i] - $totals['C'][$i] - $totals['D'][$i], 2);
            $balance += $net[$i];
            $closing[$i] = round($balance, 2);
        }

        $replace = [
            ...$subtotals,
            'A' => $open,
            'B' => $totals['B'],
            'C' => $totals['C'],
            'D' => $totals['D'],
            'E1' => $net,
            'E2' => $closing,
            'E4' => array_map(fn (float $v) => round($v - $minimum, 2), $closing),
            'E5' => array_map(fn (float $v) => $v < $minimum ? 'DEFICIT' : ($v < $comfort ? 'ATENȚIE' : 'OK'), $closing),
        ];

        foreach ($lines as &$line) {
            if (isset($replace[$line['code']])) {
                $line['values'] = $replace[$line['code']];
            }

            if (in_array($line['kind'] ?? null, ['value', 'subtotal', 'total'], true)) {
                $line['total'] = round(array_sum(array_map('floatval', $line['values'])), 2);
            }
        }
        unset($line);

        $payload['lines'] = $lines;

        return $payload;
    }
}
