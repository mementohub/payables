<?php

namespace App\Http\Controllers;

use App\Models\Builders\InvoiceBuilder;
use App\Models\Company;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\InvoiceApproval;
use App\Models\User;
use App\Services\Invoices\InvoiceApprovalService;
use App\Services\Invoices\InvoiceCommentService;
use App\Services\Invoices\InvoiceListQuery;
use App\Services\Invoices\InvoicePaymentService;
use App\Services\Invoices\InvoicePresenter;
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
        private readonly InvoiceApprovalService $approvalService,
        private readonly InvoiceCommentService $commentService,
        private readonly InvoicePaymentService $paymentService,
    ) {}

    public function emise(Request $request): Response
    {
        return $this->list($request, 'emise');
    }

    public function primite(Request $request): Response
    {
        return $this->list($request, 'primite');
    }

    public function exportEmise(Request $request): StreamedResponse
    {
        return $this->export($request, 'emise');
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

        $companies = Company::orderBy('name')->get(['id', 'name']);
        $activeCompany = $filters['company_id']
            ? $companies->firstWhere('id', $filters['company_id'])
            : null;

        return Inertia::render('invoices/index', [
            'invoices' => $invoices,
            'scope' => $scope,
            'filters' => $filters,
            'companies' => $companies,
            'activeCompany' => $activeCompany
                ? ['id' => (int) $activeCompany->id, 'name' => $activeCompany->name]
                : null,
            'currentUser' => $this->currentUserContext($request),
            'availableResponsibles' => $scope === 'primite'
                ? User::query()
                    ->whereHas('departments', fn ($d) => $d->where('type', Department::TYPE_RESPONSABIL))
                    ->orderBy('name')
                    ->get(['id', 'name'])
                : [],
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
            : ['Data', 'Scadență', 'Tip doc', 'Număr', 'Furnizor', 'CUI', 'Companie', 'Monedă', 'Total', 'TVA', 'Plătit', 'Rest', 'Status plată', 'Bun de plată'];

        $rows = function () use ($query, $scope) {
            foreach ($query->lazy(500) as $invoice) {
                $rest = round((float) $invoice->val_mon - (float) $invoice->val_mon_paid, 2);
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
                    $this->presenter->paymentStatusLabel($invoice->payment_status),
                ];

                if ($scope === 'primite') {
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
            'partner.responsabilDepartments',
            'company',
            'details',
            'payments.bankStatementLine.statement:id,data_extras,banca,iban',
            'approvals.user:id,name,email',
            'approvals.department:id,name,type',
            'approvals.revokedBy:id,name',
            'events' => fn ($q) => $q->orderByDesc('created_at')->orderByDesc('id'),
            'events.user:id,name,email',
            'events.department:id,name,type',
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
                'payment_status' => $invoice->payment_status,
                'payment_status_updated_at' => $invoice->payment_status_updated_at?->toIso8601String(),
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
                'approval' => $this->presenter->approvalPayload($invoice),
                'timeline' => $this->presenter->timelinePayload($invoice),
                'source_invoice' => $this->presenter->sourceInvoicePayload($invoice),
                'baza' => $this->presenter->bazaPayload($invoice),
            ],
            'activeCompany' => ['id' => (int) $invoice->company->id, 'name' => $invoice->company->name],
            'currentUser' => $this->currentUserContext($request),
        ]);
    }

    public function approve(Request $request, Invoice $invoice): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        $validated = $request->validate([
            'department_id' => ['required', 'integer', 'exists:departments,id'],
        ]);

        $department = Department::query()->findOrFail($validated['department_id']);

        $this->approvalService->approve($invoice, $department, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Bun de plată înregistrat.']);

        return back();
    }

    public function revokeApproval(Request $request, Invoice $invoice, InvoiceApproval $approval): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($approval->invoice_id === $invoice->id, 404);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $this->approvalService->revoke($approval, $user, $validated['reason']);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Aprobarea a fost retrasă.']);

        return back();
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
            'status' => ['required', 'string', 'in:'.implode(',', Invoice::PAYMENT_STATUSES)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->paymentService->updateStatus($invoice, $user, $validated['status'], $validated['note'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Status plată actualizat.']);

        return back();
    }

    private function authorizeShow(Request $request, Invoice $invoice): void
    {
        // Authorization temporarily disabled — all data visible to every authenticated user.
    }

    /**
     * @return array{id: ?int, name: ?string, responsabil_department_ids: list<int>, ordonator_department_ids: list<int>, plati_department_ids: list<int>}
     */
    private function currentUserContext(Request $request): array
    {
        $user = $request->user();

        if (! $user) {
            return [
                'id' => null,
                'name' => null,
                'responsabil_department_ids' => [],
                'ordonator_department_ids' => [],
                'plati_department_ids' => [],
            ];
        }

        $departments = $user->departments()->get(['departments.id', 'departments.type']);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'responsabil_department_ids' => $departments
                ->where('type', Department::TYPE_RESPONSABIL)
                ->pluck('id')
                ->values()
                ->all(),
            'ordonator_department_ids' => $departments
                ->where('type', Department::TYPE_ORDONATOR)
                ->pluck('id')
                ->values()
                ->all(),
            'plati_department_ids' => $departments
                ->where('type', Department::TYPE_PLATI)
                ->pluck('id')
                ->values()
                ->all(),
        ];
    }
}
