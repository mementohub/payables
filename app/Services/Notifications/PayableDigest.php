<?php

namespace App\Services\Notifications;

use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Facturile care au trecut toate aprobările și așteaptă să fie plătite.
 *
 * Atenție la nume, fiindcă înșală: în fluxul nostru „final” înseamnă că toate
 * departamentele au aprobat și se așteaptă Top Management-ul, iar „approved”
 * înseamnă bun de plată. Trezoreria are treabă cu al doilea.
 *
 * Cele amânate rămân afară până le vine ziua: altfel trezoreria ar plăti
 * tocmai ce s-a hotărât să nu se plătească încă.
 */
class PayableDigest
{
    /**
     * @return array<string, mixed>
     */
    public function gather(?Carbon $since = null): array
    {
        $today = Carbon::today();

        $invoices = Invoice::query()
            ->where('partener_type', 'furnizor')
            ->whereNull('omc_removed_at')
            // Numai ce a primit aprobarea finală, și numai de la Top Management:
            // starea singură n-ar fi destul, semnătura o dovedește.
            ->where('approval_status', 'approved')
            ->whereNotNull('final_decided_at')
            ->whereRaw('val_mon - val_mon_paid - val_mon_storno > 0.01')
            ->where(fn ($q) => $q->whereNull('postponed_until')->orWhereDate('postponed_until', '<=', $today))
            ->when($since, fn ($q, Carbon $from) => $q->where('final_decided_at', '>=', $from))
            ->with(['partner:id,name', 'company:id,name', 'department:id,name', 'finalDecidedBy:id,name'])
            ->orderByRaw('case when data_scadenta is null then 1 else 0 end')
            ->orderBy('data_scadenta')
            ->orderByDesc('val_mon')
            ->get();

        // Firimiturile nu merită drumul la bancă: lista ar fi mai lungă, nu
        // mai de folos. Pragul se judecă pe valoarea în lei, oricare ar fi
        // moneda, și aici, nu în interogare: cursul stă într-o coloană
        // zecimală, iar înmulțirea cu el nu se poartă la fel în toate bazele.
        $rows = $invoices->map(fn (Invoice $invoice) => [
            'id' => $invoice->id,
            'nr_doc' => trim((string) $invoice->nr_doc),
            'tip_doc' => $invoice->tip_doc,
            'partner' => $invoice->partner?->name ?? '—',
            'department' => $invoice->department?->name,
            'data_doc' => $invoice->data_doc,
            'data_scadenta' => $invoice->data_scadenta,
            'moneda' => $invoice->moneda ?: 'RON',
            'rest' => round((float) $invoice->val_mon - (float) $invoice->val_mon_paid - (float) $invoice->val_mon_storno, 2),
            'lei' => round(((float) $invoice->val_mon - (float) $invoice->val_mon_paid - (float) $invoice->val_mon_storno) * (float) ($invoice->curs ?: 1), 2),
            'overdue' => $invoice->data_scadenta !== null && $invoice->data_scadenta->lt($today),
            'days' => $invoice->data_scadenta?->diffInDays($today, false),
            'approved_at' => $invoice->final_decided_at,
            'approved_by' => $invoice->finalDecidedBy?->name,
        ])->filter(fn (array $row) => abs($row['lei']) >= $this->threshold())->values();

        // Tablouri, nu colecții: vrafului ăsta i se uită și prin mail, și
        // într-o foaie de calcul, iar o colecție prefăcută în tablou nu dă
        // rândurile, ci măruntaiele ei.
        return [
            'rows' => $rows->all(),
            'count' => $rows->count(),
            'totals' => $this->totals($rows),
            'overdue' => $rows->where('overdue', true)->values()->all(),
            'week' => $rows->filter(fn (array $row) => $row['data_scadenta'] !== null
                && $row['data_scadenta']->betweenIncluded($today, $today->copy()->addDays(7)))->values()->all(),
            'since' => $since,
            'today' => $today,
            'min_lei' => $this->threshold(),
        ];
    }

    /** Sub cât nu se mai trece o factură pe listă, în lei. */
    private function threshold(): float
    {
        return (float) config('notifications.payable.min_lei', 50);
    }

    /**
     * Totalurile pe monede: un total în lei ar ascunde ce are de plătit
     * trezoreria în valută, iar ei de acolo pornesc.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array{moneda: string, count: int, rest: float}>
     */
    private function totals(Collection $rows): array
    {
        return $rows
            ->groupBy('moneda')
            ->map(fn (Collection $group, string $moneda) => [
                'moneda' => $moneda,
                'count' => $group->count(),
                'rest' => round((float) $group->sum('rest'), 2),
            ])
            ->sortByDesc('rest')
            ->values()
            ->all();
    }
}
