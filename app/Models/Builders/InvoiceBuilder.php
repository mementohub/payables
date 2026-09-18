<?php

namespace App\Models\Builders;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<Invoice>
 */
class InvoiceBuilder extends Builder
{
    public function withListRelations(): self
    {
        return $this
            ->with([
                'partner:id,name,cui',
                'company:id,name',
                'department:id,name,group',
                'departmentApprovals' => fn ($q) => $q->with(['department:id,name', 'decidedBy:id,name'])->orderByDesc('amount'),
                'sourceCompany:id,name',
                'sourceInvoice:id,company_id,partner_id,data_doc,tip_doc,nr_doc,tip_doc_baza,nr_doc_baza,data_doc_baza',
                'sourceInvoice.partner:id,name,cui',
                'sourceInvoice.company:id,name',
            ])
            ->withCount('comments');
    }

    public function forScope(string $scope): self
    {
        return match ($scope) {
            'primite' => $this->where('partener_type', 'furnizor'),
            'emise' => $this->where('partener_type', 'client'),
            default => $this,
        };
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
        if (! in_array($status, Invoice::PAYMENT_STATUSES, true)) {
            return $this;
        }

        return $this->whereRaw('('.Invoice::paymentStatusSql().') = ?', [$status]);
    }

    /**
     * Invoices at one step of the approval flow; "none" for the ones outside
     * it (paid when they arrived, or credit notes).
     */
    public function approvalStatus(?string $status): self
    {
        return match (true) {
            $status === 'none' => $this->whereNull('approval_status'),
            in_array($status, ['routing', 'department', 'final', 'approved', 'disputed', 'postponed'], true) => $this->where('approval_status', $status),
            default => $this,
        };
    }

    /**
     * Invoices a department owns part of.
     */
    public function inDepartment(?int $departmentId): self
    {
        if (! $departmentId) {
            return $this;
        }

        return $this->where(fn ($q) => $q
            ->where('department_id', $departmentId)
            ->orWhereHas('departmentApprovals', fn ($a) => $a->where('department_id', $departmentId)));
    }

    public function search(?string $term): self
    {
        if (! $term) {
            return $this;
        }

        // `nr_doc` mirrors the ERP key byte for byte, so the column is stored
        // on a case-sensitive collation; the search folds the case itself.
        $folded = '%'.mb_strtolower($term).'%';

        return $this->where(function ($q) use ($term, $folded) {
            $q->whereRaw('lower(nr_doc) like ?', [$folded])
                ->orWhereHas('partner', fn ($p) => $p->where('name', 'like', "%{$term}%"));
        });
    }
}
