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
     * Cât a adus fiecare cursă: pasagerii, banii și marja lor, pe luna
     * plecării.
     *
     * Rezervarea se pune la cursa cu care a plecat, nu la fiecare picior al
     * drumului: altfel dusul și întorsul ar număra aceiași oameni de două
     * ori. Banii sunt ai rezervării întregi — pachetul se vinde în jurul
     * zborului, deci acolo îi e locul.
     *
     * Interogarea trece de două minute pe un an: se cheamă din comanda care
     * strânge istoricul, nu dintr-o pagină.
     *
     * @return list<array{destination: string, home: string, month: int, bookings: int, pax: int, net: float, margin: float, flight_cost: float}>
     */
    public function money(int $year, ?string $connection = null): array
    {
        $connection ??= (string) config('pnl.etrip_connection', 'etrip_chr');
        $home = implode(',', array_fill(0, count(self::HOME), '?'));

        $sql = <<<SQL
            WITH outbound AS (
                SELECT DISTINCT ON (bi.booking)
                       bi.booking,
                       il.allocation AS pool,
                       EXTRACT(MONTH FROM il.dep_date)::int AS month
                FROM flight.item_legs il
                JOIN flight.journeys j ON j.id = il.journey
                JOIN bookings.items bi ON bi.id = j.item AND bi.uses_allocation AND bi.supplier_status = 'confirmed'
                WHERE il.dep_date >= ?::date AND il.dep_date < ?::date AND il.allocation IS NOT NULL
                ORDER BY bi.booking, il.dep_date
            ),
            cost AS (
                -- Ce plătim companiei aeriene pentru locurile vândute: din
                -- el ies pragul de rentabilitate și costul locului gol.
                SELECT bi.booking,
                       SUM((((bi.cost).gross + (bi.cost).tax - COALESCE((bi.cost).commission, 0)) / NULLIF(bi.exchange_rate, 0)) * COALESCE(fx.ron_per_unit, 1)) AS flight_cost
                FROM bookings.items bi
                JOIN flight.items fi ON fi.item = bi.id
                JOIN bookings.bookings b ON b.id = bi.booking
                LEFT JOIN LATERAL (
                    SELECT hr.rate / NULLIF(hr.multiplier, 0) AS ron_per_unit
                    FROM settings.historic_rates hr
                    WHERE hr.currency = b.currency AND hr.date <= (b.ctime)::date
                    ORDER BY hr.date DESC LIMIT 1
                ) fx ON UPPER(b.currency) NOT IN ('RON', 'LEI')
                WHERE bi.client_status = 'confirmed' AND bi.uses_allocation
                GROUP BY bi.booking
            ),
            money AS (
                SELECT bi.booking,
                       SUM(((bi.price).gross + (bi.price).tax - COALESCE((bi.price).commission, 0)) * COALESCE(fx.ron_per_unit, 1)) AS net,
                       SUM((((bi.price).gross + (bi.price).tax - COALESCE((bi.price).commission, 0))
                            - (((bi.cost).gross + (bi.cost).tax - COALESCE((bi.cost).commission, 0)) / NULLIF(bi.exchange_rate, 0))
                            - COALESCE(bi.toms_vat, 0)) * COALESCE(fx.ron_per_unit, 1)) AS margin
                FROM bookings.items bi
                JOIN bookings.bookings b ON b.id = bi.booking
                LEFT JOIN LATERAL (
                    SELECT hr.rate / NULLIF(hr.multiplier, 0) AS ron_per_unit
                    FROM settings.historic_rates hr
                    WHERE hr.currency = b.currency AND hr.date <= (b.ctime)::date
                    ORDER BY hr.date DESC LIMIT 1
                ) fx ON UPPER(b.currency) NOT IN ('RON', 'LEI')
                WHERE bi.client_status = 'confirmed' AND b.status = 'confirmed'
                GROUP BY bi.booking
            ),
            px AS (
                SELECT bi.booking, count(DISTINCT ip.pax) AS pax
                FROM bookings.items bi
                JOIN bookings.item_pax ip ON ip.item = bi.id
                WHERE bi.client_status = 'confirmed'
                GROUP BY bi.booking
            )
            SELECT substring(p.label from '^([A-Z]{3})') AS home,
                   substring(p.label from '^[A-Z]{3}.([A-Z]{3})') AS destination,
                   o.month,
                   count(*) AS bookings,
                   COALESCE(SUM(px.pax), 0)::int AS pax,
                   COALESCE(SUM(m.net), 0)::numeric(20,2) AS net,
                   COALESCE(SUM(m.margin), 0)::numeric(20,2) AS margin,
                   COALESCE(SUM(c.flight_cost), 0)::numeric(20,2) AS flight_cost
            FROM outbound o
            JOIN flight.alloc_pools p ON p.id = o.pool
            JOIN money m ON m.booking = o.booking
            LEFT JOIN px ON px.booking = o.booking
            LEFT JOIN cost c ON c.booking = o.booking
            WHERE substring(p.label from '^([A-Z]{3})') IN ({$home})
            GROUP BY 1, 2, 3
            SQL;

        $reader = $this->etrip->connection($connection);
        $reader->statement('SET statement_timeout = '.(int) config('pnl.statement_timeout_ms', 180000));

        $rows = $reader->select($sql, [
            sprintf('%04d-01-01', $year),
            sprintf('%04d-01-01', $year + 1),
            ...self::HOME,
        ]);

        return array_map(fn ($row) => [
            'destination' => (string) ($row->destination ?: '—'),
            'home' => (string) ($row->home ?: '—'),
            'month' => (int) $row->month,
            'bookings' => (int) $row->bookings,
            'pax' => (int) $row->pax,
            'net' => round((float) $row->net, 2),
            'margin' => round((float) $row->margin, 2),
            'flight_cost' => round((float) $row->flight_cost, 2),
        ], $rows);
    }

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
              -- Numai blocurile ferme. Alocările cu zile de release se dau
              -- înapoi companiei aeriene dacă nu se vând, iar ele se vând
              -- prost — în 2026, 14.768 de locuri cu 15,6% ocupare — așa că
              -- ținute în socoteală strică și numărul de locuri, și gradul
              -- de ocupare din care se face planul.
              AND COALESCE(v.release_days, 0) = 0
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
