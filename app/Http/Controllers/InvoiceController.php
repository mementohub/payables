<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\InvoiceApproval;
use App\Models\User;
use App\Services\Xlsx\XlsxWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceController extends Controller
{
    public function emise(Request $request): Response
    {
        abort_unless($request->user()?->isMaster(), 403);

        return $this->list($request, 'emise');
    }

    public function primite(Request $request): Response
    {
        return $this->list($request, 'primite');
    }

    private function list(Request $request, string $scope): Response
    {
        $invoices = $this->buildListQuery($request, $scope)
            ->orderByDesc('data_doc')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Invoice $invoice) => $this->transformForList($invoice, $scope));

        $search = $request->string('search')->toString();
        $companyId = $request->integer('company_id');
        $payment = $request->string('payment')->toString();
        $dataDocFrom = $request->string('data_doc_from')->toString();
        $dataDocTo = $request->string('data_doc_to')->toString();
        $scadentaFrom = $request->string('data_scadenta_from')->toString();
        $scadentaTo = $request->string('data_scadenta_to')->toString();
        $approval = $request->string('approval')->toString();
        $responsibleId = $request->integer('responsible_id');

        return Inertia::render('invoices/index', [
            'invoices' => $invoices,
            'scope' => $scope,
            'filters' => [
                'search' => $search ?: null,
                'company_id' => $companyId ?: null,
                'payment' => $payment ?: null,
                'data_doc_from' => $dataDocFrom ?: null,
                'data_doc_to' => $dataDocTo ?: null,
                'data_scadenta_from' => $scadentaFrom ?: null,
                'data_scadenta_to' => $scadentaTo ?: null,
                'approval' => $approval ?: null,
                'responsible_id' => $responsibleId ?: null,
            ],
            'companies' => Company::orderBy('name')->get(['id', 'name']),
            'currentUser' => $this->currentUserContext($request),
            'availableResponsibles' => $scope === 'primite'
                ? User::query()
                    ->whereHas('departments', fn ($d) => $d->where('type', Department::TYPE_SUPERVISOR))
                    ->orderBy('name')
                    ->get(['id', 'name'])
                : [],
        ]);
    }

    public function exportEmise(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->isMaster(), 403);

        return $this->export($request, 'emise');
    }

    public function exportPrimite(Request $request): StreamedResponse
    {
        return $this->export($request, 'primite');
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
                    $this->paymentStatusLabel($invoice->payment_status),
                ];

                if ($scope === 'primite') {
                    $row[] = $this->approvalStageLabel($invoice);
                }

                yield $row;
            }
        };

        $filename = ($scope === 'emise' ? 'facturi-emise' : 'facturi-primite').'-'.now()->format('Ymd-His').'.xlsx';

        return XlsxWriter::streamDownload($filename, $headers, $rows(), $scope === 'emise' ? 'Facturi emise' : 'Facturi primite');
    }

    private function buildListQuery(Request $request, string $scope): Builder
    {
        $user = $request->user();
        $isMaster = (bool) $user?->isMaster();

        $search = $request->string('search')->toString();
        $companyId = $request->integer('company_id');
        $payment = $request->string('payment')->toString();
        $dataDocFrom = $request->string('data_doc_from')->toString();
        $dataDocTo = $request->string('data_doc_to')->toString();
        $scadentaFrom = $request->string('data_scadenta_from')->toString();
        $scadentaTo = $request->string('data_scadenta_to')->toString();
        $approval = $request->string('approval')->toString();
        $responsibleId = $request->integer('responsible_id');

        return Invoice::query()
            ->with([
                'partner:id,name,cui',
                'partner.supervisorDepartments:id,name,type',
                'company:id,name',
                'approvals:id,invoice_id,department_id,user_id,role,approved_at',
                'approvals.user:id,name,email',
                'approvals.department:id,name,type',
            ])
            ->when($scope === 'primite', fn ($q) => $q->furnizor())
            ->when($scope === 'emise', fn ($q) => $q->client())
            ->when($scope === 'primite' && ! $isMaster, function ($q) use ($user) {
                $deptIds = $user?->departmentIds() ?? [];
                $q->where(function ($q) use ($deptIds) {
                    $q->whereDoesntHave('partner.departments')
                        ->orWhereHas('partner.departments', fn ($d) => $d->whereIn('departments.id', $deptIds));
                });
            })
            ->when($companyId, fn ($q, $id) => $q->where('company_id', $id))
            ->when($dataDocFrom, fn ($q, $d) => $q->where('data_doc', '>=', $d))
            ->when($dataDocTo, fn ($q, $d) => $q->where('data_doc', '<=', $d))
            ->when($scadentaFrom, fn ($q, $d) => $q->where('data_scadenta', '>=', $d))
            ->when($scadentaTo, fn ($q, $d) => $q->where('data_scadenta', '<=', $d))
            ->when($payment === 'paid', fn ($q) => $q->whereColumn('val_mon_paid', '>=', DB::raw('val_mon - 0.01')))
            ->when($payment === 'unpaid', fn ($q) => $q->where('val_mon_paid', '<=', 0.009))
            ->when($payment === 'partial', function ($q) {
                $q->where('val_mon_paid', '>', 0.009)
                    ->whereColumn('val_mon_paid', '<', DB::raw('val_mon - 0.01'));
            })
            ->when($scope === 'primite' && $approval === 'ok', fn ($q) => $q->where('is_fully_approved', true))
            ->when($scope === 'primite' && $approval === 'supervisors_ok', function ($q) {
                $q->where('is_fully_approved', false)
                    ->whereNotNull('supervisors_approved_at');
            })
            ->when($scope === 'primite' && $approval === 'pending', function ($q) {
                $q->where('is_fully_approved', false)
                    ->whereHas('partner.supervisorDepartments');
            })
            ->when($scope === 'primite' && $approval === 'needs_approval', function ($q) {
                $q->whereHas('partner.supervisorDepartments');
            })
            ->when($scope === 'primite' && $approval === 'na', function ($q) {
                $q->whereDoesntHave('partner.supervisorDepartments');
            })
            ->when($scope === 'primite' && $responsibleId, function ($q, $uid) {
                $q->whereHas('partner.supervisorDepartments.members', fn ($m) => $m->where('users.id', $uid));
            })
            ->when($search, function ($q, $term) {
                $q->where(function ($q) use ($term) {
                    $q->where('nr_doc', 'like', "%{$term}%")
                        ->orWhereHas('partner', fn ($p) => $p->where('name', 'like', "%{$term}%"));
                });
            });
    }

    private function paymentStatusLabel(string $status): string
    {
        return match ($status) {
            'paid' => 'Plătită',
            'unpaid' => 'Neplătită',
            'partial' => 'Parțial',
            default => $status,
        };
    }

    private function approvalStageLabel(Invoice $invoice): string
    {
        $supervisorDepts = $invoice->partner?->supervisorDepartments ?? collect();
        if ($supervisorDepts->isEmpty()) {
            return 'Fără departament';
        }

        if ($invoice->is_fully_approved) {
            return 'Bun de plată';
        }
        if ($invoice->supervisors_approved_at !== null) {
            return 'Așteaptă master';
        }

        return 'Așteaptă supervizor';
    }

    public function show(Request $request, Invoice $invoice): Response
    {
        $user = $request->user();
        $isMaster = (bool) $user?->isMaster();

        if ($invoice->partener_type === 'client') {
            abort_unless($isMaster, 403);
        }

        if ($invoice->partener_type === 'furnizor' && ! $isMaster) {
            $invoice->loadMissing('partner.departments:id');
            $partnerDeptIds = $invoice->partner?->departments->pluck('id') ?? collect();
            $userDeptIds = collect($user?->departmentIds() ?? []);

            abort_unless(
                $partnerDeptIds->isEmpty() || $partnerDeptIds->intersect($userDeptIds)->isNotEmpty(),
                403,
            );
        }

        $invoice->load([
            'partner',
            'partner.supervisorDepartments',
            'company',
            'details',
            'payments',
            'approvals.user:id,name,email',
            'approvals.department:id,name,type',
        ]);

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
                'approval' => $this->approvalPayload($invoice),
            ],
        ]);
    }

    public function approve(Request $request, Invoice $invoice): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        if ($invoice->partener_type !== 'furnizor') {
            throw ValidationException::withMessages(['invoice' => 'Doar facturile primite pot fi aprobate.']);
        }

        $validated = $request->validate([
            'department_id' => ['required', 'integer', 'exists:departments,id'],
        ]);

        $department = Department::query()->findOrFail($validated['department_id']);

        $isMember = $user->departments()->where('departments.id', $department->id)->exists();
        if (! $isMember) {
            throw new AuthorizationException('Nu faci parte din acest departament.');
        }

        $invoice->loadMissing('partner.supervisorDepartments');

        DB::transaction(function () use ($invoice, $department, $user) {
            $fresh = Invoice::whereKey($invoice->id)->lockForUpdate()->first();

            if ($department->type === Department::TYPE_SUPERVISOR) {
                $this->recordSupervisorApproval($fresh, $department, $user);
            } elseif ($department->type === Department::TYPE_MASTER) {
                $this->recordMasterApproval($fresh, $department, $user);
            } else {
                throw ValidationException::withMessages(['department_id' => 'Tip departament necunoscut.']);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Bun de plată înregistrat.']);

        return back();
    }

    private function recordSupervisorApproval(Invoice $invoice, Department $department, User $user): void
    {
        $assigned = $invoice->partner?->supervisorDepartments ?? collect();
        if (! $assigned->contains('id', $department->id)) {
            throw new AuthorizationException('Departamentul nu este atribuit acestui furnizor.');
        }

        if ($invoice->supervisors_approved_at !== null) {
            throw ValidationException::withMessages(['department_id' => 'Etapa de supervizori este deja închisă.']);
        }

        $existing = InvoiceApproval::where('invoice_id', $invoice->id)
            ->where('department_id', $department->id)
            ->exists();
        if ($existing) {
            throw ValidationException::withMessages(['department_id' => 'Departamentul a confirmat deja această factură.']);
        }

        $now = Carbon::now();

        InvoiceApproval::create([
            'invoice_id' => $invoice->id,
            'department_id' => $department->id,
            'user_id' => $user->id,
            'role' => InvoiceApproval::ROLE_SUPERVISOR,
            'approved_at' => $now,
        ]);

        $approvedDeptIds = InvoiceApproval::where('invoice_id', $invoice->id)
            ->where('role', InvoiceApproval::ROLE_SUPERVISOR)
            ->pluck('department_id');

        $requiredDeptIds = $assigned->pluck('id');

        if ($requiredDeptIds->diff($approvedDeptIds)->isEmpty()) {
            $invoice->forceFill(['supervisors_approved_at' => $now])->save();
        }
    }

    private function recordMasterApproval(Invoice $invoice, Department $department, User $user): void
    {
        if ($invoice->supervisors_approved_at === null) {
            throw ValidationException::withMessages(['department_id' => 'Masterii pot aproba doar după supervizori.']);
        }

        if ($invoice->is_fully_approved) {
            throw ValidationException::withMessages(['department_id' => 'Factura este deja aprobată complet.']);
        }

        $now = Carbon::now();

        InvoiceApproval::create([
            'invoice_id' => $invoice->id,
            'department_id' => $department->id,
            'user_id' => $user->id,
            'role' => InvoiceApproval::ROLE_MASTER,
            'approved_at' => $now,
        ]);

        $invoice->forceFill([
            'is_fully_approved' => true,
            'fully_approved_at' => $now,
        ])->save();
    }

    private function transformForList(Invoice $invoice, string $scope): array
    {
        $row = [
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
        ];

        if ($scope === 'primite') {
            $row['approval'] = $this->approvalPayload($invoice);
        }

        return $row;
    }

    private function approvalPayload(Invoice $invoice): array
    {
        $supervisorDepts = $invoice->partner?->supervisorDepartments ?? collect();
        $approvals = $invoice->approvals;

        $supervisorApprovalsByDept = $approvals
            ->where('role', InvoiceApproval::ROLE_SUPERVISOR)
            ->keyBy('department_id');

        $supervisorSteps = $supervisorDepts->map(function (Department $dept) use ($supervisorApprovalsByDept) {
            $approval = $supervisorApprovalsByDept->get($dept->id);

            return [
                'department_id' => $dept->id,
                'department_name' => $dept->name,
                'approved' => (bool) $approval,
                'approved_by' => $approval?->user ? [
                    'id' => $approval->user->id,
                    'name' => $approval->user->name,
                ] : null,
                'approved_at' => $approval?->approved_at?->toIso8601String(),
            ];
        })->values();

        $masterApproval = $approvals->firstWhere('role', InvoiceApproval::ROLE_MASTER);

        $needsApproval = $supervisorDepts->isNotEmpty();
        $stage = 'na';
        if ($needsApproval) {
            if ($invoice->is_fully_approved) {
                $stage = 'ok';
            } elseif ($invoice->supervisors_approved_at !== null) {
                $stage = 'supervisors_ok';
            } else {
                $stage = 'pending';
            }
        }

        return [
            'needs_approval' => $needsApproval,
            'stage' => $stage,
            'supervisors_approved_at' => $invoice->supervisors_approved_at?->toIso8601String(),
            'is_fully_approved' => (bool) $invoice->is_fully_approved,
            'fully_approved_at' => $invoice->fully_approved_at?->toIso8601String(),
            'supervisor_steps' => $supervisorSteps,
            'master' => $masterApproval ? [
                'department_id' => $masterApproval->department_id,
                'department_name' => $masterApproval->department?->name,
                'approved_by' => $masterApproval->user ? [
                    'id' => $masterApproval->user->id,
                    'name' => $masterApproval->user->name,
                ] : null,
                'approved_at' => $masterApproval->approved_at?->toIso8601String(),
            ] : null,
        ];
    }

    private function currentUserContext(Request $request): array
    {
        $user = $request->user();
        if (! $user) {
            return ['id' => null, 'supervisor_department_ids' => [], 'master_department_ids' => []];
        }

        $departments = $user->departments()->get(['departments.id', 'departments.type']);

        return [
            'id' => $user->id,
            'supervisor_department_ids' => $departments
                ->where('type', Department::TYPE_SUPERVISOR)
                ->pluck('id')
                ->values()
                ->all(),
            'master_department_ids' => $departments
                ->where('type', Department::TYPE_MASTER)
                ->pluck('id')
                ->values()
                ->all(),
        ];
    }
}
