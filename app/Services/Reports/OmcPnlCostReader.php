<?php

namespace App\Services\Reports;

use App\Models\Company;
use App\Services\RemoteConnection;

/**
 * Cheltuielile de exploatare din registrul jurnal al OMC, pe lună.
 *
 * Sursa e registrul, nu facturile: pe facturi cu categorie de cheltuială stau
 * și conturi de bilanț (471 cheltuieli în avans, 408 facturi nesosite), iar
 * salariile — cea mai mare cheltuială a companiei — nu apar deloc acolo.
 *
 * Ce se scoate din clasa 6 și de ce:
 *  - **628** e costul serviciilor vândute (hotel, zbor, transfer). eTrip îl
 *    scade deja în marjă, așa că a-l pune și aici l-ar număra de două ori;
 *  - **665 / 666 / 681 / 691** (diferențe de curs, dobânzi, amortizări,
 *    impozit pe profit) stau sub EBITDA, nu în el.
 *
 * Perioada se ia după `data_reg_jurnal`, data notei contabile, nu după data
 * facturii. Diferența nu e mică: o factură plătită în avans intră pe 471 la
 * data ei și se trece pe cheltuială în tranșe lunare, fiecare cu nota ei. Pe
 * data facturii, toate tranșele — inclusiv cele din lunile care n-au venit
 * încă — ar cădea în luna facturii, iar lunile următoare ar rămâne goale. Doar
 * în 2026 sunt peste 5,6 milioane de lei care ar sta în luna greșită.
 *
 * `data_doc` rămâne ce era: identitatea documentului, pentru detaliu și pentru
 * corecturile pe factură.
 */
class OmcPnlCostReader
{
    /** Conturi de clasa 6 care NU sunt cheltuieli de exploatare pentru EBITDA. */
    public const EXCLUDED_ACCOUNTS = ['628', '665', '666', '681', '691'];

    /** Conturile de personal, pentru cheia de repartizare pe masă salarială. */
    public const PAYROLL_ACCOUNTS = ['641', '642', '645', '646', '647', '621'];

    public function __construct(private RemoteConnection $connection) {}

    /**
     * O linie per (cont, sediu, partener, explicație, lună).
     *
     * @return list<array{account: string, sediu: string, partner: string, note: string, month: int, lei: float}>
     */
    public function costs(Company $company, int $year): array
    {
        $excluded = implode(' ', array_map(
            fn (string $prefix) => sprintf("AND j.conts_db NOT LIKE '%s%%'", $prefix),
            self::EXCLUDED_ACCOUNTS,
        ));

        $tripJoin = $this->tripCostsEnabled()
            ? 'LEFT JOIN ('.$this->tripShareSql().') tr ON (tr.data_doc, tr.tip_doc, tr.nr_doc) = (j.data_doc, j.tip_doc, j.nr_doc)'
            : '';
        $tripSelect = $this->tripCostsEnabled() ? 'COALESCE(tr.share, 0)' : '0';
        $window = [sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)];

        /*
         * Documentele care parchează cheltuială pe 471.
         *
         * Notele „Diverși furnizori” scot cheltuieli din conturile de clasa 6
         * și le trec în avans, ca să fie recunoscute în luna în care pleacă
         * efectiv cursa. Rândul scos are drept contrapartidă furnizorul, nu
         * 471 — mutarea se vede numai la nivel de document.
         */
        $parked = sprintf(<<<'SQL'
            LEFT JOIN (
                SELECT data_doc, tip_doc, nr_doc
                FROM reg_jurnal
                WHERE conts_db LIKE '471%%'
                  AND data_reg_jurnal >= '%s'::date AND data_reg_jurnal < '%s'::date
                GROUP BY 1, 2, 3
                HAVING SUM(valoare_lei) > 0
            ) av ON (av.data_doc, av.tip_doc, av.nr_doc) = (j.data_doc, j.tip_doc, j.nr_doc)
            SQL, $window[0], $window[1]);

        $rows = $this->connection->connection($company)->select(<<<SQL
            SELECT BTRIM(j.conts_db) AS account,
                   COALESCE(BTRIM(j.eu_punct_lucru), '') AS sediu,
                   COALESCE(BTRIM(d.partener), '') AS partner,
                   COALESCE(BTRIM(j.explicatie), '') AS note,
                   EXTRACT(MONTH FROM j.data_reg_jurnal)::int AS month,
                   {$tripSelect} AS trip_share,
                   CASE
                       WHEN j.conts_cr LIKE '471%' THEN 'din_avans'
                       WHEN av.nr_doc IS NOT NULL THEN 'in_avans'
                       ELSE 'consum'
                   END AS kind,
                   SUM(j.valoare_lei)::numeric(20,2) AS lei
            FROM reg_jurnal j
            LEFT JOIN doc d ON (d.data_doc, d.tip_doc, d.nr_doc) = (j.data_doc, j.tip_doc, j.nr_doc)
            {$tripJoin}
            {$parked}
            WHERE j.data_reg_jurnal >= ?::date AND j.data_reg_jurnal < ?::date
              AND j.conts_db LIKE '6%'
              {$excluded}
            GROUP BY 1, 2, 3, 4, 5, 6, 7
            SQL,
            [...($this->tripCostsEnabled() ? $window : []), ...$window],
        );

        return array_map(fn ($row) => [
            'account' => (string) $row->account,
            'sediu' => (string) $row->sediu,
            'partner' => (string) $row->partner,
            'note' => (string) $row->note,
            'month' => (int) $row->month,
            'trip_share' => round((float) $row->trip_share, 6),
            // Ce fel de mișcare e: consumul lunii, o cheltuială trecută în
            // avans sau una întoarsă din avans. Vederea operațională le arată
            // pe rânduri deosebite; cea financiară le ține laolaltă, ca în
            // registru.
            'kind' => (string) $row->kind,
            'lei' => round((float) $row->lei, 2),
        ], $rows);
    }

    /**
     * Masa salarială pe punct de lucru și lună.
     *
     * E cheia de repartizare a finanțelor: cât efectiv ține un magazin, nu cât
     * vinde. Pentru un magazin nou sau unul cu un sezon slab, cele două dau
     * răspunsuri diferite, și amândouă sunt de discutat.
     *
     * Analiticul contabil e cel care poartă magazinul (".1.Plaza", ".Sun
     * Plaza"); punctul de lucru e aproape întotdeauna sediul central, fiindcă
     * acolo se face statul de plată.
     *
     * @return list<array{sediu: string, analytic: string, month: int, lei: float}>
     */
    public function payrollByPlace(Company $company, int $year): array
    {
        $accounts = implode(' OR ', array_map(
            fn (string $prefix) => sprintf("j.conts_db LIKE '%s%%'", $prefix),
            self::PAYROLL_ACCOUNTS,
        ));

        $rows = $this->connection->connection($company)->select(<<<SQL
            SELECT COALESCE(BTRIM(j.eu_punct_lucru), '') AS sediu,
                   COALESCE(BTRIM(j.conta_db), '') AS analytic,
                   EXTRACT(MONTH FROM j.data_reg_jurnal)::int AS month,
                   SUM(j.valoare_lei)::numeric(20,2) AS lei
            FROM reg_jurnal j
            WHERE j.data_reg_jurnal >= ?::date AND j.data_reg_jurnal < ?::date
              AND ({$accounts})
            GROUP BY 1, 2, 3
            SQL,
            [sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)],
        );

        return array_map(fn ($row) => [
            'sediu' => (string) $row->sediu,
            'analytic' => (string) $row->analytic,
            'month' => (int) $row->month,
            'lei' => round((float) $row->lei, 2),
        ], $rows);
    }

    /**
     * Cât din fiecare document e cost de cursă, între 0 și 1.
     *
     * Marcajul e al finanțelor: centrele de cost care încep cu „TR” (TR6022
     * combustibil, TR625 diurne, TR635 taxe de drum). Se citește de pe
     * pozițiile documentului, nu din jurnal, fiindcă jurnalul ține contul, nu
     * centrul de cost. O factură cu poziții și pe cursă, și pe sediu se împarte
     * proporțional — în iulie, 48 de documente din 158 sunt amestecate.
     */
    private function tripShareSql(): string
    {
        $prefix = str_replace("'", '', (string) config('pnl.trip_costs.object_prefix', 'TR'));

        return <<<SQL
            SELECT p.data_doc, p.tip_doc, p.nr_doc,
                   SUM(CASE WHEN BTRIM(p.nr_obiect) ILIKE '{$prefix}%' THEN ABS(p.pret * p.cant) ELSE 0 END)
                       / NULLIF(SUM(ABS(p.pret * p.cant)), 0) AS share
            FROM doc_poz p
            WHERE p.data_doc >= ?::date AND p.data_doc < ?::date
            GROUP BY 1, 2, 3
            HAVING SUM(CASE WHEN BTRIM(p.nr_obiect) ILIKE '{$prefix}%' THEN 1 ELSE 0 END) > 0
            SQL;
    }

    private function tripCostsEnabled(): bool
    {
        return (bool) config('pnl.trip_costs.enabled', true);
    }

    /**
     * Înregistrările din spatele unei cheltuieli, document cu document, pentru
     * lunile cerute. Maparea pe linia de P&L se face în PHP, la fel ca în
     * raport, ca detaliul să arate exact ce s-a adunat în celulă.
     *
     * @param  list<int>  $months
     * @return list<array<string, mixed>>
     */
    public function details(Company $company, int $year, array $months): array
    {
        $months = array_values(array_filter(array_map('intval', $months), fn (int $m) => $m >= 1 && $m <= 12));

        if ($months === []) {
            return [];
        }

        $excluded = implode(' ', array_map(
            fn (string $prefix) => sprintf("AND j.conts_db NOT LIKE '%s%%'", $prefix),
            self::EXCLUDED_ACCOUNTS,
        ));
        $monthList = implode(',', array_fill(0, count($months), '?'));

        $tripJoin = $this->tripCostsEnabled()
            ? 'LEFT JOIN ('.$this->tripShareSql().') tr ON (tr.data_doc, tr.tip_doc, tr.nr_doc) = (j.data_doc, j.tip_doc, j.nr_doc)'
            : '';
        $tripSelect = $this->tripCostsEnabled() ? 'COALESCE(tr.share, 0)' : '0';
        $window = [sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)];

        /*
         * Cheltuiala pe care documentul a trecut-o în avans.
         *
         * Notele de tipul „Diverși furnizori” scot cheltuieli din conturile de
         * clasa 6 și le parchează pe 471, ca să fie recunoscute în luna în
         * care pleacă efectiv cursele. Rândul negativ are drept contrapartidă
         * furnizorul (401), nu 471 — mutarea se vede numai la nivel de
         * document. De aceea o aducem de acolo: altfel omul citește un minus
         * fără pereche și crede că raportul greșește.
         *
         * Datele se pun în clar, nu ca parametri: vin dintr-un an întreg.
         */
        $deferred = sprintf(<<<'SQL'
            LEFT JOIN (
                SELECT data_doc, tip_doc, nr_doc, SUM(valoare_lei) AS lei
                FROM reg_jurnal
                WHERE conts_db LIKE '471%%'
                  AND data_reg_jurnal >= '%s'::date AND data_reg_jurnal < '%s'::date
                GROUP BY 1, 2, 3
                HAVING SUM(valoare_lei) > 0
            ) av ON (av.data_doc, av.tip_doc, av.nr_doc) = (j.data_doc, j.tip_doc, j.nr_doc)
            SQL, $window[0], $window[1]);

        $rows = $this->connection->connection($company)->select(<<<SQL
            SELECT j.data_doc::date AS data_doc,
                   COALESCE(BTRIM(j.tip_doc), '') AS tip_doc,
                   COALESCE(BTRIM(j.nr_doc), '') AS nr_doc,
                   BTRIM(j.conts_db) AS account,
                   COALESCE(BTRIM(j.eu_punct_lucru), '') AS sediu,
                   COALESCE(BTRIM(d.partener), '') AS partner,
                   COALESCE(BTRIM(j.explicatie), '') AS note,
                   EXTRACT(MONTH FROM j.data_reg_jurnal)::int AS month,
                   j.data_reg_jurnal::date AS booked,
                   COALESCE(BTRIM(j.conts_cr), '') AS counterpart,
                   COALESCE(av.lei, 0) AS deferred,
                   CASE
                       WHEN j.conts_cr LIKE '471%' THEN 'din_avans'
                       WHEN av.nr_doc IS NOT NULL THEN 'in_avans'
                       ELSE 'consum'
                   END AS kind,
                   {$tripSelect} AS trip_share,
                   COALESCE(BTRIM(d.moneda), '') AS currency,
                   COALESCE(d.curs, 1) AS rate,
                   COALESCE(d.val_mon, 0) AS doc_total,
                   COALESCE(d.val_mon_tva, 0) AS doc_vat,
                   d.data_scadenta::date AS due,
                   d.data_emitere::date AS issued,
                   COALESCE(BTRIM(d.emitent), '') AS issuer,
                   COALESCE(BTRIM(d.obs_txt), '') AS observation,
                   COALESCE(BTRIM(d.partener_punct_lucru), '') AS partner_office,
                   COALESCE(BTRIM(d.conts_doc), '') AS partner_account,
                   COALESCE(BTRIM(d.jurnal), '') AS journal,
                   SUM(j.valoare_lei)::numeric(20,2) AS lei
            FROM reg_jurnal j
            LEFT JOIN doc d ON (d.data_doc, d.tip_doc, d.nr_doc) = (j.data_doc, j.tip_doc, j.nr_doc)
            {$tripJoin}
            {$deferred}
            WHERE j.data_reg_jurnal >= ?::date AND j.data_reg_jurnal < ?::date
              AND EXTRACT(MONTH FROM j.data_reg_jurnal)::int IN ({$monthList})
              AND j.conts_db LIKE '6%'
              {$excluded}
            GROUP BY 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24
            SQL,
            [...($this->tripCostsEnabled() ? $window : []), ...$window, ...$months],
        );

        return array_map(fn ($row) => [
            'data_doc' => (string) $row->data_doc,
            'tip_doc' => (string) $row->tip_doc,
            'nr_doc' => (string) $row->nr_doc,
            'account' => (string) $row->account,
            'sediu' => (string) $row->sediu,
            'partner' => (string) $row->partner,
            'note' => (string) $row->note,
            'month' => (int) $row->month,
            // Nota contabilă care a dus cheltuiala în luna asta, și contul din
            // care a venit: pe 471 se vede că e o tranșă dintr-o plată în avans.
            'booked' => (string) $row->booked,
            'counterpart' => (string) $row->counterpart,
            // Cât a trecut documentul în cheltuieli în avans (471). Pe un rând
            // negativ, asta e perechea care lipsea.
            'deferred' => round((float) $row->deferred, 2),
            'kind' => (string) $row->kind,
            // Cât din document e cost de cursă: partea aia nu e în linie.
            'trip_share' => round((float) $row->trip_share, 6),
            // Factura întreagă, pe lângă felia care a căzut pe linia asta:
            // moneda, cursul, totalul cu TVA, scadența, cine a emis-o.
            'currency' => (string) $row->currency,
            'rate' => round((float) $row->rate, 4),
            'doc_total' => round((float) $row->doc_total, 2),
            'doc_vat' => round((float) $row->doc_vat, 2),
            'due' => $row->due !== null ? (string) $row->due : null,
            'issued' => $row->issued !== null ? (string) $row->issued : null,
            'issuer' => (string) $row->issuer,
            'observation' => (string) $row->observation,
            'partner_office' => (string) $row->partner_office,
            'partner_account' => (string) $row->partner_account,
            'journal' => (string) $row->journal,
            'lei' => round((float) $row->lei, 2),
        ], $rows);
    }

    /**
     * Documentele din spatele cifrelor de sub EBITDA.
     *
     * Amortizarea, rezultatul financiar și impozitul nu trec prin liniile de
     * cheltuială — se adună direct din registru, pe conturile lor — așa că
     * nici detaliul lor nu putea fi cerut ca la celelalte linii. Aici sunt:
     * aceleași coloane ca la o cheltuială obișnuită, ca panoul să le arate la
     * fel, cu reluările scăzute (creditul vine cu minus), fiindcă rândul din
     * raport e net.
     *
     * @param  list<int>  $months
     * @param  'amortizare'|'financiar'|'impozit'  $bucket
     * @return list<array<string, mixed>>
     */
    public function belowDetails(Company $company, int $year, array $months, string $bucket): array
    {
        $months = array_values(array_filter(array_map('intval', $months), fn (int $m) => $m >= 1 && $m <= 12));

        if ($months === [] || ! in_array($bucket, ['amortizare', 'financiar', 'impozit'], true)) {
            return [];
        }

        $documents = array_values(array_filter(array_map(
            fn ($value) => trim((string) $value),
            (array) config('pnl.fx_commission.documents', []),
        ), fn (string $value) => $value !== ''));

        $inDocuments = $documents === []
            ? 'false'
            : sprintf('BTRIM(j.tip_doc) IN (%s)', implode(',', array_fill(0, count($documents), '?')));

        $monthList = implode(',', array_fill(0, count($months), '?'));
        $window = [sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)];

        // Coloanele documentului, aceleași în amândouă jumătățile unirii.
        $columns = <<<'SQL'
            j.data_doc::date AS data_doc,
            COALESCE(BTRIM(j.tip_doc), '') AS tip_doc,
            COALESCE(BTRIM(j.nr_doc), '') AS nr_doc,
            COALESCE(BTRIM(j.eu_punct_lucru), '') AS sediu,
            COALESCE(BTRIM(d.partener), '') AS partner,
            COALESCE(BTRIM(j.explicatie), '') AS note,
            EXTRACT(MONTH FROM j.data_reg_jurnal)::int AS month,
            j.data_reg_jurnal::date AS booked,
            COALESCE(BTRIM(d.moneda), '') AS currency,
            COALESCE(d.curs, 1) AS rate,
            COALESCE(d.val_mon, 0) AS doc_total,
            COALESCE(d.val_mon_tva, 0) AS doc_vat,
            d.data_scadenta::date AS due,
            d.data_emitere::date AS issued,
            COALESCE(BTRIM(d.emitent), '') AS issuer,
            COALESCE(BTRIM(d.obs_txt), '') AS observation,
            COALESCE(BTRIM(d.partener_punct_lucru), '') AS partner_office,
            COALESCE(BTRIM(d.conts_doc), '') AS partner_account,
            COALESCE(BTRIM(d.jurnal), '') AS journal
            SQL;

        $rows = $this->connection->connection($company)->select(<<<SQL
            SELECT bucket, data_doc, tip_doc, nr_doc, account, sediu, partner, note, month, booked,
                   counterpart, currency, rate, doc_total, doc_vat, due, issued, issuer,
                   observation, partner_office, partner_account, journal,
                   SUM(lei)::numeric(20,2) AS lei
            FROM (
                SELECT CASE
                         WHEN j.conts_db LIKE '681%' THEN 'amortizare'
                         WHEN j.conts_db LIKE '691%' OR j.conts_db LIKE '698%' THEN 'impozit'
                         WHEN j.conts_db LIKE '665%' AND {$inDocuments} THEN 'comision_curs'
                         ELSE 'financiar'
                       END AS bucket,
                       substring(BTRIM(j.conts_db) from 1 for 4) AS account,
                       COALESCE(BTRIM(j.conts_cr), '') AS counterpart,
                       {$columns},
                       j.valoare_lei AS lei
                FROM reg_jurnal j
                LEFT JOIN doc d ON (d.data_doc, d.tip_doc, d.nr_doc) = (j.data_doc, j.tip_doc, j.nr_doc)
                WHERE j.data_reg_jurnal >= ?::date AND j.data_reg_jurnal < ?::date
                  AND EXTRACT(MONTH FROM j.data_reg_jurnal)::int IN ({$monthList})
                  AND (j.conts_db LIKE '665%' OR j.conts_db LIKE '666%' OR j.conts_db LIKE '667%'
                       OR j.conts_db LIKE '668%' OR j.conts_db LIKE '681%'
                       OR j.conts_db LIKE '691%' OR j.conts_db LIKE '698%')
                UNION ALL
                SELECT CASE
                         WHEN j.conts_cr LIKE '781%' OR j.conts_cr LIKE '786%' THEN 'amortizare'
                         WHEN j.conts_cr LIKE '765%' AND {$inDocuments} THEN 'comision_curs'
                         ELSE 'financiar'
                       END,
                       substring(BTRIM(j.conts_cr) from 1 for 4),
                       COALESCE(BTRIM(j.conts_db), ''),
                       {$columns},
                       -j.valoare_lei
                FROM reg_jurnal j
                LEFT JOIN doc d ON (d.data_doc, d.tip_doc, d.nr_doc) = (j.data_doc, j.tip_doc, j.nr_doc)
                WHERE j.data_reg_jurnal >= ?::date AND j.data_reg_jurnal < ?::date
                  AND EXTRACT(MONTH FROM j.data_reg_jurnal)::int IN ({$monthList})
                  AND (j.conts_cr LIKE '765%' OR j.conts_cr LIKE '766%' OR j.conts_cr LIKE '767%'
                       OR j.conts_cr LIKE '768%' OR j.conts_cr LIKE '781%' OR j.conts_cr LIKE '786%')
            ) x
            WHERE bucket = ?
            GROUP BY 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22
            SQL,
            [
                ...$documents, ...$window, ...$months,
                ...$documents, ...$window, ...$months,
                $bucket,
            ],
        );

        return array_map(fn ($row) => [
            'data_doc' => (string) $row->data_doc,
            'tip_doc' => (string) $row->tip_doc,
            'nr_doc' => (string) $row->nr_doc,
            'account' => (string) $row->account,
            'sediu' => (string) $row->sediu,
            'partner' => (string) $row->partner,
            'note' => (string) $row->note,
            'month' => (int) $row->month,
            'booked' => (string) $row->booked,
            'counterpart' => (string) $row->counterpart,
            'deferred' => 0.0,
            'kind' => 'consum',
            'trip_share' => 0.0,
            'currency' => (string) $row->currency,
            'rate' => round((float) $row->rate, 4),
            'doc_total' => round((float) $row->doc_total, 2),
            'doc_vat' => round((float) $row->doc_vat, 2),
            'due' => $row->due !== null ? (string) $row->due : null,
            'issued' => $row->issued !== null ? (string) $row->issued : null,
            'issuer' => (string) $row->issuer,
            'observation' => (string) $row->observation,
            'partner_office' => (string) $row->partner_office,
            'partner_account' => (string) $row->partner_account,
            'journal' => (string) $row->journal,
            'lei' => round((float) $row->lei, 2),
        ], $rows);
    }

    /**
     * Cursul BNR al fiecărei monede, pe ultima zi a fiecărei luni din an.
     *
     * @return array<string, array<string, float>> moneda → „2026-09” → curs
     */
    public function monthlyRates(Company $company, int $year): array
    {
        $rows = $this->connection->connection($company)->select(<<<'SQL'
            SELECT DISTINCT ON (BTRIM(c.moneda), date_trunc('month', c.data_curs))
                   BTRIM(c.moneda) AS currency,
                   to_char(date_trunc('month', c.data_curs), 'YYYY-MM') AS month,
                   c.curs_bnr AS rate
            FROM curs c
            WHERE c.data_curs >= ?::date AND c.data_curs < ?::date AND c.curs_bnr > 0
            ORDER BY BTRIM(c.moneda), date_trunc('month', c.data_curs), c.data_curs DESC
            SQL,
            [sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)],
        );

        $rates = [];

        foreach ($rows as $row) {
            $rates[strtoupper((string) $row->currency)][(string) $row->month] = (float) $row->rate;
        }

        return $rates;
    }

    /**
     * Chiria plătită, pe proprietar și pe spațiu, lună de lună.
     *
     * De aici pornește registrul de contracte: OMC știe cui plătim, pentru
     * ce punct de lucru și cât, dar nu și până când ține contractul. Restul
     * se completează o dată, de mână.
     *
     * @param  list<string>  $accounts  prefixele conturilor de chirie
     * @return list<array{lessor: string, location: string, currency: string, months: array<int, float>, total: float}>
     */
    public function rentsByPlace(Company $company, int $year, array $accounts = ['612']): array
    {
        $where = $this->likeAny('BTRIM(j.conts_db)', $accounts);

        $rows = $this->connection->connection($company)->select(<<<SQL
            SELECT COALESCE(BTRIM(d.partener), '') AS lessor,
                   COALESCE(BTRIM(j.eu_punct_lucru), '') AS location,
                   COALESCE(BTRIM(d.moneda), 'Lei') AS currency,
                   EXTRACT(MONTH FROM j.data_reg_jurnal)::int AS month,
                   SUM(j.valoare_lei)::numeric(20,2) AS lei
            FROM reg_jurnal j
            LEFT JOIN doc d ON (d.data_doc, d.tip_doc, d.nr_doc) = (j.data_doc, j.tip_doc, j.nr_doc)
            WHERE ({$where}) AND BTRIM(j.conts_cr) NOT LIKE '121%'
              AND j.data_reg_jurnal >= ?::date AND j.data_reg_jurnal < ?::date
            GROUP BY 1, 2, 3, 4
            SQL,
            [sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)],
        );

        $places = [];

        foreach ($rows as $row) {
            $key = $row->lessor.'|'.$row->location;
            $place = $places[$key] ?? [
                'lessor' => (string) $row->lessor,
                'location' => (string) $row->location,
                'currency' => (string) $row->currency,
                'months' => [],
                'total' => 0.0,
            ];

            $month = (int) $row->month;
            $place['months'][$month] = round(($place['months'][$month] ?? 0.0) + (float) $row->lei, 2);
            $place['total'] = round($place['total'] + (float) $row->lei, 2);
            $places[$key] = $place;
        }

        usort($places, fn (array $a, array $b) => abs($b['total']) <=> abs($a['total']));

        return array_values($places);
    }

    /**
     * Anumite documente, după semnătura lor (data|tip|număr). Se folosesc la
     * corecturile pe document: raportul adună pe grupe, așa că factura mutată
     * de mână se scade din linia ei firească și se adună pe cea aleasă.
     *
     * @param  list<string>  $keys
     * @return list<array{data_doc: string, tip_doc: string, nr_doc: string, account: string, sediu: string, partner: string, note: string, month: int, lei: float}>
     */
    public function documentsByKey(Company $company, int $year, array $keys): array
    {
        $parsed = [];

        foreach ($keys as $key) {
            $parts = explode('|', $key);

            if (count($parts) === 3 && $parts[0] !== '') {
                $parsed[] = $parts;
            }
        }

        if ($parsed === []) {
            return [];
        }

        $excluded = implode(' ', array_map(
            fn (string $prefix) => sprintf("AND j.conts_db NOT LIKE '%s%%'", $prefix),
            self::EXCLUDED_ACCOUNTS,
        ));
        $match = implode(' OR ', array_fill(0, count($parsed), '(j.data_doc = ?::date AND BTRIM(j.tip_doc) = ? AND BTRIM(j.nr_doc) = ?)'));
        $bindings = [sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)];

        foreach ($parsed as $parts) {
            array_push($bindings, ...$parts);
        }

        $rows = $this->connection->connection($company)->select(<<<SQL
            SELECT j.data_doc::date AS data_doc,
                   COALESCE(BTRIM(j.tip_doc), '') AS tip_doc,
                   COALESCE(BTRIM(j.nr_doc), '') AS nr_doc,
                   BTRIM(j.conts_db) AS account,
                   COALESCE(BTRIM(j.eu_punct_lucru), '') AS sediu,
                   COALESCE(BTRIM(d.partener), '') AS partner,
                   COALESCE(BTRIM(j.explicatie), '') AS note,
                   EXTRACT(MONTH FROM j.data_reg_jurnal)::int AS month,
                   SUM(j.valoare_lei)::numeric(20,2) AS lei
            FROM reg_jurnal j
            LEFT JOIN doc d ON (d.data_doc, d.tip_doc, d.nr_doc) = (j.data_doc, j.tip_doc, j.nr_doc)
            WHERE j.data_reg_jurnal >= ?::date AND j.data_reg_jurnal < ?::date
              AND ({$match})
              AND j.conts_db LIKE '6%'
              {$excluded}
            GROUP BY 1, 2, 3, 4, 5, 6, 7, 8
            SQL, $bindings);

        return array_map(fn ($row) => [
            'data_doc' => (string) $row->data_doc,
            'tip_doc' => (string) $row->tip_doc,
            'nr_doc' => (string) $row->nr_doc,
            'account' => (string) $row->account,
            'sediu' => (string) $row->sediu,
            'partner' => (string) $row->partner,
            'note' => (string) $row->note,
            'month' => (int) $row->month,
            'lei' => round((float) $row->lei, 2),
        ], $rows);
    }

    /**
     * Veniturile din exploatare și costul serviciilor vândute, din contabilitate.
     *
     * Vederea operațională ia venitul din eTrip, la data vânzării. Finanțele îl
     * recunosc altfel — la facturare — așa că aici se citește direct din
     * registru: clasa 70 pe credit (minus stornările pe debit) pentru venit,
     * contul 628 pentru costul serviciilor cumpărate de la furnizori.
     *
     * Contul 628 e exact cel scos din cheltuielile de exploatare, ca să nu fie
     * numărat de două ori: acolo e cost al vânzărilor, nu cheltuială generală.
     *
     * Se citește rulajul, nu soldul: venitul de pe credit, costul de pe debit,
     * fără să se scadă unul din altul. La sfârșit de an conturile de venit și
     * de cheltuială se închid — 70x pe debit, 628 pe credit — cu exact aceleași
     * sume, deci o netare ar da zero pe tot anul.
     *
     * @return list<array{bucket: string, month: int, lei: float}>
     */
    public function revenueAndCogs(Company $company, int $year): array
    {
        $rows = $this->connection->connection($company)->select(<<<'SQL'
            SELECT bucket, month, SUM(lei)::numeric(20,2) AS lei FROM (
                SELECT 'revenue' AS bucket, EXTRACT(MONTH FROM j.data_reg_jurnal)::int AS month, j.valoare_lei AS lei
                FROM reg_jurnal j
                WHERE j.data_reg_jurnal >= ?::date AND j.data_reg_jurnal < ?::date AND j.conts_cr LIKE '70%'
                UNION ALL
                SELECT 'other_income', EXTRACT(MONTH FROM j.data_reg_jurnal)::int, j.valoare_lei
                FROM reg_jurnal j
                WHERE j.data_reg_jurnal >= ?::date AND j.data_reg_jurnal < ?::date
                  AND (j.conts_cr LIKE '74%' OR j.conts_cr LIKE '75%')
                UNION ALL
                SELECT 'cogs', EXTRACT(MONTH FROM j.data_reg_jurnal)::int, j.valoare_lei
                FROM reg_jurnal j
                WHERE j.data_reg_jurnal >= ?::date AND j.data_reg_jurnal < ?::date AND j.conts_db LIKE '628%'
            ) x
            GROUP BY 1, 2
            SQL,
            array_merge(...array_fill(0, 3, [sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)])),
        );

        return array_map(fn ($row) => [
            'bucket' => (string) $row->bucket,
            'month' => (int) $row->month,
            'lei' => round((float) $row->lei, 2),
        ], $rows);
    }

    /**
     * Ce stă sub EBITDA, pe lună: amortizarea, rezultatul financiar și
     * impozitul pe profit, plus veniturile care le corespund (reluări de
     * provizioane, diferențe de curs favorabile, dobânzi încasate).
     *
     * Cheltuielile se citesc pe debit, veniturile pe credit, iar veniturile se
     * întorc cu semn negativ: în raport toate rândurile de sub EBITDA se scad,
     * deci un venit trebuie să adauge, nu să taie.
     *
     * Diferențele de curs de pe documentele de încasare de la client ies
     * separat, în găleata `comision_curs`: prețurile sunt în euro, clientul
     * plătește în lei la cursul BNR plus un adaos, iar diferența aia e un
     * comision încasat, nu un rezultat financiar. Raportul o duce sus, în
     * venituri. Reevaluările de sfârșit de lună și plățile către furnizori
     * rămân unde le e locul, în rezultatul financiar.
     *
     * @return list<array{bucket: string, account: string, month: int, lei: float}>
     */
    public function belowEbitda(Company $company, int $year): array
    {
        // Pe metoda financiarului, diferențele de curs rămân toate în
        // rezultatul financiar, iar adaosul se scoate pe urmă, socotit din
        // încasări. Pe metoda veche, le desparte chiar interogarea, după
        // documentul pe care s-au născut.
        $byDocument = self::commissionMethod() === 'documents';

        $documents = $byDocument ? array_values(array_filter(array_map(
            fn ($value) => trim((string) $value),
            (array) config('pnl.fx_commission.documents', []),
        ), fn (string $value) => $value !== '')) : [];

        $inDocuments = $documents === []
            ? 'false'
            : sprintf('BTRIM(j.tip_doc) IN (%s)', implode(',', array_fill(0, count($documents), '?')));

        $rows = $this->connection->connection($company)->select(<<<SQL
            SELECT bucket, account, month, SUM(lei)::numeric(20,2) AS lei FROM (
                SELECT CASE
                         WHEN j.conts_db LIKE '681%' THEN 'amortizare'
                         WHEN j.conts_db LIKE '691%' OR j.conts_db LIKE '698%' THEN 'impozit'
                         WHEN j.conts_db LIKE '665%' AND {$inDocuments} THEN 'comision_curs'
                         ELSE 'financiar'
                       END AS bucket,
                       substring(BTRIM(j.conts_db) from 1 for 4) AS account,
                       EXTRACT(MONTH FROM j.data_reg_jurnal)::int AS month,
                       j.valoare_lei AS lei
                FROM reg_jurnal j
                WHERE j.data_reg_jurnal >= ?::date AND j.data_reg_jurnal < ?::date
                  AND (j.conts_db LIKE '665%' OR j.conts_db LIKE '666%' OR j.conts_db LIKE '667%'
                       OR j.conts_db LIKE '668%' OR j.conts_db LIKE '681%'
                       OR j.conts_db LIKE '691%' OR j.conts_db LIKE '698%')
                UNION ALL
                SELECT CASE
                         WHEN j.conts_cr LIKE '781%' OR j.conts_cr LIKE '786%' THEN 'amortizare'
                         WHEN j.conts_cr LIKE '765%' AND {$inDocuments} THEN 'comision_curs'
                         ELSE 'financiar'
                       END,
                       substring(BTRIM(j.conts_cr) from 1 for 4),
                       EXTRACT(MONTH FROM j.data_reg_jurnal)::int,
                       -j.valoare_lei
                FROM reg_jurnal j
                WHERE j.data_reg_jurnal >= ?::date AND j.data_reg_jurnal < ?::date
                  AND (j.conts_cr LIKE '765%' OR j.conts_cr LIKE '766%' OR j.conts_cr LIKE '767%'
                       OR j.conts_cr LIKE '768%' OR j.conts_cr LIKE '781%' OR j.conts_cr LIKE '786%')
            ) x
            GROUP BY 1, 2, 3
            SQL,
            [
                ...$documents,
                sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1),
                ...$documents,
                sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1),
            ],
        );

        $below = array_map(fn ($row) => [
            'bucket' => (string) $row->bucket,
            'account' => (string) $row->account,
            'month' => (int) $row->month,
            'lei' => round((float) $row->lei, 2),
        ], $rows);

        return $byDocument ? $below : [...$below, ...$this->commissionFromReceipts($company, $year)];
    }

    /** Cum se măsoară adaosul de curs: `receipts`, `documents` sau `none`. */
    private static function commissionMethod(): string
    {
        $method = (string) config('pnl.fx_commission.method', 'receipts');

        return in_array($method, ['receipts', 'documents', 'none'], true) ? $method : 'receipts';
    }

    /**
     * Adaosul de curs, socotit ca financiarul: din ce s-a încasat în lei.
     *
     * Prețul e în euro, clientul plătește în lei la cursul BNR plus două
     * procente, deci din suma încasată adaosul e 2/102. Mutarea se scrie în
     * două rânduri — venitul iese din rezultatul financiar și intră în
     * găleata lui — ca linia de jos să rămână neatinsă: e o reașezare, nu un
     * câștig nou.
     *
     * Dacă financiarul a mutat el partea asta în cifra de afaceri, ea nu se
     * mai socotește a doua oară.
     *
     * @return list<array{bucket: string, account: string, month: int, lei: float}>
     */
    private function commissionFromReceipts(Company $company, int $year): array
    {
        if (self::commissionMethod() !== 'receipts') {
            return [];
        }

        $rate = (float) config('pnl.fx_commission.rate', 0.02);

        if ($rate <= 0) {
            return [];
        }

        $receipts = $this->leiReceipts($company, $year);
        $already = $this->commissionAlreadyInTurnover($company, $year);
        $rows = [];

        // Lunile în care financiarul a mutat el adaosul se iau și ele, chiar
        // dacă ies negative: nota lui din noiembrie acoperă tot anul, iar
        // fără rândul negativ al lunii ăleia, anul ar număra adaosul de două
        // ori. Pe un cumul care se oprește înaintea notei, nu se scade nimic
        // — pe atunci nici nu era mutat.
        foreach (array_unique([...array_keys($receipts), ...array_keys($already)]) as $month) {
            $commission = round(($receipts[$month] ?? 0.0) * $rate / (1 + $rate), 2) - ($already[$month] ?? 0.0);

            if (abs($commission) < 0.005) {
                continue;
            }

            // Sub EBITDA totul se scade, deci un venit se scrie cu minus.
            $rows[] = ['bucket' => 'comision_curs', 'account' => '7651', 'month' => $month, 'lei' => -$commission];
            $rows[] = ['bucket' => 'financiar', 'account' => '7651', 'month' => $month, 'lei' => $commission];
        }

        return $rows;
    }

    /**
     * Ce s-a încasat în lei de la clienți, pe luni: baza adaosului.
     *
     * @return array<int, float>
     */
    public function leiReceipts(Company $company, int $year): array
    {
        $documents = array_values(array_filter(array_map(
            fn ($value) => trim((string) $value),
            (array) config('pnl.fx_commission.receipt_documents', []),
        ), fn (string $value) => $value !== ''));

        if ($documents === []) {
            return [];
        }

        $clients = $this->likeAny('BTRIM(j.conts_cr)', (array) config('pnl.fx_commission.client_accounts', ['4111']));
        $lei = $this->likeAny('BTRIM(j.conts_db)', (array) config('pnl.fx_commission.lei_accounts', ['5121', '5311']));
        $inDocuments = implode(',', array_fill(0, count($documents), '?'));

        $rows = $this->connection->connection($company)->select(<<<SQL
            SELECT EXTRACT(MONTH FROM j.data_reg_jurnal)::int AS month, SUM(j.valoare_lei)::numeric(20,2) AS lei
            FROM reg_jurnal j
            WHERE ({$clients}) AND ({$lei}) AND BTRIM(j.tip_doc) IN ({$inDocuments})
              AND j.data_reg_jurnal >= ?::date AND j.data_reg_jurnal < ?::date
            GROUP BY 1
            SQL,
            [...$documents, sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)],
        );

        $byMonth = [];

        foreach ($rows as $row) {
            $byMonth[(int) $row->month] = round((float) $row->lei, 2);
        }

        return $byMonth;
    }

    /**
     * Cât din adaos a mutat deja financiarul în cifra de afaceri: fie direct
     * (765 → 704), fie prin contul de viramente, cu explicația cunoscută.
     *
     * @return array<int, float>
     */
    public function commissionAlreadyInTurnover(Company $company, int $year): array
    {
        $note = trim((string) config('pnl.fx_commission.reclass_note', ''));
        $window = [sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)];

        $rows = $this->connection->connection($company)->select(<<<'SQL'
            SELECT EXTRACT(MONTH FROM j.data_reg_jurnal)::int AS month, SUM(j.valoare_lei)::numeric(20,2) AS lei
            FROM reg_jurnal j
            WHERE BTRIM(j.conts_db) LIKE '765%' AND BTRIM(j.conts_cr) LIKE '704%'
              AND j.data_reg_jurnal >= ?::date AND j.data_reg_jurnal < ?::date
            GROUP BY 1
            SQL, $window);

        $byMonth = [];

        foreach ($rows as $row) {
            $byMonth[(int) $row->month] = round((float) $row->lei, 2);
        }

        if ($note === '') {
            return $byMonth;
        }

        // Și drumul ocolit, prin 581: nota poartă explicația dată de om.
        $rows = $this->connection->connection($company)->select(<<<'SQL'
            SELECT EXTRACT(MONTH FROM j.data_reg_jurnal)::int AS month, SUM(j.valoare_lei)::numeric(20,2) AS lei
            FROM reg_jurnal j
            WHERE BTRIM(j.conts_db) LIKE '581%' AND BTRIM(j.conts_cr) LIKE '704%'
              AND LOWER(BTRIM(j.explicatie)) LIKE ?
              AND j.data_reg_jurnal >= ?::date AND j.data_reg_jurnal < ?::date
            GROUP BY 1
            SQL, ['%'.mb_strtolower($note).'%', ...$window]);

        foreach ($rows as $row) {
            $byMonth[(int) $row->month] = ($byMonth[(int) $row->month] ?? 0.0) + round((float) $row->lei, 2);
        }

        return $byMonth;
    }

    /**
     * @param  array<int, string>  $prefixes
     */
    private function likeAny(string $column, array $prefixes): string
    {
        $prefixes = array_values(array_filter(array_map(fn ($value) => preg_replace('/[^0-9]/', '', (string) $value), $prefixes)));

        if ($prefixes === []) {
            return 'false';
        }

        return implode(' OR ', array_map(fn (string $prefix) => sprintf("%s LIKE '%s%%'", $column, $prefix), $prefixes));
    }

    /**
     * Cheltuielile scoase din EBITDA, pe cont și lună, ca raportul să poată
     * arăta ce a lăsat afară și de ce.
     *
     * @return list<array{account: string, month: int, lei: float}>
     */
    public function excluded(Company $company, int $year): array
    {
        $included = implode(' OR ', array_map(
            fn (string $prefix) => sprintf("j.conts_db LIKE '%s%%'", $prefix),
            self::EXCLUDED_ACCOUNTS,
        ));

        $rows = $this->connection->connection($company)->select(<<<SQL
            SELECT substring(BTRIM(j.conts_db) from 1 for 3) AS account,
                   EXTRACT(MONTH FROM j.data_reg_jurnal)::int AS month,
                   SUM(j.valoare_lei)::numeric(20,2) AS lei
            FROM reg_jurnal j
            WHERE j.data_reg_jurnal >= ?::date AND j.data_reg_jurnal < ?::date
              AND ({$included})
            GROUP BY 1, 2
            SQL,
            [sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)],
        );

        return array_map(fn ($row) => [
            'account' => (string) $row->account,
            'month' => (int) $row->month,
            'lei' => round((float) $row->lei, 2),
        ], $rows);
    }
}
