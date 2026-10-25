<?php

namespace App\Services\Forecast;

use App\Models\Plan;
use App\Models\PlanLine;
use App\Services\Reports\PnlReportService;
use Illuminate\Support\Facades\DB;

/**
 * Umple un plan cu pârghiile lui, pornind din istoric.
 *
 * Pentru categoriile obișnuite, pârghia e pasagerul: câți au fost anul trecut
 * în luna aia, cât a lăsat fiecare și cât a rămas din el. Pentru charter,
 * pârghia e locul: câte s-au contractat pe destinația aia (pentru anul care
 * vine, cifra e deja în eTrip, nu e o presupunere), cu ce grad de ocupare și
 * cu ce venit de pasager. Cheltuielile pornesc de la ce s-a cheltuit, linie
 * cu linie, lună cu lună.
 *
 * Ce a scris omul rămâne: reconstrucția sare peste rândurile marcate „de
 * mână”, altfel planul s-ar șterge singur la fiecare apăsare de buton.
 */
class PlanBuilder
{
    /** Categoria de charter se plănuiește pe destinații, nu pe ea însăși. */
    public const CHARTER_CATEGORY = 'Charter';

    public function __construct(
        private ForecastEtripReader $etrip,
        private ForecastCharterReader $charter,
        private PnlReportService $pnl,
    ) {}

    public function seed(Plan $plan, bool $keepManual = true): int
    {
        $written = 0;

        $written += $this->seedCategories($plan, $keepManual);
        $written += $this->seedCharter($plan, $keepManual);
        $written += $this->seedCosts($plan, $keepManual);

        return $written;
    }

    /**
     * Categoriile, pe pasageri: volumul lunii trecute, venitul și marja
     * pe pasager din aceeași lună.
     */
    private function seedCategories(Plan $plan, bool $keepManual): int
    {
        $history = $this->etrip->byProduct($plan->base_year);
        $rows = [];

        foreach ($history as $row) {
            if ($row['product'] === self::CHARTER_CATEGORY || $row['pax'] <= 0) {
                continue;
            }

            $rows[] = [
                'kind' => PlanLine::CATEGORY,
                'key' => $row['product'],
                'label' => $row['product'],
                'month' => $row['month'],
                'volume' => $row['pax'],
                'unit_net' => round($row['net'] / $row['pax'], 2),
                'unit_margin' => round($row['margin'] / $row['pax'], 2),
            ];
        }

        return $this->write($plan, PlanLine::CATEGORY, $rows, $keepManual);
    }

    /**
     * Charterul, pe destinații: locurile contractate pentru anul plănuit
     * dacă există deja, altfel cele din anul de bază, cu gradul de ocupare
     * de atunci și cu banii pe pasager ai categoriei.
     */
    private function seedCharter(Plan $plan, bool $keepManual): int
    {
        $planned = $this->charter->capacity($plan->year);
        $base = $this->charter->capacity($plan->base_year);
        $contracted = $planned !== [] && array_sum(array_column($planned, 'seats')) > 0;

        // Gradul de ocupare se ia de anul trecut, pe destinație și lună; dacă
        // destinația e nouă, se ia media anului.
        $loads = [];
        $baseSeats = array_sum(array_column($base, 'seats'));
        $baseSold = array_sum(array_column($base, 'sold'));
        $average = $baseSeats > 0 ? $baseSold / $baseSeats : 0.75;

        foreach ($base as $row) {
            if ($row['seats'] > 0) {
                $loads[self::route($row).'|'.$row['month']] = round($row['sold'] / $row['seats'], 4);
            }
        }

        $money = $this->charterMoney($plan->base_year);
        $rows = [];

        foreach ($contracted ? $planned : $base as $row) {
            $route = self::route($row);
            $key = $route.'|'.$row['month'];

            $rows[] = [
                'kind' => PlanLine::CHARTER,
                'key' => $route,
                'label' => $route,
                'month' => $row['month'],
                'capacity' => $row['seats'],
                'load' => min(1.0, $loads[$key] ?? round($average, 4)),
                'unit_net' => $money['net'],
                'unit_margin' => $money['margin'],
                'note' => $contracted
                    ? sprintf('%d plecări contractate în eTrip pentru %d.', $row['departures'], $plan->year)
                    : sprintf('Locurile anului %d; pentru %d nu e contractat încă nimic.', $plan->base_year, $plan->year),
            ];
        }

        return $this->write($plan, PlanLine::CHARTER, $rows, $keepManual);
    }

    /**
     * Cursa, așa cum o cunoaște omul: de unde pleacă și unde ajunge.
     *
     * @param  array{home: string, destination: string}  $row
     */
    private static function route(array $row): string
    {
        return $row['home'].'→'.$row['destination'];
    }

    /**
     * Banii pe pasager ai charterului, din anul de bază.
     *
     * @return array{net: float, margin: float}
     */
    private function charterMoney(int $year): array
    {
        $rows = collect($this->etrip->byProduct($year))->where('product', self::CHARTER_CATEGORY);
        $pax = (int) $rows->sum('pax');

        return $pax > 0
            ? ['net' => round($rows->sum('net') / $pax, 2), 'margin' => round($rows->sum('margin') / $pax, 2)]
            : ['net' => 0.0, 'margin' => 0.0];
    }

    /**
     * Cheltuielile și cifrele de sub EBITDA, din raportul anului de bază.
     */
    private function seedCosts(Plan $plan, bool $keepManual): int
    {
        $report = $this->pnl->cached($plan->company, $plan->base_year);

        if ($report === null || isset($report['error'])) {
            return 0;
        }

        $rows = [];

        foreach (($report['lines'] ?? []) as $line) {
            foreach (($line['months'] ?? []) as $month => $cell) {
                if (abs((float) $cell['total']) < 0.005) {
                    continue;
                }

                $rows[] = [
                    'kind' => PlanLine::OPEX,
                    'key' => (string) $line['saf'],
                    'label' => trim((string) $line['group'].' · '.$line['label']),
                    'month' => (int) $month,
                    'amount' => round((float) $cell['total'], 2),
                ];
            }
        }

        $written = $this->write($plan, PlanLine::OPEX, $rows, $keepManual);
        $below = [];

        foreach (['amortizare', 'financiar', 'impozit'] as $bucket) {
            foreach (($report['below'][$bucket]['months'] ?? []) as $month => $cell) {
                if (abs((float) $cell['total']) < 0.005) {
                    continue;
                }

                $below[] = [
                    'kind' => PlanLine::BELOW,
                    'key' => $bucket,
                    'label' => PnlReportService::BELOW_LABELS[$bucket] ?? $bucket,
                    'month' => (int) $month,
                    'amount' => round((float) $cell['total'], 2),
                ];
            }
        }

        return $written + $this->write($plan, PlanLine::BELOW, $below, $keepManual);
    }

    /**
     * Scrie rândurile unui fel, fără să calce peste cele scrise de mână.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function write(Plan $plan, string $kind, array $rows, bool $keepManual): int
    {
        $manual = $keepManual
            ? PlanLine::query()->where('plan_id', $plan->id)->where('kind', $kind)->where('manual', true)
                ->get()->keyBy(fn (PlanLine $line) => $line->key.'|'.$line->month)
            : collect();

        return DB::transaction(function () use ($plan, $kind, $rows, $manual) {
            PlanLine::query()->where('plan_id', $plan->id)->where('kind', $kind)->where('manual', false)->delete();

            $written = 0;

            foreach (array_chunk($rows, 400) as $chunk) {
                $payload = [];

                foreach ($chunk as $row) {
                    if ($manual->has($row['key'].'|'.$row['month'])) {
                        continue;
                    }

                    $payload[] = [
                        'plan_id' => $plan->id,
                        'manual' => false,
                        'note' => null,
                        'label' => null,
                        'volume' => null, 'capacity' => null, 'load' => null,
                        'unit_net' => null, 'unit_margin' => null, 'amount' => null,
                        'created_at' => now(), 'updated_at' => now(),
                        ...$row,
                    ];
                }

                if ($payload !== []) {
                    PlanLine::query()->insert($payload);
                    $written += count($payload);
                }
            }

            return $written;
        });
    }
}
