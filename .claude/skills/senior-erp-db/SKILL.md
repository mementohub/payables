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

### Other tables seen in the schema (mostly ignored for sync)

- `doc_comp` — has a similar shape for compensation pairing but appears **empty/unused** in christiantour; do not rely on it.
- `doc_text` — free-text notes attached to a doc.
- `doc_scadenta` — scheduled payment tranches (installment plans).
- `partener_contact`, `partener_punct_lucru`, `partener_departament` — extra partner detail.
- `banca`, `moneda`, `tara`, `tva` — lookup/dictionary tables.
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
