<?php

namespace App\Http\Controllers\Approvals;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\PaymentRun;
use App\Models\PaymentRunItem;
use App\Models\User;
use App\Services\Approvals\ApprovalPresenter;
use App\Services\Approvals\PaymentRunService;
use App\Services\CashFlow\OmcCashFlowReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The weekly payment runs: the list, one run with its invoices by
 * department and the cash position, and the steps that move it on.
 */
class PaymentRunController extends Controller
{
    public function __construct(private PaymentRunService $runs, private ApprovalPresenter $presenter) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $payDate = Carbon::now()->next(Carbon::WEDNESDAY);

        return Inertia::render('payment-runs/index', [
            'runs' => PaymentRun::query()
                ->with(['createdBy:id,name', 'approvedBy:id,name', 'exportedBy:id,name'])
                ->withCount(['items as included_count' => fn ($q) => $q->where('status', PaymentRunItem::INCLUDED)])
                ->orderByDesc('pay_date')
                ->orderByDesc('id')
                ->paginate(20)
                ->through(fn (PaymentRun $run) => [
                    'id' => $run->id,
                    'reference' => $run->reference,
                    'pay_date' => $run->pay_date->toDateString(),
                    'due_until' => $run->due_until->toDateString(),
                    'status' => $run->status,
                    'included_count' => (int) $run->included_count,
                    'totals' => $this->totals($run),
                    'created_by' => $run->createdBy?->name,
                    'approved_by' => $run->approvedBy?->name,
                    'approved_at' => $run->approved_at?->toIso8601String(),
                    'exported_by' => $run->exportedBy?->name,
                    'exported_at' => $run->exported_at?->toIso8601String(),
                ]),
            'defaults' => ['pay_date' => $payDate->toDateString(), 'due_until' => $payDate->copy()->addDays(6)->toDateString()],
            'can' => ['create' => $user->hasRole(User::ROLE_FINANCE)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'pay_date' => ['required', 'date', 'after_or_equal:today'],
            'due_until' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $company = Company::query()->findOrFail((int) ($request->session()->get('active_company_id') ?? Company::query()->orderBy('id')->value('id')));
        $run = $this->runs->create($company, Carbon::parse($validated['pay_date']), Carbon::parse($validated['due_until']), $request->user(), $validated['note'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => sprintf('%s: %d facturi de plătit.', $run->reference, $run->items()->count())]);

        return redirect()->route('payment-runs.show', $run);
    }

    public function show(Request $request, PaymentRun $run): Response
    {
        $user = $request->user();
        $this->runs->recompute($run);
        $run->load(['createdBy:id,name', 'approvedBy:id,name', 'exportedBy:id,name']);

        $items = $run->items()
            ->with(['department:id,name,group', 'invoice' => fn ($q) => $q->with(ApprovalPresenter::relations())])
            ->get()
            ->sortBy(fn (PaymentRunItem $item) => [$item->department?->name ?? 'ZZZ', $item->invoice?->data_scadenta?->toDateString() ?? '9999'])
            ->values();

        $myDepartments = $user->isAdmin() ? null : $user->departmentIds();

        return Inertia::render('payment-runs/show', [
            'run' => [
                'id' => $run->id,
                'reference' => $run->reference,
                'pay_date' => $run->pay_date->toDateString(),
                'due_until' => $run->due_until->toDateString(),
                'status' => $run->status,
                'note' => $run->note,
                'created_by' => $run->createdBy?->name,
                'approved_by' => $run->approvedBy?->name,
                'approved_at' => $run->approved_at?->toIso8601String(),
                'exported_by' => $run->exportedBy?->name,
                'exported_at' => $run->exported_at?->toIso8601String(),
                'totals' => $this->totals($run),
            ],
            'cash' => $this->runs->cashPosition($run),
            'items' => $items->map(fn (PaymentRunItem $item) => [
                'id' => $item->id,
                'status' => $item->status,
                'amount' => $item->amount,
                'currency' => $item->currency,
                'comment' => $item->comment,
                'department' => $item->department ? ['id' => $item->department->id, 'name' => $item->department->name] : null,
                'invoice' => $item->invoice ? $this->presenter->invoice($item->invoice) : null,
            ]),
            'my_departments' => $myDepartments,
            'can' => [
                'approve' => $user->hasRole(User::ROLE_TOP_MANAGEMENT) && $run->status === PaymentRun::FINAL,
                'final' => $user->hasRole(User::ROLE_TOP_MANAGEMENT),
                'edit' => ($user->hasRole(User::ROLE_FINANCE) || $user->hasRole(User::ROLE_TOP_MANAGEMENT)) && in_array($run->status, [PaymentRun::REVIEW, PaymentRun::FINAL], true),
                'export' => $user->hasRole(User::ROLE_TREASURY) && in_array($run->status, [PaymentRun::APPROVED, PaymentRun::EXPORTED], true),
                'close' => $user->hasRole(User::ROLE_FINANCE) && $run->isActive(),
            ],
            'payable_invoice_ids' => $this->runs->payableInvoiceIds($run),
        ]);
    }

    public function approve(Request $request, PaymentRun $run): RedirectResponse
    {
        $validated = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);
        $this->runs->approve($run, $request->user(), $validated['comment'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$run->reference} a fost aprobat; Trezoreria îl poate trimite la bancă."]);

        return back();
    }

    public function toggle(Request $request, PaymentRun $run, PaymentRunItem $item): RedirectResponse
    {
        abort_unless($item->payment_run_id === $run->id, 404);

        $validated = $request->validate([
            'included' => ['required', 'boolean'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->runs->setIncluded($run, $item, (bool) $validated['included'], $request->user(), $validated['comment'] ?? null);

        return back();
    }

    public function exported(Request $request, PaymentRun $run): RedirectResponse
    {
        $this->runs->markExported($run, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$run->reference} a fost trimis la bancă. Se închide singur când OMC înregistrează plățile."]);

        return back();
    }

    public function close(Request $request, PaymentRun $run): RedirectResponse
    {
        $validated = $request->validate(['cancel' => ['nullable', 'boolean']]);
        $this->runs->close($run, $request->user(), (bool) ($validated['cancel'] ?? false));

        return back();
    }

    /**
     * Totals of the invoices still in the run, by currency and in lei.
     *
     * @return array{count: int, by_currency: array<string, float>}
     */
    private function totals(PaymentRun $run): array
    {
        $byCurrency = [];
        $count = 0;

        foreach ($run->items()->where('status', PaymentRunItem::INCLUDED)->get(['amount', 'currency']) as $item) {
            $currency = OmcCashFlowReader::currency((string) ($item->currency ?? 'RON'));
            $byCurrency[$currency] = round(($byCurrency[$currency] ?? 0) + $item->amount, 2);
            $count++;
        }

        return ['count' => $count, 'by_currency' => $byCurrency];
    }
}
