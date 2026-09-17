<?php

namespace App\Services\CashFlow;

use App\Models\CashFlowSetting;
use App\Models\User;

/**
 * The assumptions of the report: defaults from config/cashflow.php, overridden
 * by what was saved from the report page.
 */
class CashFlowParameters
{
    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        $opex = [];

        foreach ((array) config('cashflow.opex') as $category) {
            $opex[$category['key']] = $category['accounts'] === [] ? (float) $category['default'] : null;
        }

        return [
            'etrip_connections' => array_values((array) config('cashflow.etrip.connections', ['etrip_chr'])),
            'fx' => ['mode' => 'auto', 'EUR' => 5.085, 'USD' => 4.35],
            'thresholds' => ['minimum' => 3000000, 'comfort' => 6000000],
            'overdue' => ['recent_days' => 60, 'recent_pct' => 80, 'recent_weeks' => 4, 'old_pct' => 0],
            'payables' => [
                'days_before_checkin' => 7,
                'prepaid_pct' => 0,
                'ticket_days' => 7,
                'supplier_balance' => null,
                'supplier_balance_weeks' => 2,
            ],
            'scenario' => [
                'enabled' => true,
                'factor' => 0.95,
                'charter_factor' => 1.0,
                'charter_base_season' => null,
                'charter_target_season' => null,
            ],
            'opex' => $opex,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function load(): array
    {
        $saved = CashFlowSetting::query()->where('key', CashFlowSetting::PARAMETERS)->value('value');

        return array_replace_recursive(self::defaults(), is_array($saved) ? $saved : []);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function save(array $values, ?User $by = null): array
    {
        $merged = array_replace_recursive($this->load(), $values);

        // Lists are replaced, not merged position by position.
        if (array_key_exists('etrip_connections', $values)) {
            $merged['etrip_connections'] = array_values((array) $values['etrip_connections']);
        }

        // A null override means "back to automatic", which array_replace_recursive would drop.
        foreach (['opex', 'payables', 'scenario'] as $section) {
            foreach ((array) ($values[$section] ?? []) as $key => $value) {
                if ($value === null) {
                    $merged[$section][$key] = null;
                }
            }
        }

        CashFlowSetting::query()->updateOrCreate(
            ['key' => CashFlowSetting::PARAMETERS],
            ['value' => $merged, 'updated_by_id' => $by?->id],
        );

        return $merged;
    }

    /**
     * The OPEX categories with their rule and the value in force.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, float>  $computed  monthly averages computed from OMC, by key
     * @return list<array{key: string, label: string, rule: array<string, mixed>, accounts: list<string>, monthly: float, override: ?float, computed: ?float, source: string}>
     */
    public static function opexCatalogue(array $parameters, array $computed = []): array
    {
        $rows = [];

        foreach ((array) config('cashflow.opex') as $category) {
            $override = $parameters['opex'][$category['key']] ?? null;
            $auto = $computed[$category['key']] ?? null;

            $rows[] = [
                'key' => $category['key'],
                'label' => $category['label'],
                'rule' => $category['rule'],
                'accounts' => array_values($category['accounts']),
                'monthly' => $override !== null ? (float) $override : ($auto ?? (float) $category['default']),
                'override' => $override !== null ? (float) $override : null,
                'computed' => $auto,
                'source' => $category['source'],
            ];
        }

        return $rows;
    }
}
