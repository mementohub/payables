---
name: senior-erp-db
description: Reference for the Romanian SeniorERP-style PostgreSQL database (christiantour and similar company DBs) that Centrofin imports from. Use whenever you need to query, sync, or understand the remote `doc` / `doc_poz` / `partener` / `partener_banca` / `doc_fin` / `tip_doc` tables; when interpreting Romanian column names (`da_nu_*`, `val_mon_*`, `tip_doc`); when mapping invoices, payments, partners, or bank accounts from the remote DB to Centrofin's local tables. Trigger on keywords: SeniorERP, WinMentor, christiantour, doc table, doc_poz, doc_fin, partener, partener_banca, tip_doc, FactFI, FactCI, val_mon, sync invoice, sync furnizor.
---

# SeniorERP-style PostgreSQL source DB

Each Centrofin company connects to its own PostgreSQL database (schema `public`, pgsql 13+). Defaults: `127.0.0.1:5432`, user `root`, encrypted per-company via `App\Models\Company`. The schema follows a Romanian ERP convention (SeniorERP / WinMentor lineage). Column names are Romanian — read this reference before writing SQL.

## Naming conventions

- `da_nu_<x>` — boolean ("yes/no" = `true`/`false`). E.g. `da_nu_furnizor`, `da_nu_platitor_tva`, `da_nu_ue`.
- `val_mon_<x>` — monetary amount in `moneda` (the document's currency). E.g. `val_mon`, `val_mon_tva`, `val_mon_pl`, `val_mon_inc`.
- `val_lei` — monetary amount in RON (always).
- `data_<x>` — date or timestamp. `data_doc` = document date, `data_scadenta` = due date, `data_inchidere` = accounting close date, `data_crono` = creation timestamp, `data_repartizare` = allocation date.
- `tip_<x>` — a category/type (FK to a dictionary table). E.g. `tip_doc`, `tip_deductibilitate`.
- `nr_<x>` — number/identifier (usually a human-readable string, not numeric).
- `scv` — line ordinal (sequence) on a document.
- `proc_<x>` — percentage. E.g. `proc_tva` (VAT rate).
- `cant` — quantity. `um` — unit of measure.

## Key tables

### `partener` — partners (suppliers, clients, etc.)

- **Primary key**: `partener` (varchar(100)) — the partner NAME is the PK. CUI is separate.
- Important columns:
  - `cod_cci` — CUI / fiscal code (Romanian VAT ID).
  - `reg_comert_nr` — trade register number (without the "J" prefix).
  - `da_nu_furnizor`, `da_nu_client`, `da_nu_asigurator`, `da_nu_bugetar`, `da_nu_producator`, `da_nu_deb_cred`, `da_nu_persoana_juridica`, `da_nu_platitor_tva` — role flags.
  - `tara` (3 chars, e.g. `RO`), `localit`, `adresa`, `strada`, `nr_strada`, `bloc`, `scara`, `etaj`, `apt`, `cod_postal_adresa`.
  - `telefon`, `email_adr`, `web_adr`.
  - `gr_partener` — partner group.

**⚠ Gotcha**: `da_nu_furnizor` and `da_nu_client` on `partener` are effectively *both true* for most rows (they mark *potential* role, not actual usage). Do NOT use these flags to classify partners into suppliers vs customers. Instead, derive the role from the `doc.tip_doc` on invoices the partner actually appears on.

### `partener_banca` — bank accounts per partner

- **Primary key**: `(partener, banca, cont_banca)` composite.
- `cont_banca` — IBAN (up to 28 chars).
- `moneda` — currency of the account (3 chars).
- `da_nu_implicit` — default account for its currency.
- `discontinued` — account closed/inactive.
- `banca` — free-text bank name. When unknown, stores `"-"` (literal dash) — treat that as NULL in your UI.

### `tip_doc` — document type dictionary

- **Primary key**: `tip_doc` (varchar(7)).
- Classification columns:
  - `clasa` — one of `Comercial`, `Financiar`, `Buget`, etc.
  - `grupa` — one of `client`, `furnizor`, `incasare`, `plata`, `Buget`, `service`.
  - `denumire_doc` — human-readable Romanian label.
- Behavioral flags: `incasare_b`, `plata_b`, `incasare_c`, `plata_c`, `stoc_intrare`, `stoc_iesire`, `da_nu_efect_financiar`, `special`, etc.

**Important tip_doc values you'll encounter:**

| `tip_doc` | `clasa`   | `grupa`   | Meaning                                    |
| --------- | --------- | --------- | ------------------------------------------ |
| `FactCI`  | Comercial | client    | Factură Client Intern (outgoing, domestic) |
| `FactCE`  | Comercial | client    | Factură Client Extern (outgoing, export)   |
| `FactINT` | Comercial | client    | Facturi de întocmit (invoices to issue)    |
| `FactFI`  | Comercial | furnizor  | Factură Furnizor Intern (incoming)         |
| `FactFE`  | Comercial | furnizor  | Factură Furnizor Extern (incoming import)  |
| `AVZ_F`   | Comercial | furnizor  | Aviz furnizor (delivery note)              |
| `Cmd_C`   | Comercial | client    | Client order                               |
| `Cmd_F`   | Comercial | furnizor  | Supplier order                             |
| `Contr_C` | Comercial | client    | Client contract                            |
| `Contr_F` | Comercial | furnizor  | Supplier contract                          |
| `B_Casa`  | Comercial | client    | Cash receipt                               |
| `OP_PL`   | Financiar | plata     | Payment order (out)                        |
| `OP_INC`  | Financiar | incasare  | Payment order (in, bank collection)        |
| `Ch_PL`   | Financiar | plata     | Cash payment                               |
| `Ch_INC`  | Financiar | incasare  | Cash collection                            |
| `Reg_PL`  | Financiar | plata     | Bank register payment                      |
| `Reg_INC` | Financiar | incasare  | Bank register collection                   |
| `CardINC` | Financiar | incasare  | Card collection                            |
| `Comis_B` | Financiar | —         | Bank commission                            |
| `DP_Casa` | Financiar | —         | Cash deposit                               |
| `DI_Casa` | Financiar | —         | Cash withdrawal                            |
| `Dob_INC` | Financiar | incasare  | Interest collection                        |
| `TichVac` | Financiar | incasare  | Vacation voucher                           |
| `DVI`     | Comercial | Buget     | Declarație Vamală de Import                |
| `DVE`     | Comercial | Buget     | Declarație Vamală de Export                |
| `Decont`  | —         | —         | Expense settlement                         |
| `NotaC`   | —         | —         | Accounting note                            |

**Quick filters:**

- Invoices received from suppliers → `tip_doc IN ('FactFI','FactFE')`.
- Invoices issued to clients → `tip_doc IN ('FactCI','FactCE','FactINT')`.

### `doc` — all documents (invoices, payments, receipts, etc.)

- **Primary key**: `(data_doc, tip_doc, nr_doc)` composite — there is no single-column invoice ID. `doc_id` exists but is internal sequence; always prefer the composite key for joins.
- Core columns you'll use:
  - `partener` — FK to `partener.partener`.
  - `moneda` — document currency (`Lei`, `EUR`, `USD`, ...).
  - `curs` — exchange rate to RON at document date.
  - `val_mon` — net amount in `moneda`.
  - `val_mon_tva` — VAT in `moneda`.
  - `val_mon_pl` — total **paid** so far, in `moneda` (relevant for `grupa = furnizor` invoices).
  - `val_mon_inc` — total **collected** so far, in `moneda` (relevant for `grupa = client` invoices).
  - `val_mon_pl_previz`, `val_mon_inc_previz` — previsional (expected) amounts.
  - `data_scadenta` — due date.
  - `data_inchidere` — accounting close date (mostly NULL in practice — not a reliable paid indicator).
  - `data_crono` — creation timestamp.
  - `data_doc_baza`, `tip_doc_baza`, `nr_doc_baza` — reference back to a "base" document (e.g. a Ch_PL linked to a Decont, or a credit note referring to the original invoice). NOT used to pair payments with commercial invoices — see `doc_fin` for that.
  - `emitent` — issuer name (free text).
  - `partener_punct_lucru`, `partener_persoana_contact`, `partener_platitor_tva` — snapshot of partner state at issue time.
  - Many more (VAT treatment, delivery, customs, stock, etc.) — consult the table when needed; most are optional.

### `doc_poz` — document line items (invoice rows)

- **Primary key**: `(data_doc, tip_doc, nr_doc, scv)` composite. `scv` is the 1-based line ordinal.
- Columns:
  - `articol` — item name / code.
  - `detaliu_articol` — free-text description.
  - `cant` — quantity.
  - `um` — unit of measure.
  - `pret` — unit price in `moneda`.
  - `proc_tva` — VAT rate %.
  - `tip_tratare_tva` — VAT treatment code.
  - `loc`, `com_int`, `gr_chelt_ven_postc` — cost-center / internal allocation.

### `doc_fin` — payment/collection allocations

This is the **pairing table** between commercial invoices and financial documents. One row = "this payment/collection was allocated against that invoice, on this date, for this amount".

- **Primary key**: `(data_doc_fin, tip_doc_fin, nr_doc_fin, data_doc_com, tip_doc_com, nr_doc_com, data_repartizare, da_nu_garantie)`.
- `*_com` = commercial doc (the invoice — typically `tip_doc_com IN ('FactFI','FactFE','FactCI','FactCE','FactINT')`).
- `*_fin` = financial doc (the payment — typically `tip_doc_fin IN ('OP_PL','OP_INC','Ch_PL','Ch_INC','Reg_PL','Reg_INC','CardINC','Comis_B')`).
- `val_com` — amount allocated, **in the invoice's currency**.
- `val_fin` — amount in the payment document's currency (can differ from `val_com` on FX payments).
- `data_repartizare` — accounting allocation date (often equals `data_doc_fin`; what shows up on the ledger).
- `val_dif_curs` — FX difference.
- `da_nu_garantie` — payment against a retention/guarantee.

**Example — all payments applied to one furnizor invoice:**

```sql
SELECT df.data_repartizare, df.data_doc_fin, df.tip_doc_fin, df.nr_doc_fin,
       df.val_com, df.val_fin, d.moneda AS fin_moneda
FROM doc_fin df
LEFT JOIN doc d
  ON d.data_doc = df.data_doc_fin
 AND d.tip_doc  = df.tip_doc_fin
 AND d.nr_doc   = df.nr_doc_fin
WHERE df.data_doc_com = :data_doc
  AND df.tip_doc_com  = :tip_doc
  AND df.nr_doc_com   = :nr_doc
ORDER BY df.data_repartizare;
```

### `extrasb` — bank statement headers

- **Primary key**: `(data_extras, banca_eu, cont_banca_eu)`.
- `banca_eu` + `cont_banca_eu` reference `eu_banca(banca, cont_banca)` (the *company's own* bank accounts — not to be confused with `partener_banca` which is for partners).
- `operator` = user who imported the statement.

**Statement lines** are `doc` rows where `(d.data_contab, d.banca_eu, d.cont_banca_eu) = (e.data_extras, e.banca_eu, e.cont_banca_eu)` — this FK is enforced. Typical `tip_doc` values in `doc` lines: `OP_INC`, `OP_PL`, `Ch_INC`, `Ch_PL`, `Reg_INC`, `Reg_PL`, `CardINC`, `Comis_B`, `Dob_INC`, `FV_B`, `FV_C`.

**Unallocated receipts/payments:** a statement line (a `doc` row, financial doc) is "unallocated" when the sum of `doc_fin.val_fin` for rows where `(df.data_doc_fin, df.tip_doc_fin, df.nr_doc_fin)` matches the line is less than the line's `val_mon`. This is how you flag orphan receipts (money came in but isn't matched to any issued invoice) or orphan payments (money went out without being matched to a received invoice).

Example — pull lines + allocated total per line for one statement:

```sql
SELECT d.data_doc, d.tip_doc, d.nr_doc, d.partener, d.moneda, d.val_mon,
       COALESCE(SUM(df.val_fin), 0) AS val_allocated
FROM doc d
LEFT JOIN doc_fin df
  ON df.data_doc_fin = d.data_doc
 AND df.tip_doc_fin  = d.tip_doc
 AND df.nr_doc_fin   = d.nr_doc
WHERE d.data_contab = :data_extras
  AND d.banca_eu    = :banca
  AND d.cont_banca_eu = :iban
GROUP BY d.data_doc, d.tip_doc, d.nr_doc, d.partener, d.moneda, d.val_mon;
```

### `eu_banca` — company's own bank accounts

- PK: `(banca, cont_banca)`.
- `moneda`, `da_nu_implicit`, `discontinued`, `eu_punct_lucru`, `conts`+`conta` (chart-of-accounts binding), `jurnal`.

### `eu_punct_lucru` — **puncte de lucru proprii ale companiei**

- PK: `eu_punct_lucru` (varchar(50)) — numele e cheia (ex. `SEDIUL CENTRAL`, `Corporate`, `Cluj Iulius Mall`, `BRASOV`, …).
- `tip_punct_lucru` → FK `tip_punct_lucru.tip_punct_lucru`. În `christiantour` valorile observate sunt doar `Punct de Lucru` și `Sediu Social` (dicționar `tip_punct_lucru` foarte simplu: doar numele + `discontinued`).
- Alte coloane utile: `localit`, `adresa_punct_lucru`, `cod_fiscal_punct_lucru`, `banca_pl`+`cont_banca_pl` (FK → `eu_banca`), `conts_decontari`+`conta_decontari` (FK → `conta`, contul contabil al PL-ului), `eu_punct_lucru_procesare` (PL de procesare default), `discontinued`.
- Volum (christiantour snapshot): 583 PL-uri (576 `Punct de Lucru` + 7 `Sediu Social`). Multe sunt aparent „PL-uri logice" create pentru o cheltuială specifică (ex. `Abonament Fortigate`), nu doar sedii fizice — nu presupune că `eu_punct_lucru` reflectă doar locații.

**Unde apar PL-urile pe documente:**

| Coloană | În | Ce înseamnă |
| --- | --- | --- |
| `doc.eu_punct_lucru` | `doc` (obligatoriu, NOT NULL) | PL-ul propriu pe care se emite/primește documentul |
| `doc.eu_punct_lucru_procesare` | `doc` (nullable) | PL-ul care procesează doc-ul (poate diferi) |
| `doc.partener_punct_lucru` | `doc` (nullable) | PL-ul partenerului asociat doc-ului (FK compozit `(partener, partener_punct_lucru)` → `partener_punct_lucru`) |
| `eu_banca.eu_punct_lucru` | `eu_banca` | PL-ul de care ține contul bancar propriu |
| `conta.eu_punct_lucru_conta` | `conta` | Analiticul poate fi legat de un PL anume |

### `gr_doc` — dicționar grupuri de document

- PK: `gr_doc` (varchar(20)). Singura altă coloană: `discontinued`. Pe `doc.gr_doc` se păstrează grupul la nivel de document (categorie grosieră, pe deasupra lui `tip_doc`).

### `gr_chelt_ven_postc` — **categoria „business" pentru postcalcul cheltuieli / venituri**

- PK: `gr_chelt_ven_postc` (varchar(25)).
- `gr_chelt_ven_postc_sup` (self-FK) — categoria părinte; permite ierarhii (ex. `SALARII DEPARTAMEN` → `5.2.55.Administrativ`, `5.2.56.B2B`, …).
- `da_nu_chelt_postc` boolean — intră în postcalcul cheltuieli.
- `proc_deductibilitate`, `activitate`, `ordonare`, `detalii_gr_chelt_ven_postc`, `discontinued`.
- Volum (christiantour): 5 711 categorii, 5 697 marcate `da_nu_chelt_postc=true`.

**Aceasta este „categoria" pentru facturi în acest ERP.** Exemple din `christiantour`: `UTILITATI`, `TRANSPORT ARAD`, `ADMINISTRATIV`, `Cheltuieli de publicitate`, `Servicii (704)`, `B2C`, `HQ`, `Venituri anticipate`, `Cheltuieli anticipate`.

**Unde apar categoriile:**

| Coloană | În | Ce înseamnă |
| --- | --- | --- |
| `doc_poz.gr_chelt_ven_postc` | `doc_poz` | **CATEGORIA per linie de factură** — punctul principal de clasificare |
| `doc_poz.loc` | `doc_poz` | FK → `loc`, centru de cost |
| `doc_poz.com_int` | `doc_poz` | FK → `com_int`, comanda internă |
| `doc_poz.gr_art_contare` | `doc_poz` | FK → `gr_art_contare`, grup contare articol |
| `articol.gr_chelt_ven_postc` | `articol` | Categorie default pe articol |
| `conta.gr_chelt_ven_postc` | `conta` | Categorie default pe contul analitic |

Notă: pe `doc` direct NU există o coloană unică de „categorie" — o factură poate avea linii cu categorii diferite. Pentru raportări pe categorie, agregă pe `doc_poz.gr_chelt_ven_postc`.

### `conts` / `conta` — planul de conturi

- `conts` — cont **sintetic** (chart of accounts synthetic). PK `conts` (varchar(7), ex. `411`, `4426`, `707`).
  - `den_conts` — denumire (ex. „Clienți", „TVA deductibilă", „Venituri din vânzarea mărfurilor").
  - `tip_conts` — `activ` | `pasiv` | `bifunctional`.
  - `clasa_conts` — `Financiar` | `Comercial` | `Buget` | … (clasa contabilă, FK → `clasa_conts`).
  - `da_nu_disponibilitati` — cont de disponibilități (cash/bank).
  - `da_nu_taxa` — cont de taxe (TVA).
  - `prez_sold_bal` (`D`/`C`), `da_nu_plata_cont_unic`, `discontinued`.
- `conta` — cont **analitic**. PK compozit `(conts, conta)`; `conta` varchar(25).
  - `moneda` — moneda contului (`Lei`, `EUR`, `USD`, …).
  - `den_conta` — denumire analitic.
  - `gr_chelt_ven_postc` — **legătura explicită cont ↔ categorie de cheltuieli/venituri**.
  - `conts_profit` + `conta_profit` — contul de profit asociat (self-FK).
  - `conts_grup` + `conta_grup` — analitic părinte, pt. consolidări.
  - `eu_punct_lucru_conta` — analiticul poate fi legat de un PL (FK → `eu_punct_lucru`).
  - `capitol_bugetar`, `id_ifrs`, `saft_taxa`, `saft_taxcode` — coduri pt. raportări externe (buget, IFRS, SAF-T).
  - `discontinued`.

### Other tables seen in the schema (mostly ignored for sync)

- `doc_comp` — has a similar shape for compensation pairing but appears **empty/unused** in christiantour; do not rely on it.
- `doc_text` — free-text notes attached to a doc.
- `doc_scadenta` — scheduled payment tranches (installment plans).
- `partener_contact`, `partener_punct_lucru`, `partener_departament` — extra partner detail.
- `banca`, `moneda`, `tara`, `tva` — lookup/dictionary tables.
- `loc`, `com_int`, `gr_art_contare`, `capitol_bugetar`, `conta_ifrs` — auxiliary dictionaries used by `doc_poz` / `conta`.
- Thousands of other tables — ignore unless explicitly needed.

## Payment-status logic

Don't introduce a dedicated "paid" boolean from the remote DB — there isn't a reliable one. Derive status locally:

- **Furnizor invoice** (`tip_doc IN ('FactFI','FactFE')`): look at `doc.val_mon_pl`.
- **Client invoice** (`tip_doc IN ('FactCI','FactCE','FactINT')`): look at `doc.val_mon_inc`.
- Status:
  - `paid` — `paid_amount + 0.01 >= val_mon + val_mon_tva`
  - `unpaid` — `paid_amount <= 0.009`
  - `partial` — otherwise
- For the full breakdown (which payment, when, how much), join `doc_fin` as shown above.

## Partner-role logic

Because `partener.da_nu_furnizor` / `da_nu_client` are over-permissive, in Centrofin:

```php
$role = in_array($row->tip_doc, ['FactFI','FactFE'], true) ? 'furnizor' : 'client';
```

Then OR the role flags with prior values so earlier syncs aren't overwritten:

```php
'is_furnizor' => $role === 'furnizor' || (bool) $prior?->is_furnizor,
'is_client'   => $role === 'client'   || (bool) $prior?->is_client,
```

## Volumes (christiantour, snapshot)

Use these to size queries / pagination / job timeouts.

| Table / filter                         | Rows      |
| -------------------------------------- | --------- |
| `doc` WHERE `tip_doc = 'FactCI'`       | 2,261,907 |
| `doc` WHERE `tip_doc = 'OP_INC'`       | 1,300,708 |
| `doc` WHERE `tip_doc = 'Ch_INC'`       |   825,642 |
| `doc` WHERE `tip_doc = 'FactFI'`       |   434,457 |
| `doc` WHERE `tip_doc = 'OP_PL'`        |   344,047 |
| `doc_fin`                              | 2,578,789 |
| `doc_comp`                             |         1 |

**Always sync with a date range.** Never pull the full `doc` table without filtering `data_doc`.

## Centrofin sync mapping (`App\Services\SyncService`)

| Remote table       | Local table              | Scope / key                                                                 |
| ------------------ | ------------------------ | --------------------------------------------------------------------------- |
| `doc`              | `invoices`               | `tip_doc IN ('FactFI','FactFE','FactCI','FactCE','FactINT')`, filtered date |
| `doc_poz`          | `invoice_details`        | Joined by `(data_doc, tip_doc, nr_doc)`                                     |
| `partener`         | `partners`               | Only partners referenced by synced invoices                                 |
| `partener_banca`   | `partner_bank_accounts`  | Only for partners that are furnizori in the sync window                     |
| `doc_fin`          | `invoice_payments`       | `tip_doc_com` matches sync scope, `data_doc_com` in range; cascade-deletes local rows before re-inserting to handle unallocations |
| `extrasb`          | `bank_statements`        | All statements whose `data_extras` falls in the sync window                 |
| `doc` (statement lines) + `doc_fin` (allocations) | `bank_statement_lines`   | Joined via `(data_contab, banca_eu, cont_banca_eu)`; `val_allocated` = Σ `doc_fin.val_fin`; direction derived from `tip_doc` grupa (`incasare` → incoming, `plata` → outgoing). Lines are deleted and re-inserted per sync. |

## Categorii & puncte de lucru — cheat-sheet

**Găsirea categoriei unei facturi (la nivel de linie):**

```sql
SELECT d.data_doc, d.tip_doc, d.nr_doc, dp.scv, dp.articol,
       dp.gr_chelt_ven_postc AS categorie, gcv.gr_chelt_ven_postc_sup AS categorie_parinte,
       dp.loc, dp.com_int
FROM doc d
JOIN doc_poz dp  ON (dp.data_doc, dp.tip_doc, dp.nr_doc) = (d.data_doc, d.tip_doc, d.nr_doc)
LEFT JOIN gr_chelt_ven_postc gcv ON gcv.gr_chelt_ven_postc = dp.gr_chelt_ven_postc
WHERE d.data_doc = :data_doc AND d.tip_doc = :tip_doc AND d.nr_doc = :nr_doc
ORDER BY dp.scv;
```

**Agregare cheltuieli pe categorie (factură furnizor, 2025):**

```sql
SELECT COALESCE(dp.gr_chelt_ven_postc, '(fără categorie)') AS categorie,
       SUM(dp.cant * dp.pret) AS total_mon,
       SUM(dp.cant * dp.pret * COALESCE(d.curs, 1)) AS total_lei
FROM doc d
JOIN doc_poz dp ON (dp.data_doc, dp.tip_doc, dp.nr_doc) = (d.data_doc, d.tip_doc, d.nr_doc)
WHERE d.tip_doc IN ('FactFI','FactFE')
  AND d.data_doc BETWEEN '2025-01-01' AND '2025-12-31'
GROUP BY 1 ORDER BY total_lei DESC NULLS LAST LIMIT 50;
```

> ⚠ `dp.gr_chelt_ven_postc` conține multe coduri-numerice (`1.1.34.1`, `2.2.1.48.Timisoara Bega`, `1.1.49 Hartie A4 TM Bega`) care nu spun nimic unui om. Niciodată nu le afișa direct în UI / chat. Rezolvă-le la text — vezi secțiunea următoare.

**Rezolvarea codului la CALE IERARHICĂ COMPLETĂ (format preferat):**

Regulă de aur pentru UI / chat: nu afișa niciodată un cod brut (`2.2.6.48.Timisoara Bega`, `1.1.34.1`, …). În locul lui produce calea ierarhică completă de la prima etichetă citibilă până la rădăcină, unite cu `→`:

| Cod (`gr_chelt_ven_postc`) | Rezultat afișat |
| --- | --- |
| `2.2.6.48.Timisoara Bega` | `SALUBRIZAREA → CHELTUIELI UTILITATI → SERVICII SEDII → ADMINISTRATIV` |
| `2.2.1.48.Timisoara Bega` | `ENERGIE ELECTRICA → CHELTUIELI UTILITATI → SERVICII SEDII → ADMINISTRATIV` |
| `1.1.34.1` | `Rental expenses` (are `detalii`, fără părinte) |
| `B2C` | `B2C` (text, fără părinte) |

Reguli per nod:

1. Eticheta nodului = `detalii_gr_chelt_ven_postc` DOAR când e text real (nenull, nenul după `BTRIM`, diferit de codul însuși). Altfel eticheta = codul-nod (la părinți codul ESTE numele: `ENERGIE ELECTRICA`, `ADMINISTRATIV` etc.).
2. Frunza se **omite** din lanț dacă nu are detalii utile ȘI are părinte — pornește lanțul de la părinte (nu vrem `2.2.6.48.Timisoara Bega → SALUBRIZAREA → …`).
3. Oprește urcarea când parent = node (auto-ciclu, ex. `ADMINISTRATIV → ADMINISTRATIV`), când parent `IS NULL`, sau la adâncimea 10. Ține un array de noduri vizitate ca protecție împotriva ciclurilor mai lungi.

Pattern SQL standard (agregare pe categorii cu calea completă):

```sql
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
      ELSE NULL                    -- frunză-cod: o sărim, va fi reprezentată de părinte
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
JOIN doc_poz dp ON (dp.data_doc, dp.tip_doc, dp.nr_doc) = (d.data_doc, d.tip_doc, d.nr_doc)
LEFT JOIN cat_label cl ON cl.leaf = dp.gr_chelt_ven_postc
WHERE d.tip_doc IN ('FactFI','FactFE')
  AND d.data_doc BETWEEN :start AND :end
GROUP BY cl.category_path ORDER BY total_lei DESC NULLS LAST LIMIT 50;
```

> Note despre cast-uri: rădăcina CTE-ului trebuie să aibă TIPURI EXPLICITE (`::text` pe `label` și pe elementele array-ului `visited`). Fără ele, PostgreSQL se plânge cu `type character varying(25)[] in non-recursive term but type character varying[] overall`.

**Facturi pe punct de lucru propriu:**

```sql
SELECT d.eu_punct_lucru, COUNT(*) AS nr_facturi,
       SUM(d.val_lei + COALESCE(d.val_lei_tva, 0)) AS total_lei
FROM doc d
WHERE d.tip_doc IN ('FactCI','FactCE','FactFI','FactFE')
  AND d.data_doc BETWEEN '2025-01-01' AND '2025-12-31'
GROUP BY 1 ORDER BY total_lei DESC;
```

**Conturi analitice legate de o categorie:**

```sql
SELECT c.conts, c.conta, c.den_conta, c.moneda, c.gr_chelt_ven_postc, c.eu_punct_lucru_conta
FROM conta c
WHERE c.gr_chelt_ven_postc = :categorie AND c.discontinued = false
ORDER BY c.conts, c.conta;
```

## Quick diagnostic queries

```bash
# List available databases
psql -U root -h 127.0.0.1 -p 5432 -l

# Inspect a remote table structure
psql -U root -h 127.0.0.1 -p 5432 -d <db> -c "\d doc"

# Count distinct tip_doc values
psql -U root -h 127.0.0.1 -p 5432 -d <db> -c "SELECT tip_doc, COUNT(*) FROM doc GROUP BY tip_doc ORDER BY COUNT(*) DESC LIMIT 20;"

# Sample a partner's bank accounts
psql -U root -h 127.0.0.1 -p 5432 -d <db> -c "SELECT banca, cont_banca, moneda, da_nu_implicit, discontinued FROM partener_banca WHERE partener = '<NAME>' ORDER BY da_nu_implicit DESC;"
```

## Do / Don't

- **Do** use `mcp__laravel-boost__database-query` against the configured remote connection to explore schema and sample data before writing sync code.
- **Do** respect the composite PK `(data_doc, tip_doc, nr_doc)` everywhere. Concatenate to a string key when building lookup maps.
- **Do** coalesce NULL to 0 for all `val_mon_*` reads — payments aren't always set.
- **Don't** write to the remote DB. This is a read-only integration.
- **Don't** trust `partener.da_nu_furnizor` / `da_nu_client` for role classification.
- **Don't** use `doc_comp` — it's empty. Use `doc_fin`.
- **Don't** rely on `data_inchidere` to mean "paid". It's mostly NULL.
- **Don't** pull the full `doc` table. Always filter by `data_doc` range.
