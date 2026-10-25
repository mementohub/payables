<?php

namespace App\Services\Forecast;

use App\Services\Etrip\EtripReader;
use App\Services\Reports\EtripPnlReader;

/**
 * Istoricul din care se face planul: câți oameni au plecat, cât au plătit și
 * cât a rămas, pe fiecare categorie de produs și pe fiecare lună.
 *
 * Citirea folosește aceeași împărțire pe produse ca P&L-ul — scrisă o
 * singură dată, în `EtripPnlReader::productCte()` — ca planul să pornească
 * exact de la cifrele raportului, nu de la altele asemănătoare.
 */
class ForecastEtripReader
{
    public function __construct(private EtripReader $etrip) {}

    /**
     * Cum se mută venitul de la data rezervării la data plecării.
     *
     * Vederea operațională spune ce s-a vândut în luna aia; cea financiară
     * spune ce a plecat. Diferența nu e mică — o rezervare făcută în
     * februarie pentru august aduce banii în februarie pe una și în august
     * pe cealaltă — iar tiparul se citește din istoric: din venitul vândut
     * în fiecare lună, cât a plecat în fiecare lună.
     *
     * @return list<array{product: string, booked: int, travelled: int, net: float, margin: float}>
     */
    public function lag(int $year, ?string $connection = null): array
    {
        $connection ??= (string) config('pnl.etrip_connection', 'etrip_chr');
        $timezone = (string) config('pnl.timezone', 'Europe/Bucharest');
        $product = EtripPnlReader::productCte();

        $sql = <<<SQL
            WITH sel AS (
                SELECT b.id, EXTRACT(MONTH FROM (b.ctime AT TIME ZONE ?))::int AS month,
                       COALESCE(fx.ron_per_unit, 1) AS fx
                FROM bookings.bookings b
                LEFT JOIN LATERAL (
                    SELECT hr.rate / NULLIF(hr.multiplier, 0) AS ron_per_unit
                    FROM settings.historic_rates hr
                    WHERE hr.currency = b.currency AND hr.date <= (b.ctime AT TIME ZONE ?)::date
                    ORDER BY hr.date DESC LIMIT 1
                ) fx ON UPPER(b.currency) NOT IN ('RON', 'LEI')
                WHERE b.status = 'confirmed' AND b.ctime >= ?::timestamp AND b.ctime < ?::timestamp
            ),
            {$product},
            travel AS (
                SELECT bi.booking, MIN(bi.start_date) AS start_date
                FROM bookings.items bi
                JOIN sel s ON s.id = bi.booking
                WHERE bi.client_status = 'confirmed' AND bi.start_date IS NOT NULL
                GROUP BY bi.booking
            ),
            val AS (
                SELECT bi.booking,
                       sum(((bi.price).gross + (bi.price).tax - COALESCE((bi.price).commission, 0)) * s.fx) AS net,
                       sum(((((bi.price).gross + (bi.price).tax - COALESCE((bi.price).commission, 0))
                             - (((bi.cost).gross + (bi.cost).tax - COALESCE((bi.cost).commission, 0))
                                / NULLIF(bi.exchange_rate, 0))
                             - COALESCE(bi.toms_vat, 0)) * s.fx)) AS margin
                FROM bookings.items bi
                JOIN sel s ON s.id = bi.booking
                WHERE bi.client_status = 'confirmed'
                GROUP BY bi.booking
            )
            SELECT COALESCE(t.product, '- -') AS product,
                   s.month AS booked,
                   EXTRACT(MONTH FROM tr.start_date)::int AS travelled,
                   COALESCE(sum(v.net), 0)::numeric(20,2) AS net,
                   COALESCE(sum(v.margin), 0)::numeric(20,2) AS margin
            FROM sel s
            JOIN travel tr ON tr.booking = s.id
            LEFT JOIN typed t ON t.booking = s.id
            LEFT JOIN val v ON v.booking = s.id
            GROUP BY 1, 2, 3
            SQL;

        $reader = $this->etrip->connection($connection);
        $reader->statement('SET statement_timeout = '.(int) config('pnl.statement_timeout_ms', 180000));

        $rows = $reader->select($sql, [
            $timezone, $timezone,
            sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1),
        ]);

        return array_map(fn ($row) => [
            'product' => (string) $row->product,
            'booked' => (int) $row->booked,
            'travelled' => (int) $row->travelled,
            'net' => round((float) $row->net, 2),
            'margin' => round((float) $row->margin, 2),
        ], $rows);
    }

    /**
     * Pe categorie și lună: rezervări, pax, venit net și marjă.
     *
     * @return list<array{product: string, month: int, bookings: int, pax: int, net: float, margin: float, charter_pax: int, charter_net: float, charter_margin: float}>
     */
    public function byProduct(int $year, ?string $connection = null): array
    {
        $connection ??= (string) config('pnl.etrip_connection', 'etrip_chr');
        $timezone = (string) config('pnl.timezone', 'Europe/Bucharest');
        $product = EtripPnlReader::productCte();

        $sql = <<<SQL
            WITH sel AS (
                SELECT b.id, EXTRACT(MONTH FROM (b.ctime AT TIME ZONE ?))::int AS month,
                       COALESCE(fx.ron_per_unit, 1) AS fx
                FROM bookings.bookings b
                LEFT JOIN LATERAL (
                    SELECT hr.rate / NULLIF(hr.multiplier, 0) AS ron_per_unit
                    FROM settings.historic_rates hr
                    WHERE hr.currency = b.currency AND hr.date <= (b.ctime AT TIME ZONE ?)::date
                    ORDER BY hr.date DESC LIMIT 1
                ) fx ON UPPER(b.currency) NOT IN ('RON', 'LEI')
                WHERE b.status = 'confirmed' AND b.ctime >= ?::timestamp AND b.ctime < ?::timestamp
            ),
            {$product},
            val AS (
                SELECT bi.booking,
                       sum(((bi.price).gross + (bi.price).tax - COALESCE((bi.price).commission, 0)) * s.fx) AS net,
                       sum(((((bi.price).gross + (bi.price).tax - COALESCE((bi.price).commission, 0))
                             - (((bi.cost).gross + (bi.cost).tax - COALESCE((bi.cost).commission, 0))
                                / NULLIF(bi.exchange_rate, 0))
                             - COALESCE(bi.toms_vat, 0)) * s.fx)) AS margin
                FROM bookings.items bi
                JOIN sel s ON s.id = bi.booking
                WHERE bi.client_status = 'confirmed'
                GROUP BY bi.booking
            ),
            px AS (
                SELECT bi.booking, count(DISTINCT ip.pax) AS pax
                FROM bookings.items bi
                JOIN bookings.item_pax ip ON ip.item = bi.id
                JOIN sel s ON s.id = bi.booking
                WHERE bi.client_status = 'confirmed'
                GROUP BY bi.booking
            ),
            -- Dosarele care stau pe un loc de charter. Ele se plănuiesc pe
            -- cursă, nu pe categorie: dacă s-ar număra în amândouă, venitul
            -- lor ar intra de două ori în plan.
            alloc AS (
                SELECT DISTINCT bi.booking
                FROM bookings.items bi
                JOIN sel s ON s.id = bi.booking
                WHERE bi.uses_allocation AND bi.supplier_status = 'confirmed'
            )
            SELECT COALESCE(t.product, '- -') AS product,
                   s.month,
                   count(*) AS bookings,
                   COALESCE(sum(px.pax), 0)::int AS pax,
                   COALESCE(sum(v.net), 0)::numeric(20,2) AS net,
                   COALESCE(sum(v.margin), 0)::numeric(20,2) AS margin,
                   COALESCE(sum(px.pax) FILTER (WHERE a.booking IS NOT NULL), 0)::int AS charter_pax,
                   COALESCE(sum(v.net) FILTER (WHERE a.booking IS NOT NULL), 0)::numeric(20,2) AS charter_net,
                   COALESCE(sum(v.margin) FILTER (WHERE a.booking IS NOT NULL), 0)::numeric(20,2) AS charter_margin
            FROM sel s
            LEFT JOIN typed t ON t.booking = s.id
            LEFT JOIN val v ON v.booking = s.id
            LEFT JOIN px ON px.booking = s.id
            LEFT JOIN alloc a ON a.booking = s.id
            GROUP BY 1, 2
            SQL;

        $reader = $this->etrip->connection($connection);
        // Planul citește tot anul, cu pasageri cu tot: are nevoie de aceeași
        // răbdare ca raportul, nu de cele treizeci de secunde ale paginilor.
        $reader->statement('SET statement_timeout = '.(int) config('pnl.statement_timeout_ms', 180000));

        $rows = $reader->select($sql, [
            $timezone, $timezone,
            sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1),
        ]);

        return array_map(fn ($row) => [
            'product' => (string) $row->product,
            'month' => (int) $row->month,
            'bookings' => (int) $row->bookings,
            'pax' => (int) $row->pax,
            'net' => round((float) $row->net, 2),
            'margin' => round((float) $row->margin, 2),
            // Cât din ele stă pe locuri de charter, deci se plănuiește pe
            // cursă, nu pe categorie.
            'charter_pax' => (int) $row->charter_pax,
            'charter_net' => round((float) $row->charter_net, 2),
            'charter_margin' => round((float) $row->charter_margin, 2),
        ], $rows);
    }
}
