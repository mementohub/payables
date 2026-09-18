<?php

namespace App\Services\Approvals;

use App\Models\Invoice;
use App\Models\InvoiceDepartmentApproval;
use Illuminate\Database\Eloquent\Builder;

/**
 * An invoice as the approval pages show it: who it is from, what is still
 * to pay, which departments own it and where each of them stands.
 */
class ApprovalPresenter
{
    /**
     * What the pages load with each invoice.
     *
     * @return array<string, \Closure|string>
     */
    public static function relations(): array
    {
        return [
            'partner:id,name,cui',
            'department:id,name,group',
            'departmentApprovals' => fn ($query) => $query->with(['department:id,name', 'decidedBy:id,name'])->orderByDesc('amount'),
            'finalDecidedBy:id,name',
        ];
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public static function load(Builder $query): Builder
    {
        return $query->with(self::relations());
    }

    /**
     * @return array<string, mixed>
     */
    public function invoice(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'nr_doc' => $invoice->nr_doc,
            'data_doc' => $invoice->data_doc?->toDateString(),
            'data_scadenta' => $invoice->data_scadenta?->toDateString(),
            'partner' => $invoice->partner ? ['id' => $invoice->partner->id, 'name' => $invoice->partner->name] : null,
            'moneda' => $invoice->moneda,
            'val_mon' => (float) $invoice->val_mon,
            'outstanding' => $invoice->outstandingAmount(),
            'payment_status' => $invoice->paymentStatus(),
            'department' => $invoice->department ? ['id' => $invoice->department->id, 'name' => $invoice->department->name, 'group' => $invoice->department->group] : null,
            'assignment_state' => $invoice->assignment_state,
            'approval_track' => $invoice->approval_track,
            'approval_status' => $invoice->approval_status,
            'postponed_until' => $invoice->postponed_until?->toDateString(),
            'final' => $invoice->final_decided_at !== null ? [
                'by' => $invoice->finalDecidedBy?->name,
                'at' => $invoice->final_decided_at->toIso8601String(),
                'comment' => $invoice->final_comment,
            ] : null,
            'departments' => $invoice->relationLoaded('departmentApprovals')
                ? $invoice->departmentApprovals->map(fn (InvoiceDepartmentApproval $approval) => [
                    'id' => $approval->department_id,
                    'name' => $approval->department?->name,
                    'amount' => $approval->amount,
                    'status' => $approval->status,
                    'comment' => $approval->comment,
                    'postponed_until' => $approval->postponed_until?->toDateString(),
                    'by' => $approval->decidedBy?->name,
                    'at' => $approval->decided_at?->toIso8601String(),
                ])->values()->all()
                : [],
        ];
    }
}
