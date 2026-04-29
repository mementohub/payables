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
        $responsabilDepts = $invoice->partner?->responsabilDepartments ?? collect();

        $responsabilApprovalsByDept = $invoice->approvals
            ->where('role', InvoiceApproval::ROLE_RESPONSABIL)
            ->keyBy('department_id');

        $responsabilSteps = $responsabilDepts->map(function (Department $dept) use ($responsabilApprovalsByDept) {
            $approval = $responsabilApprovalsByDept->get($dept->id);

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

        $ordonatorApproval = $invoice->approvals->firstWhere('role', InvoiceApproval::ROLE_ORDONATOR);
        $needsApproval = $responsabilDepts->isNotEmpty();

        return [
            'needs_approval' => $needsApproval,
            'stage' => $this->stage($invoice, $needsApproval),
            'responsabili_approved_at' => $invoice->responsabili_approved_at?->toIso8601String(),
            'is_fully_approved' => (bool) $invoice->is_fully_approved,
            'fully_approved_at' => $invoice->fully_approved_at?->toIso8601String(),
            'responsabil_steps' => $responsabilSteps,
            'ordonator' => $ordonatorApproval ? [
                'department_id' => $ordonatorApproval->department_id,
                'department_name' => $ordonatorApproval->department?->name,
                'approved_by' => $ordonatorApproval->user ? [
                    'id' => $ordonatorApproval->user->id,
                    'name' => $ordonatorApproval->user->name,
                ] : null,
                'approved_at' => $ordonatorApproval->approved_at?->toIso8601String(),
            ] : null,
        ];
    }

    public function exportApprovalLabel(Invoice $invoice): string
    {
        $responsabilDepts = $invoice->partner?->responsabilDepartments ?? collect();

        if ($responsabilDepts->isEmpty()) {
            return 'Fără departament';
        }

        if ($invoice->is_fully_approved) {
            return 'Bun de plată';
        }

        if ($invoice->responsabili_approved_at !== null) {
            return 'Așteaptă ordonator';
        }

        return 'Așteaptă responsabil';
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

        if ($invoice->responsabili_approved_at !== null) {
            return 'responsabili_ok';
        }

        return 'pending';
    }
}
