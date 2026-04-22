<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceDetail;
use App\Models\InvoicePayment;
use App\Models\Partner;
use App\Models\PartnerBankAccount;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SyncService
{
    public const array FURNIZOR_DOC_TYPES = ['FactFI', 'FactFE'];

    public const array CLIENT_DOC_TYPES = ['FactCI', 'FactCE', 'FactINT'];

    public function __construct(private readonly RemoteConnection $remote) {}

    /**
     * @return array{partners: int, invoices: int, details: int, bank_accounts: int, payments: int}
     */
    public function sync(Company $company, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $remote = $this->remote->connection($company);
        $from ??= Carbon::now()->subMonth()->startOfDay();
        $to ??= Carbon::now()->endOfDay();

        $tipDocs = [...self::FURNIZOR_DOC_TYPES, ...self::CLIENT_DOC_TYPES];

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

        $company->forceFill(['last_synced_at' => now()])->save();

        return [
            'partners' => $partnersCount,
            'invoices' => $invoicesCount,
            'details' => $detailsCount,
            'bank_accounts' => $bankAccountsCount,
            'payments' => $paymentsCount,
        ];
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
        $invoiceRows = $remote->table('doc')
            ->select([
                'data_doc', 'tip_doc', 'nr_doc', 'partener', 'moneda', 'curs',
                'val_mon', 'val_mon_tva', 'val_mon_inc', 'val_mon_pl',
                'data_scadenta', 'data_inchidere', 'emitent',
            ])
            ->whereIn('tip_doc', $tipDocs)
            ->whereBetween('data_doc', [$from->toDateString(), $to->toDateString()])
            ->orderBy('data_doc')
            ->get();

        $partnerLookup = Partner::where('company_id', $company->id)
            ->pluck('id', 'name');

        $invoicesCount = 0;
        $detailsCount = 0;

        DB::transaction(function () use ($company, $remote, $invoiceRows, $partnerLookup, &$invoicesCount, &$detailsCount) {
            foreach ($invoiceRows as $row) {
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

                $detailsCount += $this->syncInvoiceDetails($remote, $invoice, $row);
            }
        });

        return [$invoicesCount, $detailsCount];
    }

    private function syncInvoiceDetails(ConnectionInterface $remote, Invoice $invoice, object $docRow): int
    {
        $rows = $remote->table('doc_poz')
            ->select(['scv', 'articol', 'detaliu_articol', 'cant', 'um', 'pret', 'proc_tva'])
            ->where('data_doc', $docRow->data_doc)
            ->where('tip_doc', $docRow->tip_doc)
            ->where('nr_doc', $docRow->nr_doc)
            ->orderBy('scv')
            ->get();

        $invoice->details()->delete();

        $count = 0;
        foreach ($rows as $row) {
            InvoiceDetail::create([
                'invoice_id' => $invoice->id,
                'scv' => $row->scv,
                'articol' => $row->articol,
                'detaliu_articol' => $row->detaliu_articol,
                'cant' => $row->cant ?? 0,
                'um' => $row->um,
                'pret' => $row->pret ?? 0,
                'proc_tva' => $row->proc_tva,
            ]);
            $count++;
        }

        return $count;
    }
}
