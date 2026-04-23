<?php

namespace App\Ai\Tools;

use App\Models\Company;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class DescribeSchema implements Tool
{
    public function description(): Stringable|string
    {
        return 'Returnează cheat-sheet-ul schemei PostgreSQL a bazei remote SeniorERP pentru o companie: '
            .'tabele cheie (doc, doc_poz, doc_fin, partener, partener_banca, tip_doc, extrasb), convențiile '
            .'de numire a coloanelor în română (da_nu_*, val_mon_*, tip_doc) și capcanele comune. '
            .'Folosește-l înainte de RunQuery pentru a ști ce coloane să selectezi.';
    }

    public function handle(Request $request): Stringable|string
    {
        $companyId = (int) ($request['company_id'] ?? 0);
        $company = Company::find($companyId);
        if (! $company) {
            return json_encode(['error' => "Company id {$companyId} nu există."]);
        }

        return json_encode([
            'company' => ['id' => $company->id, 'name' => $company->name, 'db' => $company->db_database],
            'schema' => self::CHEATSHEET,
        ], JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'company_id' => $schema->integer()->required()
                ->description('ID-ul companiei Centrofin (din ListCompanies).'),
        ];
    }

    private const CHEATSHEET = <<<'TXT'
    Bază PostgreSQL SeniorERP / WinMentor. Schema: public. Read-only.

    CONVENȚII NUME COLOANE:
    - da_nu_<x> — boolean (da/nu). ex: da_nu_furnizor, da_nu_platitor_tva.
    - val_mon_<x> — sumă în moneda documentului (doc.moneda). ex: val_mon, val_mon_tva, val_mon_pl, val_mon_inc.
    - val_lei — sumă în RON.
    - data_<x> — date/timestamp. data_doc, data_scadenta, data_inchidere (mai mereu NULL!), data_crono, data_repartizare.
    - tip_<x> — categorie / FK într-un dicționar (ex: tip_doc).
    - nr_<x> — identificator (string, nu numeric).
    - scv — ordin linie pe document.
    - proc_<x> — procent.

    TABELE CHEIE:

    `partener` — parteneri (PK = `partener` varchar, numele e cheia).
      - cod_cci (CUI), reg_comert_nr, da_nu_furnizor, da_nu_client, da_nu_platitor_tva,
        tara, localit, adresa, telefon, email_adr, gr_partener.
      - ⚠ da_nu_furnizor / da_nu_client sunt aproape întotdeauna true pe toți partenerii.
        Nu le folosi pentru clasificare! Derivă rolul din doc.tip_doc.

    `partener_banca` — conturi bancare (PK compozit: partener, banca, cont_banca).
      - cont_banca = IBAN. moneda (3 chars). da_nu_implicit. discontinued.
      - banca „-" înseamnă „necunoscut".

    `tip_doc` — dicționar tipuri document. PK = `tip_doc` (max 7 chars).
      - clasa (Comercial | Financiar | Buget | ...).
      - grupa (client | furnizor | incasare | plata | service | ...).
      - denumire_doc, plus flags (incasare_b, plata_b, stoc_intrare, special, ...).
      Valori importante:
        FactCI, FactCE, FactINT  → facturi client (emise)
        FactFI, FactFE           → facturi furnizor (primite)
        OP_PL, Ch_PL, Reg_PL     → plăți (out)
        OP_INC, Ch_INC, Reg_INC, CardINC → încasări (in)
        Comis_B                  → comision bancar
        Cmd_C, Cmd_F             → comenzi
        Contr_C, Contr_F         → contracte

    `doc` — TOATE documentele (facturi, plăți, ...).
      - PK compozit (data_doc, tip_doc, nr_doc). doc_id e intern, NU îl folosi ca join.
      - partener (FK → partener.partener). moneda, curs, val_mon, val_mon_tva.
      - val_mon_pl = total plătit până acum (relevant pt. factură furnizor).
      - val_mon_inc = total încasat până acum (relevant pt. factură client).
      - data_scadenta. data_inchidere e deseori NULL — NU îl folosi drept „plătit".
      - data_doc_baza/tip_doc_baza/nr_doc_baza = document de bază (storno, decont, etc.).
      - eu_punct_lucru (FK → eu_punct_lucru.eu_punct_lucru) = PUNCTUL DE LUCRU propriu pe care e emis/primit documentul (ex. „SEDIUL CENTRAL", „Corporate", „Cluj Iulius Mall"). Populat pe toate facturile.
      - eu_punct_lucru_procesare = punctul de lucru care procesează documentul (poate diferi de cel de emitere).
      - partener_punct_lucru = punctul de lucru al partenerului (FK → partener_punct_lucru(partener, partener_punct_lucru)).
      - gr_doc (FK → gr_doc.gr_doc) = grup document (categorie la nivel de document; dicționar).
      - gr_chelt_ven_postc pe `doc_poz` (nu pe doc) = categoria cheltuială/venit postcalcul per linie.

    `doc_poz` — linii de document. PK (data_doc, tip_doc, nr_doc, scv).
      - articol, detaliu_articol, cant, um, pret, proc_tva.
      - gr_chelt_ven_postc (FK → gr_chelt_ven_postc) = CATEGORIA principală a liniei (cheltuieli/venituri). Ex: „UTILITATI", „TRANSPORT ARAD", „ADMINISTRATIV", „Cheltuieli de publicitate", „Servicii (704)", „B2C", „HQ".
      - loc (FK → loc) = locul de cost (cost center) pe linie.
      - com_int (FK → com_int) = comanda internă asociată liniei.
      - gr_art_contare = grup de contare pe articol.

    `eu_punct_lucru` — PUNCTELE DE LUCRU proprii ale companiei. PK = `eu_punct_lucru` (varchar).
      - tip_punct_lucru (FK → tip_punct_lucru; valori: „Punct de Lucru", „Sediu Social").
      - localit, adresa_punct_lucru, cod_fiscal_punct_lucru.
      - banca_pl + cont_banca_pl → FK eu_banca (contul bancar al PL-ului).
      - conts_decontari + conta_decontari → FK conta (contul contabil pentru decontări al PL-ului).
      - discontinued — PL închis. Filtrează `discontinued=false` pentru activitate curentă.

    `tip_punct_lucru` — dicționar tipuri PL. Valori: „Punct de Lucru", „Sediu Social".
    `partener_punct_lucru` — PK (partener, partener_punct_lucru). Puncte de lucru ale partenerilor.

    `gr_doc` — dicționar grupuri de documente. PK `gr_doc`. Discontinued boolean.
    `gr_chelt_ven_postc` — dicționar CATEGORII cheltuieli/venituri pentru postcalcul. PK `gr_chelt_ven_postc`.
      - gr_chelt_ven_postc_sup (self-FK) = categoria părinte (ierarhie, ex: „SALARII DEPARTAMEN" → „5.2.55.Administrativ").
      - da_nu_chelt_postc = true dacă intră în postcalcul cheltuieli.
      - proc_deductibilitate, activitate, detalii_gr_chelt_ven_postc.
      - Referențiat și de `articol.gr_chelt_ven_postc` și `conta.gr_chelt_ven_postc` — adică și articolele și conturile analitice pot avea o categorie default de cheltuieli/venituri.

    ⚠ REZOLVAREA CATEGORIEI LA TEXT IERARHIC (obligatoriu înainte de a afișa):
    Codurile frunză arată adesea ca numere („1.1.34.1", „2.2.1.48.Timisoara Bega",
    „1.1.49 Hartie A4 TM Bega", „2.2.6.48.Timisoara Bega") și nu le afișa niciodată
    în răspunsul final. În schimb, pentru FIECARE cod rezolvă TOT LANȚUL IERARHIC până
    la rădăcină, unit cu „ → ".

    Rezultat așteptat:
      2.2.6.48.Timisoara Bega → „SALUBRIZAREA → CHELTUIELI UTILITATI → SERVICII SEDII → ADMINISTRATIV"
      2.2.1.48.Timisoara Bega → „ENERGIE ELECTRICA → CHELTUIELI UTILITATI → SERVICII SEDII → ADMINISTRATIV"
      1.1.34.1                → „Rental expenses" (are detalii, fără părinte)
      B2C                     → „B2C" (text, fără părinte)

    Reguli per nod în lanț:
      - Eticheta nodului = `detalii_gr_chelt_ven_postc` dacă e text real (nenull,
        nenul după BTRIM, diferit de codul însuși). Altfel eticheta = `gr_chelt_ven_postc`
        (la părinți codul ESTE numele: „ENERGIE ELECTRICA", „ADMINISTRATIV" etc.).
      - Frunza se OMITE din lanț dacă nu are detalii utile și are părinte (nu vrem
        „2.2.6.48.Timisoara Bega → SALUBRIZAREA → …" — începe direct cu „SALUBRIZAREA").
      - Oprește când parent = node (auto-ciclu, ex. „ADMINISTRATIV" → „ADMINISTRATIV"),
        când parent IS NULL sau la adâncimea 10. Protejează și împotriva ciclurilor
        mai lungi cu un array de noduri deja vizitate.

    Pattern SQL standard (agregare facturi furnizor pe categorii, cale ierarhică):

      WITH RECURSIVE cat_chain AS (
        SELECT
          l.gr_chelt_ven_postc AS leaf,
          l.gr_chelt_ven_postc AS node,
          l.gr_chelt_ven_postc_sup AS parent,
          (CASE
            WHEN BTRIM(COALESCE(l.detalii_gr_chelt_ven_postc, '')) <> ''
             AND BTRIM(l.detalii_gr_chelt_ven_postc) <> l.gr_chelt_ven_postc
              THEN l.detalii_gr_chelt_ven_postc
            WHEN l.gr_chelt_ven_postc_sup IS NULL
              OR l.gr_chelt_ven_postc_sup = l.gr_chelt_ven_postc
              THEN l.gr_chelt_ven_postc
            ELSE NULL       -- frunză-cod: omite, va fi reprezentată de părinte
          END)::text AS label,
          ARRAY[l.gr_chelt_ven_postc::text] AS visited,
          0 AS depth
        FROM gr_chelt_ven_postc l
        UNION ALL
        SELECT c.leaf, g.gr_chelt_ven_postc, g.gr_chelt_ven_postc_sup,
          (CASE
            WHEN BTRIM(COALESCE(g.detalii_gr_chelt_ven_postc, '')) <> ''
             AND BTRIM(g.detalii_gr_chelt_ven_postc) <> g.gr_chelt_ven_postc
              THEN g.detalii_gr_chelt_ven_postc
            ELSE g.gr_chelt_ven_postc
          END)::text,
          c.visited || g.gr_chelt_ven_postc::text,
          c.depth + 1
        FROM cat_chain c
        JOIN gr_chelt_ven_postc g ON g.gr_chelt_ven_postc = c.parent
        WHERE c.parent IS NOT NULL
          AND c.parent <> c.node
          AND NOT (g.gr_chelt_ven_postc::text = ANY(c.visited))
          AND c.depth < 10
      ),
      cat_label AS (
        SELECT leaf,
               string_agg(label, ' → ' ORDER BY depth)
                 FILTER (WHERE label IS NOT NULL) AS category_path
        FROM cat_chain GROUP BY leaf
      )
      SELECT cl.category_path,
             SUM(dp.cant * dp.pret * COALESCE(d.curs, 1)) AS total_lei
      FROM doc d
      JOIN doc_poz dp ON (dp.data_doc, dp.tip_doc, dp.nr_doc)
                       = (d.data_doc, d.tip_doc, d.nr_doc)
      LEFT JOIN cat_label cl ON cl.leaf = dp.gr_chelt_ven_postc
      WHERE d.tip_doc IN ('FactFI','FactFE')
        AND d.data_doc BETWEEN :start AND :end
      GROUP BY cl.category_path ORDER BY total_lei DESC NULLS LAST LIMIT 50;

    PLAN DE CONTURI (chart of accounts):
    `conts` — cont SINTETIC (ex. „411", „4426", „707"). PK `conts` (varchar 7).
      - den_conts = denumirea contului sintetic (ex. „Clienți", „TVA deductibilă", „Venituri din vânzarea mărfurilor").
      - tip_conts = „activ" | „pasiv" | „bifunctional".
      - clasa_conts = clasa contabilă („Financiar", „Comercial", „Buget", ...).
      - da_nu_disponibilitati = cont de disponibilități bănești. da_nu_taxa = cont de taxe (TVA).
      - prez_sold_bal („D"/„C"), da_nu_plata_cont_unic.
    `conta` — cont ANALITIC. PK compozit (conts, conta) — `conta` varchar(25).
      - moneda (3 chars, ex. „Lei", „EUR"). den_conta = denumirea analiticului.
      - gr_chelt_ven_postc — categoria default de cheltuieli/venituri a analiticului (link explicit cont → categorie).
      - conts_profit + conta_profit → cont de profit asociat.
      - eu_punct_lucru_conta — analiticul poate fi legat de un punct de lucru anume.
      - capitol_bugetar, id_ifrs, saft_taxa, saft_taxcode — clasificări pt. raportări.
      - conts_grup + conta_grup = analitic părinte (consolidare analitică).
      - discontinued.

    `doc_fin` — ALOCĂRI plăți ↔ facturi. Fără această tabelă nu poți împerechea un OP cu o FactFI.
      - PK compozit (data_doc_fin, tip_doc_fin, nr_doc_fin, data_doc_com, tip_doc_com, nr_doc_com, data_repartizare, da_nu_garantie).
      - *_com = factura. *_fin = plata/încasarea.
      - val_com = sumă în moneda facturii. val_fin = sumă în moneda plății.
      - data_repartizare = data contabilă a alocării.

    `extrasb` — headerul extrasului bancar. PK (data_extras, banca_eu, cont_banca_eu).
    `eu_banca` — conturile companiei (nu ale partenerilor). PK (banca, cont_banca).

    REGULI PLĂȚI / STATUS FACTURĂ:
    - factură furnizor: compară doc.val_mon_pl cu (doc.val_mon + doc.val_mon_tva).
    - factură client  : compară doc.val_mon_inc cu (doc.val_mon + doc.val_mon_tva).
    - paid: paid >= total - 0.01. unpaid: paid <= 0.009. partial: altfel.

    EXEMPLE:

    -- top 10 furnizori după total facturi FactFI în 2025
    SELECT p.partener, p.cod_cci, SUM(d.val_mon + d.val_mon_tva) AS total
    FROM doc d JOIN partener p ON p.partener = d.partener
    WHERE d.tip_doc IN ('FactFI','FactFE')
      AND d.data_doc BETWEEN '2025-01-01' AND '2025-12-31'
    GROUP BY p.partener, p.cod_cci ORDER BY total DESC LIMIT 10;

    -- cash-flow lunar 2025 (RON): încasări vs plăți
    SELECT date_trunc('month', d.data_doc) AS luna,
           SUM(CASE WHEN td.grupa = 'incasare' THEN d.val_lei ELSE 0 END) AS incasari,
           SUM(CASE WHEN td.grupa = 'plata' THEN d.val_lei ELSE 0 END) AS plati
    FROM doc d JOIN tip_doc td ON td.tip_doc = d.tip_doc
    WHERE d.data_doc BETWEEN '2025-01-01' AND '2025-12-31'
      AND td.clasa = 'Financiar'
    GROUP BY 1 ORDER BY 1;

    -- facturi furnizor restante pe scadență
    SELECT d.partener, d.data_doc, d.data_scadenta, d.nr_doc, d.moneda,
           d.val_mon + d.val_mon_tva AS total, d.val_mon_pl
    FROM doc d
    WHERE d.tip_doc IN ('FactFI','FactFE')
      AND d.val_mon_pl < d.val_mon + d.val_mon_tva - 0.01
      AND d.data_scadenta < CURRENT_DATE
    ORDER BY d.data_scadenta ASC LIMIT 100;

    -- cheltuieli pe categorie (gr_chelt_ven_postc) din facturi furnizor 2025
    SELECT COALESCE(dp.gr_chelt_ven_postc, '(fără categorie)') AS categorie,
           SUM(dp.cant * dp.pret) AS total_val_mon,
           SUM(dp.cant * dp.pret * COALESCE(d.curs, 1)) AS total_lei
    FROM doc d
    JOIN doc_poz dp ON dp.data_doc = d.data_doc AND dp.tip_doc = d.tip_doc AND dp.nr_doc = d.nr_doc
    WHERE d.tip_doc IN ('FactFI','FactFE')
      AND d.data_doc BETWEEN '2025-01-01' AND '2025-12-31'
    GROUP BY 1 ORDER BY total_lei DESC NULLS LAST LIMIT 50;

    -- facturi pe punct de lucru propriu în 2025
    SELECT d.eu_punct_lucru, COUNT(*) AS nr_facturi,
           SUM(d.val_lei + COALESCE(d.val_lei_tva, 0)) AS total_lei
    FROM doc d
    WHERE d.tip_doc IN ('FactCI','FactCE','FactINT','FactFI','FactFE')
      AND d.data_doc BETWEEN '2025-01-01' AND '2025-12-31'
    GROUP BY 1 ORDER BY total_lei DESC LIMIT 50;

    -- conturi analitice după categorie (conta → gr_chelt_ven_postc)
    SELECT c.conts, c.conta, c.den_conta, c.moneda, c.gr_chelt_ven_postc
    FROM conta c
    WHERE c.gr_chelt_ven_postc IS NOT NULL AND c.discontinued = false
    ORDER BY c.conts, c.conta LIMIT 50;

    RECOMANDĂRI:
    - cere întotdeauna o fereastră de timp (data_doc BETWEEN …) când ataci `doc`.
    - folosește COALESCE pe val_mon_* (pot fi NULL).
    - pentru totaluri în RON folosește val_lei, nu val_mon * curs.
    - LIMIT e aplicat automat de RunQuery (max 500 rânduri).
    TXT;
}
