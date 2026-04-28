<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\EInvoice;
use Einvoicing\Invoice as EInvoicingInvoice;
use Einvoicing\InvoiceLine;
use Einvoicing\Party;
use Einvoicing\Readers\UblReader;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EInvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();
        $companyId = $request->integer('company_id');
        $status = $request->string('status')->toString();
        $matched = $request->string('matched')->toString();
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();

        if (! $request->has('status')) {
            $status = 'pending';
        }

        $eInvoices = EInvoice::query()
            ->with([
                'company:id,name',
                'partner:id,name,cui',
                'invoice:id,data_doc,tip_doc,nr_doc',
            ])
            ->when($companyId, fn ($q, $id) => $q->where('company_id', $id))
            ->when($status === 'pending', fn ($q) => $q->whereNull('data_ins_omc'))
            ->when($status === 'error', fn ($q) => $q->whereNotNull('err_ins_omc')->where('err_ins_omc', '!=', ''))
            ->when($status === 'processed', fn ($q) => $q->whereNotNull('data_ins_omc'))
            ->when($matched === 'yes', fn ($q) => $q->whereNotNull('invoice_id'))
            ->when($matched === 'no', fn ($q) => $q->whereNull('invoice_id'))
            ->when($from, fn ($q, $d) => $q->where('msg_data_creare_d', '>=', $d))
            ->when($to, fn ($q, $d) => $q->where('msg_data_creare_d', '<=', $d.' 23:59:59'))
            ->when($search, function ($q, $term) {
                $q->where(function ($q) use ($term) {
                    $q->where('nr_doc_xml', 'like', "%{$term}%")
                        ->orWhere('partener_xml', 'like', "%{$term}%")
                        ->orWhere('cod_cci_xml', 'like', "%{$term}%")
                        ->orWhere('msg_id', 'like', "%{$term}%");
                });
            })
            ->orderByDesc('msg_data_creare_d')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (EInvoice $row) => $this->transformForList($row));

        return Inertia::render('e-invoices/index', [
            'eInvoices' => $eInvoices,
            'filters' => [
                'search' => $search ?: null,
                'company_id' => $companyId ?: null,
                'status' => $status === '' ? 'all' : $status,
                'matched' => $matched ?: null,
                'from' => $from ?: null,
                'to' => $to ?: null,
            ],
            'companies' => Company::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function detail(EInvoice $eInvoice): array
    {
        $eInvoice->load([
            'company:id,name',
            'partner:id,name,cui',
            'invoice:id,data_doc,tip_doc,nr_doc',
        ]);

        return [
            'eInvoice' => $this->transformForDetail($eInvoice),
        ];
    }

    public function parsed(EInvoice $eInvoice): array
    {
        if ($eInvoice->msg_xml === null || trim($eInvoice->msg_xml) === '') {
            return ['parsed' => null, 'error' => 'XML indisponibil pentru această eFactură.'];
        }

        try {
            $invoice = (new UblReader)->import($eInvoice->msg_xml);
        } catch (\Throwable $e) {
            return ['parsed' => null, 'error' => 'Nu am putut citi XML-ul: '.$e->getMessage()];
        }

        return ['parsed' => $this->transformParsedInvoice($invoice), 'error' => null];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformParsedInvoice(EInvoicingInvoice $invoice): array
    {
        $totals = $invoice->getTotals();

        return [
            'number' => $invoice->getNumber(),
            'issue_date' => $invoice->getIssueDate()?->format('Y-m-d'),
            'due_date' => $invoice->getDueDate()?->format('Y-m-d'),
            'tax_point_date' => $invoice->getTaxPointDate()?->format('Y-m-d'),
            'currency' => $invoice->getCurrency(),
            'notes' => $invoice->getNotes(),
            'buyer_reference' => $invoice->getBuyerReference(),
            'purchase_order_reference' => $invoice->getPurchaseOrderReference(),
            'contract_reference' => $invoice->getContractReference(),
            'paid_amount' => $invoice->getPaidAmount(),
            'rounding_amount' => $invoice->getRoundingAmount(),
            'seller' => $this->transformParty($invoice->getSeller()),
            'buyer' => $this->transformParty($invoice->getBuyer()),
            'payee' => $this->transformParty($invoice->getPayee()),
            'totals' => [
                'currency' => $totals->currency,
                'net_amount' => $totals->netAmount,
                'allowances_amount' => $totals->allowancesAmount,
                'charges_amount' => $totals->chargesAmount,
                'tax_exclusive_amount' => $totals->taxExclusiveAmount,
                'vat_amount' => $totals->vatAmount,
                'tax_inclusive_amount' => $totals->taxInclusiveAmount,
                'paid_amount' => $totals->paidAmount,
                'rounding_amount' => $totals->roundingAmount,
                'payable_amount' => $totals->payableAmount,
            ],
            'lines' => array_map(fn (InvoiceLine $line) => [
                'name' => $line->getName(),
                'description' => $line->getDescription(),
                'quantity' => $line->getQuantity(),
                'unit' => $line->getUnit(),
                'price' => $line->getPrice(),
                'net_amount' => $line->getNetAmount(),
            ], $invoice->getLines()),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function transformParty(?Party $party): ?array
    {
        if ($party === null) {
            return null;
        }

        return [
            'name' => $party->getName(),
            'trading_name' => $party->getTradingName(),
            'vat_number' => $party->getVatNumber(),
            'company_id' => $party->getCompanyId()?->getValue(),
            'address' => $party->getAddress(),
            'city' => $party->getCity(),
            'postal_code' => $party->getPostalCode(),
            'country' => $party->getCountry(),
            'contact_name' => $party->getContactName(),
            'contact_phone' => $party->getContactPhone(),
            'contact_email' => $party->getContactEmail(),
        ];
    }

    private function transformForList(EInvoice $row): array
    {
        return [
            'id' => $row->id,
            'msg_id' => $row->msg_id,
            'msg_index_incarcare' => $row->msg_index_incarcare,
            'msg_data_creare_d' => $row->msg_data_creare_d?->toIso8601String(),
            'data_doc_xml' => $row->data_doc_xml?->toDateString(),
            'tip_doc_xml' => $row->tip_doc_xml,
            'nr_doc_xml' => $row->nr_doc_xml,
            'partener_xml' => $row->partener_xml,
            'cod_cci_xml' => $row->cod_cci_xml,
            'data_ins_omc' => $row->data_ins_omc?->toIso8601String(),
            'err_ins_omc' => $row->err_ins_omc,
            'status' => $row->status,
            'company' => ['id' => $row->company->id, 'name' => $row->company->name],
            'partner' => $row->partner ? [
                'id' => $row->partner->id,
                'name' => $row->partner->name,
                'cui' => $row->partner->cui,
            ] : null,
            'invoice' => $row->invoice ? [
                'id' => $row->invoice->id,
                'data_doc' => $row->invoice->data_doc?->toDateString(),
                'tip_doc' => $row->invoice->tip_doc,
                'nr_doc' => $row->invoice->nr_doc,
            ] : null,
        ];
    }

    private function transformForDetail(EInvoice $row): array
    {
        return [
            ...$this->transformForList($row),
            'msg_detalii' => $row->msg_detalii,
            'msg_xml' => $row->msg_xml,
        ];
    }
}
