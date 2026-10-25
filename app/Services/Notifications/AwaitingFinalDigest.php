<?php

namespace App\Services\Notifications;

use App\Models\Invoice;
use App\Models\InvoiceDepartmentApproval;
use App\Services\Approvals\InvoiceWorkflow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Facturile pe care departamentele le-au aprobat și care așteaptă semnătura
 * Top Management-ului.
 *
 * Între aprobarea de departament și cea finală nu trecea nimic: factura
 * ajungea în coada de sus, iar cine trebuia s-o semneze afla abia când
 * intra în aplicație. De obicei merge repede — jumătate se decid în două
 * ore și jumătate — dar coada are o coadă lungă: la o trecere, douăzeci de
 * facturi stăteau de până la trei săptămâni.
 */
class AwaitingFinalDigest
{
    /**
     * @return array<string, mixed>
     */
    public function gather(): array
    {
        $today = Carbon::today();

        $invoices = Invoice::query()
            ->where('partener_type', 'furnizor')
            ->whereNull('omc_removed_at')
            // „final” înseamnă în fluxul nostru: toate departamentele au
            // aprobat, se așteaptă Top Management-ul.
            ->where('approval_status', InvoiceWorkflow::FINAL)
            ->whereNull('final_decided_at')
            ->with(['partner:id,name', 'company:id,name'])
            ->orderByDesc('val_mon')
            ->get();

        if ($invoices->isEmpty()) {
            return $this->empty($today);
        }

        // Cine a aprobat ultimul și când: de atunci stă factura la rând.
        $approvals = InvoiceDepartmentApproval::query()
            ->whereIn('invoice_id', $invoices->modelKeys())
            ->with(['department:id,name', 'decidedBy:id,name'])
            ->get()
            ->groupBy('invoice_id');

        $rows = $invoices->map(function (Invoice $invoice) use ($approvals, $today) {
            $theirs = $approvals->get($invoice->id, collect());
            $last = $theirs->sortByDesc('decided_at')->first();
            $rest = round((float) $invoice->val_mon - (float) $invoice->val_mon_paid - (float) $invoice->val_mon_storno, 2);

            return [
                'id' => $invoice->id,
                'nr_doc' => trim((string) $invoice->nr_doc),
                'tip_doc' => $invoice->tip_doc,
                'partner' => $invoice->partner?->name ?? '—',
                'company' => $invoice->company?->name,
                'departments' => $theirs->map(fn (InvoiceDepartmentApproval $one) => $one->department?->name)->filter()->unique()->values()->all(),
                'approved_by' => $last?->decidedBy?->name,
                'approved_at' => $last?->decided_at,
                'waiting_days' => $last?->decided_at !== null ? (int) $last->decided_at->startOfDay()->diffInDays($today) : null,
                'data_doc' => $invoice->data_doc,
                'data_scadenta' => $invoice->data_scadenta,
                'moneda' => $invoice->moneda ?: 'RON',
                'value' => round((float) $invoice->val_mon, 2),
                'rest' => $rest,
                'lei' => round($rest * (float) ($invoice->curs ?: 1), 2),
                'paid' => $rest <= 0.01,
                'overdue' => $invoice->data_scadenta !== null && $invoice->data_scadenta->lt($today),
            ];
        });

        // Două teancuri: cele care țin banii pe loc și cele deja plătite, care
        // așteaptă semnătura doar pentru evidență. Al doilea e scurt și stă
        // la urmă, ca să nu îngroape ce e de decis azi.
        $toPay = $rows->where('paid', false)->sortByDesc('lei')->values();
        $recorded = $rows->where('paid', true)->sortByDesc('waiting_days')->values();
        $stale = (int) config('notifications.awaiting_final.stale_days', 3);

        return [
            'rows' => $rows->values()->all(),
            'to_pay' => $toPay->all(),
            'recorded' => $recorded->all(),
            'count' => $rows->count(),
            'totals' => $this->totals($toPay),
            'stale' => $toPay->merge($recorded)
                ->filter(fn (array $row) => ($row['waiting_days'] ?? 0) >= $stale)
                ->sortByDesc('waiting_days')->values()->all(),
            'stale_days' => $stale,
            'today' => $today,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array{moneda: string, count: int, rest: float}>
     */
    private function totals(Collection $rows): array
    {
        return $rows
            ->groupBy('moneda')
            ->map(fn (Collection $group, string $currency) => [
                'moneda' => $currency,
                'count' => $group->count(),
                'rest' => round((float) $group->sum('rest'), 2),
            ])
            ->sortByDesc('rest')
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function empty(Carbon $today): array
    {
        return [
            'rows' => [], 'to_pay' => [], 'recorded' => [], 'count' => 0,
            'totals' => [], 'stale' => [],
            'stale_days' => (int) config('notifications.awaiting_final.stale_days', 3),
            'today' => $today,
        ];
    }
}
