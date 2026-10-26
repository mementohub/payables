<?php

namespace App\Services\Forecast;

use App\Models\Company;
use App\Models\ForecastHistory;
use App\Models\PlanLine;
use App\Services\Reports\EtripPnlReader;
use App\Services\Reports\TinaPnlReader;
use Illuminate\Support\Facades\DB;

/**
 * Strânge istoricul din care se judecă planul: cum a mers fiecare categorie
 * și fiecare cursă, an de an.
 *
 * Se cheamă rar — o dată pe noapte sau când o cere omul — fiindcă citirea
 * banilor pe cursă trece de două minute pe an. Pagina planului nu așteaptă
 * după ea: citește ce s-a strâns aici.
 */
class ForecastHistoryBuilder
{
    public function __construct(
        private ForecastEtripReader $etrip,
        private ForecastCharterReader $charter,
        private ForecastTinaReader $tina,
        private EtripPnlReader $pnlEtrip,
        private TinaPnlReader $pnlTina,
    ) {}

    /**
     * @return array{categories: int, charter: int, mix: int, lag: int, extra: int}
     */
    public function build(Company $company, int $year): array
    {
        return [
            'categories' => $this->categories($company, $year),
            'charter' => $this->charter($company, $year),
            'mix' => $this->mix($company, $year),
            'lag' => $this->lag($company, $year),
            'extra' => $this->extra($company, $year),
        ];
    }

    /**
     * Bazele de rezervări care aparțineau companiei în anul ăla.
     *
     * Vacanza are baza ei; din 2026 e a noastră. Istoricul le adună pe
     * aceleași categorii și aceleași curse, fiindcă de la anul se vând
     * dintr-un singur loc — iar planul trebuie să pornească de la toată
     * afacerea, nu de la patru cincimi din ea.
     *
     * @return list<array{connection: ?string, months: ?list<int>}>
     */
    private function bases(Company $company, int $year): array
    {
        $out = [['connection' => null, 'months' => null]];

        foreach ((array) config('pnl.extra_bases', []) as $connection => $base) {
            $months = $this->window($base, $year);

            if ($months === []) {
                continue;
            }

            $out[] = ['connection' => (string) $connection, 'months' => $months];
        }

        return $out;
    }

    /**
     * De când se citește dintr-o bază, în anul ăla.
     *
     * Până în iunie 2026, Vacanza era altă societate: ce s-a vândut atunci
     * nu e al nostru și n-are ce căuta în comparație. De la fuziune încolo
     * e a noastră, iar rezervările necălătorite s-au mutat atunci în baza
     * Christian Tour, care le numără de-acolo — deci din baza ei se ia
     * numai de la luna fuziunii încolo.
     *
     * @param  array<string, mixed>  $base
     * @return list<int>|null null = tot anul, [] = niciun an
     */
    private function window(array $base, int $year): ?array
    {
        $from = (string) ($base['from'] ?? '');

        if (preg_match('/^(\d{4})-(\d{1,2})$/', $from, $parts) !== 1 || $year < (int) $parts[1]) {
            return [];
        }

        return $year === (int) $parts[1] ? range((int) $parts[2], 12) : null;
    }

    /**
     * Cât aduce fiecare bază în parte, ca să se poată spune pe față din ce
     * e făcut anul de bază — și ca vederea lui să nu pară că sare.
     */
    private function extra(Company $company, int $year): int
    {
        $rows = [];

        foreach ((array) config('pnl.extra_bases', []) as $connection => $base) {
            $window = $this->window($base, $year);

            if ($window === []) {
                continue;
            }

            foreach ($this->etrip->byProduct($year, (string) $connection) as $row) {
                if ($window !== null && ! in_array((int) $row['month'], $window, true)) {
                    continue;
                }

                $key = (string) ($base['label'] ?? $connection);
                $cell = $rows[$key.'|'.$row['month']] ?? [
                    'kind' => 'extra', 'unit' => PlanLine::PAX, 'key' => $key,
                    'month' => $row['month'], 'volume' => 0, 'net' => 0.0, 'margin' => 0.0, 'cost' => 0.0,
                ];

                $rows[$key.'|'.$row['month']] = [
                    ...$cell,
                    'volume' => $cell['volume'] + $row['pax'],
                    'net' => round($cell['net'] + $row['net'], 2),
                    'margin' => round($cell['margin'] + $row['margin'], 2),
                ];
            }
        }

        return $this->write($company, $year, 'extra', array_values($rows));
    }

    /**
     * Amestecul canalelor dintr-o categorie: cât a vândut retailul, cât
     * B2B-ul, cât site-ul. Planul se face pe produs, dar se citește și pe
     * canal, iar proporția se ia de aici.
     */
    private function mix(Company $company, int $year): int
    {
        $rows = [];

        foreach ($this->bases($company, $year) as $base) {
            foreach ($this->pnlEtrip->byChannelAndProduct($year, $base['connection']) as $row) {
                if (abs((float) $row['net']) < 0.5) {
                    continue;
                }

                if ($base['months'] !== null && ! in_array((int) $row['month'], $base['months'], true)) {
                    continue;
                }

                $key = $row['product'].'|'.$row['channel'].'|'.$row['month'];
                $cell = $rows[$key] ?? [
                    'kind' => 'mix', 'unit' => PlanLine::PAX, 'key' => $row['product'],
                    'channel' => $row['channel'], 'month' => (int) $row['month'],
                    'volume' => 0, 'net' => 0.0, 'margin' => 0.0, 'cost' => 0.0,
                ];

                $rows[$key] = [
                    ...$cell,
                    'volume' => $cell['volume'] + (int) $row['bookings'],
                    'net' => round($cell['net'] + (float) $row['net'], 2),
                    'margin' => round($cell['margin'] + (float) $row['margin'], 2),
                ];
            }
        }

        foreach ($this->pnlTina->byChannelAndProduct($year) as $row) {
            if (abs((float) $row['net']) < 0.5) {
                continue;
            }

            $key = PlanBuilder::TINA_PREFIX.$row['product'].'|'.$row['channel'].'|'.$row['month'];
            $cell = $rows[$key] ?? [
                'kind' => 'mix', 'unit' => PlanLine::SERVICE, 'key' => PlanBuilder::TINA_PREFIX.$row['product'],
                'channel' => $row['channel'], 'month' => (int) $row['month'],
                'volume' => 0, 'net' => 0.0, 'margin' => 0.0, 'cost' => 0.0,
            ];

            $rows[$key] = [
                ...$cell,
                'volume' => $cell['volume'] + (int) $row['bookings'],
                'net' => round($cell['net'] + (float) $row['net'], 2),
                'margin' => round($cell['margin'] + (float) $row['margin'], 2),
            ];
        }

        return $this->write($company, $year, 'mix', array_values($rows));
    }

    /**
     * Tiparul „vândut în luna X, plecat în luna Y”, pe categorie.
     *
     * Din el se mută venitul între vederea operațională (la rezervare) și
     * cea financiară (la plecare), fără să se inventeze nimic: din ce s-a
     * vândut în februarie, 24% a plecat în iulie și 23% în august.
     */
    private function lag(Company $company, int $year): int
    {
        $rows = [];

        foreach ($this->bases($company, $year) as $base) {
            foreach ($this->etrip->lag($year, $base['connection']) as $row) {
                if (abs($row['net']) < 0.5 || $row['travelled'] < 1 || $row['travelled'] > 12) {
                    continue;
                }

                if ($base['months'] !== null && ! in_array((int) $row['booked'], $base['months'], true)) {
                    continue;
                }

                $key = $row['product'].'|'.$row['booked'].'|'.$row['travelled'];
                $cell = $rows[$key] ?? [
                    'kind' => 'lag',
                    'unit' => PlanLine::PAX,
                    'key' => $row['product'],
                    // Luna plecării stă în coloana de canal: tabelul are deja
                    // cheia potrivită, iar un rând de tipar n-are canal.
                    'channel' => (string) $row['travelled'],
                    'month' => $row['booked'],
                    'volume' => 0, 'net' => 0.0, 'margin' => 0.0, 'cost' => 0.0,
                ];

                $rows[$key] = [
                    ...$cell,
                    'net' => round($cell['net'] + $row['net'], 2),
                    'margin' => round($cell['margin'] + $row['margin'], 2),
                ];
            }
        }

        return $this->write($company, $year, 'lag', array_values($rows));
    }

    private function categories(Company $company, int $year): int
    {
        $rows = [];

        foreach ($this->bases($company, $year) as $base) {
            foreach ($this->etrip->byProduct($year, $base['connection']) as $row) {
                if ($row['pax'] <= 0 && abs($row['net']) < 0.5) {
                    continue;
                }

                if ($base['months'] !== null && ! in_array((int) $row['month'], $base['months'], true)) {
                    continue;
                }

                $key = $row['product'].'|'.$row['month'];
                $cell = $rows[$key] ?? [
                    'kind' => PlanLine::CATEGORY,
                    'unit' => PlanLine::PAX,
                    'key' => $row['product'],
                    'month' => $row['month'],
                    'volume' => 0, 'net' => 0.0, 'margin' => 0.0, 'cost' => 0.0,
                    'charter_volume' => 0, 'charter_net' => 0.0, 'charter_margin' => 0.0,
                ];

                $rows[$key] = [
                    ...$cell,
                    'volume' => $cell['volume'] + $row['pax'],
                    'net' => round($cell['net'] + $row['net'], 2),
                    'margin' => round($cell['margin'] + $row['margin'], 2),
                    'charter_volume' => $cell['charter_volume'] + $row['charter_pax'],
                    'charter_net' => round($cell['charter_net'] + $row['charter_net'], 2),
                    'charter_margin' => round($cell['charter_margin'] + $row['charter_margin'], 2),
                ];
            }
        }

        $rows = array_values($rows);

        foreach ($this->tina->byProduct($year) as $row) {
            if ($row['services'] <= 0) {
                continue;
            }

            $rows[] = [
                'kind' => PlanLine::CATEGORY,
                'unit' => PlanLine::SERVICE,
                'key' => PlanBuilder::TINA_PREFIX.$row['product'],
                'month' => $row['month'],
                'volume' => $row['services'],
                'net' => $row['net'],
                'margin' => $row['margin'],
                'cost' => 0.0,
            ];
        }

        return $this->write($company, $year, PlanLine::CATEGORY, $rows);
    }

    /**
     * Cursele: locurile contractate și vândute dintr-o parte, pasagerii și
     * banii din cealaltă, lipite pe aceeași cheie.
     */
    private function charter(Company $company, int $year): int
    {
        // Locurile și vândutele vin din alocări, banii și pasagerii din
        // rezervări. Se strâng deoparte, fiindcă se adună din mai multe baze
        // și fiecare are socoteala ei: „vândut” numără locuri, iar un copil
        // în poală are loc, dar n-are scaun.
        $seats = [];
        $sold = [];
        $pax = [];
        $net = [];
        $margin = [];
        $cost = [];
        $where = [];

        foreach ($this->bases($company, $year) as $base) {
            foreach ($this->charter->capacity($year, $base['connection']) as $row) {
                if ($base['months'] !== null && ! in_array((int) $row['month'], $base['months'], true)) {
                    continue;
                }

                $cell = $row['home'].'→'.$row['destination'].'|'.$row['month'];
                $where[$cell] = [$row['home'].'→'.$row['destination'], (int) $row['month']];
                $seats[$cell] = ($seats[$cell] ?? 0) + $row['seats'];
                $sold[$cell] = ($sold[$cell] ?? 0) + $row['sold'];
            }

            foreach ($this->charter->money($year, $base['connection']) as $row) {
                if ($base['months'] !== null && ! in_array((int) $row['month'], $base['months'], true)) {
                    continue;
                }

                $cell = $row['home'].'→'.$row['destination'].'|'.$row['month'];
                $where[$cell] = [$row['home'].'→'.$row['destination'], (int) $row['month']];
                $pax[$cell] = ($pax[$cell] ?? 0) + $row['pax'];
                $net[$cell] = round(($net[$cell] ?? 0) + $row['net'], 2);
                $margin[$cell] = round(($margin[$cell] ?? 0) + $row['margin'], 2);
                $cost[$cell] = round(($cost[$cell] ?? 0) + $row['flight_cost'], 2);
            }
        }

        $rows = [];

        foreach ($where as $cell => [$key, $month]) {
            $rows[] = [
                'kind' => PlanLine::CHARTER,
                'unit' => PlanLine::PAX,
                'key' => $key,
                'month' => $month,
                'capacity' => $seats[$cell] ?? null,
                'sold' => $sold[$cell] ?? null,
                // Pasagerii din rezervări sunt cei adevărați; fără ei,
                // rămân locurile vândute din alocare.
                'volume' => ($pax[$cell] ?? 0) > 0 ? $pax[$cell] : ($sold[$cell] ?? 0),
                'net' => $net[$cell] ?? 0.0,
                'margin' => $margin[$cell] ?? 0.0,
                'cost' => $cost[$cell] ?? 0.0,
            ];
        }

        return $this->write($company, $year, PlanLine::CHARTER, $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function write(Company $company, int $year, string $kind, array $rows): int
    {
        return DB::transaction(function () use ($company, $year, $kind, $rows) {
            ForecastHistory::query()
                ->where('company_id', $company->getKey())
                ->where('year', $year)
                ->where('kind', $kind)
                ->delete();

            $written = 0;

            foreach (array_chunk($rows, 400) as $chunk) {
                ForecastHistory::query()->insert(array_map(fn (array $row) => [
                    'company_id' => $company->getKey(),
                    'year' => $year,
                    'capacity' => null,
                    'sold' => null,
                    'cost' => 0,
                    'charter_volume' => 0,
                    'charter_net' => 0,
                    'charter_margin' => 0,
                    'channel' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                    ...$row,
                ], $chunk));

                $written += count($chunk);
            }

            return $written;
        });
    }
}
