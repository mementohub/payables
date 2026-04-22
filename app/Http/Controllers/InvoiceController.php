<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function emise(Request $request): Response
    {
        return $this->list($request, 'emise');
    }

    public function primite(Request $request): Response
    {
        return $this->list($request, 'primite');
    }

    private function list(Request $request, string $scope): Response
    {
        $search = $request->string('search')->toString();
        $companyId = $request->integer('company_id');
        $payment = $request->string('payment')->toString();

        $invoices = Invoice::query()
            ->with(['partner:id,name,cui', 'company:id,name'])
            ->when($scope === 'primite', fn ($q) => $q->furnizor())
            ->when($scope === 'emise', fn ($q) => $q->client())
            ->when($companyId, fn ($q, $id) => $q->where('company_id', $id))
            ->when($payment === 'paid', fn ($q) => $q->whereColumn('val_mon_paid', '>=', \Illuminate\Support\Facades\DB::raw('val_mon + val_mon_tva - 0.01')))
            ->when($payment === 'unpaid', fn ($q) => $q->where('val_mon_paid', '<=', 0.009))
            ->when($payment === 'partial', function ($q) {
                $q->where('val_mon_paid', '>', 0.009)
                    ->whereColumn('val_mon_paid', '<', \Illuminate\Support\Facades\DB::raw('val_mon + val_mon_tva - 0.01'));
            })
            ->when($search, function ($q, $term) {
                $q->where(function ($q) use ($term) {
                    $q->where('nr_doc', 'like', "%{$term}%")
                        ->orWhereHas('partner', fn ($p) => $p->where('name', 'like', "%{$term}%"));
                });
            })
            ->orderByDesc('data_doc')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'data_doc' => $invoice->data_doc?->toDateString(),
                'data_scadenta' => $invoice->data_scadenta?->toDateString(),
                'nr_doc' => $invoice->nr_doc,
                'partener_type' => $invoice->partener_type,
                'partner' => $invoice->partner ? [
                    'id' => $invoice->partner->id,
                    'name' => $invoice->partner->name,
                    'cui' => $invoice->partner->cui,
                ] : null,
                'company' => ['id' => $invoice->company->id, 'name' => $invoice->company->name],
                'moneda' => $invoice->moneda,
                'val_mon' => (float) $invoice->val_mon,
                'val_mon_tva' => (float) $invoice->val_mon_tva,
                'val_mon_paid' => (float) $invoice->val_mon_paid,
                'payment_status' => $invoice->payment_status,
            ]);

        return Inertia::render('invoices/index', [
            'invoices' => $invoices,
            'scope' => $scope,
            'filters' => [
                'search' => $search ?: null,
                'company_id' => $companyId ?: null,
                'payment' => $payment ?: null,
            ],
            'companies' => \App\Models\Company::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Invoice $invoice): Response
    {
        $invoice->load(['partner', 'company', 'details', 'payments']);

        return Inertia::render('invoices/show', [
            'invoice' => [
                'id' => $invoice->id,
                'data_doc' => $invoice->data_doc?->toDateString(),
                'tip_doc' => $invoice->tip_doc,
                'nr_doc' => $invoice->nr_doc,
                'partener_type' => $invoice->partener_type,
                'moneda' => $invoice->moneda,
                'curs' => (float) $invoice->curs,
                'val_mon' => (float) $invoice->val_mon,
                'val_mon_tva' => (float) $invoice->val_mon_tva,
                'val_mon_paid' => (float) $invoice->val_mon_paid,
                'payment_status' => $invoice->payment_status,
                'data_scadenta' => $invoice->data_scadenta?->toDateString(),
                'data_inchidere' => $invoice->data_inchidere?->toDateString(),
                'emitent' => $invoice->emitent,
                'partner' => $invoice->partner ? [
                    'id' => $invoice->partner->id,
                    'name' => $invoice->partner->name,
                    'cui' => $invoice->partner->cui,
                    'address' => $invoice->partner->address,
                    'city' => $invoice->partner->city,
                    'country' => $invoice->partner->country,
                    'is_furnizor' => $invoice->partner->is_furnizor,
                ] : null,
                'company' => ['id' => $invoice->company->id, 'name' => $invoice->company->name],
                'details' => $invoice->details->map(fn ($row) => [
                    'id' => $row->id,
                    'scv' => $row->scv,
                    'articol' => $row->articol,
                    'detaliu_articol' => $row->detaliu_articol,
                    'cant' => (float) $row->cant,
                    'um' => $row->um,
                    'pret' => (float) $row->pret,
                    'proc_tva' => (float) $row->proc_tva,
                ]),
                'payments' => $invoice->payments->map(fn ($payment) => [
                    'id' => $payment->id,
                    'data_doc' => $payment->data_doc?->toDateString(),
                    'tip_doc' => $payment->tip_doc,
                    'nr_doc' => $payment->nr_doc,
                    'data_repartizare' => $payment->data_repartizare?->toDateString(),
                    'val_fin' => (float) $payment->val_fin,
                    'val_com' => (float) $payment->val_com,
                    'moneda' => $payment->moneda,
                ]),
            ],
        ]);
    }
}
