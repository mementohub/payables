<?php

namespace App\Services\Forecast;

use App\Services\Etrip\EtripReader;

/**
 * Locurile de charter: câte am contractat și câte s-au vândut, pe fiecare
 * destinație și lună.
 *
 * Fiecare rotație are două pool-uri — dusul și întorsul — cu aceleași locuri.
 * Se numără doar dusul, adică plecarea dintr-un aeroport din țară: altfel
 * flota ar părea de două ori mai mare decât e.
 *
 * Pentru anul care vine, cifra asta e cea mai tare carte din planificare: nu
 * e o presupunere, ci ce s-a contractat deja cu compania aeriană.
 */
class ForecastCharterReader
{
    /** Aeroporturile de plecare din țară, după codul IATA. */
    public const HOME = ['OTP', 'TSR', 'CLJ', 'IAS', 'SBZ', 'CRA', 'BAY', 'OMR', 'GHV', 'SCV', 'TGM', 'CND', 'ARW', 'BCM', 'SUJ'];

    public function __construct(private EtripReader $etrip) {}

    /**
     * @return list<array{destination: string, home: string, month: int, departures: int, seats: int, sold: int}>
     */
    public function capacity(int $year, ?string $connection = null): array
    {
        $connection ??= (string) config('pnl.etrip_connection', 'etrip_chr');
        $home = implode(',', array_fill(0, count(self::HOME), '?'));

        $sql = <<<SQL
            SELECT substring(p.label from '^([A-Z]{3})') AS home,
                   substring(p.label from '^[A-Z]{3}.([A-Z]{3})') AS destination,
                   EXTRACT(MONTH FROM v.date)::int AS month,
                   count(DISTINCT v.date) AS departures,
                   SUM(v.seats)::int AS seats,
                   SUM(v.sold)::int AS sold
            FROM public.flight_alloc_view v
            JOIN flight.alloc_pools p ON p.id = v.pool
            WHERE v.date >= ?::date AND v.date < ?::date
              AND substring(p.label from '^([A-Z]{3})') IN ({$home})
            GROUP BY 1, 2, 3
            HAVING SUM(v.seats) > 0
            SQL;

        $reader = $this->etrip->connection($connection);
        $rows = $reader->select($sql, [
            sprintf('%04d-01-01', $year),
            sprintf('%04d-01-01', $year + 1),
            ...self::HOME,
        ]);

        return array_map(fn ($row) => [
            'destination' => (string) ($row->destination ?: '—'),
            'home' => (string) ($row->home ?: '—'),
            'month' => (int) $row->month,
            'departures' => (int) $row->departures,
            'seats' => (int) $row->seats,
            'sold' => (int) $row->sold,
        ], $rows);
    }
}
