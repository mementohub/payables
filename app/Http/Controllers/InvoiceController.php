<?php

namespace App\Http\Controllers;

use App\Models\Builders\InvoiceBuilder;
use App\Models\Company;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\InvoiceLineDepartment;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\Approvals\ApprovalPresenter;
use App\Services\Invoices\InvoiceCommentService;
use App\Services\Invoices\InvoiceListQuery;
use App\Services\Invoices\InvoicePaymentService;
use App\Services\Invoices\InvoicePresenter;
use App\Services\Maintenance\ArtisanRunner;
use App\Services\Xlsx\XlsxWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoicePresenter $presenter,
        private readonly InvoiceCommentService $commentService,
        private readonly InvoicePaymentService $paymentService,
    ) {}

    public function primite(Request $request): Response
    {
        return $this->list($request, 'primite');
    }

    public function exportPrimite(Request $request): StreamedResponse
    {
        return $this->export($request, 'primite');
    }

    private function list(Request $request, string $scope): Response
    {
        $filters = $this->parseFilters($request);

        $paginator = $this->buildListQuery($request, $scope)
            ->orderByDesc('data_doc')
            ->paginate(25)
            ->withQueryString();

        $this->presenter->preloadBazaInvoices($paginator->items());

        $invoices = $paginator->through(fn (Invoice $invoice) => $this->presenter->listRow($invoice, $scope));

        $companies = Company::orderBy('name')->get(['id', 'name', 'last_synced_at']);
        $activeCompany = $filters['company_id']
            ? $companies->firstWhere('id', $filters['company_id'])
            : null;

        return Inertia::render('invoices/index', [
            'invoices' => $invoices,
            'scope' => $scope,
            'filters' => $filters,
            'companies' => $companies,
            'syncRunning' => app(ArtisanRunner::class)->isRunning(ArtisanRunner::SYNC),
            'activeCompany' => $activeCompany
                ? ['id' => (int) $activeCompany->id, 'name' => $activeCompany->name]
                : null,
            'currentUser' => $this->currentUserContext($request),
            'departments' => Department::query()->whereNotNull('code')->orderBy('sort')->get(['id', 'name']),
        ]);
    }

    private function export(Request $request, string $scope): StreamedResponse
    {
        $ids = $request->input('ids');
        $selectAll = $request->boolean('select_all');

        if (! $selectAll && (! is_array($ids) || empty($ids))) {
            abort(422, 'Selecție goală.');
        }

        $query = $this->buildListQuery($request, $scope)->orderByDesc('data_doc');

        if (! $selectAll) {
            $idList = array_values(array_filter(array_map('intval', $ids), fn ($id) => $id > 0));
            $query->whereIn('invoices.id', $idList);
        }

        $headers = $scope === 'emise'
            ? ['Data', 'Scadență', 'Tip doc', 'Număr', 'Client', 'CUI', 'Companie', 'Monedă', 'Total', 'TVA', 'Plătit', 'Rest', 'Status plată']
            : ['Data', 'Scadență', 'Tip doc', 'Număr', 'Furnizor', 'CUI', 'Companie', 'Monedă', 'Total', 'TVA', 'Plătit', 'Rest', 'Status plată', 'Departament', 'Aprobare'];

        $rows = function () use ($query, $scope) {
            foreach ($query->lazy(500) as $invoice) {
                $rest = $invoice->outstandingAmount();
                $row = [
                    $invoice->data_doc?->toDateString() ?? '',
                    $invoice->data_scadenta?->toDateString() ?? '',
                    $invoice->tip_doc,
                    $invoice->nr_doc,
                    $invoice->partner?->name ?? '',
                    $invoice->partner?->cui ?? '',
                    $invoice->company->name,
                    $invoice->moneda ?? '',
                    (float) $invoice->val_mon,
                    (float) $invoice->val_mon_tva,
                    (float) $invoice->val_mon_paid,
                    $rest,
                    $this->presenter->paymentStatusLabel($invoice->paymentStatus()),
                ];

                if ($scope === 'primite') {
                    $row[] = $invoice->department?->name ?? '';
                    $row[] = $this->presenter->exportApprovalLabel($invoice);
                }

                yield $row;
            }
        };

        $filename = ($scope === 'emise' ? 'facturi-emise' : 'facturi-primite').'-'.now()->format('Ymd-His').'.xlsx';

        return XlsxWriter::streamDownload($filename, $headers, $rows(), $scope === 'emise' ? 'Facturi emise' : 'Facturi primite');
    }

    private function buildListQuery(Request $request, string $scope): InvoiceBuilder
    {
        return InvoiceListQuery::build($request, $scope);
    }

    /**
     * @return array{search: ?string, company_id: ?int, payment: ?string, data_doc_from: ?string, data_doc_to: ?string, data_scadenta_from: ?string, data_scadenta_to: ?string, approval: ?string, responsible_id: ?int}
     */
    private function parseFilters(Request $request): array
    {
        return InvoiceListQuery::parseFilters($request);
    }

    public function show(Request $request, Invoice $invoice): Response
    {
        $this->authorizeShow($request, $invoice);

        $invoice->load([
            'partner',
            'company',
            'details',
            ...ApprovalPresenter::relations(),
            'payments.bankStatementLine.statement:id,data_extras,banca,iban',
            'events' => fn ($q) => $q->orderByDesc('created_at')->orderByDesc('id'),
            'events.user:id,name,email',
            'events.department:id,name',
            'sourceCompany:id,name',
            'sourceInvoice:id,company_id,partner_id,data_doc,tip_doc,nr_doc,tip_doc_baza,nr_doc_baza,data_doc_baza',
            'sourceInvoice.partner:id,name,cui',
            'sourceInvoice.company:id,name',
        ]);

        $this->presenter->preloadBazaInvoices([$invoice]);

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
                'val_mon_storno' => (float) $invoice->val_mon_storno,
                'payment_status' => $invoice->paymentStatus(),
                'payment_status_erp' => $invoice->erpPaymentStatus(),
                'payment_status_manual' => $invoice->payment_status_manual,
                'payment_status_updated_at' => $invoice->payment_status_updated_at?->toIso8601String(),
                'data_scadenta' => $invoice->data_scadenta?->toDateString(),
                'data_inchidere' => $invoice->data_inchidere?->toDateString(),
                'emitent' => $invoice->emitent,
                'com_int' => $invoice->com_int,
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
                'payments' => $invoice->payments->map(function ($payment) {
                    $statement = $payment->bankStatementLine?->statement;

                    return [
                        'id' => $payment->id,
                        'data_doc' => $payment->data_doc?->toDateString(),
                        'tip_doc' => $payment->tip_doc,
                        'nr_doc' => $payment->nr_doc,
                        'data_repartizare' => $payment->data_repartizare?->toDateString(),
                        'val_fin' => (float) $payment->val_fin,
                        'val_com' => (float) $payment->val_com,
                        'moneda' => $payment->moneda,
                        'bank_statement' => $statement ? [
                            'id' => $statement->id,
                            'line_id' => $payment->bank_statement_line_id,
                            'data_extras' => $statement->data_extras?->toDateString(),
                            'banca' => $statement->banca,
                            'iban' => $statement->iban,
                        ] : null,
                    ];
                }),
                'workflow' => $this->presenter->workflowPayload($invoice),
                'routing' => $this->routingPayload($invoice),
                'timeline' => $this->presenter->timelinePayload($invoice),
                'source_invoice' => $this->presenter->sourceInvoicePayload($invoice),
                'payment_requests' => $invoice->paymentRequests()
                    ->with('createdBy:id,name')
                    ->orderByDesc('id')
                    ->get()
                    ->map(fn (PaymentRequest $paymentRequest) => [
                        'id' => $paymentRequest->id,
                        'kind' => $paymentRequest->kind,
                        'status' => $paymentRequest->status,
                        'status_label' => $paymentRequest->statusLabel(),
                        'level' => $paymentRequest->level,
                        'requested_amount' => (float) $paymentRequest->requested_amount,
                        'requested_currency' => $paymentRequest->requested_currency,
                        'difference_pct' => $paymentRequest->difference_pct !== null ? (float) $paymentRequest->difference_pct : null,
                        'checkin_from' => $paymentRequest->checkin_from?->toDateString(),
                        'checkin_to' => $paymentRequest->checkin_to?->toDateString(),
                        'created_by' => $paymentRequest->createdBy?->name,
                        'created_at' => $paymentRequest->created_at?->toIso8601String(),
                    ])
                    ->all(),
                'baza' => $this->presenter->bazaPayload($invoice),
                'com_int_matches' => [],
            ],
            'activeCompany' => ['id' => (int) $invoice->company->id, 'name' => $invoice->company->name],
            'currentUser' => $this->currentUserContext($request),
            'departments' => Department::query()->whereNotNull('code')->orderBy('sort')->get(['id', 'name', 'group', 'parent_id']),
        ]);
    }

    public function comment(Request $request, Invoice $invoice): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        $validated = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:5000'],
        ]);

        $this->commentService->add($invoice, $user, $validated['body']);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Comentariu adăugat.']);

        return back();
    }

    public function updatePaymentStatus(Request $request, Invoice $invoice): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', [...Invoice::PAYMENT_STATUSES, InvoicePaymentService::AUTO])],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->paymentService->updateStatus($invoice, $user, $validated['status'], $validated['note'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => $validated['status'] === InvoicePaymentService::AUTO
            ? 'Marcajul manual a fost eliminat; statusul urmează ERP-ul.'
            : 'Status plată actualizat.']);

        return back();
    }

    private function authorizeShow(Request $request, Invoice $invoice): void
    {
        // Authorization temporarily disabled — all data visible to every authenticated user.
    }

    /**
     * Who is looking, and what they may do on an invoice.
     *
     * @return array{id: ?int, name: ?string, department_ids: list<int>, roles: list<string>}
     */
    private function currentUserContext(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $user?->id,
            'name' => $user?->name,
            'department_ids' => $user ? ($user->isAdmin() ? Department::query()->whereNotNull('code')->pluck('id')->all() : $user->departmentIds()) : [],
            'roles' => $user ? array_values((array) ($user->roles ?? [])) : [],
        ];
    }

    /**
     * Each line with the department it was routed to and why.
     *
     * @return list<array<string, mixed>>
     */
    private function routingPayload(Invoice $invoice): array
    {
        $routing = InvoiceLineDepartment::query()
            ->where('invoice_id', $invoice->id)
            ->with(['department:id,name', 'channel:id,name', 'assignedBy:id,name'])
            ->get()
            ->keyBy('scv');

        return $invoice->details->sortBy('scv')->map(fn ($line) => [
            'scv' => $line->scv,
            'account' => $line->account,
            'loc' => $line->loc,
            'com_int' => $line->com_int,
            'amount' => round((float) $line->cant * (float) $line->pret, 2),
            'department' => $routing->get($line->scv)?->department?->name,
            'channel' => $routing->get($line->scv)?->channel?->name,
            'rule' => $routing->get($line->scv)?->rule,
            'detail' => $routing->get($line->scv)?->detail,
            'manual_by' => $routing->get($line->scv)?->is_manual ? $routing->get($line->scv)?->assignedBy?->name : null,
        ])->values()->all();
    }
}
