<?php

namespace App\Services\Approvals;

use App\Models\Department;
use App\Models\Invoice;
use App\Models\InvoiceDepartmentApproval;
use App\Models\InvoiceEvent;
use App\Models\InvoiceLineDepartment;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The approval of an open supplier invoice.
 *
 *   routing     part of it has no department yet (Finance routes it);
 *   department  waiting for the departments that own it;
 *   final       every department approved; waiting for Top Management
 *               (one by one for overhead, through the payment run for
 *               tourism and intra-group invoices);
 *   approved    good to pay;
 *   disputed    a department or Top Management contests it;
 *   postponed   not to be paid before a date.
 *
 * A department's approval covers its share of the invoice (the value of
 * the lines routed to it). When the routing changes, the shares follow; a
 * department that no longer owns part of the invoice drops out.
 */
class InvoiceWorkflow
{
    public const ROUTING = 'routing';

    public const DEPARTMENT = 'department';

    public const FINAL = 'final';

    public const APPROVED = 'approved';

    public const DISPUTED = 'disputed';

    public const POSTPONED = 'postponed';

    public const TRACK_RUN = 'run';

    public const TRACK_INVOICE = 'invoice';

    /**
     * Bring the department approvals of the invoices in line with their
     * routing, and their status with the approvals.
     *
     * @param  iterable<int>  $invoiceIds
     */
    public function refresh(iterable $invoiceIds): void
    {
        foreach (collect($invoiceIds)->chunk(500) as $chunk) {
            $invoices = Invoice::query()->whereKey($chunk->all())->with(['department:id,group'])->get();
            $shares = InvoiceLineDepartment::query()
                ->whereIn('invoice_id', $chunk->all())
                ->whereNotNull('department_id')
                ->selectRaw('invoice_id, department_id, sum(abs(amount)) as amount')
                ->groupBy('invoice_id', 'department_id')
                ->get()
                ->groupBy('invoice_id');
            $approvals = InvoiceDepartmentApproval::query()->whereIn('invoice_id', $chunk->all())->get()->groupBy('invoice_id');

            foreach ($invoices as $invoice) {
                if (! $this->isPayable($invoice)) {
                    continue;
                }

                $this->reconcile($invoice, $shares->get($invoice->id, collect()), $approvals->get($invoice->id, collect()));
            }
        }
    }

    /**
     * A department's decision on its share of the invoice.
     */
    public function decide(Invoice $invoice, Department $department, User $user, string $decision, ?string $comment = null, ?CarbonInterface $until = null): void
    {
        if (! $user->approvesFor($department)) {
            throw new AuthorizationException('Nu aprobați pentru departamentul '.$department->name.'.');
        }

        $this->validateDecision($decision, $comment, $until);

        $approval = InvoiceDepartmentApproval::query()->where('invoice_id', $invoice->id)->where('department_id', $department->id)->first();

        if ($approval === null) {
            throw ValidationException::withMessages(['department' => 'Factura nu are nicio parte la departamentul '.$department->name.'.']);
        }

        DB::transaction(function () use ($invoice, $department, $user, $decision, $comment, $until, $approval) {
            $approval->forceFill([
                'status' => $decision,
                'comment' => $comment,
                'postponed_until' => $decision === InvoiceDepartmentApproval::POSTPONED ? $until?->toDateString() : null,
                'decided_by_id' => $user->id,
                'decided_at' => now(),
            ])->save();

            $this->event($invoice, $user, 'department_'.$decision, $comment, $department->id, $until);
            $this->recompute($invoice->fresh());
        });
    }

    /**
     * Top Management's decision on the whole invoice.
     */
    public function decideFinal(Invoice $invoice, User $user, string $decision, ?string $comment = null, ?CarbonInterface $until = null, ?string $via = null): void
    {
        if (! $user->hasRole(User::ROLE_TOP_MANAGEMENT)) {
            throw new AuthorizationException('Aprobarea finală aparține Top Management.');
        }

        $this->validateDecision($decision, $comment, $until);

        if ($decision === InvoiceDepartmentApproval::APPROVED && $invoice->approval_status !== self::FINAL) {
            throw ValidationException::withMessages(['decision' => 'Factura se aprobă final după ce o aprobă toate departamentele ei.']);
        }

        DB::transaction(function () use ($invoice, $user, $decision, $comment, $until, $via) {
            $invoice->forceFill([
                'approval_status' => $decision,
                'postponed_until' => $decision === self::POSTPONED ? $until?->toDateString() : null,
                'final_decided_by_id' => $user->id,
                'final_decided_at' => now(),
                'final_comment' => $comment,
            ])->save();

            $this->event($invoice, $user, 'final_'.$decision, $comment, null, $until, $via !== null ? ['via' => $via] : []);
        });
    }

    /**
     * Take a disputed or postponed invoice (or a final decision) back into
     * the flow: the departments that disputed or postponed decide again.
     */
    public function reopen(Invoice $invoice, User $user, ?string $comment = null): void
    {
        if (! $user->hasRole(User::ROLE_TOP_MANAGEMENT) && ! $user->hasRole(User::ROLE_FINANCE)) {
            throw new AuthorizationException('Doar Top Management sau Financiar pot redeschide o factură.');
        }

        DB::transaction(function () use ($invoice, $user, $comment) {
            InvoiceDepartmentApproval::query()
                ->where('invoice_id', $invoice->id)
                ->whereIn('status', [InvoiceDepartmentApproval::DISPUTED, InvoiceDepartmentApproval::POSTPONED])
                ->update(['status' => InvoiceDepartmentApproval::PENDING, 'postponed_until' => null, 'decided_by_id' => null, 'decided_at' => null]);

            $invoice->forceFill(['final_decided_by_id' => null, 'final_decided_at' => null, 'final_comment' => null, 'postponed_until' => null])->save();
            $this->event($invoice, $user, 'reopened', $comment);
            $this->recompute($invoice->fresh());
        });
    }

    /**
     * The status the approvals add up to, unless Top Management decided.
     */
    public function recompute(Invoice $invoice): void
    {
        $status = $this->statusOf($invoice);

        $invoice->forceFill([
            'approval_status' => $status,
            'postponed_until' => $status === self::POSTPONED
                ? ($invoice->final_decided_at !== null ? $invoice->postponed_until : InvoiceDepartmentApproval::query()->where('invoice_id', $invoice->id)->max('postponed_until'))
                : null,
        ])->save();
    }

    /**
     * Unpaid, positive and still in OMC: the invoices the flow is about.
     */
    public function isPayable(Invoice $invoice): bool
    {
        return $invoice->omc_removed_at === null
            && (float) $invoice->val_mon > 0
            && $invoice->outstandingAmount() > 0.01;
    }

    /**
     * @param  Collection<int, InvoiceLineDepartment>  $shares
     * @param  Collection<int, InvoiceDepartmentApproval>  $approvals
     */
    private function reconcile(Invoice $invoice, $shares, $approvals): void
    {
        $byDepartment = $approvals->keyBy('department_id');
        $owners = $shares->keyBy('department_id');
        $now = now();

        foreach ($owners as $departmentId => $share) {
            $approval = $byDepartment->get($departmentId);

            if ($approval === null) {
                InvoiceDepartmentApproval::query()->create([
                    'invoice_id' => $invoice->id,
                    'department_id' => $departmentId,
                    'amount' => round((float) $share->amount, 2),
                    'status' => InvoiceDepartmentApproval::PENDING,
                ]);
            } elseif (abs((float) $approval->amount - (float) $share->amount) > 0.005) {
                $approval->forceFill(['amount' => round((float) $share->amount, 2), 'updated_at' => $now])->save();
            }
        }

        $gone = $approvals->reject(fn (InvoiceDepartmentApproval $approval) => $owners->has($approval->department_id))->pluck('id');

        if ($gone->isNotEmpty()) {
            InvoiceDepartmentApproval::query()->whereKey($gone->all())->delete();
        }

        $invoice->approval_track = $invoice->department?->group === Department::GROUP_PRODUCT ? self::TRACK_RUN : self::TRACK_INVOICE;
        $this->recompute($invoice);
    }

    private function statusOf(Invoice $invoice): string
    {
        if ($invoice->final_decided_at !== null) {
            $postponedOver = $invoice->approval_status === self::POSTPONED && $invoice->postponed_until !== null && $invoice->postponed_until->isPast();

            if (! $postponedOver) {
                return (string) $invoice->approval_status;
            }
        }

        $approvals = InvoiceDepartmentApproval::query()->where('invoice_id', $invoice->id)->get(['status', 'postponed_until']);

        if ($invoice->assignment_state !== 'assigned' || $approvals->isEmpty()) {
            return self::ROUTING;
        }

        if ($approvals->contains('status', InvoiceDepartmentApproval::DISPUTED)) {
            return self::DISPUTED;
        }

        $postponed = $approvals->where('status', InvoiceDepartmentApproval::POSTPONED)
            ->filter(fn (InvoiceDepartmentApproval $approval) => $approval->postponed_until === null || $approval->postponed_until->isFuture());

        if ($postponed->isNotEmpty()) {
            return self::POSTPONED;
        }

        return $approvals->every(fn (InvoiceDepartmentApproval $approval) => in_array($approval->status, [InvoiceDepartmentApproval::APPROVED, InvoiceDepartmentApproval::POSTPONED], true))
            ? self::FINAL
            : self::DEPARTMENT;
    }

    private function validateDecision(string $decision, ?string $comment, ?CarbonInterface $until): void
    {
        if (! in_array($decision, [InvoiceDepartmentApproval::APPROVED, InvoiceDepartmentApproval::DISPUTED, InvoiceDepartmentApproval::POSTPONED], true)) {
            throw ValidationException::withMessages(['decision' => 'Decizie necunoscută.']);
        }

        if ($decision === InvoiceDepartmentApproval::DISPUTED && trim((string) $comment) === '') {
            throw ValidationException::withMessages(['comment' => 'Spuneți de ce contestați factura.']);
        }

        if ($decision === InvoiceDepartmentApproval::POSTPONED && ($until === null || ! $until->isFuture())) {
            throw ValidationException::withMessages(['until' => 'Alegeți data până la care se amână plata.']);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function event(Invoice $invoice, User $user, string $type, ?string $body, ?int $departmentId = null, ?CarbonInterface $until = null, array $payload = []): void
    {
        InvoiceEvent::query()->create([
            'invoice_id' => $invoice->id,
            'user_id' => $user->id,
            'department_id' => $departmentId,
            'type' => $type,
            'body' => $body,
            'payload' => array_filter([...$payload, 'until' => $until?->toDateString()]) ?: null,
        ]);
    }
}
