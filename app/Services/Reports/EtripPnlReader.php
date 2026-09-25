<?php

namespace App\Services\Reports;

use App\Services\Etrip\EtripReader;

/**
 * Venitul net și marja din eTrip, pe canal de vânzare și categorie de produs.
 *
 * Cele două axe sunt ortogonale: canalul se citește de pe rezervare (cine a
 * vândut), categoria din compoziția ei (ce s-a vândut). Regulile sunt cele din
 * aplicația de supracomision B2B/B2C, transcrise 1:1 — precedența ramurilor
 * este regula, nu lista: o rezervare aprinde de obicei mai multe flaguri și
 * prima ramură care se potrivește câștigă. Nu reordona ramurile.
 *
 * Banii: venitul net = brut + taxe − comision, costul furnizorului se aduce în
 * moneda rezervării prin `exchange_rate`, iar totul se convertește în lei la
 * cursul BNR din ziua rezervării (`settings.historic_rates`).
 */
class EtripPnlReader
{
    public function __construct(private EtripReader $etrip) {}

    /**
     * Venit net și marjă pe (canal, produs, lună) pentru un an calendaristic,
     * în lei, după data rezervării.
     *
     * @return list<array{channel: string, branch: string, product: string, month: int, bookings: int, net: float, margin: float}>
     */
    public function byChannelAndProduct(int $year, ?string $connection = null): array
    {
        $connection ??= (string) config('pnl.etrip_connection', 'etrip_chr');
        $timezone = (string) config('pnl.timezone', 'Europe/Bucharest');
        $segmentation = (array) config('pnl.segmentation');

        $siteUsers = $this->values($segmentation['site_users'] ?? []);
        $siteLabels = $this->values($segmentation['site_labels'] ?? []);
        $b2bBranches = $this->values($segmentation['b2b_branches'] ?? []);
        $siteBranches = $this->values($segmentation['site_branches'] ?? []);
        $ccBranches = $this->values($segmentation['cc_branches'] ?? []);
        $franchiseBranches = $this->values($segmentation['franchise_branches'] ?? []);
        $otherBranches = $this->values($segmentation['other_branches'] ?? []);
        $operational = $this->values($segmentation['operational_branches'] ?? []);

        $sql = sprintf(
            $this->sql(),
            $this->placeholders($siteUsers),
            $this->placeholders($siteLabels),
            $this->excluded($operational),
            $this->contains('branch_name', $b2bBranches),
            $this->contains('branch_name', $siteBranches),
            $this->contains('branch_name', $ccBranches),
            $this->contains('branch_name', $franchiseBranches),
            $this->exact('branch_name', $otherBranches),
        );

        $bindings = [
            ...array_map($this->normalise(...), $siteUsers),
            ...array_map($this->normalise(...), $siteLabels),
            $timezone, $timezone,
            sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1),
            ...array_map($this->normalise(...), $operational),
            ...array_map($this->normalise(...), $b2bBranches),
            ...array_map($this->normalise(...), $siteBranches),
            ...array_map($this->normalise(...), $ccBranches),
            ...array_map($this->normalise(...), $franchiseBranches),
            ...array_map($this->normalise(...), $otherBranches),
        ];

        $reader = $this->etrip->connection($connection);
        // Citirea obișnuită din eTrip are 30 s; asta trece de ele pe un an întreg.
        $reader->statement('SET statement_timeout = '.(int) config('pnl.statement_timeout_ms', 180000));

        try {
            $rows = $reader->select($sql, $bindings);
        } finally {
            $reader->statement('SET statement_timeout = '.(int) config('etrip.statement_timeout_ms', 30000));
        }

        return array_map(fn ($row) => [
            'channel' => (string) $row->channel,
            'branch' => trim((string) $row->branch_label),
            'product' => (string) $row->product,
            'month' => (int) $row->month,
            'bookings' => (int) $row->bookings,
            'net' => round((float) $row->net, 2),
            'margin' => round((float) $row->margin, 2),
        ], $rows);
    }

    /**
     * Normalizatorul comun (PHP și SQL trebuie să dea același șir, altfel
     * „rezervari site- suport sediu” din config nu prinde „Rezervari Site -
     * Suport Sediu” din eTrip).
     */
    public static function normalise(string $value): string
    {
        $folded = strtr(mb_strtolower(trim($value)), [
            'ă' => 'a', 'â' => 'a', 'à' => 'a', 'î' => 'i', 'ì' => 'i',
            'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
        ]);

        return (string) preg_replace('/[^a-z0-9]/', '', $folded);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function values(array $values): array
    {
        return array_values(array_filter(array_map(fn ($v) => trim((string) $v), $values), fn (string $v) => $v !== ''));
    }

    /**
     * @param  list<string>  $values
     */
    private function placeholders(array $values): string
    {
        return $values === [] ? "''" : implode(',', array_fill(0, count($values), '?'));
    }

    /**
     * Sucursalele de backoffice ies din analiză; fără listă, condiția e mereu
     * adevărată, ca interogarea să rămână validă.
     *
     * @param  list<string>  $values
     */
    private function excluded(array $values): string
    {
        return $values === []
            ? 'true'
            : sprintf('%s NOT IN (%s)', $this->norm('COALESCE(bo.name, \'\')'), $this->placeholders($values));
    }

    /**
     * „numele sucursalei conține unul dintre fragmente”, normalizat.
     *
     * @param  list<string>  $values
     */
    /**
     * „numele sucursalei e chiar unul dintre astea”, normalizat. Se folosește
     * acolo unde un fragment ar prinde prea mult: „Corporate” pe fragment ar
     * lua și o viitoare „Corporate Events”, iar lista asta scoate sucursale
     * din retail, deci greșeala s-ar vedea în cifre.
     *
     * @param  list<string>  $values
     */
    private function exact(string $column, array $values): string
    {
        if ($values === []) {
            return 'false';
        }

        return sprintf('%s IN (%s)', $column, $this->placeholders($values));
    }

    private function contains(string $column, array $values): string
    {
        if ($values === []) {
            return 'false';
        }

        return implode(' OR ', array_fill(0, count($values), sprintf('%s LIKE \'%%\' || ? || \'%%\'', $column)));
    }

    private function norm(string $expression): string
    {
        return sprintf("regexp_replace(lower(translate(%s, 'ăâàîìșşțţ', 'aaaiisstt')), '[^a-z0-9]', '', 'g')", $expression);
    }

    private function sql(): string
    {
        $normBranch = $this->norm('COALESCE(bo.name, \'\')');
        $normVia = $this->norm('COALESCE(dc.name, \'\')');

        return <<<SQL
            WITH site_users AS (
                SELECT u.id FROM settings.users u
                WHERE {$this->norm('u.username')} IN (%s)
            ),
            site_labels AS (
                SELECT sl.id FROM settings.booking_labels sl
                WHERE {$this->norm('sl.label')} IN (%s)
            ),
            sel AS (
                SELECT b.id,
                       EXTRACT(MONTH FROM (b.ctime AT TIME ZONE ?))::int AS month,
                       EXISTS (SELECT 1 FROM clients.trade ct WHERE ct.code = b.client) AS is_b2b,
                       (b.owner IN (SELECT id FROM site_users)
                        OR EXISTS (SELECT 1 FROM bookings.labels bl
                                   WHERE bl.booking = b.id AND bl.label IN (SELECT id FROM site_labels))) AS is_site,
                       {$normBranch} AS branch_name,
                       COALESCE(bo.name, '') AS branch_label,
                       {$normVia} AS booked_via_name,
                       COALESCE(fx.ron_per_unit, 1) AS fx
                FROM bookings.bookings b
                LEFT JOIN settings.branch_offices bo ON bo.id = b.branch
                LEFT JOIN settings.distribution_channels dc ON dc.id = b.booked_via
                LEFT JOIN LATERAL (
                    SELECT hr.rate / NULLIF(hr.multiplier, 0) AS ron_per_unit
                    FROM settings.historic_rates hr
                    WHERE hr.currency = b.currency
                      AND hr.date <= (b.ctime AT TIME ZONE ?)::date
                    ORDER BY hr.date DESC
                    LIMIT 1
                ) fx ON UPPER(b.currency) NOT IN ('RON', 'LEI')
                WHERE b.status = 'confirmed'
                  AND b.ctime >= ?::timestamp AND b.ctime < ?::timestamp
                  AND %s
            ),
            pflags AS (
                SELECT bi.booking,
                       bool_or(fi.item IS NOT NULL) AS has_flight,
                       bool_or(ti.item IS NOT NULL) AS has_transfer,
                       bool_or(ai.item IS NOT NULL) AS has_accommodation,
                       bool_or(sup.name ILIKE '%%memento air%%') AS has_memento_air,
                       bool_or(sup.name ILIKE '%%croazier%%') AS has_cruise,
                       bool_or(sup.name ILIKE '%%circuite exotice%%') AS has_exotic,
                       bool_or(sup.name ILIKE '%%sejururi exotice%%') AS has_sejur_exotic,
                       bool_or((sup.name ILIKE '%%circuite%%' AND sup.name NOT ILIKE '%%circuite exotice%%')
                               OR sup.name ILIKE '%%pelerinaje%%') AS has_cultural,
                       bool_or(sup.name ILIKE '%%senior voyage%%') AS has_senior,
                       bool_or(bui.item IS NOT NULL
                               OR pt.label ILIKE '%%bus%%' OR pt.label ILIKE '%%autocar%%'
                               OR bi.short_description ILIKE '%%autocar%%'
                               OR bi.description ILIKE '%%autocar%%') AS has_bus_charter
                FROM bookings.items bi
                LEFT JOIN flight.items fi ON fi.item = bi.id
                LEFT JOIN transfer.items ti ON ti.item = bi.id
                LEFT JOIN accommodation.items ai ON ai.item = bi.id
                LEFT JOIN bus.items bui ON bui.item = bi.id
                LEFT JOIN public.product_types pt ON pt.id = bi.product_type
                LEFT JOIN suppliers.suppliers sup ON sup.code = bi.supplier
                WHERE bi.client_status = 'confirmed'
                  AND EXISTS (SELECT 1 FROM sel s WHERE s.id = bi.booking)
                GROUP BY bi.booking
            ),
            typed AS (
                SELECT pf.booking,
                       CASE
                         WHEN pf.has_cruise OR EXISTS (
                              SELECT 1 FROM bookings.labels bl
                              JOIN settings.booking_labels sbl ON sbl.id = bl.label
                              WHERE bl.booking = pf.booking AND sbl.label ILIKE '%%croazier%%') THEN 'Croaziere'
                         WHEN pf.has_exotic THEN 'Circuite Exotice'
                         WHEN pf.has_sejur_exotic THEN 'Sejururi Exotice'
                         WHEN pf.has_cultural THEN 'Circuite Culturale'
                         WHEN pf.has_senior THEN 'Senior Voyage'
                         WHEN pf.has_bus_charter AND NOT pf.has_flight THEN 'Charter Bus'
                         WHEN pf.has_transfer AND pf.has_memento_air AND EXISTS (
                              SELECT 1 FROM bookings.labels bl2
                              JOIN settings.booking_labels sbl2 ON sbl2.id = bl2.label
                              WHERE bl2.booking = pf.booking AND sbl2.label ILIKE '%%dinamic memento%%') THEN 'Pachet dinamic'
                         WHEN pf.has_transfer AND pf.has_memento_air THEN 'Charter'
                         WHEN pf.has_transfer THEN 'Marketplace'
                         WHEN pf.has_flight AND NOT pf.has_accommodation THEN 'Ticketing'
                         WHEN NOT pf.has_flight THEN 'Cazare'
                         ELSE '- -'
                       END AS product
                FROM pflags pf
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
            SELECT CASE
                     WHEN s.is_b2b THEN 'b2b'
                     WHEN s.branch_name <> '' AND (%s) THEN 'b2b'
                     WHEN s.is_site THEN 'site'
                     WHEN s.branch_name <> '' AND (%s) THEN
                          CASE WHEN s.booked_via_name LIKE '%%franc%%' THEN 'franciza' ELSE 'retail' END
                     WHEN s.branch_name <> '' AND (%s) THEN 'cc'
                     WHEN s.branch_name <> '' AND (%s) THEN 'franciza'
                     WHEN s.branch_name <> '' AND (%s) THEN 'other'
                     WHEN s.booked_via_name LIKE '%%call%%' THEN 'cc'
                     WHEN s.booked_via_name LIKE '%%franc%%' THEN 'franciza'
                     ELSE 'retail'
                   END AS channel,
                   s.branch_label,
                   COALESCE(t.product, '- -') AS product,
                   s.month,
                   count(*) AS bookings,
                   COALESCE(sum(v.net), 0)::numeric(20,2) AS net,
                   COALESCE(sum(v.margin), 0)::numeric(20,2) AS margin
            FROM sel s
            LEFT JOIN typed t ON t.booking = s.id
            LEFT JOIN val v ON v.booking = s.id
            GROUP BY 1, 2, 3, 4
            SQL;
    }
}
