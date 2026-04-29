<?php

namespace App\Services\Invoices;

use App\Models\Department;
use App\Models\Invoice;
use App\Models\InvoiceApproval;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceApprovalService
{
    public function approve(Invoice $invoice, Department $department, User $user): void
    {
        if ($invoice->partener_type !== 'furnizor') {
            throw ValidationException::withMessages(['invoice' => 'Doar facturile primite pot fi aprobate.']);
        }

        if (! $user->departments()->whereKey($department->id)->exists()) {
            throw new AuthorizationException('Nu faci parte din acest departament.');
        }

        $invoice->loadMissing('partner.supervisorDepartments');

        DB::transaction(function () use ($invoice, $department, $user) {
            $fresh = Invoice::whereKey($invoice->id)->lockForUpdate()->first();

            match ($department->type) {
                Department::TYPE_SUPERVISOR => $this->recordSupervisorApproval($fresh, $department, $user),
                Department::TYPE_MASTER => $this->recordMasterApproval($fresh, $department, $user),
                default => throw ValidationException::withMessages(['department_id' => 'Tip departament necunoscut.']),
            };
        });
    }

    private function recordSupervisorApproval(Invoice $invoice, Department $department, User $user): void
    {
        $assigned = $invoice->partner?->supervisorDepartments ?? collect();

        if (! $assigned->contains('id', $department->id)) {
            throw new AuthorizationException('Departamentul nu este atribuit acestui furnizor.');
        }

        if ($invoice->supervisors_approved_at !== null) {
            throw ValidationException::withMessages(['department_id' => 'Etapa de supervizori este deja închisă.']);
        }

        $alreadyApproved = InvoiceApproval::where('invoice_id', $invoice->id)
            ->where('department_id', $department->id)
            ->exists();

        if ($alreadyApproved) {
            throw ValidationException::withMessages(['department_id' => 'Departamentul a confirmat deja această factură.']);
        }

        $now = Carbon::now();

        InvoiceApproval::create([
            'invoice_id' => $invoice->id,
            'department_id' => $department->id,
            'user_id' => $user->id,
            'role' => InvoiceApproval::ROLE_SUPERVISOR,
            'approved_at' => $now,
        ]);

        $approvedDeptIds = InvoiceApproval::where('invoice_id', $invoice->id)
            ->where('role', InvoiceApproval::ROLE_SUPERVISOR)
            ->pluck('department_id');

        if ($assigned->pluck('id')->diff($approvedDeptIds)->isEmpty()) {
            $invoice->forceFill(['supervisors_approved_at' => $now])->save();
        }
    }

    private function recordMasterApproval(Invoice $invoice, Department $department, User $user): void
    {
        if ($invoice->supervisors_approved_at === null) {
            throw ValidationException::withMessages(['department_id' => 'Masterii pot aproba doar după supervizori.']);
        }

        if ($invoice->is_fully_approved) {
            throw ValidationException::withMessages(['department_id' => 'Factura este deja aprobată complet.']);
        }

        $now = Carbon::now();

        InvoiceApproval::create([
            'invoice_id' => $invoice->id,
            'department_id' => $department->id,
            'user_id' => $user->id,
            'role' => InvoiceApproval::ROLE_MASTER,
            'approved_at' => $now,
        ]);

        $invoice->forceFill([
            'is_fully_approved' => true,
            'fully_approved_at' => $now,
        ])->save();
    }
}
