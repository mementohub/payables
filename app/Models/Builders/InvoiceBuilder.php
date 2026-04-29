<?php

namespace App\Models\Builders;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * @extends Builder<Invoice>
 */
class InvoiceBuilder extends Builder
{
    public function withListRelations(): self
    {
        return $this->with([
            'partner:id,name,cui',
            'partner.responsabilDepartments:id,name,type',
            'company:id,name',
            'approvals:id,invoice_id,department_id,user_id,role,approved_at',
            'approvals.user:id,name,email',
            'approvals.department:id,name,type',
            'sourceCompany:id,name',
            'sourceInvoice:id,company_id,partner_id,data_doc,tip_doc,nr_doc',
            'sourceInvoice.partner:id,name,cui',
        ]);
    }

    public function forScope(string $scope): self
    {
        return match ($scope) {
            'primite' => $this->where('partener_type', 'furnizor'),
            'emise' => $this->where('partener_type', 'client'),
            default => $this,
        };
    }

    public function visibleToFurnizorUser(?User $user): self
    {
        return $this;
    }

    public function forCompany(?int $companyId): self
    {
        return $companyId ? $this->where('company_id', $companyId) : $this;
    }

    public function dataDocBetween(?string $from, ?string $to): self
    {
        return $this
            ->when($from, fn ($q, $d) => $q->where('data_doc', '>=', $d))
            ->when($to, fn ($q, $d) => $q->where('data_doc', '<=', $d));
    }

    public function scadentaBetween(?string $from, ?string $to): self
    {
        return $this
            ->when($from, fn ($q, $d) => $q->where('data_scadenta', '>=', $d))
            ->when($to, fn ($q, $d) => $q->where('data_scadenta', '<=', $d));
    }

    public function paymentStatus(?string $status): self
    {
        return match ($status) {
            'paid' => $this->whereColumn('val_mon_paid', '>=', DB::raw('val_mon - 0.01')),
            'unpaid' => $this->where('val_mon_paid', '<=', 0.009),
            'partial' => $this
                ->where('val_mon_paid', '>', 0.009)
                ->whereColumn('val_mon_paid', '<', DB::raw('val_mon - 0.01')),
            default => $this,
        };
    }

    public function approvalStage(?string $stage): self
    {
        return match ($stage) {
            'ok' => $this->where('is_fully_approved', true),
            'responsabili_ok' => $this
                ->where('is_fully_approved', false)
                ->whereNotNull('responsabili_approved_at'),
            'pending' => $this
                ->where('is_fully_approved', false)
                ->whereHas('partner.responsabilDepartments'),
            'needs_approval' => $this->whereHas('partner.responsabilDepartments'),
            'na' => $this->whereDoesntHave('partner.responsabilDepartments'),
            default => $this,
        };
    }

    public function responsibleUser(?int $userId): self
    {
        if (! $userId) {
            return $this;
        }

        return $this->whereHas(
            'partner.responsabilDepartments.members',
            fn ($m) => $m->where('users.id', $userId),
        );
    }

    public function search(?string $term): self
    {
        if (! $term) {
            return $this;
        }

        return $this->where(function ($q) use ($term) {
            $q->where('nr_doc', 'like', "%{$term}%")
                ->orWhereHas('partner', fn ($p) => $p->where('name', 'like', "%{$term}%"));
        });
    }
}
