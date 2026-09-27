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
        $actor = $request->user();

        // „Vezi ca”: căsuța se desenează cu ochii altcuiva, ca un administrator
        // să poată verifica ce are omul de făcut, fără să se dezlogheze și să
        // intre cu contul lui. Contul rămâne al administratorului — cât timp
        // se uită prin ochii altuia, butoanele de decizie dispar, ca să nu
        // apese nimeni în numele cuiva.
        $preview = $actor->isAdmin() && $request->integer('as') !== 0
            ? User::query()->find($request->integer('as'))
            : null;

        $user = $preview ?? $actor;
        $departments = $this->departmentsOf($user);
        $canRoute = $preview === null && $user->hasRole(User::ROLE_FINANCE);
        $tab = $request->string('tab')->toString();
        $tab = in_array($tab, ['mine', 'final', 'blocked', 'routing', 'all'], true) ? $tab : ($departments->isEmpty() && $user->hasRole(User::ROLE_TOP_MANAGEMENT) ? 'final' : 'mine');
        $departmentId = $request->integer('department') ?: null;
        $search = trim($request->string('search')->toString());
        $dueUntil = $request->string('due_until')->toString() ?: null;
        $docFrom = $request->string('doc_from')->toString() ?: null;
        $docTo = $request->string('doc_to')->toString() ?: null;
        // Implicit se uită lumea la ce a venit de curând; scadența rămâne o
        // alegere, pentru zilele în care se pregătește plata.
        $sort = $request->string('sort')->toString() === 'due' ? 'due' : 'doc';
        $payment = in_array($request->string('payment')->toString(), ['paid', 'partial', 'unpaid'], true)
            ? $request->string('payment')->toString()
            : null;

        $query = match ($tab) {
            'final' => $this->finalQuery(),
            'blocked' => Invoice::query()->whereIn('approval_status', [InvoiceWorkflow::DISPUTED, InvoiceWorkflow::POSTPONED]),
            // Facturile pe care regulile n-au putut să le dea unui departament:
            // stau aici, unde se și decid, nu într-o listă separată.
            'routing' => Invoice::query()->where('approval_status', InvoiceWorkflow::ROUTING),
            // Toate facturile primite, plătite sau nu, decise sau nu: cozile
            // arată ce e de lucru, asta arată ce există.
            'all' => Invoice::query()->visibleTo($user),
            default => $this->mineQuery($departments->pluck('id')->all(), $departmentId),
        };

        // Restul cozilor arată doar ce e de plătit și nedecis; „Toate” arată
        // tot ce a intrat, inclusiv facturile deja achitate.
        $rows = ApprovalPresenter::load($tab === 'all' ? $this->received($query) : $this->payable($query))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('nr_doc', 'like', "%{$search}%")->orWhereHas('partner', fn (Builder $p) => $p->where('name', 'like', "%{$search}%"))))
            ->when($dueUntil !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull('data_scadenta')->orWhere('data_scadenta', '<=', $dueUntil)))
            ->when($docFrom !== null, fn (Builder $q) => $q->where('data_doc', '>=', $docFrom))
            ->when($docTo !== null, fn (Builder $q) => $q->where('data_doc', '<=', $docTo))
            ->when($payment !== null, fn (Builder $q) => $q->paymentStatus($payment))
            ->when(
                $sort === 'due',
                fn (Builder $q) => $q->orderByRaw('data_scadenta is null, data_scadenta')->orderBy('id'),
                fn (Builder $q) => $q->orderByDesc('data_doc')->orderByDesc('id'),
            )
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
                'final' => $user->hasRole(User::ROLE_TOP_MANAGEMENT) ? $this->payable($this->finalQuery())->count() : 0,
                'blocked' => $this->payable(Invoice::query()->whereIn('approval_status', [InvoiceWorkflow::DISPUTED, InvoiceWorkflow::POSTPONED]))->count(),
                'routing' => $canRoute ? $this->payable(Invoice::query()->where('approval_status', InvoiceWorkflow::ROUTING))->count() : 0,
            ],
            'filters' => ['department' => $departmentId, 'search' => $search, 'due_until' => $dueUntil, 'doc_from' => $docFrom, 'doc_to' => $docTo, 'sort' => $sort, 'payment' => $payment, 'as' => $preview?->id],
            'can' => [
                'final' => $preview === null && $user->hasRole(User::ROLE_TOP_MANAGEMENT),
                'reopen' => $preview === null && ($user->hasRole(User::ROLE_TOP_MANAGEMENT) || $user->hasRole(User::ROLE_FINANCE)),
                'route' => $canRoute,
            ],
            'preview' => $preview !== null ? ['id' => $preview->id, 'name' => $preview->name] : null,
            // Cine poate fi privit peste umăr; lista o vede doar un administrator.
            'people' => $actor->isAdmin()
                ? User::query()->orderBy('name')->get(['id', 'name', 'roles'])->map(fn (User $person) => [
                    'id' => $person->id,
                    'name' => $person->name,
                    'roles' => array_values((array) ($person->roles ?? [])),
                ])->all()
                : [],
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
        $this->speaksFor($request, $department);
        $until = isset($validated['until']) ? Carbon::parse($validated['until']) : null;
        $invoices = $this->invoicesFor($request, $validated['invoice_ids']);

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

        abort_unless($request->user()->hasRole(User::ROLE_TOP_MANAGEMENT), 403, 'Decizia finală este a Top Management.');

        $until = isset($validated['until']) ? Carbon::parse($validated['until']) : null;
        $invoices = $this->invoicesFor($request, $validated['invoice_ids']);

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
        $this->speaksFor($request, $from);
        $to = Department::query()->findOrFail($validated['to_department_id']);
        $invoices = $this->invoicesFor($request, $validated['invoice_ids']);

        DB::transaction(fn () => $invoices->each(fn (Invoice $invoice) => $assigner->redirect($invoice, $from, $to, $request->user(), $validated['comment'])));

        Inertia::flash('toast', ['type' => 'success', 'message' => $invoices->count() === 1
            ? "Factura a fost trimisă la {$to->name}."
            : "{$invoices->count()} facturi au fost trimise la {$to->name}."]);

        return back();
    }

    public function reopen(Request $request, Invoice $invoice, InvoiceWorkflow $workflow): RedirectResponse
    {
        $user = $request->user();

        abort_unless(
            $user->hasRole(User::ROLE_TOP_MANAGEMENT) || $user->hasRole(User::ROLE_FINANCE),
            403,
            'Doar Top Management sau finanțele pot redeschide o factură.',
        );
        $this->invoicesFor($request, [$invoice->getKey()]);

        $validated = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);
        $workflow->reopen($invoice, $user, $validated['comment'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Factura s-a întors la departamente.']);

        return back();
    }

    /**
     * Omul decide numai pentru departamentele lui. Pagina îi arată doar
     * butoanele pe care le are voie, dar cererea se verifică oricum aici:
     * un formular trimis de mână nu e o autorizare.
     */
    private function speaksFor(Request $request, Department $department): void
    {
        abort_unless(
            $request->user()->approvesFor($department),
            403,
            "Nu decideți pentru {$department->name}.",
        );
    }

    /**
     * Facturile cerute, dar numai cele pe care omul le poate vedea. Dacă una
     * nu e a lui, cererea pică întreagă — nu se decide „pe cât se poate”.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Invoice>
     */
    private function invoicesFor(Request $request, array $ids)
    {
        $invoices = Invoice::query()->whereKey($ids)->visibleTo($request->user())->get();

        abort_unless($invoices->count() === count(array_unique($ids)), 403, 'Una dintre facturi este a altui departament.');

        return $invoices;
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
     * Invoices every department approved, waiting for Top Management.
     *
     * @return Builder<Invoice>
     */
    private function finalQuery(): Builder
    {
        return Invoice::query()->where('approval_status', InvoiceWorkflow::FINAL);
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    /**
     * Tot ce a venit de la furnizori și nu a fost șters din OMC.
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    private function received(Builder $query): Builder
    {
        return $query->where('partener_type', 'furnizor')->whereNull('omc_removed_at');
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
