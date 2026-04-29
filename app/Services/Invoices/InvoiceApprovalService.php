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

        $invoice->loadMissing('partner.responsabilDepartments');

        DB::transaction(function () use ($invoice, $department, $user) {
            $fresh = Invoice::whereKey($invoice->id)->lockForUpdate()->first();

            match ($department->type) {
                Department::TYPE_RESPONSABIL => $this->recordResponsabilApproval($fresh, $department, $user),
                Department::TYPE_ORDONATOR => $this->recordOrdonatorApproval($fresh, $department, $user),
                default => throw ValidationException::withMessages(['department_id' => 'Tip departament necunoscut.']),
            };
        });
    }

    private function recordResponsabilApproval(Invoice $invoice, Department $department, User $user): void
    {
        $assigned = $invoice->partner?->responsabilDepartments ?? collect();

        if (! $assigned->contains('id', $department->id)) {
            throw new AuthorizationException('Departamentul nu este atribuit acestui furnizor.');
        }

        if ($invoice->responsabili_approved_at !== null) {
            throw ValidationException::withMessages(['department_id' => 'Etapa de responsabili este deja închisă.']);
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
            'role' => InvoiceApproval::ROLE_RESPONSABIL,
            'approved_at' => $now,
        ]);

        $approvedDeptIds = InvoiceApproval::where('invoice_id', $invoice->id)
            ->where('role', InvoiceApproval::ROLE_RESPONSABIL)
            ->pluck('department_id');

        if ($assigned->pluck('id')->diff($approvedDeptIds)->isEmpty()) {
            $invoice->forceFill(['responsabili_approved_at' => $now])->save();
        }
    }

    private function recordOrdonatorApproval(Invoice $invoice, Department $department, User $user): void
    {
        if ($invoice->responsabili_approved_at === null) {
            throw ValidationException::withMessages(['department_id' => 'Ordonatorii pot aproba doar după responsabili.']);
        }

        if ($invoice->is_fully_approved) {
            throw ValidationException::withMessages(['department_id' => 'Factura este deja aprobată complet.']);
        }

        $now = Carbon::now();

        InvoiceApproval::create([
            'invoice_id' => $invoice->id,
            'department_id' => $department->id,
            'user_id' => $user->id,
            'role' => InvoiceApproval::ROLE_ORDONATOR,
            'approved_at' => $now,
        ]);

        $invoice->forceFill([
            'is_fully_approved' => true,
            'fully_approved_at' => $now,
        ])->save();
    }
}
