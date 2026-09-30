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
use App\Services\Xlsx\XlsxWriter;
use App\Support\ViewAs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        // apese nimeni în numele cuiva. Alegerea se ține în sesiune, ca să se
        // vadă și în meniu, nu doar în lista asta (`ApplyViewAs` ține minte
        // alegerea venită prin `?as=`).
        $preview = ViewAs::user($request);

        $user = $preview ?? $actor;
        $departments = $this->departmentsOf($user);
        $canRoute = $preview === null && $user->hasRole(User::ROLE_FINANCE);
        // Cine răspunde de toate departamentele (administrare, finanțe,
        // trezorerie, Top Management) vede în „De aprobat” tot ce așteaptă o
        // semnătură, nu doar ce e al lui — aprobă tot numai ce e al lui, dar
        // altfel n-ar avea de unde ști ce stă pe loc. Restul își văd coada.
        $seesAll = $user->seesEveryQueue();
        $scope = $request->string('scope')->toString() === 'mine' ? 'mine' : ($seesAll ? 'all' : 'mine');
        $queueIds = $scope === 'all'
            ? Department::query()->whereNotNull('code')->pluck('id')->all()
            : $departments->pluck('id')->all();
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
            default => $this->mineQuery($queueIds, $departmentId),
        };

        // Restul cozilor arată doar ce e de plătit și nedecis; „Toate” arată
        // tot ce a intrat, inclusiv facturile deja achitate.
        $filters = compact('search', 'dueUntil', 'docFrom', 'docTo', 'payment', 'sort');
        $listed = $this->filtered($tab === 'all' ? $this->received($query) : $this->payable($query), $filters);

        $rows = ApprovalPresenter::load(clone $listed)
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
                'mine' => $queueIds === [] ? 0 : $this->payable($this->mineQuery($queueIds, null))->count(),
                'final' => $user->hasRole(User::ROLE_TOP_MANAGEMENT) ? $this->payable($this->finalQuery())->count() : 0,
                'blocked' => $this->payable(Invoice::query()->whereIn('approval_status', [InvoiceWorkflow::DISPUTED, InvoiceWorkflow::POSTPONED]))->count(),
                'routing' => $canRoute ? $this->payable(Invoice::query()->where('approval_status', InvoiceWorkflow::ROUTING))->count() : 0,
            ],
            'totals' => $this->totals(clone $listed),
            'filters' => ['department' => $departmentId, 'search' => $search, 'due_until' => $dueUntil, 'doc_from' => $docFrom, 'doc_to' => $docTo, 'sort' => $sort, 'payment' => $payment, 'as' => $preview?->id, 'scope' => $scope],
            'can' => [
                'final' => $preview === null && $user->hasRole(User::ROLE_TOP_MANAGEMENT),
                'reopen' => $preview === null && ($user->hasRole(User::ROLE_TOP_MANAGEMENT) || $user->hasRole(User::ROLE_FINANCE)),
                'route' => $canRoute,
                // Poate privi coada tuturor departamentelor, nu doar pe a lui.
                'scope' => $seesAll,
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
            // Se poate aproba și o parte din sumă, dar numai factură cu
            // factură: pe un teanc n-ar avea ce să însemne.
            'amount' => ['nullable', 'numeric', 'gt:0'],
        ]);

        $department = Department::query()->findOrFail($validated['department_id']);
        $this->speaksFor($request, $department);
        $until = isset($validated['until']) ? Carbon::parse($validated['until']) : null;
        $invoices = $this->invoicesFor($request, $validated['invoice_ids']);

        $amount = $this->amountFor($validated, $invoices->count());

        DB::transaction(fn () => $invoices->each(fn (Invoice $invoice) => $workflow->decide($invoice, $department, $request->user(), $validated['decision'], $validated['comment'] ?? null, $until, $amount)));

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
            'amount' => ['nullable', 'numeric', 'gt:0'],
        ]);

        abort_unless($request->user()->hasRole(User::ROLE_TOP_MANAGEMENT), 403, 'Decizia finală este a Top Management.');

        $until = isset($validated['until']) ? Carbon::parse($validated['until']) : null;
        $invoices = $this->invoicesFor($request, $validated['invoice_ids']);

        $amount = $this->amountFor($validated, $invoices->count());

        DB::transaction(fn () => $invoices->each(fn (Invoice $invoice) => $workflow->decideFinal($invoice, $request->user(), $validated['decision'], $validated['comment'] ?? null, $until, null, $amount)));

        Inertia::flash('toast', ['type' => 'success', 'message' => $this->message($validated['decision'], $invoices->count(), null)]);

        return back();
    }

    /**
     * Lista de facturi a cererii, gata filtrată: același drum pentru pagină,
     * pentru totaluri și pentru export.
     *
     * @return array{query: Builder<Invoice>, tab: string, filters: array<string, mixed>}
     */
    private function listing(Request $request): array
    {
        $user = ViewAs::effective($request);
        $departments = $this->departmentsOf($user);
        $queueIds = ($request->string('scope')->toString() === 'mine' || ! $user->seesEveryQueue())
            ? $departments->pluck('id')->all()
            : Department::query()->whereNotNull('code')->pluck('id')->all();

        $tab = $request->string('tab')->toString();
        $tab = in_array($tab, ['mine', 'final', 'blocked', 'routing', 'all'], true) ? $tab : 'mine';
        $departmentId = $request->integer('department') ?: null;

        $base = match ($tab) {
            'final' => $this->finalQuery(),
            'blocked' => Invoice::query()->whereIn('approval_status', [InvoiceWorkflow::DISPUTED, InvoiceWorkflow::POSTPONED]),
            'routing' => Invoice::query()->where('approval_status', InvoiceWorkflow::ROUTING),
            'all' => Invoice::query()->visibleTo($user),
            default => $this->mineQuery($queueIds, $departmentId),
        };

        $filters = [
            'search' => trim($request->string('search')->toString()),
            'dueUntil' => $request->string('due_until')->toString() ?: null,
            'docFrom' => $request->string('doc_from')->toString() ?: null,
            'docTo' => $request->string('doc_to')->toString() ?: null,
            'payment' => in_array($request->string('payment')->toString(), ['paid', 'partial', 'unpaid'], true) ? $request->string('payment')->toString() : null,
            'sort' => $request->string('sort')->toString() === 'due' ? 'due' : 'doc',
        ];

        return [
            'query' => $this->filtered($tab === 'all' ? $this->received($base) : $this->payable($base), $filters),
            'tab' => $tab,
            'filters' => $filters,
        ];
    }

    /**
     * Lista, așa cum se vede, într-un fișier de calcul.
     */
    public function export(Request $request): StreamedResponse
    {
        ['query' => $query, 'tab' => $tab] = $this->listing($request);

        $rows = function () use ($query) {
            foreach ($query->with(['partner:id,name,cui', 'company:id,name', 'departmentApprovals.department:id,name'])->cursor() as $invoice) {
                yield [
                    $invoice->company?->name,
                    $invoice->partner?->name,
                    $invoice->partner?->cui,
                    $invoice->tip_doc,
                    $invoice->nr_doc,
                    $invoice->data_doc?->toDateString(),
                    $invoice->data_scadenta?->toDateString(),
                    $invoice->moneda,
                    round((float) $invoice->val_mon, 2),
                    $invoice->outstandingAmount(),
                    round($invoice->outstandingAmount() * ((float) ($invoice->curs ?: 0) ?: 1), 2),
                    $invoice->departmentApprovals->map(fn ($approval) => $approval->department?->name)->filter()->implode(', '),
                    $invoice->approval_status,
                    $invoice->paymentStatus(),
                ];
            }
        };

        return XlsxWriter::streamDownload(
            'aprobari-'.$tab.'-'.now()->toDateString().'.xlsx',
            ['Companie', 'Furnizor', 'CUI', 'Tip', 'Număr', 'Data facturii', 'Scadență', 'Monedă', 'Valoare', 'De plată', 'De plată (lei)', 'Departamente', 'Aprobare', 'Plată'],
            $rows(),
            'Aprobari',
        );
    }

    /**
     * Filtrele listei, într-un singur loc: le folosesc și pagina, și
     * totalurile, și exportul, ca să nu numere fiecare altceva.
     *
     * @param  Builder<Invoice>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Invoice>
     */
    private function filtered(Builder $query, array $filters): Builder
    {
        $search = (string) ($filters['search'] ?? '');

        return $query
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('nr_doc', 'like', "%{$search}%")->orWhereHas('partner', fn (Builder $p) => $p->where('name', 'like', "%{$search}%"))))
            ->when($filters['dueUntil'] ?? null, fn (Builder $q, $d) => $q->where(fn (Builder $w) => $w->whereNull('data_scadenta')->orWhere('data_scadenta', '<=', $d)))
            ->when($filters['docFrom'] ?? null, fn (Builder $q, $d) => $q->where('data_doc', '>=', $d))
            ->when($filters['docTo'] ?? null, fn (Builder $q, $d) => $q->where('data_doc', '<=', $d))
            ->when($filters['payment'] ?? null, fn (Builder $q, $p) => $q->paymentStatus($p))
            ->when(
                ($filters['sort'] ?? 'doc') === 'due',
                fn (Builder $q) => $q->orderByRaw('data_scadenta is null, data_scadenta')->orderBy('id'),
                fn (Builder $q) => $q->orderByDesc('data_doc')->orderByDesc('id'),
            );
    }

    /**
     * Cât e de plată în toată lista, nu doar pe pagina deschisă: pe monede,
     * fiindcă facturile vin în lei și în valută, plus echivalentul în lei.
     *
     * @param  Builder<Invoice>  $query
     * @return array<string, mixed>
     */
    private function totals(Builder $query): array
    {
        $rest = '(val_mon - val_mon_paid - val_mon_storno)';
        $lei = '(case when curs is null or curs = 0 then 1 else curs end)';

        $rows = $query->reorder()
            ->selectRaw("moneda, count(*) as invoices, sum({$rest}) as rest, sum({$rest} * {$lei}) as rest_lei")
            ->groupBy('moneda')
            ->get();

        return [
            'invoices' => (int) $rows->sum('invoices'),
            'lei' => round((float) $rows->sum('rest_lei'), 2),
            'by_currency' => $rows
                ->mapWithKeys(fn ($row) => [mb_strtoupper((string) ($row->moneda ?: 'RON')) => round((float) $row->rest, 2)])
                ->all(),
        ];
    }

    /**
     * Suma aprobată, dacă s-a cerut una: numai la o singură factură și numai
     * la aprobare. Pe mai multe facturi deodată o sumă n-ar ști a cui e.
     *
     * @param  array<string, mixed>  $validated
     */
    private function amountFor(array $validated, int $invoices): ?float
    {
        $amount = isset($validated['amount']) ? (float) $validated['amount'] : null;

        if ($amount === null) {
            return null;
        }

        if ($validated['decision'] !== 'approved') {
            throw ValidationException::withMessages(['amount' => 'O sumă se poate pune doar la aprobare.']);
        }

        if ($invoices !== 1) {
            throw ValidationException::withMessages(['amount' => 'Suma se aprobă factură cu factură.']);
        }

        return $amount;
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
        // Cine poate decide ceva pentru departament trece; ce anume poate
        // decide — aprobare sau doar contestare — spune fluxul.
        abort_unless(
            $request->user()->decidesFor($department),
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
     * Departamentele pentru care omul semnează: cele pe care e repartizat, și
     * atât. Nici administratorul nu le are pe toate, fiindcă aprobarea ține de
     * departament, nu de rol.
     *
     * @return Collection<int, Department>
     */
    private function departmentsOf(User $user)
    {
        return Department::query()
            ->whereNotNull('code')
            ->whereIn('id', $user->departmentIds())
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
