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

        $rows = $this->connection->connection($company)->select(<<<SQL
            SELECT BTRIM(j.conts_db) AS account,
                   COALESCE(BTRIM(j.eu_punct_lucru), '') AS sediu,
                   COALESCE(BTRIM(d.partener), '') AS partner,
                   COALESCE(BTRIM(j.explicatie), '') AS note,
                   EXTRACT(MONTH FROM j.data_reg_jurnal)::int AS month,
                   {$tripSelect} AS trip_share,
                   SUM(j.valoare_lei)::numeric(20,2) AS lei
            FROM reg_jurnal j
            LEFT JOIN doc d ON (d.data_doc, d.tip_doc, d.nr_doc) = (j.data_doc, j.tip_doc, j.nr_doc)
            {$tripJoin}
            WHERE j.data_reg_jurnal >= ?::date AND j.data_reg_jurnal < ?::date
              AND j.conts_db LIKE '6%'
              {$excluded}
            GROUP BY 1, 2, 3, 4, 5, 6
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
            WHERE j.data_reg_jurnal >= ?::date AND j.data_reg_jurnal < ?::date
              AND EXTRACT(MONTH FROM j.data_reg_jurnal)::int IN ({$monthList})
              AND j.conts_db LIKE '6%'
              {$excluded}
            GROUP BY 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22
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
        $documents = array_values(array_filter(array_map(
            fn ($value) => trim((string) $value),
            (array) config('pnl.fx_commission.documents', []),
        ), fn (string $value) => $value !== ''));

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

        return array_map(fn ($row) => [
            'bucket' => (string) $row->bucket,
            'account' => (string) $row->account,
            'month' => (int) $row->month,
            'lei' => round((float) $row->lei, 2),
        ], $rows);
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
