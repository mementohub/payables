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
     * @return array{categories: int, charter: int}
     */
    public function build(Company $company, int $year): array
    {
        return [
            'categories' => $this->categories($company, $year),
            'charter' => $this->charter($company, $year),
            'mix' => $this->mix($company, $year),
        ];
    }

    /**
     * Amestecul canalelor dintr-o categorie: cât a vândut retailul, cât
     * B2B-ul, cât site-ul. Planul se face pe produs, dar se citește și pe
     * canal, iar proporția se ia de aici.
     */
    private function mix(Company $company, int $year): int
    {
        $rows = [];

        foreach ($this->pnlEtrip->byChannelAndProduct($year) as $row) {
            if (abs((float) $row['net']) < 0.5) {
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

    private function categories(Company $company, int $year): int
    {
        $rows = [];

        foreach ($this->etrip->byProduct($year) as $row) {
            if ($row['pax'] <= 0 && abs($row['net']) < 0.5) {
                continue;
            }

            $rows[] = [
                'kind' => PlanLine::CATEGORY,
                'unit' => PlanLine::PAX,
                'key' => $row['product'],
                'month' => $row['month'],
                'volume' => $row['pax'],
                'net' => $row['net'],
                'margin' => $row['margin'],
                'cost' => 0.0,
            ];
        }

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

        foreach ($this->charter->capacity($year) as $row) {
            $key = $row['home'].'→'.$row['destination'];
            $rows[$key.'|'.$row['month']] = [
                'kind' => PlanLine::CHARTER,
                'unit' => PlanLine::PAX,
                'key' => $key,
                'month' => $row['month'],
                'capacity' => $row['seats'],
                'sold' => $row['sold'],
                'volume' => $row['sold'],
                'net' => 0.0,
                'margin' => 0.0,
                'cost' => 0.0,
            ];
        }

        foreach ($this->charter->money($year) as $row) {
            $key = $row['home'].'→'.$row['destination'];
            $cell = $rows[$key.'|'.$row['month']] ?? [
                'kind' => PlanLine::CHARTER,
                'unit' => PlanLine::PAX,
                'key' => $key,
                'month' => $row['month'],
                'capacity' => null,
                'sold' => null,
                'volume' => 0.0,
            ];

            // Pasagerii din rezervări sunt cei adevărați: „vândut” din
            // alocare numără locuri, iar un copil în poală are loc, dar n-are
            // scaun.
            $rows[$key.'|'.$row['month']] = [
                ...$cell,
                'volume' => $row['pax'] > 0 ? $row['pax'] : ($cell['volume'] ?? 0),
                'net' => $row['net'],
                'margin' => $row['margin'],
                // Ce plătim companiei aeriene pentru locurile alea.
                'cost' => $row['flight_cost'],
            ];
        }

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
