<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\EInvoice;
use App\Models\Invoice;
use App\Services\SyncService;
use Illuminate\Http\RedirectResponse;
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

        if ($status === '' && ! $request->has('status')) {
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
                'status' => $status ?: null,
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

    public function searchCandidates(Request $request, EInvoice $eInvoice): array
    {
        $term = $request->string('q')->toString();

        $query = Invoice::query()
            ->where('company_id', $eInvoice->company_id)
            ->whereIn('tip_doc', SyncService::FURNIZOR_DOC_TYPES)
            ->with('partner:id,name,cui');

        if ($term !== '') {
            $query->where(function ($q) use ($term) {
                $q->where('nr_doc', 'like', "%{$term}%")
                    ->orWhereHas('partner', fn ($p) => $p->where('name', 'like', "%{$term}%"));
            });
        } elseif ($eInvoice->nr_doc_xml !== null) {
            $query->where('nr_doc', 'like', '%'.$eInvoice->nr_doc_xml.'%');
        }

        $candidates = $query->orderByDesc('data_doc')->limit(20)->get();

        return [
            'candidates' => $candidates->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'data_doc' => $invoice->data_doc?->toDateString(),
                'tip_doc' => $invoice->tip_doc,
                'nr_doc' => $invoice->nr_doc,
                'partner' => $invoice->partner ? [
                    'id' => $invoice->partner->id,
                    'name' => $invoice->partner->name,
                    'cui' => $invoice->partner->cui,
                ] : null,
                'val_mon' => (float) $invoice->val_mon,
                'moneda' => $invoice->moneda,
            ])->all(),
        ];
    }

    public function match(Request $request, EInvoice $eInvoice): RedirectResponse
    {
        $validated = $request->validate([
            'invoice_id' => ['nullable', 'integer', 'exists:invoices,id'],
        ]);

        if (isset($validated['invoice_id'])) {
            $invoice = Invoice::query()
                ->where('id', $validated['invoice_id'])
                ->where('company_id', $eInvoice->company_id)
                ->firstOrFail();
            $eInvoice->forceFill(['invoice_id' => $invoice->id])->save();
            Inertia::flash('toast', ['type' => 'success', 'message' => 'eFactură asociată cu factura.']);
        } else {
            $eInvoice->forceFill(['invoice_id' => null])->save();
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Asocierea cu factura a fost eliminată.']);
        }

        return back();
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
