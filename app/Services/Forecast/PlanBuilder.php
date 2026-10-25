<?php

namespace App\Services\Forecast;

use App\Models\ForecastHistory;
use App\Models\Plan;
use App\Models\PlanLine;
use App\Services\Reports\PnlReportService;
use Illuminate\Support\Collection;
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

    /** Rândurile venite din celălalt ERP își spun numele. */
    public const TINA_PREFIX = 'Tina · ';

    public function __construct(private PnlReportService $pnl) {}

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
        $rows = [];

        foreach ($this->history($plan, $plan->base_year, PlanLine::CATEGORY) as $row) {
            if ($row->key === self::CHARTER_CATEGORY || $row->volume <= 0) {
                continue;
            }

            $rows[] = [
                'kind' => PlanLine::CATEGORY,
                'unit' => $row->unit,
                'key' => $row->key,
                'label' => $row->key,
                'month' => $row->month,
                'volume' => $row->volume,
                'unit_net' => round($row->net / $row->volume, 2),
                'unit_margin' => round($row->margin / $row->volume, 2),
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
        $planned = $this->history($plan, $plan->year, PlanLine::CHARTER);
        $base = $this->history($plan, $plan->base_year, PlanLine::CHARTER);
        $contracted = $planned->sum('capacity') > 0;

        // Gradul de ocupare și banii pe pasager se iau de pe cursa ei, din
        // anul de bază — nu dintr-o medie pe toată flota: la Monastir omul
        // lasă 2.834 de lei, la Cluj–Antalya 4.091.
        $byKey = $base->groupBy('key');
        $seats = (float) $base->sum('capacity');
        $sold = (float) $base->sum('sold');
        $average = $seats > 0 ? $sold / $seats : 0.75;
        $money = $this->charterMoney($base);

        $rows = [];

        foreach (($contracted ? $planned : $base) as $row) {
            $theirs = $byKey->get($row->key, collect());
            $month = $theirs->firstWhere('month', $row->month);
            $load = $month !== null && $month->capacity > 0
                ? round((float) $month->sold / (float) $month->capacity, 4)
                : round($average, 4);

            $pax = (float) $theirs->sum('volume');

            $rows[] = [
                'kind' => PlanLine::CHARTER,
                'unit' => PlanLine::PAX,
                'key' => $row->key,
                'label' => $row->key,
                'month' => $row->month,
                'capacity' => $row->capacity,
                'load' => min(1.0, $load),
                'unit_net' => $pax > 0 ? round((float) $theirs->sum('net') / $pax, 2) : $money['net'],
                'unit_margin' => $pax > 0 ? round((float) $theirs->sum('margin') / $pax, 2) : $money['margin'],
                'note' => $contracted
                    ? sprintf('Locuri contractate în eTrip pentru %d; ocupare și bani pe pasager din %d.', $plan->year, $plan->base_year)
                    : sprintf('Locurile anului %d; pentru %d nu e contractat încă nimic.', $plan->base_year, $plan->year),
            ];
        }

        return $this->write($plan, PlanLine::CHARTER, $rows, $keepManual);
    }

    /**
     * Istoricul strâns al unui an, pe felul cerut.
     *
     * @return Collection<int, ForecastHistory>
     */
    private function history(Plan $plan, int $year, string $kind): Collection
    {
        return ForecastHistory::query()
            ->where('company_id', $plan->company_id)
            ->where('year', $year)
            ->where('kind', $kind)
            ->get();
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
     * Media flotei, pentru cursele fără istoric propriu.
     *
     * @param  Collection<int, ForecastHistory>  $base
     * @return array{net: float, margin: float}
     */
    private function charterMoney(Collection $base): array
    {
        $pax = (float) $base->sum('volume');

        return $pax > 0
            ? ['net' => round((float) $base->sum('net') / $pax, 2), 'margin' => round((float) $base->sum('margin') / $pax, 2)]
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
                        'unit' => PlanLine::PAX,
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
