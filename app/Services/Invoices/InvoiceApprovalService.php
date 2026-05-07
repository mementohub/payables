<?php

namespace App\Services\Invoices;

use App\Models\Department;
use App\Models\Invoice;
use App\Models\InvoiceApproval;
use App\Models\InvoiceEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceApprovalService
{
    public function approve(Invoice $invoice, Department $department, User $user): InvoiceApproval
    {
        if ($invoice->partener_type !== 'furnizor') {
            throw ValidationException::withMessages(['invoice' => 'Doar facturile primite pot fi aprobate.']);
        }

        if (! $user->departments()->whereKey($department->id)->exists()) {
            throw new AuthorizationException('Nu faci parte din acest departament.');
        }

        $invoice->loadMissing('partner.responsabilDepartments');

        return DB::transaction(function () use ($invoice, $department, $user) {
            $fresh = Invoice::whereKey($invoice->id)->lockForUpdate()->first();

            $approval = match ($department->type) {
                Department::TYPE_RESPONSABIL => $this->recordResponsabilApproval($fresh, $department, $user),
                Department::TYPE_ORDONATOR => $this->recordOrdonatorApproval($fresh, $department, $user),
                default => throw ValidationException::withMessages(['department_id' => 'Tip departament necunoscut.']),
            };

            InvoiceEvent::create([
                'invoice_id' => $fresh->id,
                'user_id' => $user->id,
                'department_id' => $department->id,
                'type' => InvoiceEvent::TYPE_APPROVED,
                'payload' => [
                    'approval_id' => $approval->id,
                    'role' => $approval->role,
                    'department_name' => $department->name,
                ],
            ]);

            return $approval;
        });
    }

    public function revoke(InvoiceApproval $approval, User $user, string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Motivul retragerii este obligatoriu.']);
        }

        if ($approval->isRevoked()) {
            throw ValidationException::withMessages(['approval' => 'Aprobarea a fost deja retrasă.']);
        }

        if ($approval->user_id !== $user->id) {
            throw new AuthorizationException('Doar utilizatorul care a aprobat poate retrage aprobarea.');
        }

        DB::transaction(function () use ($approval, $user, $reason) {
            $now = Carbon::now();

            $approval->forceFill([
                'revoked_at' => $now,
                'revoked_by_id' => $user->id,
                'revoke_reason' => $reason,
            ])->save();

            $invoice = Invoice::whereKey($approval->invoice_id)->lockForUpdate()->first();
            $this->recomputeApprovalFlags($invoice);

            InvoiceEvent::create([
                'invoice_id' => $approval->invoice_id,
                'user_id' => $user->id,
                'department_id' => $approval->department_id,
                'type' => InvoiceEvent::TYPE_APPROVAL_REVOKED,
                'body' => $reason,
                'payload' => [
                    'approval_id' => $approval->id,
                    'role' => $approval->role,
                ],
            ]);
        });
    }

    private function recordResponsabilApproval(Invoice $invoice, Department $department, User $user): InvoiceApproval
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
            ->active()
            ->exists();

        if ($alreadyApproved) {
            throw ValidationException::withMessages(['department_id' => 'Departamentul a confirmat deja această factură.']);
        }

        $now = Carbon::now();

        $approval = InvoiceApproval::create([
            'invoice_id' => $invoice->id,
            'department_id' => $department->id,
            'user_id' => $user->id,
            'role' => InvoiceApproval::ROLE_RESPONSABIL,
            'approved_at' => $now,
        ]);

        $approvedDeptIds = InvoiceApproval::where('invoice_id', $invoice->id)
            ->where('role', InvoiceApproval::ROLE_RESPONSABIL)
            ->active()
            ->pluck('department_id');

        if ($assigned->pluck('id')->diff($approvedDeptIds)->isEmpty()) {
            $invoice->forceFill(['responsabili_approved_at' => $now])->save();
        }

        return $approval;
    }

    private function recordOrdonatorApproval(Invoice $invoice, Department $department, User $user): InvoiceApproval
    {
        if ($invoice->responsabili_approved_at === null) {
            throw ValidationException::withMessages(['department_id' => 'Ordonatorii pot aproba doar după responsabili.']);
        }

        $hasActiveOrdonator = InvoiceApproval::where('invoice_id', $invoice->id)
            ->where('role', InvoiceApproval::ROLE_ORDONATOR)
            ->active()
            ->exists();

        if ($hasActiveOrdonator) {
            throw ValidationException::withMessages(['department_id' => 'Factura este deja aprobată complet.']);
        }

        $now = Carbon::now();

        $approval = InvoiceApproval::create([
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

        return $approval;
    }

    private function recomputeApprovalFlags(Invoice $invoice): void
    {
        $invoice->loadMissing('partner.responsabilDepartments');
        $assigned = $invoice->partner?->responsabilDepartments ?? collect();

        $approvedRespDeptIds = InvoiceApproval::where('invoice_id', $invoice->id)
            ->where('role', InvoiceApproval::ROLE_RESPONSABIL)
            ->active()
            ->pluck('department_id');

        $allRespApproved = $assigned->isNotEmpty()
            && $assigned->pluck('id')->diff($approvedRespDeptIds)->isEmpty();

        $hasActiveOrdonator = InvoiceApproval::where('invoice_id', $invoice->id)
            ->where('role', InvoiceApproval::ROLE_ORDONATOR)
            ->active()
            ->exists();

        $invoice->forceFill([
            'responsabili_approved_at' => $allRespApproved ? ($invoice->responsabili_approved_at ?? Carbon::now()) : null,
            'is_fully_approved' => $allRespApproved && $hasActiveOrdonator,
            'fully_approved_at' => ($allRespApproved && $hasActiveOrdonator) ? ($invoice->fully_approved_at ?? Carbon::now()) : null,
        ])->save();
    }
}
