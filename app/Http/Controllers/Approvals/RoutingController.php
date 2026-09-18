<?php

namespace App\Http\Controllers\Approvals;

use App\Http\Controllers\Controller;
use App\Models\AssignmentRule;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\InvoiceDetail;
use App\Models\InvoiceLineDepartment;
use App\Models\User;
use App\Services\Maintenance\ArtisanRunner;
use App\Services\Routing\DepartmentAssigner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Routing invoices to departments: the open invoices no rule could route,
 * the rules themselves, and how well the rules agree with the cost centres
 * the accountants tag.
 */
class RoutingController extends Controller
{
    public function index(Request $request, ArtisanRunner $runner): Response
    {
        $tab = in_array($request->string('tab')->toString(), ['queue', 'rules', 'accuracy'], true) ? $request->string('tab')->toString() : 'queue';
        $search = trim($request->string('search')->toString());

        return Inertia::render('routing/index', [
            'tab' => $tab,
            'departments' => Department::query()->whereNotNull('code')->orderBy('sort')->get(['id', 'name', 'group', 'parent_id']),
            'queue' => $tab === 'queue' ? $this->queue($search) : null,
            'rules' => $tab === 'rules' ? $this->rules() : null,
            'accuracy' => $tab === 'accuracy' ? $this->accuracy() : null,
            'counts' => ['queue' => $this->queueQuery()->count()],
            'filters' => ['search' => $search],
            'run' => $runner->status(ArtisanRunner::ROUTING),
            'can' => ['edit' => $request->user()->hasRole(User::ROLE_FINANCE)],
        ]);
    }

    /**
     * Send an invoice, or some of its lines, to a department by hand.
     */
    public function assign(Request $request, Invoice $invoice, DepartmentAssigner $assigner): RedirectResponse
    {
        $this->authorizeFinance($request->user());

        $validated = $request->validate([
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'scvs' => ['nullable', 'array'],
            'scvs.*' => ['integer'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $department = Department::query()->findOrFail($validated['department_id']);
        $assigner->assignManually($invoice, $department, $request->user()->id, $validated['scvs'] ?? null);

        // "Always send this supplier here": a partner rule, used from now on.
        if (($validated['remember'] ?? false) && $invoice->partner !== null) {
            AssignmentRule::query()->updateOrCreate(
                ['kind' => 'partner', 'pattern' => $invoice->partner->name],
                ['department_id' => $department->id, 'created_by_id' => $request->user()->id, 'note' => 'din coada de rutare'],
            );
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Factura {$invoice->nr_doc} merge la {$department->name}."]);

        return back();
    }

    public function release(Request $request, Invoice $invoice, DepartmentAssigner $assigner): RedirectResponse
    {
        $this->authorizeFinance($request->user());
        $assigner->release($invoice);

        return back();
    }

    public function storeRule(Request $request): RedirectResponse
    {
        $this->authorizeFinance($request->user());
        AssignmentRule::query()->create([...$this->validateRule($request), 'created_by_id' => $request->user()->id]);

        return back();
    }

    public function updateRule(Request $request, AssignmentRule $rule): RedirectResponse
    {
        $this->authorizeFinance($request->user());
        $rule->update($this->validateRule($request, $rule));

        return back();
    }

    public function destroyRule(Request $request, AssignmentRule $rule): RedirectResponse
    {
        $this->authorizeFinance($request->user());
        $rule->delete();

        return back();
    }

    /**
     * Route the last months again with the current rules, in the background.
     */
    public function rerun(Request $request, ArtisanRunner $runner): RedirectResponse
    {
        $this->authorizeFinance($request->user());

        try {
            $runner->start(ArtisanRunner::ROUTING, ['--since='.now()->subMonths(13)->startOfMonth()->toDateString()], $request->user()->name);
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Rutarea rulează din nou pe ultimele 13 luni, în fundal.']);
        } catch (Throwable $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Rutarea nu a putut porni: '.trim($e->getMessage())]);
        }

        return back();
    }

    /**
     * @return Builder<Invoice>
     */
    private function queueQuery(): Builder
    {
        return Invoice::query()
            ->where('partener_type', 'furnizor')
            ->whereNull('omc_removed_at')
            ->where('val_mon', '>', 0)
            ->whereRaw('val_mon - val_mon_paid - val_mon_storno > 0.01')
            ->whereIn('assignment_state', ['unassigned', 'partial']);
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function queue(string $search)
    {
        return $this->queueQuery()
            ->with('partner:id,name')
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('nr_doc', 'like', "%{$search}%")->orWhereHas('partner', fn (Builder $p) => $p->where('name', 'like', "%{$search}%"))))
            ->orderByRaw('data_scadenta is null, data_scadenta')
            ->paginate(25)
            ->withQueryString()
            ->through(function (Invoice $invoice) {
                $routing = InvoiceLineDepartment::query()->where('invoice_id', $invoice->id)->with('department:id,name')->get()->keyBy('scv');

                return [
                    'id' => $invoice->id,
                    'nr_doc' => $invoice->nr_doc,
                    'data_doc' => $invoice->data_doc?->toDateString(),
                    'data_scadenta' => $invoice->data_scadenta?->toDateString(),
                    'partner' => $invoice->partner?->name,
                    'moneda' => $invoice->moneda,
                    'outstanding' => $invoice->outstandingAmount(),
                    'office' => $invoice->office,
                    'lines' => InvoiceDetail::query()->where('invoice_id', $invoice->id)->orderBy('scv')->get()->map(fn (InvoiceDetail $line) => [
                        'scv' => $line->scv,
                        'articol' => $line->articol,
                        'detaliu' => $line->detaliu_articol,
                        'account' => $line->account,
                        'loc' => $line->loc,
                        'com_int' => $line->com_int,
                        'amount' => round((float) $line->cant * (float) $line->pret, 2),
                        'department' => $routing->get($line->scv)?->department?->name,
                        'rule' => $routing->get($line->scv)?->rule,
                        'detail' => $routing->get($line->scv)?->detail,
                    ])->all(),
                ];
            });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rules(): array
    {
        return AssignmentRule::query()
            ->with(['department:id,name', 'createdBy:id,name'])
            ->orderBy('kind')
            ->orderByRaw("case when pattern like '~%' then 1 else 0 end")
            ->orderBy('id')
            ->get()
            ->map(fn (AssignmentRule $rule) => [
                'id' => $rule->id,
                'kind' => $rule->kind,
                'pattern' => $rule->pattern,
                'department_id' => $rule->department_id,
                'department' => $rule->department?->name,
                'note' => $rule->note,
                'created_by' => $rule->createdBy?->name,
            ])
            ->all();
    }

    /**
     * How the lines of the last twelve months were routed, and for the ones
     * the cost centre decided, how often the other rules said the same.
     *
     * @return array<string, mixed>
     */
    private function accuracy(): array
    {
        $since = now()->subYear()->toDateString();
        $base = fn () => DB::table('invoice_line_departments as l')
            ->join('invoices as i', 'i.id', '=', 'l.invoice_id')
            ->where('i.data_doc', '>=', $since)
            ->whereNull('i.omc_removed_at');

        $byRule = $base()
            ->selectRaw('l.rule, count(*) as line_count, sum(abs(l.amount)) as amount')
            ->groupBy('l.rule')
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($row) => ['rule' => $row->rule, 'lines' => (int) $row->line_count, 'amount' => round((float) $row->amount, 2)]);

        $check = $base()
            ->where('l.rule', 'loc')
            ->selectRaw('count(*) as line_count, sum(case when l.predicted_department_id = l.department_id then 1 else 0 end) as agree, sum(case when l.predicted_department_id is null then 1 else 0 end) as silent')
            ->first();

        $disagreements = $base()
            ->where('l.rule', 'loc')
            ->whereNotNull('l.predicted_department_id')
            ->whereColumn('l.predicted_department_id', '!=', 'l.department_id')
            ->join('departments as d', 'd.id', '=', 'l.department_id')
            ->join('departments as p', 'p.id', '=', 'l.predicted_department_id')
            ->selectRaw('d.name as tagged, p.name as predicted, count(*) as line_count')
            ->groupBy('d.name', 'p.name')
            ->orderByDesc('line_count')
            ->limit(15)
            ->get();

        return [
            'since' => $since,
            'by_rule' => $byRule,
            'tagged_lines' => (int) ($check->line_count ?? 0),
            'agree' => (int) ($check->agree ?? 0),
            'silent' => (int) ($check->silent ?? 0),
            'disagreements' => $disagreements->map(fn ($row) => ['tagged' => $row->tagged, 'predicted' => $row->predicted, 'lines' => (int) $row->line_count]),
        ];
    }

    /**
     * @return array{kind: string, pattern: string, department_id: int, note: ?string}
     */
    private function validateRule(Request $request, ?AssignmentRule $rule = null): array
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in(AssignmentRule::KINDS)],
            'pattern' => ['required', 'string', 'max:255', Rule::unique('assignment_rules')->where('kind', $request->input('kind'))->ignore($rule?->id)],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if (str_starts_with($validated['pattern'], '~') && @preg_match('/'.str_replace('/', '\/', substr($validated['pattern'], 1)).'/iu', '') === false) {
            throw ValidationException::withMessages(['pattern' => 'Expresia după „~” nu este validă.']);
        }

        return $validated;
    }

    private function authorizeFinance(User $user): void
    {
        if (! $user->hasRole(User::ROLE_FINANCE)) {
            throw new AuthorizationException('Rutarea o gestionează Financiar.');
        }
    }
}
