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

        $base = $this->complete(
            $this->history($plan, $plan->base_year, PlanLine::CATEGORY),
            $this->history($plan, $plan->base_year - 1, PlanLine::CATEGORY),
            $plan->base_year,
        );

        foreach ($base as $row) {
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
                'note' => ($row->estimated ?? false)
                    ? sprintf('Luna n-avea încă cifre în %d: estimat din %d.', $plan->base_year, $plan->base_year - 1)
                    : null,
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
        $base = $this->complete(
            $this->history($plan, $plan->base_year, PlanLine::CHARTER),
            $this->history($plan, $plan->base_year - 1, PlanLine::CHARTER),
            $plan->base_year,
        );
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
                // Cât plătim avionului pentru un loc: din el ies pragul și
                // costul locurilor goale.
                'unit_cost' => $pax > 0 ? round((float) $theirs->sum('cost') / $pax, 2) : $money['cost'],
                'note' => $contracted
                    ? sprintf('Locuri contractate în eTrip pentru %d; ocupare și bani pe pasager din %d.', $plan->year, $plan->base_year)
                    : sprintf('Locurile anului %d; pentru %d nu e contractat încă nimic.', $plan->base_year, $plan->year),
            ];
        }

        return $this->write($plan, PlanLine::CHARTER, $rows, $keepManual);
    }

    /**
     * Anul întreg al unei cheltuieli: lunile care n-au venit încă iau media
     * celor care au.
     *
     * Un plan pornit în octombrie din anul curent ar avea salarii nouă luni
     * și zero în ultimele trei — iar EBITDA planificat ar ieși fals de bun.
     *
     * @param  array<int, float>  $months
     * @return array<int, float>
     */
    private function fillYear(array $months): array
    {
        if ($months === []) {
            return [];
        }

        $average = round(array_sum($months) / count($months), 2);
        $out = [];

        foreach (range(1, 12) as $month) {
            $out[$month] = $months[$month] ?? $average;
        }

        return $out;
    }

    /**
     * Anul de bază întregit: lunile care n-au venit încă se estimează.
     *
     * Un plan pornit din 2026 în octombrie ar lua nouă luni adevărate și
     * trei goale, iar anul ar ieși cu un sfert mai mic decât va fi. Așa că
     * lunile lipsă se iau din anul dinainte, înmulțite cu cât a crescut
     * anul până acum — și se spune pe rând că sunt estimate.
     *
     * @param  Collection<int, ForecastHistory>  $base
     * @param  Collection<int, ForecastHistory>  $before
     * @return Collection<int, ForecastHistory>
     */
    private function complete(Collection $base, Collection $before, int $baseYear): Collection
    {
        $months = $base->pluck('month')->unique();

        if ($months->isEmpty() || $months->count() === 12) {
            return $base;
        }

        // Cât a crescut anul pe lunile care există în amândouă.
        $common = $before->whereIn('month', $months->all());
        $growth = $common->sum('net') > 0 ? $base->sum('net') / $common->sum('net') : 1.0;
        $growth = max(0.2, min(3.0, $growth));

        $filled = $before
            ->whereNotIn('month', $months->all())
            ->map(function (ForecastHistory $row) use ($growth, $baseYear) {
                $copy = $row->replicate();
                $copy->year = $baseYear;
                $copy->volume = round($row->volume * $growth, 2);
                $copy->net = round($row->net * $growth, 2);
                $copy->margin = round($row->margin * $growth, 2);
                $copy->cost = round($row->cost * $growth, 2);
                $copy->estimated = true;

                return $copy;
            });

        return $base->concat($filled)->values();
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
     * @return array{net: float, margin: float, cost: float}
     */
    private function charterMoney(Collection $base): array
    {
        $pax = (float) $base->sum('volume');

        return $pax > 0
            ? [
                'net' => round((float) $base->sum('net') / $pax, 2),
                'margin' => round((float) $base->sum('margin') / $pax, 2),
                'cost' => round((float) $base->sum('cost') / $pax, 2),
            ]
            : ['net' => 0.0, 'margin' => 0.0, 'cost' => 0.0];
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
            $months = [];

            foreach (($line['months'] ?? []) as $month => $cell) {
                if (abs((float) $cell['total']) < 0.005) {
                    continue;
                }

                $months[(int) $month] = round((float) $cell['total'], 2);
            }

            foreach ($this->fillYear($months) as $month => $amount) {
                $rows[] = [
                    'kind' => PlanLine::OPEX,
                    'key' => (string) $line['saf'],
                    'label' => trim((string) $line['group'].' · '.$line['label']),
                    'month' => $month,
                    'amount' => $amount,
                    'note' => isset($months[$month])
                        ? null
                        : sprintf('Luna n-avea încă cheltuieli în %d: pusă media lunilor cu mișcare.', $plan->base_year),
                ];
            }
        }

        $written = $this->write($plan, PlanLine::OPEX, $rows, $keepManual);
        $below = [];

        foreach (['amortizare', 'financiar', 'impozit'] as $bucket) {
            $months = [];

            foreach (($report['below'][$bucket]['months'] ?? []) as $month => $cell) {
                if (abs((float) $cell['total']) < 0.005) {
                    continue;
                }

                $months[(int) $month] = round((float) $cell['total'], 2);
            }

            foreach ($this->fillYear($months) as $month => $amount) {
                $below[] = [
                    'kind' => PlanLine::BELOW,
                    'key' => $bucket,
                    'label' => PnlReportService::BELOW_LABELS[$bucket] ?? $bucket,
                    'month' => $month,
                    'amount' => $amount,
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
