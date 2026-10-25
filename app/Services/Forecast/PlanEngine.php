<?php

namespace App\Services\Forecast;

use App\Models\ForecastHistory;
use App\Models\Lease;
use App\Models\Plan;
use App\Models\PlanLine;
use App\Services\Reports\Ifrs16Schedule;
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
    /**
     * @param  string  $period  an, trimestru sau cumul, ca în raport („year”, „q3”, „ytd9”, „mtd9”)
     * @param  string  $basis  statutar sau IFRS 16, ca în raport
     */
    public function compute(Plan $plan, string $period = 'year', string $basis = PnlReportService::BASIS_RAS): array
    {
        $lines = $plan->lines()->get();
        $wanted = PnlReportService::months($period);

        // Chiriile retratate ca în raport: ies din exploatare și se întorc ca
        // amortizare și dobândă, după contractele din registru.
        $leases = $basis === PnlReportService::BASIS_IFRS16 ? $this->leases($plan) : null;

        $months = [];

        foreach (range(1, 12) as $month) {
            $months[$month] = ['net' => 0.0, 'margin' => 0.0, 'opex' => 0.0, 'below' => 0.0, 'pax' => 0.0, 'services' => 0.0, 'seats' => 0.0, 'empty_seats' => 0.0, 'empty_cost' => 0.0];
        }

        foreach ($lines as $line) {
            $month = (int) $line->month;

            if (! isset($months[$month]) || ! in_array($month, $wanted, true)) {
                continue;
            }

            match ($line->kind) {
                // Pasagerii și serviciile se numără în coloane diferite: un
                // bilet de avion vândut unei firme nu e un pasager plecat.
                PlanLine::CATEGORY, PlanLine::CHARTER => $months[$month] = [
                    ...$months[$month],
                    'net' => $months[$month]['net'] + $line->net(),
                    'margin' => $months[$month]['margin'] + $line->margin(),
                    'pax' => $months[$month]['pax'] + ($line->unit === PlanLine::SERVICE ? 0 : $line->pax()),
                    'services' => $months[$month]['services'] + ($line->unit === PlanLine::SERVICE ? $line->pax() : 0),
                    'seats' => $months[$month]['seats'] + (float) ($line->capacity ?? 0),
                    'empty_seats' => $months[$month]['empty_seats'] + $line->emptySeats(),
                    'empty_cost' => $months[$month]['empty_cost'] + $line->emptyCost(),
                ],
                PlanLine::OPEX => $months[$month]['opex'] += (float) $line->amount,
                PlanLine::BELOW => $months[$month]['below'] += (float) $line->amount,
                default => null,
            };
        }

        // Chiria iese din cheltuieli, amortizarea și dobânda intră sub EBITDA.
        if ($leases !== null) {
            foreach ($months as $month => $cell) {
                // Numai lunile cerute: altfel o lună singură ar scădea chiria
                // întregului an din cheltuielile ei.
                if (! in_array($month, $wanted, true)) {
                    continue;
                }

                $months[$month]['opex'] = round($cell['opex'] - (float) ($leases['rent'][$month] ?? 0), 2);
                $months[$month]['below'] = round($cell['below'] + (float) ($leases['below'][$month] ?? 0), 2);
            }
        }

        foreach ($months as $month => $cell) {
            $months[$month]['ebitda'] = round($cell['margin'] - $cell['opex'], 2);
            $months[$month]['net_profit'] = round($cell['margin'] - $cell['opex'] - $cell['below'], 2);
            $months[$month] = array_map(fn ($value) => round((float) $value, 2), $months[$month]);
        }

        return [
            'months' => $months,
            'totals' => $this->totals($months),
            // Planul în forma raportului: aceleași rânduri, în aceeași
            // ordine, ca să se poată citi unul lângă altul cu P&L-ul
            // adevărat, nu ca două tabele care seamănă.
            'statement' => $this->statement($plan, $lines->whereIn('month', $wanted), $months, $leases),
            'period' => $period,
            'basis' => $basis,
            // Același venit, citit pe canale de vânzare: amestecul fiecărei
            // categorii se ia din anul de bază, nu se inventează.
            'channels' => $this->channels($plan, $lines, $wanted),
            'leases' => $leases === null ? null : $leases['summary'],
            'categories' => $this->byKey($lines->whereIn('kind', [PlanLine::CATEGORY, PlanLine::CHARTER])),
            'charter' => $this->byKey($lines->where('kind', PlanLine::CHARTER)),
            'opex' => $this->byKey($lines->where('kind', PlanLine::OPEX)),
            'below' => $this->byKey($lines->where('kind', PlanLine::BELOW)),
            'base' => $this->base($plan),
        ];
    }

    /**
     * Planul, rând cu rând, ca în contul de profit și pierdere.
     *
     * @param  Collection<int, PlanLine>  $lines
     * @param  array<int, array<string, float>>  $months
     * @return list<array<string, mixed>>
     */
    private function statement(Plan $plan, Collection $lines, array $months, ?array $leases = null): array
    {
        $rows = [];
        $pick = fn (string $key) => array_map(fn (array $cell) => (float) ($cell[$key] ?? 0), $months);

        $rows[] = $this->row('revenue', 'Venituri nete', 'strong', $pick('net'));
        $rows[] = $this->row('margin', 'Marjă brută', 'strong', $pick('margin'));

        // Cheltuielile se strâng pe grupele raportului, cu liniile sub ele.
        $groups = [];

        $rentLines = array_map('strval', (array) config('pnl.ifrs16.lines', []));

        foreach ($lines->where('kind', PlanLine::OPEX) as $line) {
            // Pe IFRS 16, liniile de chirie nu mai stau în exploatare.
            if ($leases !== null && in_array((string) $line->key, $rentLines, true)) {
                continue;
            }

            [$group, $label] = array_pad(explode(' · ', (string) $line->label, 2), 2, '');
            $group = $group !== '' ? $group : 'Alte cheltuieli';

            $groups[$group]['months'][(int) $line->month] = ($groups[$group]['months'][(int) $line->month] ?? 0) + (float) $line->amount;
            $groups[$group]['lines'][$line->key]['label'] = $label !== '' ? $label : $line->key;
            $groups[$group]['lines'][$line->key]['months'][(int) $line->month] =
                ($groups[$group]['lines'][$line->key]['months'][(int) $line->month] ?? 0) + (float) $line->amount;
        }

        uasort($groups, fn (array $a, array $b) => array_sum($b['months']) <=> array_sum($a['months']));

        foreach ($groups as $group => $cells) {
            $rows[] = $this->row('group:'.$group, $group, 'group', $this->spread($cells['months']), count($cells['lines']));

            uasort($cells['lines'], fn (array $a, array $b) => array_sum($b['months']) <=> array_sum($a['months']));

            foreach ($cells['lines'] as $saf => $line) {
                $rows[] = $this->row('line:'.$saf, $saf.' '.$line['label'], 'line', $this->spread($line['months']), null, $group);
            }
        }

        $rows[] = $this->row('costs', 'Cheltuieli totale operaționale', 'strong', $pick('opex'));
        $rows[] = $this->row('ebitda', 'EBITDA', 'result', $pick('ebitda'));

        foreach ($lines->where('kind', PlanLine::BELOW)->groupBy('key') as $bucket => $group) {
            $cells = [];

            foreach ($group as $line) {
                $cells[(int) $line->month] = ($cells[(int) $line->month] ?? 0) + (float) $line->amount;
            }

            $rows[] = $this->row(
                'below:'.$bucket,
                PnlReportService::BELOW_LABELS[$bucket] ?? (string) $bucket,
                'cost',
                $this->spread($cells),
            );
        }

        if ($leases !== null) {
            $rows[] = $this->row('leases:depreciation', 'Amortizare drept de utilizare (IFRS 16)', 'cost', $this->spread($leases['depreciation']));
            $rows[] = $this->row('leases:interest', 'Dobândă datorie de leasing (IFRS 16)', 'cost', $this->spread($leases['interest']));
        }

        $rows[] = $this->row('net', 'Profit net', 'result', $pick('net_profit'));

        return $this->withBase($plan, $rows);
    }

    /**
     * Venitul și marja planului, împărțite pe canale de vânzare.
     *
     * Planul se face pe categorie de produs, fiindcă acolo stau pârghiile —
     * pasagerii, locurile, banii pe pasager. Dar raportul se citește și pe
     * canal, iar proporția se ia din anul de bază: dacă Circuitele Culturale
     * s-au vândut anul trecut 60% prin agenții și 40% prin B2B, planul lor
     * se împarte la fel, până se schimbă cu mâna.
     *
     * @param  Collection<int, PlanLine>  $lines
     * @param  list<int>  $wanted
     * @return list<array<string, mixed>>
     */
    private function channels(Plan $plan, Collection $lines, array $wanted): array
    {
        $mix = ForecastHistory::query()
            ->where('company_id', $plan->company_id)
            ->where('year', $plan->base_year)
            ->where('kind', 'mix')
            ->get()
            ->groupBy('key');

        $out = [];

        foreach ($lines->whereIn('kind', [PlanLine::CATEGORY, PlanLine::CHARTER]) as $line) {
            if (! in_array((int) $line->month, $wanted, true)) {
                continue;
            }

            // Charterul n-are amestec propriu pe cursă: se ia cel al
            // categoriei din care face parte.
            $key = $line->kind === PlanLine::CHARTER ? PlanBuilder::CHARTER_CATEGORY : $line->key;
            $theirs = $mix->get($key, collect());
            $total = (float) $theirs->sum('net');

            $shares = $total > 0
                ? $theirs->groupBy('channel')->map(fn (Collection $group) => (float) $group->sum('net') / $total)
                : collect(['—' => 1.0]);

            foreach ($shares as $channel => $share) {
                $cell = $out[$channel] ?? ['channel' => (string) $channel, 'net' => 0.0, 'margin' => 0.0, 'months' => []];
                $cell['net'] = round($cell['net'] + $line->net() * $share, 2);
                $cell['margin'] = round($cell['margin'] + $line->margin() * $share, 2);
                $cell['months'][(int) $line->month] = round(($cell['months'][(int) $line->month] ?? 0) + $line->net() * $share, 2);
                $out[$channel] = $cell;
            }
        }

        uasort($out, fn (array $a, array $b) => $b['net'] <=> $a['net']);

        return array_values($out);
    }

    /**
     * Chiriile retratate pentru anul planului, din registrul de contracte.
     *
     * @return array<string, mixed>|null
     */
    private function leases(Plan $plan): ?array
    {
        $leases = Lease::query()->where('company_id', $plan->company_id)->counted()->get();

        if ($leases->isEmpty()) {
            return null;
        }

        $schedule = app(Ifrs16Schedule::class)->forYear($leases, (int) $plan->year);
        $rent = [];
        $below = [];
        $depreciation = [];
        $interest = [];

        foreach (range(1, 12) as $month) {
            $cell = $schedule['months'][$month] ?? ['payment' => 0.0, 'depreciation' => 0.0, 'interest' => 0.0];
            $rent[$month] = round((float) $cell['payment'], 2);
            $depreciation[$month] = round((float) $cell['depreciation'], 2);
            $interest[$month] = round((float) $cell['interest'], 2);
            $below[$month] = round($depreciation[$month] + $interest[$month], 2);
        }

        return [
            'rent' => $rent,
            'below' => $below,
            'depreciation' => $depreciation,
            'interest' => $interest,
            'summary' => [
                'leases' => (int) $schedule['leases'],
                'rent' => round(array_sum($rent), 2),
                'depreciation' => round(array_sum($depreciation), 2),
                'interest' => round(array_sum($interest), 2),
            ],
            'months' => range(1, 12),
        ];
    }

    /**
     * @param  array<int, float>  $months
     * @return array<int, float>
     */
    private function spread(array $months): array
    {
        $out = [];

        foreach (range(1, 12) as $month) {
            $out[$month] = round((float) ($months[$month] ?? 0), 2);
        }

        return $out;
    }

    /**
     * @param  array<int, float>  $months
     * @return array<string, mixed>
     */
    private function row(string $key, string $label, string $kind, array $months, ?int $lines = null, ?string $group = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'kind' => $kind,
            'group' => $group,
            'lines' => $lines,
            'months' => array_map(fn (float $value) => round($value, 2), $months),
            'total' => round(array_sum($months), 2),
        ];
    }

    /**
     * Pune lângă fiecare rând cifra anului de bază, din raportul adevărat.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withBase(Plan $plan, array $rows): array
    {
        $report = $this->pnl->cached($plan->company, $plan->base_year);

        if ($report === null || isset($report['error'])) {
            return $rows;
        }

        $slice = $this->pnl->slice($report, range(1, 12), 'year', PnlReportService::BASIS_RAS, PnlReportService::MODE_OPERATIONAL);
        $margin = (float) $slice['revenue']['total']['margin'];
        $opex = (float) $slice['totals']['total'];
        $below = array_map(
            fn (string $bucket) => (float) ($slice['below'][$bucket]['total'] ?? 0),
            array_combine(['amortizare', 'financiar', 'impozit'], ['amortizare', 'financiar', 'impozit']),
        );

        $actual = [
            'revenue' => (float) $slice['revenue']['total']['net'],
            'margin' => $margin,
            'costs' => $opex,
            'ebitda' => $margin - $opex,
            'net' => $margin - $opex - array_sum($below),
        ];

        foreach ($below as $bucket => $value) {
            $actual['below:'.$bucket] = $value;
        }

        foreach (($slice['lines'] ?? []) as $line) {
            $actual['line:'.$line['saf']] = (float) $line['total'];
            $actual['group:'.$line['group']] = ($actual['group:'.$line['group']] ?? 0) + (float) $line['total'];
        }

        return array_map(fn (array $row) => [
            ...$row,
            'base' => isset($actual[$row['key']]) ? round($actual[$row['key']], 2) : null,
        ], $rows);
    }

    /**
     * @param  array<int, array<string, float>>  $months
     * @return array<string, float>
     */
    private function totals(array $months): array
    {
        $totals = ['net' => 0.0, 'margin' => 0.0, 'opex' => 0.0, 'below' => 0.0, 'ebitda' => 0.0, 'net_profit' => 0.0, 'pax' => 0.0, 'services' => 0.0, 'seats' => 0.0, 'empty_seats' => 0.0, 'empty_cost' => 0.0];

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
                        'unit_cost' => (float) $line->unit_cost,
                        'empty_seats' => $line->emptySeats(),
                        'empty_cost' => $line->emptyCost(),
                        'break_even' => $line->breakEven(),
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
                    'unit' => $first->unit ?? PlanLine::PAX,
                    'label' => $first->label ?: $key,
                    'months' => $months,
                    'pax' => round($group->sum(fn (PlanLine $line) => $line->pax()), 2),
                    'seats' => round((float) $group->sum('capacity'), 2),
                    'net' => round($group->sum(fn (PlanLine $line) => $line->net()), 2),
                    'margin' => round($group->sum(fn (PlanLine $line) => $line->margin()), 2),
                    // Locurile rămase nevândute și cât ne costă: avionul se
                    // plătește întreg, vândut sau nu.
                    'empty_seats' => round($group->sum(fn (PlanLine $line) => $line->emptySeats()), 2),
                    'empty_cost' => round($group->sum(fn (PlanLine $line) => $line->emptyCost()), 2),
                    'unit_cost' => round((float) $group->avg('unit_cost'), 2),
                    'break_even' => $group->avg('unit_net') > 0
                        ? round((float) $group->avg('unit_cost') / (float) $group->avg('unit_net'), 4)
                        : null,
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
