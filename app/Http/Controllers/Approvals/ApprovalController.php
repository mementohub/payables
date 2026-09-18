<?php

namespace App\Http\Controllers\Approvals;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\InvoiceDepartmentApproval;
use App\Models\User;
use App\Services\Approvals\ApprovalPresenter;
use App\Services\Approvals\InvoiceWorkflow;
use App\Services\Routing\DepartmentAssigner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The approvals inbox: what the user's departments still have to decide,
 * what waits for Top Management, and what is disputed or postponed.
 */
class ApprovalController extends Controller
{
    public function __construct(private ApprovalPresenter $presenter) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $departments = $this->departmentsOf($user);
        $tab = $request->string('tab')->toString();
        $tab = in_array($tab, ['mine', 'final', 'blocked'], true) ? $tab : ($departments->isEmpty() && $user->hasRole(User::ROLE_TOP_MANAGEMENT) ? 'final' : 'mine');
        $departmentId = $request->integer('department') ?: null;
        $search = trim($request->string('search')->toString());
        $dueUntil = $request->string('due_until')->toString() ?: null;

        $query = match ($tab) {
            'final' => $this->finalQuery($request->boolean('with_runs')),
            'blocked' => Invoice::query()->whereIn('approval_status', [InvoiceWorkflow::DISPUTED, InvoiceWorkflow::POSTPONED]),
            default => $this->mineQuery($departments->pluck('id')->all(), $departmentId),
        };

        $rows = ApprovalPresenter::load($this->payable($query))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('nr_doc', 'like', "%{$search}%")->orWhereHas('partner', fn (Builder $p) => $p->where('name', 'like', "%{$search}%"))))
            ->when($dueUntil !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull('data_scadenta')->orWhere('data_scadenta', '<=', $dueUntil)))
            ->orderByRaw('data_scadenta is null, data_scadenta')
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (Invoice $invoice) => $this->presenter->invoice($invoice));

        return Inertia::render('approvals/index', [
            'tab' => $tab,
            'rows' => $rows,
            'departments' => $departments->map(fn (Department $department) => [
                'id' => $department->id,
                'name' => $department->name,
                'pending' => $this->payable($this->mineQuery([$department->id], $department->id))->count(),
            ])->values(),
            // Where a share that landed on the wrong department can be sent.
            'all_departments' => Department::query()->whereNotNull('code')->where('is_active', true)->orderBy('sort')->get(['id', 'name', 'group', 'parent_id']),
            'counts' => [
                'mine' => $departments->isEmpty() ? 0 : $this->payable($this->mineQuery($departments->pluck('id')->all(), null))->count(),
                'final' => $user->hasRole(User::ROLE_TOP_MANAGEMENT) ? $this->payable($this->finalQuery(false))->count() : 0,
                'blocked' => $this->payable(Invoice::query()->whereIn('approval_status', [InvoiceWorkflow::DISPUTED, InvoiceWorkflow::POSTPONED]))->count(),
            ],
            'filters' => ['department' => $departmentId, 'search' => $search, 'due_until' => $dueUntil, 'with_runs' => $request->boolean('with_runs')],
            'can' => [
                'final' => $user->hasRole(User::ROLE_TOP_MANAGEMENT),
                'reopen' => $user->hasRole(User::ROLE_TOP_MANAGEMENT) || $user->hasRole(User::ROLE_FINANCE),
            ],
        ]);
    }

    /**
     * A department's decision on several invoices at once.
     */
    public function decide(Request $request, InvoiceWorkflow $workflow): RedirectResponse
    {
        $validated = $request->validate([
            'invoice_ids' => ['required', 'array', 'min:1', 'max:500'],
            'invoice_ids.*' => ['integer'],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'decision' => ['required', Rule::in(['approved', 'disputed', 'postponed'])],
            'comment' => ['nullable', 'string', 'max:2000'],
            'until' => ['nullable', 'date'],
        ]);

        $department = Department::query()->findOrFail($validated['department_id']);
        $until = isset($validated['until']) ? Carbon::parse($validated['until']) : null;
        $invoices = Invoice::query()->whereKey($validated['invoice_ids'])->get();

        DB::transaction(fn () => $invoices->each(fn (Invoice $invoice) => $workflow->decide($invoice, $department, $request->user(), $validated['decision'], $validated['comment'] ?? null, $until)));

        Inertia::flash('toast', ['type' => 'success', 'message' => $this->message($validated['decision'], $invoices->count(), $department->name)]);

        return back();
    }

    /**
     * Top Management's decision on several invoices at once.
     */
    public function decideFinal(Request $request, InvoiceWorkflow $workflow): RedirectResponse
    {
        $validated = $request->validate([
            'invoice_ids' => ['required', 'array', 'min:1', 'max:500'],
            'invoice_ids.*' => ['integer'],
            'decision' => ['required', Rule::in(['approved', 'disputed', 'postponed'])],
            'comment' => ['nullable', 'string', 'max:2000'],
            'until' => ['nullable', 'date'],
        ]);

        $until = isset($validated['until']) ? Carbon::parse($validated['until']) : null;
        $invoices = Invoice::query()->whereKey($validated['invoice_ids'])->get();

        DB::transaction(fn () => $invoices->each(fn (Invoice $invoice) => $workflow->decideFinal($invoice, $request->user(), $validated['decision'], $validated['comment'] ?? null, $until)));

        Inertia::flash('toast', ['type' => 'success', 'message' => $this->message($validated['decision'], $invoices->count(), null)]);

        return back();
    }

    /**
     * A department sends invoices that landed on it by mistake to the
     * department they belong to.
     */
    public function redirect(Request $request, DepartmentAssigner $assigner): RedirectResponse
    {
        $validated = $request->validate([
            'invoice_ids' => ['required', 'array', 'min:1', 'max:500'],
            'invoice_ids.*' => ['integer'],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'to_department_id' => ['required', 'integer', 'exists:departments,id', 'different:department_id'],
            'comment' => ['required', 'string', 'min:3', 'max:2000'],
        ], [
            'to_department_id.different' => 'Alegeți alt departament decât cel de la care trimiteți.',
            'comment.required' => 'Spuneți de ce nu este factura departamentului dumneavoastră.',
        ]);

        $from = Department::query()->findOrFail($validated['department_id']);
        $to = Department::query()->findOrFail($validated['to_department_id']);
        $invoices = Invoice::query()->whereKey($validated['invoice_ids'])->get();

        DB::transaction(fn () => $invoices->each(fn (Invoice $invoice) => $assigner->redirect($invoice, $from, $to, $request->user(), $validated['comment'])));

        Inertia::flash('toast', ['type' => 'success', 'message' => $invoices->count() === 1
            ? "Factura a fost trimisă la {$to->name}."
            : "{$invoices->count()} facturi au fost trimise la {$to->name}."]);

        return back();
    }

    public function reopen(Request $request, Invoice $invoice, InvoiceWorkflow $workflow): RedirectResponse
    {
        $validated = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);
        $workflow->reopen($invoice, $request->user(), $validated['comment'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Factura s-a întors la departamente.']);

        return back();
    }

    /**
     * @return Collection<int, Department>
     */
    private function departmentsOf(User $user)
    {
        return Department::query()
            ->whereNotNull('code')
            ->when(! $user->isAdmin(), fn (Builder $q) => $q->whereIn('id', $user->departmentIds()))
            ->orderBy('sort')
            ->get(['id', 'name']);
    }

    /**
     * @param  list<int>  $departmentIds
     * @return Builder<Invoice>
     */
    private function mineQuery(array $departmentIds, ?int $departmentId): Builder
    {
        $ids = $departmentId !== null ? array_values(array_intersect($departmentIds, [$departmentId])) : $departmentIds;

        return Invoice::query()
            ->where('approval_status', InvoiceWorkflow::DEPARTMENT)
            ->whereHas('departmentApprovals', fn (Builder $q) => $q->whereIn('department_id', $ids ?: [0])->where('status', InvoiceDepartmentApproval::PENDING));
    }

    /**
     * Invoices every department approved, waiting for Top Management: the
     * overhead ones, and with `$withRuns` the ones a payment run will carry.
     *
     * @return Builder<Invoice>
     */
    private function finalQuery(bool $withRuns): Builder
    {
        return Invoice::query()
            ->where('approval_status', InvoiceWorkflow::FINAL)
            ->when(! $withRuns, fn (Builder $q) => $q->where('approval_track', InvoiceWorkflow::TRACK_INVOICE));
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    private function payable(Builder $query): Builder
    {
        return $query
            ->where('partener_type', 'furnizor')
            ->whereNull('omc_removed_at')
            ->where('val_mon', '>', 0)
            ->whereRaw('val_mon - val_mon_paid - val_mon_storno > 0.01');
    }

    private function message(string $decision, int $count, ?string $department): string
    {
        $who = $department !== null ? " pentru {$department}" : '';

        return $count === 1
            ? 'Factura a fost '.['approved' => 'aprobată', 'disputed' => 'contestată', 'postponed' => 'amânată'][$decision].$who.'.'
            : "{$count} facturi ".['approved' => 'aprobate', 'disputed' => 'contestate', 'postponed' => 'amânate'][$decision].$who.'.';
    }
}
