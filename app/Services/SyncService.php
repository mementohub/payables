<?php

namespace App\Services;

use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\BankStatementLineAllocation;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceDetail;
use App\Models\InvoicePayment;
use App\Models\Partner;
use App\Models\PartnerBankAccount;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

class SyncService
{
    public const array FURNIZOR_DOC_TYPES = ['FactFI', 'FactFE'];

    public const array CLIENT_DOC_TYPES = ['FactCI', 'FactCE', 'FactINT'];

    public function __construct(private readonly RemoteConnection $remote) {}

    /**
     * @return array{partners: int, invoices: int, details: int, bank_accounts: int, payments: int, statements: int}
     */
    public function sync(Company $company, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $remote = $this->remote->connection($company);

        $tipDocs = [...self::FURNIZOR_DOC_TYPES, ...self::CLIENT_DOC_TYPES];

        if ($to === null) {
            $remoteMax = $remote->table('doc')
                ->whereIn('tip_doc', $tipDocs)
                ->max('data_doc');

            $to = $remoteMax ? Carbon::parse($remoteMax)->subMonth() : Carbon::now()->endOfDay();
        }

        $from ??= $to->copy()->subMonth()->startOfDay();

        $partnerDocRows = $remote->table('doc')
            ->select('partener', 'tip_doc')
            ->whereIn('tip_doc', $tipDocs)
            ->whereBetween('data_doc', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('partener')
            ->distinct()
            ->get();

        $partnerRoles = [];
        foreach ($partnerDocRows as $row) {
            $isFurnizor = in_array($row->tip_doc, self::FURNIZOR_DOC_TYPES, true);
            $partnerRoles[$row->partener] ??= ['furnizor' => false, 'client' => false];
            $partnerRoles[$row->partener][$isFurnizor ? 'furnizor' : 'client'] = true;
        }

        $partnersCount = $this->syncPartners($company, $remote, $partnerRoles);
        [$invoicesCount, $detailsCount] = $this->syncInvoices($company, $remote, $from, $to, $tipDocs);

        $furnizorNames = array_keys(array_filter($partnerRoles, fn ($r) => $r['furnizor']));
        $bankAccountsCount = $this->syncBankAccounts($company, $remote, $furnizorNames);

        $paymentsCount = $this->syncInvoicePayments($company, $remote, $from, $to, $tipDocs);

        $statementsCount = $this->syncBankStatements($company, $remote, $from, $to);

        $company->forceFill(['last_synced_at' => now()])->save();

        return [
            'partners' => $partnersCount,
            'invoices' => $invoicesCount,
            'details' => $detailsCount,
            'bank_accounts' => $bankAccountsCount,
            'payments' => $paymentsCount,
            'statements' => $statementsCount,
        ];
    }

    private function syncBankStatements(Company $company, ConnectionInterface $remote, Carbon $from, Carbon $to): int
    {
        $headers = $remote->table('extrasb as e')
            ->leftJoin('eu_banca as b', function ($join) {
                $join->on('b.banca', '=', 'e.banca_eu')->on('b.cont_banca', '=', 'e.cont_banca_eu');
            })
            ->select([
                'e.data_extras', 'e.banca_eu', 'e.cont_banca_eu', 'e.operator', 'b.moneda',
            ])
            ->whereBetween('e.data_extras', [$from->toDateString(), $to->toDateString()])
            ->orderBy('e.data_extras')
            ->get();

        if ($headers->isEmpty()) {
            return 0;
        }

        $incasareTypes = [
            'OP_INC', 'Ch_INC', 'Reg_INC', 'CardINC', 'CredINC', 'BO_INC', 'CEC_INC', 'Cmb_INC',
            'DI_Casa', 'DIV_INC', 'Dob_INC', 'FV_B', 'OCV_INC', 'OVV_INC', 'DocCred',
            'B_Cadou', 'B_CasaF', 'B_Masa', 'BonMasa', 'BordMgz', 'CCredit', 'CEC_N_C',
        ];

        $partnerLookup = Partner::where('company_id', $company->id)->pluck('id', 'name');
        $invoiceLookup = Invoice::where('company_id', $company->id)
            ->get(['id', 'data_doc', 'tip_doc', 'nr_doc'])
            ->keyBy(fn ($i) => $i->data_doc->toDateString().'|'.$i->tip_doc.'|'.$i->nr_doc);

        $count = 0;

        foreach ($headers as $header) {
            $statement = BankStatement::updateOrCreate(
                [
                    'company_id' => $company->id,
                    'data_extras' => $header->data_extras,
                    'iban' => $header->cont_banca_eu,
                ],
                [
                    'banca' => $header->banca_eu !== '-' ? $header->banca_eu : null,
                    'operator' => $header->operator,
                    'moneda' => $header->moneda,
                ]
            );

            $lines = $remote->table('doc')
                ->select([
                    'data_doc', 'tip_doc', 'nr_doc', 'partener', 'moneda', 'val_mon',
                    'emitent', 'cine_preda', 'cine_primeste', 'obs_txt',
                ])
                ->where('data_contab', $header->data_extras)
                ->where('banca_eu', $header->banca_eu)
                ->where('cont_banca_eu', $header->cont_banca_eu)
                ->orderBy('data_doc')
                ->get();

            $allocationRows = $remote->table('doc_fin')
                ->select([
                    'data_doc_fin', 'tip_doc_fin', 'nr_doc_fin',
                    'data_doc_com', 'tip_doc_com', 'nr_doc_com', 'val_fin', 'val_com',
                ])
                ->whereIn('tip_doc_fin', $lines->pluck('tip_doc')->unique()->all() ?: [''])
                ->where(function ($q) use ($lines) {
                    foreach ($lines as $line) {
                        $q->orWhere(function ($q) use ($line) {
                            $q->where('data_doc_fin', $line->data_doc)
                                ->where('tip_doc_fin', $line->tip_doc)
                                ->where('nr_doc_fin', $line->nr_doc);
                        });
                    }
                })
                ->get();

            $allocationByLine = [];
            $allocationRowsByLine = [];
            foreach ($allocationRows as $row) {
                $key = $row->data_doc_fin.'|'.$row->tip_doc_fin.'|'.$row->nr_doc_fin;
                $allocationByLine[$key] = ($allocationByLine[$key] ?? 0) + ($row->val_fin ?? 0);
                $allocationRowsByLine[$key][] = $row;
            }

            $statement->lines()->delete();

            $linesCount = 0;
            $unallocatedCount = 0;
            $totalIn = 0.0;
            $totalOut = 0.0;
            $totalUnallocated = 0.0;

            foreach ($lines as $line) {
                $lineKey = $line->data_doc.'|'.$line->tip_doc.'|'.$line->nr_doc;
                $direction = in_array($line->tip_doc, $incasareTypes, true) ? 'incoming' : 'outgoing';
                $valAllocated = (float) ($allocationByLine[$lineKey] ?? 0);
                $val = (float) ($line->val_mon ?? 0);

                $lineModel = BankStatementLine::create([
                    'bank_statement_id' => $statement->id,
                    'data_doc' => $line->data_doc,
                    'tip_doc' => $line->tip_doc,
                    'nr_doc' => $line->nr_doc,
                    'direction' => $direction,
                    'partener_name' => $line->partener,
                    'partner_id' => $line->partener ? ($partnerLookup[$line->partener] ?? null) : null,
                    'emitent' => $line->emitent,
                    'cine_preda' => $line->cine_preda,
                    'cine_primeste' => $line->cine_primeste,
                    'obs_txt' => $line->obs_txt,
                    'moneda' => $line->moneda,
                    'val_mon' => $val,
                    'val_allocated' => $valAllocated,
                ]);

                foreach ($allocationRowsByLine[$lineKey] ?? [] as $alloc) {
                    $invoiceKey = $alloc->data_doc_com.'|'.$alloc->tip_doc_com.'|'.$alloc->nr_doc_com;
                    $invoiceId = isset($invoiceLookup[$invoiceKey]) ? $invoiceLookup[$invoiceKey]->id : null;

                    BankStatementLineAllocation::create([
                        'bank_statement_line_id' => $lineModel->id,
                        'invoice_id' => $invoiceId,
                        'data_doc_com' => $alloc->data_doc_com,
                        'tip_doc_com' => $alloc->tip_doc_com,
                        'nr_doc_com' => $alloc->nr_doc_com,
                        'val_fin' => $alloc->val_fin ?? 0,
                        'val_com' => $alloc->val_com ?? 0,
                    ]);
                }

                $linesCount++;
                $unallocatedAmount = max(0, $val - $valAllocated);
                if ($unallocatedAmount > 0.01) {
                    $unallocatedCount++;
                    $totalUnallocated += $unallocatedAmount;
                }
                if ($direction === 'incoming') {
                    $totalIn += $val;
                } else {
                    $totalOut += $val;
                }
            }

            $statement->forceFill([
                'lines_count' => $linesCount,
                'unallocated_count' => $unallocatedCount,
                'total_incoming' => $totalIn,
                'total_outgoing' => $totalOut,
                'total_unallocated' => $totalUnallocated,
            ])->save();

            $count++;
        }

        return $count;
    }

    /**
     * @param  array<int, string>  $tipDocs
     */
    private function syncInvoicePayments(Company $company, ConnectionInterface $remote, Carbon $from, Carbon $to, array $tipDocs): int
    {
        $invoices = Invoice::where('company_id', $company->id)
            ->whereBetween('data_doc', [$from->toDateString(), $to->toDateString()])
            ->whereIn('tip_doc', $tipDocs)
            ->get(['id', 'data_doc', 'tip_doc', 'nr_doc']);

        if ($invoices->isEmpty()) {
            return 0;
        }

        $lookup = [];
        foreach ($invoices as $invoice) {
            $key = $invoice->data_doc->toDateString().'|'.$invoice->tip_doc.'|'.$invoice->nr_doc;
            $lookup[$key] = $invoice->id;
        }

        $rows = $remote->table('doc_fin as df')
            ->leftJoin('doc as d', function ($join) {
                $join->on('d.data_doc', '=', 'df.data_doc_fin')
                    ->on('d.tip_doc', '=', 'df.tip_doc_fin')
                    ->on('d.nr_doc', '=', 'df.nr_doc_fin');
            })
            ->select([
                'df.data_doc_com', 'df.tip_doc_com', 'df.nr_doc_com',
                'df.data_doc_fin', 'df.tip_doc_fin', 'df.nr_doc_fin',
                'df.data_repartizare', 'df.val_fin', 'df.val_com',
                'd.moneda as fin_moneda',
            ])
            ->whereIn('df.tip_doc_com', $tipDocs)
            ->whereBetween('df.data_doc_com', [$from->toDateString(), $to->toDateString()])
            ->get();

        InvoicePayment::whereIn('invoice_id', array_values($lookup))->delete();

        $count = 0;
        foreach ($rows as $row) {
            $key = $row->data_doc_com.'|'.$row->tip_doc_com.'|'.$row->nr_doc_com;
            $invoiceId = $lookup[$key] ?? null;
            if ($invoiceId === null) {
                continue;
            }

            InvoicePayment::updateOrCreate(
                [
                    'invoice_id' => $invoiceId,
                    'data_doc' => $row->data_doc_fin,
                    'tip_doc' => $row->tip_doc_fin,
                    'nr_doc' => $row->nr_doc_fin,
                    'data_repartizare' => $row->data_repartizare,
                ],
                [
                    'val_fin' => $row->val_fin ?? 0,
                    'val_com' => $row->val_com ?? 0,
                    'moneda' => $row->fin_moneda,
                ]
            );
            $count++;
        }

        return $count;
    }

    /**
     * @param  array<int, string>  $furnizorNames
     */
    private function syncBankAccounts(Company $company, ConnectionInterface $remote, array $furnizorNames): int
    {
        if (empty($furnizorNames)) {
            return 0;
        }

        $partners = Partner::where('company_id', $company->id)
            ->whereIn('name', $furnizorNames)
            ->pluck('id', 'name');

        if ($partners->isEmpty()) {
            return 0;
        }

        $rows = $remote->table('partener_banca')
            ->select(['partener', 'banca', 'cont_banca', 'moneda', 'da_nu_implicit', 'discontinued'])
            ->whereIn('partener', $partners->keys()->all())
            ->get();

        $count = 0;
        foreach ($rows as $row) {
            $partnerId = $partners[$row->partener] ?? null;
            if ($partnerId === null) {
                continue;
            }

            PartnerBankAccount::updateOrCreate(
                ['partner_id' => $partnerId, 'iban' => $row->cont_banca],
                [
                    'bank' => $row->banca !== '-' ? $row->banca : null,
                    'currency' => $row->moneda,
                    'is_default' => (bool) $row->da_nu_implicit,
                    'is_discontinued' => (bool) $row->discontinued,
                ]
            );
            $count++;
        }

        return $count;
    }

    /**
     * @param  array<string, array{furnizor: bool, client: bool}>  $roles
     */
    private function syncPartners(Company $company, ConnectionInterface $remote, array $roles): int
    {
        if (empty($roles)) {
            return 0;
        }

        $rows = $remote->table('partener')
            ->select([
                'partener',
                'cod_cci',
                'reg_comert_nr',
                'da_nu_platitor_tva',
                'tara',
                'localit',
                'adresa',
                'telefon',
                'email_adr',
            ])
            ->whereIn('partener', array_keys($roles))
            ->get();

        $existing = Partner::where('company_id', $company->id)
            ->whereIn('name', array_keys($roles))
            ->get(['id', 'name', 'is_furnizor', 'is_client'])
            ->keyBy('name');

        $synced = 0;

        foreach ($rows as $row) {
            $role = $roles[$row->partener];
            $prior = $existing->get($row->partener);

            Partner::updateOrCreate(
                ['company_id' => $company->id, 'name' => $row->partener],
                [
                    'cui' => $row->cod_cci,
                    'reg_com' => $row->reg_comert_nr,
                    'is_furnizor' => $role['furnizor'] || (bool) $prior?->is_furnizor,
                    'is_client' => $role['client'] || (bool) $prior?->is_client,
                    'is_vat_payer' => (bool) $row->da_nu_platitor_tva,
                    'country' => $row->tara,
                    'city' => $row->localit,
                    'address' => $row->adresa,
                    'phone' => $row->telefon,
                    'email' => $row->email_adr,
                ]
            );
            $synced++;
        }

        return $synced;
    }

    /**
     * @param  array<int, string>  $tipDocs
     * @return array{0: int, 1: int}
     */
    private function syncInvoices(Company $company, ConnectionInterface $remote, Carbon $from, Carbon $to, array $tipDocs): array
    {
        $partnerLookup = Partner::where('company_id', $company->id)
            ->pluck('id', 'name');

        $invoicesCount = 0;
        $detailsCount = 0;

        $remote->table('doc')
            ->select([
                'data_doc', 'tip_doc', 'nr_doc', 'partener', 'moneda', 'curs',
                'val_mon', 'val_mon_tva', 'val_mon_inc', 'val_mon_pl',
                'data_scadenta', 'data_inchidere', 'emitent',
            ])
            ->whereIn('tip_doc', $tipDocs)
            ->whereBetween('data_doc', [$from->toDateString(), $to->toDateString()])
            ->orderBy('data_doc')
            ->chunk(500, function ($chunk) use ($company, $remote, $partnerLookup, &$invoicesCount, &$detailsCount) {
                $invoiceIds = [];
                $rowsByKey = [];

                foreach ($chunk as $row) {
                    $type = in_array($row->tip_doc, self::FURNIZOR_DOC_TYPES, true) ? 'furnizor' : 'client';
                    $paid = $type === 'furnizor' ? $row->val_mon_pl : $row->val_mon_inc;

                    $invoice = Invoice::updateOrCreate(
                        [
                            'company_id' => $company->id,
                            'data_doc' => $row->data_doc,
                            'tip_doc' => $row->tip_doc,
                            'nr_doc' => $row->nr_doc,
                        ],
                        [
                            'partner_id' => $partnerLookup[$row->partener] ?? null,
                            'partener_type' => $type,
                            'moneda' => $row->moneda,
                            'curs' => $row->curs,
                            'val_mon' => $row->val_mon ?? 0,
                            'val_mon_tva' => $row->val_mon_tva ?? 0,
                            'val_mon_paid' => $paid ?? 0,
                            'data_scadenta' => $row->data_scadenta,
                            'data_inchidere' => $row->data_inchidere,
                            'emitent' => $row->emitent,
                        ]
                    );

                    $invoicesCount++;
                    $invoiceIds[] = $invoice->id;
                    $key = $row->data_doc.'|'.$row->tip_doc.'|'.$row->nr_doc;
                    $rowsByKey[$key] = $invoice->id;
                }

                $detailsCount += $this->syncInvoiceDetailsBulk($remote, $invoiceIds, $rowsByKey);
            });

        return [$invoicesCount, $detailsCount];
    }

    /**
     * @param  array<int, int>  $invoiceIds
     * @param  array<string, int>  $rowsByKey
     */
    private function syncInvoiceDetailsBulk(ConnectionInterface $remote, array $invoiceIds, array $rowsByKey): int
    {
        if (empty($invoiceIds)) {
            return 0;
        }

        InvoiceDetail::whereIn('invoice_id', $invoiceIds)->delete();

        $pozRows = $remote->table('doc_poz')
            ->select(['data_doc', 'tip_doc', 'nr_doc', 'scv', 'articol', 'detaliu_articol', 'cant', 'um', 'pret', 'proc_tva'])
            ->where(function ($q) use ($rowsByKey) {
                foreach (array_keys($rowsByKey) as $key) {
                    [$dataDoc, $tipDoc, $nrDoc] = explode('|', $key);
                    $q->orWhere(function ($q) use ($dataDoc, $tipDoc, $nrDoc) {
                        $q->where('data_doc', $dataDoc)
                            ->where('tip_doc', $tipDoc)
                            ->where('nr_doc', $nrDoc);
                    });
                }
            })
            ->get();

        $inserts = [];
        $now = now();

        foreach ($pozRows as $row) {
            $key = $row->data_doc.'|'.$row->tip_doc.'|'.$row->nr_doc;
            $invoiceId = $rowsByKey[$key] ?? null;
            if ($invoiceId === null) {
                continue;
            }

            $inserts[] = [
                'invoice_id' => $invoiceId,
                'scv' => $row->scv,
                'articol' => $row->articol,
                'detaliu_articol' => $row->detaliu_articol,
                'cant' => $row->cant ?? 0,
                'um' => $row->um,
                'pret' => $row->pret ?? 0,
                'proc_tva' => $row->proc_tva,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($inserts, 500) as $batch) {
            InvoiceDetail::insert($batch);
        }

        return count($inserts);
    }
}
