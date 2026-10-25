<?php

namespace App\Services\Forecast;

use App\Models\Plan;
use App\Models\PlanLine;
use App\Services\Reports\PnlReportService;
use Illuminate\Support\Collection;

/**
 * Din pârghii iese raportul: venit, marjă, cheltuieli, EBITDA și profit,
 * lună de lună, alături de anul din care s-a pornit.
 *
 * Nu se inventează nimic aici: fiecare cifră e o înmulțire pe care o poate
 * reface omul cu creionul — pasageri × venit pe pasager, locuri × grad de
 * ocupare × venit pe pasager — iar ce nu se poate plănui din pârghii
 * (amortizarea, dobânda, impozitul) se poartă din anul de bază, de unde se
 * poate schimba cu mâna.
 */
class PlanEngine
{
    public function __construct(private PnlReportService $pnl) {}

    /**
     * @return array<string, mixed>
     */
    public function compute(Plan $plan): array
    {
        $lines = $plan->lines()->get();

        $months = [];

        foreach (range(1, 12) as $month) {
            $months[$month] = ['net' => 0.0, 'margin' => 0.0, 'opex' => 0.0, 'below' => 0.0, 'pax' => 0.0, 'seats' => 0.0];
        }

        foreach ($lines as $line) {
            $month = (int) $line->month;

            if (! isset($months[$month])) {
                continue;
            }

            match ($line->kind) {
                PlanLine::CATEGORY, PlanLine::CHARTER => $months[$month] = [
                    ...$months[$month],
                    'net' => $months[$month]['net'] + $line->net(),
                    'margin' => $months[$month]['margin'] + $line->margin(),
                    'pax' => $months[$month]['pax'] + $line->pax(),
                    'seats' => $months[$month]['seats'] + (float) ($line->capacity ?? 0),
                ],
                PlanLine::OPEX => $months[$month]['opex'] += (float) $line->amount,
                PlanLine::BELOW => $months[$month]['below'] += (float) $line->amount,
                default => null,
            };
        }

        foreach ($months as $month => $cell) {
            $months[$month]['ebitda'] = round($cell['margin'] - $cell['opex'], 2);
            $months[$month]['net_profit'] = round($cell['margin'] - $cell['opex'] - $cell['below'], 2);
            $months[$month] = array_map(fn ($value) => round((float) $value, 2), $months[$month]);
        }

        return [
            'months' => $months,
            'totals' => $this->totals($months),
            'categories' => $this->byKey($lines->whereIn('kind', [PlanLine::CATEGORY, PlanLine::CHARTER])),
            'charter' => $this->byKey($lines->where('kind', PlanLine::CHARTER)),
            'opex' => $this->byKey($lines->where('kind', PlanLine::OPEX)),
            'below' => $this->byKey($lines->where('kind', PlanLine::BELOW)),
            'base' => $this->base($plan),
        ];
    }

    /**
     * @param  array<int, array<string, float>>  $months
     * @return array<string, float>
     */
    private function totals(array $months): array
    {
        $totals = ['net' => 0.0, 'margin' => 0.0, 'opex' => 0.0, 'below' => 0.0, 'ebitda' => 0.0, 'net_profit' => 0.0, 'pax' => 0.0, 'seats' => 0.0];

        foreach ($months as $cell) {
            foreach ($totals as $key => $value) {
                $totals[$key] = round($value + (float) ($cell[$key] ?? 0), 2);
            }
        }

        return $totals;
    }

    /**
     * Rândurile strânse pe cheie, cu lunile sub ele.
     *
     * @param  Collection<int, PlanLine>  $lines
     * @return list<array<string, mixed>>
     */
    private function byKey(Collection $lines): array
    {
        return $lines
            ->groupBy('key')
            ->map(function (Collection $group, string $key) {
                $first = $group->first();
                $months = [];

                foreach ($group as $line) {
                    $months[(int) $line->month] = [
                        'id' => $line->id,
                        'volume' => $line->kind === PlanLine::CHARTER ? round($line->pax(), 2) : (float) $line->volume,
                        'capacity' => (float) $line->capacity,
                        'load' => (float) $line->load,
                        'unit_net' => (float) $line->unit_net,
                        'unit_margin' => (float) $line->unit_margin,
                        'amount' => $line->kind === PlanLine::OPEX || $line->kind === PlanLine::BELOW
                            ? (float) $line->amount
                            : $line->net(),
                        'margin' => $line->margin(),
                        'manual' => (bool) $line->manual,
                    ];
                }

                ksort($months);

                return [
                    'key' => $key,
                    'kind' => $first->kind,
                    'label' => $first->label ?: $key,
                    'months' => $months,
                    'pax' => round($group->sum(fn (PlanLine $line) => $line->pax()), 2),
                    'seats' => round((float) $group->sum('capacity'), 2),
                    'net' => round($group->sum(fn (PlanLine $line) => $line->net()), 2),
                    'margin' => round($group->sum(fn (PlanLine $line) => $line->margin()), 2),
                    'amount' => round((float) $group->sum('amount'), 2),
                    'manual' => $group->contains('manual', true),
                ];
            })
            ->sortByDesc(fn (array $row) => abs($row['kind'] === PlanLine::OPEX || $row['kind'] === PlanLine::BELOW ? $row['amount'] : $row['net']))
            ->values()
            ->all();
    }

    /**
     * Anul de bază, din raportul construit: la el se uită omul când judecă
     * dacă planul e curajos sau fricos.
     *
     * @return array<string, mixed>
     */
    private function base(Plan $plan): array
    {
        $report = $this->pnl->cached($plan->company, $plan->base_year);

        if ($report === null || isset($report['error'])) {
            return ['year' => $plan->base_year, 'built' => false];
        }

        $slice = $this->pnl->slice($report, range(1, 12), 'year', PnlReportService::BASIS_RAS, PnlReportService::MODE_OPERATIONAL);
        $margin = (float) $slice['revenue']['total']['margin'];
        $opex = (float) $slice['totals']['total'];
        $below = array_sum(array_map(
            fn (string $bucket) => (float) ($slice['below'][$bucket]['total'] ?? 0),
            ['amortizare', 'financiar', 'impozit'],
        ));

        return [
            'year' => $plan->base_year,
            'built' => true,
            'net' => round((float) $slice['revenue']['total']['net'], 2),
            'margin' => round($margin, 2),
            'opex' => round($opex, 2),
            'below' => round($below, 2),
            'ebitda' => round($margin - $opex, 2),
            'net_profit' => round($margin - $opex - $below, 2),
        ];
    }
}
