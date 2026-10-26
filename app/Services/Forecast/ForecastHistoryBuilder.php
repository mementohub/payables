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
            if ($year < (int) ($base['from_year'] ?? 9999)) {
                continue;
            }

            $out[] = ['connection' => (string) $connection, 'months' => $this->window($base, $year)];
        }

        return $out;
    }

    /**
     * Până când se citește dintr-o bază, în anul ăla.
     *
     * Fuziunea Vacanza e din iunie 2026, iar rezervările necălătorite s-au
     * mutat atunci în baza Christian Tour, care le numără de-acolo încolo.
     * Din baza ei se ia deci numai până în iunie — mai departe ar fi aceiași
     * bani de două ori.
     *
     * @param  array<string, mixed>  $base
     * @return list<int>|null null = tot anul
     */
    private function window(array $base, int $year): ?array
    {
        $until = (string) ($base['until'] ?? '');

        if (preg_match('/^(\d{4})-(\d{1,2})$/', $until, $parts) !== 1 || $year < (int) $parts[1]) {
            return null;
        }

        return $year === (int) $parts[1] ? range(1, (int) $parts[2]) : [];
    }

    /**
     * Cât aduce fiecare bază în parte, ca să se poată spune pe față din ce
     * e făcut anul de bază — și ca vederea lui să nu pară că sare.
     */
    private function extra(Company $company, int $year): int
    {
        $rows = [];

        foreach ((array) config('pnl.extra_bases', []) as $connection => $base) {
            if ($year < (int) ($base['from_year'] ?? 9999)) {
                continue;
            }

            $window = $this->window($base, $year);

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
        $rows = [];

        foreach ($this->bases($company, $year) as $base) {
            foreach ($this->charter->capacity($year, $base['connection']) as $row) {
                if ($base['months'] !== null && ! in_array((int) $row['month'], $base['months'], true)) {
                    continue;
                }

                $key = $row['home'].'→'.$row['destination'];
                $cell = $rows[$key.'|'.$row['month']] ?? [
                    'kind' => PlanLine::CHARTER,
                    'unit' => PlanLine::PAX,
                    'key' => $key,
                    'month' => $row['month'],
                    'capacity' => 0, 'sold' => 0, 'volume' => 0.0,
                    'net' => 0.0, 'margin' => 0.0, 'cost' => 0.0,
                ];

                $rows[$key.'|'.$row['month']] = [
                    ...$cell,
                    'capacity' => (float) $cell['capacity'] + $row['seats'],
                    'sold' => (float) $cell['sold'] + $row['sold'],
                    'volume' => (float) $cell['volume'] + $row['sold'],
                ];
            }

            foreach ($this->charter->money($year, $base['connection']) as $row) {
                if ($base['months'] !== null && ! in_array((int) $row['month'], $base['months'], true)) {
                    continue;
                }

                $key = $row['home'].'→'.$row['destination'];
                $cell = $rows[$key.'|'.$row['month']] ?? [
                    'kind' => PlanLine::CHARTER,
                    'unit' => PlanLine::PAX,
                    'key' => $key,
                    'month' => $row['month'],
                    'capacity' => 0, 'sold' => 0, 'volume' => 0.0,
                    'net' => 0.0, 'margin' => 0.0, 'cost' => 0.0,
                ];

                // Pasagerii din rezervări sunt cei adevărați: „vândut” din
                // alocare numără locuri, iar un copil în poală are loc, dar
                // n-are scaun. De-aia volumul se ia de aici când există.
                $rows[$key.'|'.$row['month']] = [
                    ...$cell,
                    'volume' => $row['pax'] > 0
                        ? (float) $cell['volume'] - (float) ($cell['pax_seen'] ?? 0) + $row['pax']
                        : (float) $cell['volume'],
                    'pax_seen' => (float) ($cell['pax_seen'] ?? 0) + $row['pax'],
                    'net' => round((float) $cell['net'] + $row['net'], 2),
                    'margin' => round((float) $cell['margin'] + $row['margin'], 2),
                    'cost' => round((float) $cell['cost'] + $row['flight_cost'], 2),
                ];
            }
        }

        // `pax_seen` e doar pentru socoteala de mai sus; în tabel n-are loc.
        $rows = array_map(function (array $row) {
            unset($row['pax_seen']);

            return $row;
        }, $rows);

        return $this->write($company, $year, PlanLine::CHARTER, array_values($rows));
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
