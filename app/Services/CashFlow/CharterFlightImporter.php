<?php

namespace App\Services\CashFlow;

use App\Models\CharterContract;
use App\Models\CharterFlight;
use App\Services\Xlsx\XlsxReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Loads a charter flight programme (the annex of a contract, or the
 * Detaliu_Charter sheet of the reference workbook) into charter_flights.
 * Columns are found by their header, whatever their order; rows carrying a
 * season different from the chosen contract's go to the contract of that
 * season, created when missing.
 */
class CharterFlightImporter
{
    private const HEADERS = [
        'season' => ['sezon', 'season'],
        'status' => ['status', 'stare'],
        'operator' => ['operator', 'companie'],
        'route' => ['ruta', 'rută', 'route'],
        'flight_no' => ['nr zbor', 'zbor', 'flight'],
        'flight_date' => ['data zbor', 'data', 'date'],
        'seats' => ['locuri', 'seats', 'pax'],
        'price_per_seat' => ['pret', 'preț', 'price'],
        'net_value' => ['valoare', 'value', 'net'],
        'taxes' => ['taxe', 'tax'],
        'pay_date' => ['data plata rotatie', 'data plată rotație', 'plata rotatie', 'pay date'],
        'taxes_pay_date' => ['data plata taxe', 'data plată taxe', 'plata taxe'],
    ];

    /**
     * @return array{imported: int, skipped: int, contracts: list<string>, replaced: int}
     */
    public function import(string $path, string $originalName, CharterContract $contract, bool $replace = true, ?string $sheet = null): array
    {
        $rows = Str::endsWith(Str::lower($originalName), '.csv') ? $this->csv($path) : XlsxReader::rows($path, $sheet);
        [$columns, $start] = $this->columns($rows);

        if (! isset($columns['flight_date'], $columns['net_value'])) {
            throw new RuntimeException('Nu am găsit coloanele „Data zbor” și „Valoare netă” în fișier.');
        }

        $flights = [];
        $skipped = 0;

        foreach (array_slice($rows, $start) as $row) {
            $date = $this->date($row[$columns['flight_date']] ?? null);
            $net = $this->number($row[$columns['net_value']] ?? null);

            if ($date === null || $net === null) {
                $skipped++;

                continue;
            }

            $flights[] = [
                'season' => isset($columns['season']) ? trim((string) ($row[$columns['season']] ?? '')) : '',
                'status' => isset($columns['status']) ? Str::lower(trim((string) ($row[$columns['status']] ?? ''))) : '',
                'operator' => isset($columns['operator']) ? trim((string) ($row[$columns['operator']] ?? '')) : '',
                'route' => Str::limit(trim((string) ($row[$columns['route'] ?? -1] ?? '')) ?: '-', 60, ''),
                'flight_no' => isset($columns['flight_no']) ? Str::limit(trim((string) ($row[$columns['flight_no']] ?? '')), 60, '') ?: null : null,
                'flight_date' => $date,
                'seats' => isset($columns['seats']) ? (int) ($this->number($row[$columns['seats']] ?? null) ?? 0) ?: null : null,
                'price_per_seat' => isset($columns['price_per_seat']) ? $this->number($row[$columns['price_per_seat']] ?? null) : null,
                'net_value' => $net,
                'taxes' => isset($columns['taxes']) ? ($this->number($row[$columns['taxes']] ?? null) ?? 0.0) : 0.0,
                'pay_date' => isset($columns['pay_date']) ? $this->date($row[$columns['pay_date']] ?? null) : null,
                'taxes_pay_date' => isset($columns['taxes_pay_date']) ? $this->date($row[$columns['taxes_pay_date']] ?? null) : null,
            ];
        }

        if ($flights === []) {
            throw new RuntimeException('Fișierul nu conține rotații cu dată și valoare.');
        }

        $result = DB::transaction(function () use ($flights, $contract, $replace) {
            $contracts = [$contract->season => $contract];
            $touched = [];
            $replaced = 0;
            $imported = 0;

            foreach ($flights as $flight) {
                $season = $flight['season'] !== '' ? $flight['season'] : $contract->season;
                $target = $contracts[$season] ??= CharterContract::query()->where('season', $season)->first()
                    ?? CharterContract::query()->create([
                        'name' => $season.($flight['operator'] !== '' ? ' '.$flight['operator'] : ''),
                        'season' => $season,
                        'status' => str_contains($flight['status'], 'draft') ? CharterContract::STATUS_DRAFT : CharterContract::STATUS_SIGNED,
                        'operator' => $flight['operator'] !== '' ? $flight['operator'] : null,
                        'currency' => $contract->currency,
                        'days_before_flight' => $contract->days_before_flight,
                    ]);

                if ($replace && ! isset($touched[$target->id])) {
                    $replaced += $target->flights()->delete();
                }

                $touched[$target->id] = $target->name;

                CharterFlight::query()->create([
                    'charter_contract_id' => $target->id,
                    'route' => $flight['route'],
                    'flight_no' => $flight['flight_no'],
                    'flight_date' => $flight['flight_date'],
                    'seats' => $flight['seats'],
                    'price_per_seat' => $flight['price_per_seat'],
                    'net_value' => $flight['net_value'],
                    'taxes' => $flight['taxes'],
                    'pay_date' => $flight['pay_date'],
                    'taxes_pay_date' => $flight['taxes_pay_date'],
                ]);
                $imported++;
            }

            return ['imported' => $imported, 'contracts' => array_values($touched), 'replaced' => $replaced];
        });

        return [...$result, 'skipped' => $skipped];
    }

    /**
     * @param  list<list<int|float|string|null>>  $rows
     * @return array{0: array<string, int>, 1: int}
     */
    private function columns(array $rows): array
    {
        foreach (array_slice($rows, 0, 20, true) as $index => $row) {
            $found = [];

            foreach ($row as $position => $cell) {
                $header = Str::lower(trim((string) $cell));

                if ($header === '') {
                    continue;
                }

                foreach (self::HEADERS as $field => $aliases) {
                    if (isset($found[$field])) {
                        continue;
                    }

                    foreach ($aliases as $alias) {
                        if (Str::startsWith($header, $alias)) {
                            $found[$field] = (int) $position;
                            break 2;
                        }
                    }
                }
            }

            if (isset($found['flight_date']) && isset($found['net_value'])) {
                return [$found, $index + 1];
            }
        }

        return [[], 0];
    }

    /**
     * @return list<list<string|null>>
     */
    private function csv(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException('Fișierul nu poate fi citit.');
        }

        $rows = [];
        $first = fgets($handle) ?: '';
        $separator = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        rewind($handle);

        while (($row = fgetcsv($handle, 0, $separator, '"', '\\')) !== false) {
            $rows[] = array_map(fn ($cell) => $cell === '' ? null : $cell, $row);
        }

        fclose($handle);

        return $rows;
    }

    private function date(int|float|string|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value) && (float) $value > 20000 && (float) $value < 80000) {
            return gmdate('Y-m-d', (int) round(((float) $value - 25569) * 86400));
        }

        $text = trim((string) $value);

        foreach (['Y-m-d', 'Y-m-d H:i:s', 'd.m.Y', 'd/m/Y', 'd-m-Y'] as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat($format, $text);

                if ($parsed !== false && $parsed->format($format) === $text) {
                    return $parsed->toDateString();
                }
            } catch (Throwable) {
                continue;
            }
        }

        try {
            return CarbonImmutable::parse($text)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function number(int|float|string|null $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $text = str_replace([' ', "\u{a0}"], '', trim($value));

        if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $text)) {
            $text = str_replace('.', '', $text);
        }

        $text = str_replace(',', '.', $text);

        return is_numeric($text) ? (float) $text : null;
    }
}
