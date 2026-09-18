<?php

namespace App\Http\Controllers\Approvals;

use App\Http\Controllers\Controller;
use App\Models\AssignmentRule;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Maintenance\ArtisanRunner;
use App\Services\Routing\DepartmentAssigner;
use Illuminate\Auth\Access\AuthorizationException;
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
        $tab = $request->string('tab')->toString() === 'accuracy' ? 'accuracy' : 'rules';

        return Inertia::render('routing/index', [
            'tab' => $tab,
            'departments' => Department::query()->whereNotNull('code')->orderBy('sort')->get(['id', 'name', 'group', 'parent_id']),
            'rules' => $tab === 'rules' ? $this->rules() : null,
            'accuracy' => $tab === 'accuracy' ? $this->accuracy() : null,
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

    /**
     * Send several invoices to one department at once (Facturi → Primite).
     */
    public function assignMany(Request $request, DepartmentAssigner $assigner): RedirectResponse
    {
        $this->authorizeFinance($request->user());

        $validated = $request->validate([
            'invoice_ids' => ['required', 'array', 'min:1', 'max:500'],
            'invoice_ids.*' => ['integer'],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $department = Department::query()->findOrFail($validated['department_id']);
        $invoices = Invoice::query()->whereKey($validated['invoice_ids'])->with('partner:id,name')->get();

        DB::transaction(function () use ($invoices, $department, $request, $assigner, $validated) {
            foreach ($invoices as $invoice) {
                $assigner->assignManually($invoice, $department, $request->user()->id);

                if (($validated['remember'] ?? false) && $invoice->partner !== null) {
                    AssignmentRule::query()->updateOrCreate(
                        ['kind' => 'partner', 'pattern' => $invoice->partner->name],
                        ['department_id' => $department->id, 'created_by_id' => $request->user()->id, 'note' => 'din Facturi primite'],
                    );
                }
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => $invoices->count() === 1
            ? "Factura {$invoices->first()->nr_doc} merge la {$department->name}."
            : "{$invoices->count()} facturi merg la {$department->name}."]);

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
