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
        'season' => ['season', 'sezon'],
        'status' => ['status', 'stare'],
        'operator' => ['operator', 'companie'],
        'route' => ['route', 'ruta', 'rută'],
        'flight_no' => ['flight_no', 'nr zbor', 'nr. zbor', 'zbor', 'flight'],
        'flight_date' => ['flight_date', 'data zbor', 'data', 'date'],
        'seats' => ['seats', 'locuri', 'pax'],
        'price_per_seat' => ['price_seat', 'price_per_seat', 'pret', 'preț', 'price'],
        'net_value' => ['net_value', 'valoare', 'value', 'net'],
        'taxes' => ['taxes_value', 'taxes', 'taxe', 'tax'],
        'pay_date' => ['pay_date', 'data plata rotatie', 'data plată rotație', 'plata rotatie', 'pay date'],
        'taxes_pay_date' => ['taxes_pay_date', 'data plata taxe', 'data plată taxe', 'plata taxe'],
    ];

    /**
     * The flight programme annex of a Memento Air contract: one row per
     * route with the first and last flight, the weekday and the number of
     * rotations, expanded into weekly flights.
     */
    private const ANNEX_HEADERS = [
        'operator' => ['companie aeriana', 'companie aeriană', 'companie'],
        'route' => ['ruta zbor', 'ruta', 'rută'],
        'flight_no' => ['nr. zbor', 'nr zbor', 'zbor'],
        'first' => ['primul zbor', 'primul'],
        'last' => ['ultimul zbor', 'ultimul'],
        'count' => ['numar total zboruri', 'număr total zboruri', 'numar total', 'total zboruri'],
        'weekday' => ['zi de operare', 'zi operare', 'ziua'],
        'seats' => ['numar locuri', 'număr locuri', 'locuri'],
        'price' => ['pret / loc', 'pret/loc', 'preț', 'pret'],
        'taxes_ro' => ['taxe ro'],
        'taxes_dest' => ['taxe destinatie', 'taxe destinație', 'taxe dest'],
    ];

    /**
     * @return array{imported: int, skipped: int, contracts: list<string>, replaced: int}
     */
    public function import(string $path, string $originalName, CharterContract $contract, bool $replace = true, ?string $sheet = null): array
    {
        $rows = Str::endsWith(Str::lower($originalName), '.csv') ? $this->csv($path) : XlsxReader::rows($path, $sheet);
        [$annexColumns, $annexStart] = $this->columns($rows, self::ANNEX_HEADERS, ['first', 'last', 'seats', 'price']);

        if ($annexColumns !== [] && $this->isAnnex($rows, $annexStart - 1)) {
            [$flights, $skipped] = $this->annexFlights(array_slice($rows, $annexStart), $annexColumns, $contract);

            return $this->store($flights, $skipped, $contract, $replace);
        }

        [$columns, $start] = $this->columns($rows, self::HEADERS, ['flight_date', 'net_value']);

        if (! isset($columns['flight_date'], $columns['net_value'])) {
            throw new RuntimeException('Nu am găsit coloanele „Data zbor” și „Valoare netă” (sau anexa cu „Companie Aeriana”) în fișier.');
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
                'operator' => isset($columns['operator']) ? Str::limit(trim((string) ($row[$columns['operator']] ?? '')), 80, '') : '',
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

        return $this->store($flights, $skipped, $contract, $replace);
    }

    /**
     * @param  list<array<string, mixed>>  $flights
     * @return array{imported: int, skipped: int, contracts: list<string>, replaced: int}
     */
    private function store(array $flights, int $skipped, CharterContract $contract, bool $replace): array
    {
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
                    'operator' => $flight['operator'] !== '' ? $flight['operator'] : null,
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
     * Find the header row and map the wanted fields to column positions:
     * an exact header wins over a header that only starts with the alias.
     *
     * @param  list<list<int|float|string|null>>  $rows
     * @param  array<string, list<string>>  $headers
     * @param  list<string>  $required
     * @return array{0: array<string, int>, 1: int}
     */
    private function columns(array $rows, array $headers, array $required): array
    {
        foreach (array_slice($rows, 0, 30, true) as $index => $row) {
            $cells = [];

            foreach ($row as $position => $cell) {
                $header = Str::lower(trim(preg_replace('/\s+/', ' ', (string) $cell) ?? ''));

                if ($header !== '') {
                    $cells[(int) $position] = $header;
                }
            }

            if ($cells === []) {
                continue;
            }

            $found = [];

            foreach ($headers as $field => $aliases) {
                foreach ($aliases as $alias) {
                    $exact = array_search($alias, $cells, true);

                    if ($exact !== false && ! in_array($exact, $found, true)) {
                        $found[$field] = $exact;

                        continue 2;
                    }
                }

                foreach ($aliases as $alias) {
                    foreach ($cells as $position => $header) {
                        if (Str::startsWith($header, $alias) && ! in_array($position, $found, true)) {
                            $found[$field] = $position;

                            continue 3;
                        }
                    }
                }
            }

            if (array_diff($required, array_keys($found)) === []) {
                return [$found, $index + 1];
            }
        }

        return [[], 0];
    }

    /**
     * @param  list<list<int|float|string|null>>  $rows
     */
    private function isAnnex(array $rows, int $headerIndex): bool
    {
        $header = $rows[$headerIndex] ?? [];

        foreach ($header as $cell) {
            if (Str::startsWith(Str::lower(trim((string) $cell)), 'companie')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Expand the annex rows into one flight per weekly rotation: the dates
     * on the operating weekday between the first and the last flight; when
     * their number differs from the rotations announced, the value per
     * flight is scaled so the total stays the one of the annex. The rotation
     * is paid `days_before_flight` days before the flight and the estimated
     * airport taxes in the first week of the following month.
     *
     * @param  list<list<int|float|string|null>>  $rows
     * @param  array<string, int>  $columns
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function annexFlights(array $rows, array $columns, CharterContract $contract): array
    {
        $flights = [];
        $skipped = 0;
        $cell = fn (array $row, string $field) => isset($columns[$field]) ? ($row[$columns[$field]] ?? null) : null;

        foreach ($rows as $row) {
            $first = $this->date($cell($row, 'first'));
            $last = $this->date($cell($row, 'last')) ?? $first;
            $seats = $this->number($cell($row, 'seats'));
            $price = $this->number($cell($row, 'price'));

            if ($first === null || $seats === null || $price === null || $seats <= 0) {
                $skipped++;

                continue;
            }

            $count = (int) ($this->number($cell($row, 'count')) ?? 0);
            $firstDate = CarbonImmutable::parse($first);
            $lastDate = CarbonImmutable::parse($last);
            $weekday = $this->weekday($cell($row, 'weekday')) ?? $firstDate->isoWeekday();
            $dates = [];

            for ($day = $firstDate; $day->lte($lastDate); $day = $day->addDay()) {
                if ($day->isoWeekday() === $weekday) {
                    $dates[] = $day;
                }
            }

            if ($dates === []) {
                $dates = [$firstDate];
            }

            $scale = $count > 0 ? $count / count($dates) : 1.0;
            $taxesPerSeat = ($this->number($cell($row, 'taxes_ro')) ?? 0.0) + ($this->number($cell($row, 'taxes_dest')) ?? 0.0);

            foreach ($dates as $date) {
                $flights[] = [
                    'season' => '',
                    'status' => '',
                    'operator' => Str::limit(trim((string) ($cell($row, 'operator') ?? '')), 80, ''),
                    'route' => Str::limit(trim((string) ($cell($row, 'route') ?? '')) ?: '-', 60, ''),
                    'flight_no' => Str::limit(trim((string) ($cell($row, 'flight_no') ?? '')), 60, '') ?: null,
                    'flight_date' => $date->toDateString(),
                    'seats' => (int) round($seats),
                    'price_per_seat' => $price,
                    'net_value' => round($seats * $price * $scale, 2),
                    'taxes' => round($seats * $taxesPerSeat * $scale, 2),
                    'pay_date' => $date->subDays((int) $contract->days_before_flight)->toDateString(),
                    'taxes_pay_date' => $date->addMonthNoOverflow()->startOfMonth()->addDays(4)->toDateString(),
                ];
            }
        }

        return [$flights, $skipped];
    }

    private function weekday(int|float|string|null $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', (string) $value) ?? '';

        return $digits !== '' && (int) $digits >= 1 && (int) $digits <= 7 ? (int) $digits : null;
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
