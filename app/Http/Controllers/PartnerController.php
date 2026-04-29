<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Department;
use App\Models\Partner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PartnerController extends Controller
{
    public function furnizori(Request $request): Response
    {
        return $this->list($request, 'furnizori');
    }

    public function clienti(Request $request): Response
    {
        return $this->list($request, 'clienti');
    }

    public function show(Request $request, Partner $partner): Response
    {
        abort_unless($partner->is_furnizor, 404);

        $partner->load([
            'company:id,name',
            'bankAccounts:id,partner_id,bank,iban,currency,is_default,is_discontinued',
            'responsabilDepartments:id,name,type',
        ]);

        $assignedDeptIds = $partner->responsabilDepartments->pluck('id');

        $invoiceSearch = $request->string('invoice_search')->toString();
        $invoiceTipDoc = $request->string('invoice_tip_doc')->toString();
        $invoiceFrom = $request->string('invoice_from')->toString();
        $invoiceTo = $request->string('invoice_to')->toString();
        $invoicePayment = $request->string('invoice_payment')->toString();

        $invoices = $partner->invoices()
            ->when($invoiceSearch, fn ($q, $term) => $q->where('nr_doc', 'like', "%{$term}%"))
            ->when($invoiceTipDoc, fn ($q, $type) => $q->where('tip_doc', $type))
            ->when($invoiceFrom, fn ($q, $d) => $q->where('data_doc', '>=', $d))
            ->when($invoiceTo, fn ($q, $d) => $q->where('data_doc', '<=', $d))
            ->when($invoicePayment === 'paid', fn ($q) => $q->whereColumn('val_mon_paid', '>=', DB::raw('val_mon + val_mon_tva - 0.01')))
            ->when($invoicePayment === 'unpaid', fn ($q) => $q->where('val_mon_paid', '<=', 0.009))
            ->when($invoicePayment === 'partial', function ($q) {
                $q->where('val_mon_paid', '>', 0.009)
                    ->whereColumn('val_mon_paid', '<', DB::raw('val_mon + val_mon_tva - 0.01'));
            })
            ->orderByDesc('data_doc')
            ->paginate(15, pageName: 'invoices')
            ->withQueryString()
            ->through(fn ($invoice) => [
                'id' => $invoice->id,
                'data_doc' => $invoice->data_doc?->toDateString(),
                'tip_doc' => $invoice->tip_doc,
                'nr_doc' => $invoice->nr_doc,
                'moneda' => $invoice->moneda,
                'val_mon' => (float) $invoice->val_mon,
                'val_mon_tva' => (float) $invoice->val_mon_tva,
                'val_mon_paid' => (float) $invoice->val_mon_paid,
                'payment_status' => $invoice->payment_status,
                'data_scadenta' => $invoice->data_scadenta?->toDateString(),
                'data_inchidere' => $invoice->data_inchidere?->toDateString(),
            ]);

        $availableTipDocs = $partner->invoices()
            ->select('tip_doc')
            ->distinct()
            ->orderBy('tip_doc')
            ->pluck('tip_doc');

        return Inertia::render('partners/show', [
            'partner' => [
                'id' => $partner->id,
                'name' => $partner->name,
                'cui' => $partner->cui,
                'reg_com' => $partner->reg_com,
                'country' => $partner->country,
                'city' => $partner->city,
                'address' => $partner->address,
                'phone' => $partner->phone,
                'email' => $partner->email,
                'is_furnizor' => $partner->is_furnizor,
                'is_client' => $partner->is_client,
                'company' => ['id' => $partner->company->id, 'name' => $partner->company->name],
                'bank_accounts' => $partner->bankAccounts
                    ->sortBy([['is_discontinued', 'asc'], ['is_default', 'desc'], ['currency', 'asc']])
                    ->values()
                    ->map(fn ($account) => [
                        'id' => $account->id,
                        'bank' => $account->bank,
                        'iban' => $account->iban,
                        'currency' => $account->currency,
                        'is_default' => $account->is_default,
                        'is_discontinued' => $account->is_discontinued,
                    ]),
                'responsabil_departments' => $partner->responsabilDepartments->map(fn (Department $dept) => [
                    'id' => $dept->id,
                    'name' => $dept->name,
                    'type' => $dept->type,
                ])->values(),
            ],
            'invoices' => $invoices,
            'invoiceFilters' => [
                'search' => $invoiceSearch ?: null,
                'tip_doc' => $invoiceTipDoc ?: null,
                'from' => $invoiceFrom ?: null,
                'to' => $invoiceTo ?: null,
                'payment' => $invoicePayment ?: null,
            ],
            'availableTipDocs' => $availableTipDocs,
            'availableDepartments' => Department::responsabili()
                ->whereNotIn('id', $assignedDeptIds)
                ->orderBy('name')
                ->get(['id', 'name', 'type'])
                ->map(fn (Department $dept) => [
                    'id' => $dept->id,
                    'name' => $dept->name,
                    'type' => $dept->type,
                ]),
        ]);
    }

    public function attachResponsabilDepartment(Request $request, Partner $partner): RedirectResponse
    {
        abort_unless($partner->is_furnizor, 404);

        $validated = $request->validate([
            'department_id' => ['required', 'integer', 'exists:departments,id'],
        ]);

        $department = Department::findOrFail($validated['department_id']);
        abort_unless($department->type === Department::TYPE_RESPONSABIL, 422, 'Doar departamentele de responsabili pot fi atribuite.');

        $partner->departments()->syncWithoutDetaching([$department->id]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Departament atribuit.']);

        return back();
    }

    public function detachResponsabilDepartment(Partner $partner, Department $department): RedirectResponse
    {
        abort_unless($partner->is_furnizor, 404);

        $partner->departments()->detach($department->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Departament eliminat.']);

        return back();
    }

    private function list(Request $request, string $scope): Response
    {
        $search = $request->string('search')->toString();
        $companyId = $request->integer('company_id');
        $departmentIds = collect($request->input('department_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();

        $partners = Partner::query()
            ->with(['company:id,name'])
            ->when($scope === 'furnizori', fn ($q) => $q->with('responsabilDepartments:id,name,type'))
            ->withCount('invoices')
            ->when($scope === 'furnizori', fn ($q) => $q->furnizori())
            ->when($scope === 'clienti', fn ($q) => $q->clienti())
            ->when($companyId, fn ($q, $id) => $q->where('company_id', $id))
            ->when(
                $scope === 'furnizori' && ! empty($departmentIds),
                fn ($q) => $q->whereHas(
                    'responsabilDepartments',
                    fn ($d) => $d->whereIn('departments.id', $departmentIds),
                ),
            )
            ->when($search, function ($q, $term) {
                $q->where(function ($q) use ($term) {
                    $q->where('name', 'like', "%{$term}%")
                        ->orWhere('cui', 'like', "%{$term}%");
                });
            })
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (Partner $partner) => [
                'id' => $partner->id,
                'name' => $partner->name,
                'cui' => $partner->cui,
                'reg_com' => $partner->reg_com,
                'city' => $partner->city,
                'country' => $partner->country,
                'phone' => $partner->phone,
                'email' => $partner->email,
                'is_furnizor' => $partner->is_furnizor,
                'is_client' => $partner->is_client,
                'invoices_count' => $partner->invoices_count,
                'company' => ['id' => $partner->company->id, 'name' => $partner->company->name],
                'responsabil_departments' => $scope === 'furnizori'
                    ? $partner->responsabilDepartments->map(fn (Department $dept) => [
                        'id' => $dept->id,
                        'name' => $dept->name,
                    ])->values()
                    : [],
            ]);

        return Inertia::render('partners/index', [
            'partners' => $partners,
            'scope' => $scope,
            'filters' => [
                'search' => $search ?: null,
                'company_id' => $companyId ?: null,
                'department_ids' => $scope === 'furnizori' ? $departmentIds : [],
            ],
            'companies' => Company::orderBy('name')->get(['id', 'name']),
            'availableDepartments' => $scope === 'furnizori'
                ? Department::responsabili()->orderBy('name')->get(['id', 'name'])
                : [],
        ]);
    }
}
