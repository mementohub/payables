<?php

namespace App\Services\Invoices;

use App\Models\Department;
use App\Models\Invoice;
use App\Models\InvoiceApproval;

class InvoicePresenter
{
    /**
     * @return array<string, mixed>
     */
    public function listRow(Invoice $invoice, string $scope): array
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

    /**
     * @return array<string, mixed>
     */
    public function approvalPayload(Invoice $invoice): array
    {
        $supervisorDepts = $invoice->partner?->supervisorDepartments ?? collect();

        $supervisorApprovalsByDept = $invoice->approvals
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

        $masterApproval = $invoice->approvals->firstWhere('role', InvoiceApproval::ROLE_MASTER);
        $needsApproval = $supervisorDepts->isNotEmpty();

        return [
            'needs_approval' => $needsApproval,
            'stage' => $this->stage($invoice, $needsApproval),
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

    public function exportApprovalLabel(Invoice $invoice): string
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

    public function paymentStatusLabel(string $status): string
    {
        return match ($status) {
            'paid' => 'Plătită',
            'unpaid' => 'Neplătită',
            'partial' => 'Parțial',
            default => $status,
        };
    }

    private function stage(Invoice $invoice, bool $needsApproval): string
    {
        if (! $needsApproval) {
            return 'na';
        }

        if ($invoice->is_fully_approved) {
            return 'ok';
        }

        if ($invoice->supervisors_approved_at !== null) {
            return 'supervisors_ok';
        }

        return 'pending';
    }
}
