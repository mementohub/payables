<?php

namespace App\Services\Invoices;

use App\Models\Department;
use App\Models\Invoice;
use App\Models\InvoiceApproval;
use App\Models\InvoiceEvent;
use App\Services\SyncService;

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
            'comments_count' => (int) ($invoice->comments_count ?? 0),
            'has_com_int_counterpart' => (bool) ($invoice->has_com_int_counterpart ?? false),
            'source_invoice' => $this->sourceInvoicePayload($invoice),
            'baza' => $this->bazaPayload($invoice),
        ];

        if ($scope === 'primite') {
            $row['approval'] = $this->approvalPayload($invoice);
        }

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function sourceInvoicePayload(Invoice $invoice): ?array
    {
        $source = $invoice->sourceInvoice;

        if ($source === null) {
            return null;
        }

        // The source invoice's own partner is the *customer* on that issued
        // invoice (i.e. us), so it's not the real supplier. The real supplier
        // is whoever the source invoice was billing forward — its `nr_doc_baza`
        // chain when present, otherwise the source company itself.
        $chainedPartner = $source->relationLoaded('bazaInvoice')
            ? $source->bazaInvoice?->partner
            : null;

        $sourceCompany = $invoice->sourceCompany ?? $source->company;

        return [
            'id' => $source->id,
            'data_doc' => $source->data_doc?->toDateString(),
            'tip_doc' => $source->tip_doc,
            'nr_doc' => $source->nr_doc,
            'company' => $sourceCompany
                ? ['id' => $sourceCompany->id, 'name' => $sourceCompany->name]
                : null,
            'real_supplier' => $chainedPartner ? [
                'id' => $chainedPartner->id,
                'name' => $chainedPartner->name,
                'cui' => $chainedPartner->cui,
            ] : null,
        ];
    }

    /**
     * Mark each invoice with `has_com_int_counterpart` indicating whether
     * another invoice of the *opposite* `partener_type` exists in the same
     * company sharing the same `com_int` (SeniorERP internal trip/order code).
     *
     * Run once per page to avoid N+1 — a single batched query covers all rows.
     *
     * @param  iterable<Invoice>  $invoices
     */
    public function preloadComIntCounterparts(iterable $invoices): void
    {
        $invoices = is_array($invoices) ? $invoices : iterator_to_array($invoices);

        $byCompany = [];

        foreach ($invoices as $invoice) {
            $code = $this->normalizeComInt($invoice->com_int);

            if ($code === null || $invoice->company_id === null || $invoice->partener_type === null) {
                continue;
            }

            $byCompany[$invoice->company_id][$code] = true;
        }

        $sides = [];

        if (! empty($byCompany)) {
            $rows = Invoice::query()
                ->where(function ($query) use ($byCompany) {
                    foreach ($byCompany as $companyId => $codes) {
                        $query->orWhere(function ($scoped) use ($companyId, $codes) {
                            $scoped
                                ->where('company_id', $companyId)
                                ->whereIn('com_int', array_keys($codes));
                        });
                    }
                })
                ->whereIn('partener_type', ['furnizor', 'client'])
                ->get(['company_id', 'com_int', 'partener_type']);

            foreach ($rows as $row) {
                $code = $this->normalizeComInt($row->com_int);

                if ($code === null) {
                    continue;
                }

                $sides[$row->company_id.'|'.$code][$row->partener_type] = true;
            }
        }

        foreach ($invoices as $invoice) {
            $code = $this->normalizeComInt($invoice->com_int);

            if ($code === null || $invoice->partener_type === null) {
                $invoice->has_com_int_counterpart = false;

                continue;
            }

            $opposite = $invoice->partener_type === 'furnizor' ? 'client' : 'furnizor';
            $invoice->has_com_int_counterpart = ! empty($sides[$invoice->company_id.'|'.$code][$opposite]);
        }
    }

    private function normalizeComInt(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return ($trimmed === '' || $trimmed === '-') ? null : $trimmed;
    }

    /**
     * Issued (client) invoices in the same company that share the received
     * invoice's `com_int` (internal trip/order code from SeniorERP).
     *
     * @return list<array<string, mixed>>
     */
    public function comIntMatchesPayload(Invoice $invoice): array
    {
        if ($invoice->partener_type !== 'furnizor') {
            return [];
        }

        $code = $this->normalizeComInt($invoice->com_int);

        if ($code === null) {
            return [];
        }

        return Invoice::query()
            ->where('company_id', $invoice->company_id)
            ->where('com_int', $code)
            ->where('partener_type', 'client')
            ->where('id', '!=', $invoice->id)
            ->with('partner:id,name,cui')
            ->orderBy('data_doc')
            ->get([
                'id', 'data_doc', 'tip_doc', 'nr_doc', 'partner_id',
                'moneda', 'val_mon', 'val_mon_tva', 'val_mon_paid',
                'payment_status', 'data_inchidere',
            ])
            ->map(fn (Invoice $match) => [
                'id' => $match->id,
                'data_doc' => $match->data_doc?->toDateString(),
                'tip_doc' => $match->tip_doc,
                'nr_doc' => $match->nr_doc,
                'moneda' => $match->moneda,
                'val_mon' => (float) $match->val_mon,
                'val_mon_tva' => (float) $match->val_mon_tva,
                'val_mon_paid' => (float) $match->val_mon_paid,
                'payment_status' => $match->payment_status,
                'data_inchidere' => $match->data_inchidere?->toDateString(),
                'partner' => $match->partner ? [
                    'id' => $match->partner->id,
                    'name' => $match->partner->name,
                    'cui' => $match->partner->cui,
                ] : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function bazaPayload(Invoice $invoice): ?array
    {
        if ($invoice->nr_doc_baza === null && $invoice->tip_doc_baza === null) {
            return null;
        }

        $baza = $invoice->relationLoaded('bazaInvoice') ? $invoice->bazaInvoice : null;

        return [
            'data_doc' => $invoice->data_doc_baza?->toDateString(),
            'tip_doc' => $invoice->tip_doc_baza,
            'nr_doc' => $invoice->nr_doc_baza,
            'invoice' => $baza ? [
                'id' => $baza->id,
                'real_supplier' => $baza->partner ? [
                    'id' => $baza->partner->id,
                    'name' => $baza->partner->name,
                    'cui' => $baza->partner->cui,
                ] : null,
            ] : null,
        ];
    }

    /**
     * Resolve the real-supplier chain for a batch of invoices. Two paths are
     * considered, in priority order:
     *
     *   1. Same-company refacturare: this invoice's `nr_doc_baza` matches
     *      another invoice's `nr_doc` in the same company. Attached as the
     *      `bazaInvoice` relation.
     *   2. Cross-company refacturare: a received (FactFI/FE) invoice has a
     *      counterpart issued (FactCI/CE/INT) invoice in another synced company
     *      with the same `(data_doc, nr_doc)`. Attached as `sourceInvoice`.
     *      That source invoice may itself have a same-company `nr_doc_baza`
     *      chain — its `bazaInvoice` is also resolved so the *real* supplier
     *      sits two hops away (e.g. German Touristik → Memento Bus FactCI →
     *      Memento Bus FactFI from CHRISTIAN76 TOUR SRL).
     *
     * Matching is whitespace- and case-tolerant because ERP-imported values
     * can carry trailing spaces or differ in casing.
     *
     * @param  iterable<Invoice>  $invoices
     */
    public function preloadBazaInvoices(iterable $invoices): void
    {
        $invoices = is_array($invoices) ? $invoices : iterator_to_array($invoices);

        $this->attachSameCompanyBaza($invoices);

        $needsSource = array_filter(
            $invoices,
            fn (Invoice $i) => $i->partener_type === 'furnizor'
                && ! $i->relationLoaded('bazaInvoice')
                && $i->sourceInvoice === null
        );

        $this->attachCrossCompanySource($needsSource);

        $sources = [];
        foreach ($invoices as $invoice) {
            if ($invoice->sourceInvoice !== null) {
                $sources[] = $invoice->sourceInvoice;
            }
        }

        if (! empty($sources)) {
            $this->attachSameCompanyBaza($sources);
        }
    }

    /**
     * @param  iterable<Invoice>  $invoices
     */
    private function attachSameCompanyBaza(iterable $invoices): void
    {
        $byCompany = [];

        foreach ($invoices as $invoice) {
            $needle = $this->normalizeDocNumber($invoice->nr_doc_baza);

            if ($needle === null || $invoice->company_id === null) {
                continue;
            }

            $byCompany[$invoice->company_id][$needle] = $invoice->nr_doc_baza;
        }

        if (empty($byCompany)) {
            return;
        }

        $candidates = Invoice::query()
            ->where(function ($query) use ($byCompany) {
                foreach ($byCompany as $companyId => $needles) {
                    $query->orWhere(function ($scoped) use ($companyId, $needles) {
                        $scoped
                            ->where('company_id', $companyId)
                            ->where(function ($inner) use ($needles) {
                                foreach ($needles as $needle => $_raw) {
                                    $inner->orWhereRaw('LOWER(TRIM(nr_doc)) = ?', [$needle]);
                                }
                            });
                    });
                }
            })
            ->with('partner:id,name,cui')
            ->get(['id', 'company_id', 'partner_id', 'nr_doc']);

        $matches = [];

        foreach ($candidates as $candidate) {
            $key = $candidate->company_id.'|'.$this->normalizeDocNumber($candidate->nr_doc);
            $matches[$key] ??= $candidate;
        }

        foreach ($invoices as $invoice) {
            $needle = $this->normalizeDocNumber($invoice->nr_doc_baza);

            if ($needle === null) {
                continue;
            }

            $match = $matches[$invoice->company_id.'|'.$needle] ?? null;

            if ($match !== null && $match->id !== $invoice->id) {
                $invoice->setRelation('bazaInvoice', $match);
            }
        }
    }

    /**
     * @param  iterable<Invoice>  $invoices
     */
    private function attachCrossCompanySource(iterable $invoices): void
    {
        $invoices = is_array($invoices) ? $invoices : iterator_to_array($invoices);

        if (empty($invoices)) {
            return;
        }

        $pairs = [];

        foreach ($invoices as $invoice) {
            if ($invoice->data_doc === null || $invoice->nr_doc === null) {
                continue;
            }

            $dataDoc = $invoice->data_doc->toDateString();
            $pairs[$dataDoc.'|'.$invoice->nr_doc] = [$dataDoc, $invoice->nr_doc];
        }

        if (empty($pairs)) {
            return;
        }

        $candidates = Invoice::query()
            ->whereIn('tip_doc', SyncService::CLIENT_DOC_TYPES)
            ->where(function ($query) use ($pairs) {
                foreach ($pairs as [$dataDoc, $nrDoc]) {
                    $query->orWhere(function ($scoped) use ($dataDoc, $nrDoc) {
                        $scoped
                            ->whereDate('data_doc', $dataDoc)
                            ->where('nr_doc', $nrDoc);
                    });
                }
            })
            ->with('partner:id,name,cui', 'company:id,name')
            ->get(['id', 'company_id', 'partner_id', 'data_doc', 'tip_doc', 'nr_doc', 'tip_doc_baza', 'nr_doc_baza', 'data_doc_baza']);

        $byKey = [];
        foreach ($candidates as $candidate) {
            $key = $candidate->data_doc?->toDateString().'|'.$candidate->nr_doc;
            $byKey[$key][] = $candidate;
        }

        foreach ($invoices as $invoice) {
            $key = $invoice->data_doc?->toDateString().'|'.$invoice->nr_doc;
            $bucket = $byKey[$key] ?? [];

            $crossCompany = array_values(array_filter(
                $bucket,
                fn (Invoice $c) => $c->company_id !== $invoice->company_id
            ));

            if (count($crossCompany) === 1) {
                $invoice->setRelation('sourceInvoice', $crossCompany[0]);
            }
        }
    }

    private function normalizeDocNumber(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : strtolower($trimmed);
    }

    /**
     * @return array<string, mixed>
     */
    public function approvalPayload(Invoice $invoice): array
    {
        $responsabilDepts = $invoice->partner?->responsabilDepartments ?? collect();

        $activeApprovals = $invoice->approvals->whereNull('revoked_at');

        $responsabilApprovalsByDept = $activeApprovals
            ->where('role', InvoiceApproval::ROLE_RESPONSABIL)
            ->keyBy('department_id');

        $responsabilSteps = $responsabilDepts->map(function (Department $dept) use ($responsabilApprovalsByDept) {
            $approval = $responsabilApprovalsByDept->get($dept->id);

            return [
                'approval_id' => $approval?->id,
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

        $ordonatorApproval = $activeApprovals->firstWhere('role', InvoiceApproval::ROLE_ORDONATOR);
        $needsApproval = $responsabilDepts->isNotEmpty();

        return [
            'needs_approval' => $needsApproval,
            'stage' => $this->stage($invoice, $needsApproval),
            'responsabili_approved_at' => $invoice->responsabili_approved_at?->toIso8601String(),
            'is_fully_approved' => (bool) $invoice->is_fully_approved,
            'fully_approved_at' => $invoice->fully_approved_at?->toIso8601String(),
            'responsabil_steps' => $responsabilSteps,
            'ordonator' => $ordonatorApproval ? [
                'approval_id' => $ordonatorApproval->id,
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

    /**
     * @return list<array<string, mixed>>
     */
    public function timelinePayload(Invoice $invoice): array
    {
        if (! $invoice->relationLoaded('events')) {
            return [];
        }

        return $invoice->events->map(fn (InvoiceEvent $event) => [
            'id' => $event->id,
            'type' => $event->type,
            'body' => $event->body,
            'payload' => $event->payload,
            'created_at' => $event->created_at?->toIso8601String(),
            'user' => $event->user ? [
                'id' => $event->user->id,
                'name' => $event->user->name,
            ] : null,
            'department' => $event->department ? [
                'id' => $event->department->id,
                'name' => $event->department->name,
                'type' => $event->department->type,
            ] : null,
        ])->values()->all();
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
