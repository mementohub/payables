<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestEvent;
use App\Models\User;
use App\Services\Etrip\CheckinCostCheckService;
use App\Services\PaymentRequests\PaymentRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PaymentRequestController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString();
        $kind = $request->string('kind')->toString();
        $search = $request->string('search')->toString();
        $companyId = $request->exists('company_id')
            ? ($request->integer('company_id') ?: null)
            : null;

        $base = PaymentRequest::query()
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId));

        $requests = (clone $base)
            ->with(['company:id,name', 'partner:id,name', 'createdBy:id,name'])
            ->withCount('invoices')
            ->when(in_array($status, PaymentRequest::STATUSES, true), fn ($query) => $query->where('status', $status))
            ->when(in_array($kind, PaymentRequest::KINDS, true), fn ($query) => $query->where('kind', $kind))
            ->when($search, function ($query, string $term) {
                $query->where(function ($query) use ($term) {
                    $query->where('supplier_name', 'like', "%{$term}%")
                        ->orWhere('reference', 'like', "%{$term}%");
                });
            })
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (PaymentRequest $paymentRequest) => $this->row($paymentRequest));

        $counts = (clone $base)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return Inertia::render('payment-requests/index', [
            'requests' => $requests,
            'filters' => [
                'status' => in_array($status, PaymentRequest::STATUSES, true) ? $status : null,
                'kind' => in_array($kind, PaymentRequest::KINDS, true) ? $kind : null,
                'search' => $search ?: null,
            ],
            'statuses' => PaymentRequest::STATUS_LABELS,
            'counts' => collect(PaymentRequest::STATUSES)
                ->mapWithKeys(fn (string $value) => [$value => (int) ($counts[$value] ?? 0)])
                ->all(),
        ]);
    }

    public function show(Request $request, PaymentRequest $paymentRequest): Response
    {
        $paymentRequest->load([
            'company:id,name',
            'partner:id,name',
            'etripSupplier:id,code,name,currency',
            'createdBy:id,name',
            'statusUpdatedBy:id,name',
            'events.user:id,name',
            'invoices' => fn ($query) => $query->orderByDesc('data_doc'),
        ]);

        $candidates = $paymentRequest->partner_id === null
            ? collect()
            : Invoice::query()
                ->where('company_id', $paymentRequest->company_id)
                ->where('partner_id', $paymentRequest->partner_id)
                ->furnizor()
                ->whereRaw('val_mon - val_mon_paid - val_mon_storno > 0.01')
                ->whereNotIn('id', $paymentRequest->invoices->pluck('id'))
                ->orderByDesc('data_doc')
                ->limit(20)
                ->get();

        $user = $request->user();

        return Inertia::render('payment-requests/show', [
            'request' => [
                ...$this->row($paymentRequest),
                'etrip_supplier' => $paymentRequest->etripSupplier ? [
                    'id' => $paymentRequest->etripSupplier->id,
                    'code' => $paymentRequest->etripSupplier->code,
                    'name' => $paymentRequest->etripSupplier->name,
                    'currency' => $paymentRequest->etripSupplier->currency,
                ] : null,
                'snapshot' => $paymentRequest->snapshot,
                'invoices' => $paymentRequest->invoices->map(fn (Invoice $invoice) => $this->invoiceRow($invoice))->values()->all(),
                'events' => $paymentRequest->events->map(fn (PaymentRequestEvent $event) => [
                    'id' => $event->id,
                    'type' => $event->type,
                    'body' => $event->body,
                    'payload' => $event->payload,
                    'user' => $event->user?->name,
                    'created_at' => $event->created_at?->toIso8601String(),
                ])->values()->all(),
            ],
            'candidateInvoices' => $candidates->map(fn (Invoice $invoice) => $this->invoiceRow($invoice))->values()->all(),
            'statuses' => PaymentRequest::STATUS_LABELS,
            'categories' => CheckinCostCheckService::CATEGORY_LABELS,
            'currentUser' => [
                'id' => $user?->id,
                'is_plati' => $user?->hasRole(User::ROLE_TREASURY) ?? false,
            ],
        ]);
    }

    public function store(Request $request, PaymentRequestService $service): RedirectResponse
    {
        $companyId = $request->integer('company_id');

        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'kind' => ['required', Rule::in(PaymentRequest::KINDS)],
            'supplier_name' => ['required', 'string', 'max:150'],
            'partner_id' => ['nullable', 'integer', Rule::exists('partners', 'id')->where('company_id', $companyId)],
            'etrip_supplier_id' => ['nullable', 'integer', Rule::exists('etrip_suppliers', 'id')->where('company_id', $companyId)],
            'reference' => ['nullable', 'string', 'max:100'],
            'requested_amount' => ['required', 'numeric', 'min:0'],
            'requested_currency' => ['required', 'string', 'max:5'],
            'checkin_from' => ['nullable', 'date', 'required_if:kind,'.PaymentRequest::KIND_CHECKIN],
            'checkin_to' => ['nullable', 'date', 'required_if:kind,'.PaymentRequest::KIND_CHECKIN, 'after_or_equal:checkin_from'],
            'category' => ['nullable', Rule::in(CheckinCostCheckService::CATEGORIES)],
            'expected_amount' => ['nullable', 'numeric'],
            'expected_currency' => ['nullable', 'string', 'max:5'],
            'difference' => ['nullable', 'numeric'],
            'difference_pct' => ['nullable', 'numeric'],
            'level' => ['nullable', Rule::in(['ok', 'warn', 'crit'])],
            'verdict' => ['nullable', 'string', 'max:20'],
            'snapshot' => ['nullable', 'array'],
            'status' => ['nullable', Rule::in([PaymentRequest::STATUS_PENDING, PaymentRequest::STATUS_PAYABLE, PaymentRequest::STATUS_DISPUTED])],
            'note' => ['nullable', 'string', 'max:1000'],
            'invoice_ids' => ['nullable', 'array'],
            'invoice_ids.*' => ['integer', Rule::exists('invoices', 'id')->where('company_id', $companyId)],
        ]);

        $paymentRequest = $service->create(
            $request->user(),
            Arr::except($validated, ['invoice_ids']),
            array_map('intval', $validated['invoice_ids'] ?? []),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "Cererea #{$paymentRequest->id} a fost salvată în registru."]);

        return to_route('payment-requests.show', $paymentRequest);
    }

    public function updateStatus(Request $request, PaymentRequest $paymentRequest, PaymentRequestService $service): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(PaymentRequest::STATUSES)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $service->changeStatus($paymentRequest, $request->user(), $validated['status'], $validated['note'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Status actualizat.']);

        return back();
    }

    public function comment(Request $request, PaymentRequest $paymentRequest, PaymentRequestService $service): RedirectResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $service->comment($paymentRequest, $request->user(), $validated['body']);

        return back();
    }

    public function linkInvoice(Request $request, PaymentRequest $paymentRequest, PaymentRequestService $service): RedirectResponse
    {
        $validated = $request->validate([
            'invoice_id' => ['required', 'integer', Rule::exists('invoices', 'id')->where('company_id', $paymentRequest->company_id)],
        ]);

        $service->linkInvoice($paymentRequest, $request->user(), Invoice::query()->findOrFail($validated['invoice_id']));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Factura a fost legată de cerere.']);

        return back();
    }

    public function unlinkInvoice(Request $request, PaymentRequest $paymentRequest, Invoice $invoice, PaymentRequestService $service): RedirectResponse
    {
        $service->unlinkInvoice($paymentRequest, $request->user(), $invoice);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Legătura cu factura a fost ștearsă.']);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(PaymentRequest $paymentRequest): array
    {
        return [
            'id' => $paymentRequest->id,
            'created_at' => $paymentRequest->created_at?->toIso8601String(),
            'kind' => $paymentRequest->kind,
            'supplier_name' => $paymentRequest->supplier_name,
            'company' => $paymentRequest->company ? ['id' => $paymentRequest->company->id, 'name' => $paymentRequest->company->name] : null,
            'partner' => $paymentRequest->partner ? ['id' => $paymentRequest->partner->id, 'name' => $paymentRequest->partner->name] : null,
            'reference' => $paymentRequest->reference,
            'checkin_from' => $paymentRequest->checkin_from?->toDateString(),
            'checkin_to' => $paymentRequest->checkin_to?->toDateString(),
            'category' => $paymentRequest->category,
            'requested_amount' => (float) $paymentRequest->requested_amount,
            'requested_currency' => $paymentRequest->requested_currency,
            'expected_amount' => $paymentRequest->expected_amount !== null ? (float) $paymentRequest->expected_amount : null,
            'expected_currency' => $paymentRequest->expected_currency,
            'difference' => $paymentRequest->difference !== null ? (float) $paymentRequest->difference : null,
            'difference_pct' => $paymentRequest->difference_pct !== null ? (float) $paymentRequest->difference_pct : null,
            'level' => $paymentRequest->level,
            'verdict' => $paymentRequest->verdict,
            'status' => $paymentRequest->status,
            'status_label' => $paymentRequest->statusLabel(),
            'note' => $paymentRequest->note,
            'created_by' => $paymentRequest->createdBy?->name,
            'status_updated_at' => $paymentRequest->status_updated_at?->toIso8601String(),
            'status_updated_by' => $paymentRequest->statusUpdatedBy?->name,
            'invoices_count' => $paymentRequest->invoices_count ?? $paymentRequest->invoices()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceRow(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'tip_doc' => $invoice->tip_doc,
            'nr_doc' => $invoice->nr_doc,
            'data_doc' => $invoice->data_doc?->toDateString(),
            'data_scadenta' => $invoice->data_scadenta?->toDateString(),
            'moneda' => $invoice->moneda,
            'val_mon' => (float) $invoice->val_mon,
            'rest' => $invoice->outstandingAmount(),
            'payment_status' => $invoice->payment_status,
            'is_fully_approved' => $invoice->approval_status === 'approved',
            'responsabili_approved' => in_array($invoice->approval_status, ['final', 'approved'], true),
        ];
    }
}
