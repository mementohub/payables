<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Department;
use App\Models\Invoice;
use App\Services\Approvals\InvoiceWorkflow;
use App\Services\Xlsx\XlsxWriter;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Scadențarul: ce e de plătit de azi înainte.
 *
 * Fiecare factură de furnizor cu rest de plată și scadența de azi sau mai
 * târziu, cu suma rămasă, în monedă și în lei, și cu starea în care a ajuns
 * aprobarea ei. Ce a trecut de scadență nu intră aici: raportul e pentru ce
 * urmează, nu pentru restanțe.
 */
class DueInvoicesController extends Controller
{
    /** Pragurile în zile după care se grupează scadențele. */
    private const BUCKETS = [7, 30];

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        $rows = $this->query($filters)
            ->orderByRaw('data_scadenta is null, data_scadenta')
            ->orderBy('id')
            ->paginate(100)
            ->withQueryString()
            ->through(fn (Invoice $invoice) => $this->row($invoice, $filters['today']));

        return Inertia::render('reports/due', [
            'rows' => $rows,
            'totals' => $this->totals($filters),
            'filters' => $filters,
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
            'departments' => Department::query()->whereNotNull('code')->orderBy('sort')->get(['id', 'name']),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);

        $rows = function () use ($filters) {
            foreach ($this->query($filters)->orderByRaw('data_scadenta is null, data_scadenta')->orderBy('id')->cursor() as $invoice) {
                $row = $this->row($invoice, $filters['today']);

                yield [
                    $row['company'],
                    $row['partner'],
                    $row['cui'],
                    $row['tip_doc'],
                    $row['nr_doc'],
                    $row['data_doc'],
                    $row['data_scadenta'],
                    $row['days'],
                    $row['moneda'],
                    $row['val_mon'],
                    $row['paid'],
                    $row['remaining'],
                    $row['remaining_lei'],
                    $row['department'],
                    $row['status'],
                ];
            }
        };

        return XlsxWriter::streamDownload(
            'scadentar-'.$filters['today'].'.xlsx',
            ['Companie', 'Furnizor', 'CUI', 'Tip', 'Număr', 'Data facturii', 'Scadență', 'Zile', 'Monedă', 'Valoare', 'Plătit', 'Rest', 'Rest (lei)', 'Departament', 'Stare'],
            $rows(),
            'Scadentar',
        );
    }

    /**
     * @return array{today: string, until: ?string, company_id: ?int, department: ?int, search: string, status: ?string}
     */
    private function filters(Request $request): array
    {
        $status = $request->string('status')->toString();

        return [
            'today' => Carbon::today()->toDateString(),
            'until' => $request->date('until')?->toDateString(),
            'company_id' => $request->integer('company_id') ?: null,
            'department' => $request->integer('department') ?: null,
            'search' => trim($request->string('search')->toString()),
            'status' => in_array($status, ['approved', 'waiting'], true) ? $status : null,
        ];
    }

    /**
     * @param  array{today: string, until: ?string, company_id: ?int, department: ?int, search: string, status: ?string}  $filters
     * @return Builder<Invoice>
     */
    private function query(array $filters): Builder
    {
        return Invoice::query()
            ->with(['partner:id,name,cui', 'company:id,name', 'department:id,name'])
            ->where('partener_type', 'furnizor')
            ->whereNull('omc_removed_at')
            ->where('val_mon', '>', 0)
            ->whereRaw('val_mon - val_mon_paid - val_mon_storno > 0.01')
            ->whereNotNull('data_scadenta')
            ->where('data_scadenta', '>=', $filters['today'])
            ->when($filters['until'] !== null, fn (Builder $q) => $q->where('data_scadenta', '<=', $filters['until']))
            ->when($filters['company_id'] !== null, fn (Builder $q) => $q->where('company_id', $filters['company_id']))
            ->when($filters['department'] !== null, fn (Builder $q) => $q->where('department_id', $filters['department']))
            ->when($filters['status'] === 'approved', fn (Builder $q) => $q->where('approval_status', InvoiceWorkflow::APPROVED))
            ->when($filters['status'] === 'waiting', fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull('approval_status')->orWhere('approval_status', '!=', InvoiceWorkflow::APPROVED)))
            ->when($filters['search'] !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('nr_doc', 'like', "%{$filters['search']}%")
                ->orWhereHas('partner', fn (Builder $p) => $p->where('name', 'like', "%{$filters['search']}%")->orWhere('cui', 'like', "%{$filters['search']}%"))));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Invoice $invoice, string $today): array
    {
        $remaining = round((float) $invoice->val_mon - (float) $invoice->val_mon_paid - (float) $invoice->val_mon_storno, 2);
        $rate = (float) ($invoice->curs ?: 0) ?: 1.0;

        return [
            'id' => $invoice->id,
            'company' => $invoice->company?->name,
            'partner' => $invoice->partner?->name,
            'cui' => $invoice->partner?->cui,
            'tip_doc' => $invoice->tip_doc,
            'nr_doc' => $invoice->nr_doc,
            'data_doc' => $invoice->data_doc?->toDateString(),
            'data_scadenta' => $invoice->data_scadenta?->toDateString(),
            'days' => $invoice->data_scadenta !== null ? Carbon::parse($today)->diffInDays($invoice->data_scadenta, false) : null,
            'moneda' => $invoice->moneda,
            'val_mon' => round((float) $invoice->val_mon, 2),
            'paid' => round((float) $invoice->val_mon_paid + (float) $invoice->val_mon_storno, 2),
            'remaining' => $remaining,
            'remaining_lei' => round($remaining * $rate, 2),
            'department' => $invoice->department?->name,
            'status' => $invoice->approval_status,
        ];
    }

    /**
     * Cât e de plătit: pe total, pe monedă și pe intervalele de scadență.
     *
     * @param  array{today: string, until: ?string, company_id: ?int, department: ?int, search: string, status: ?string}  $filters
     * @return array<string, mixed>
     */
    private function totals(array $filters): array
    {
        $buckets = [];
        $byCurrency = [];
        $count = 0;
        $lei = 0.0;
        $today = Carbon::parse($filters['today']);

        foreach ($this->query($filters)->cursor() as $invoice) {
            $remaining = round((float) $invoice->val_mon - (float) $invoice->val_mon_paid - (float) $invoice->val_mon_storno, 2);
            $rate = (float) ($invoice->curs ?: 0) ?: 1.0;
            $currency = mb_strtoupper((string) ($invoice->moneda ?: 'RON'));
            $days = (int) $today->diffInDays($invoice->data_scadenta, false);

            $bucket = match (true) {
                $days <= self::BUCKETS[0] => 'week',
                $days <= self::BUCKETS[1] => 'month',
                default => 'later',
            };

            $count++;
            $lei += $remaining * $rate;
            $byCurrency[$currency] = round(($byCurrency[$currency] ?? 0) + $remaining, 2);
            $buckets[$bucket] = ['count' => ($buckets[$bucket]['count'] ?? 0) + 1, 'lei' => round(($buckets[$bucket]['lei'] ?? 0) + $remaining * $rate, 2)];
        }

        return [
            'count' => $count,
            'lei' => round($lei, 2),
            'by_currency' => $byCurrency,
            'buckets' => [
                'week' => $buckets['week'] ?? ['count' => 0, 'lei' => 0],
                'month' => $buckets['month'] ?? ['count' => 0, 'lei' => 0],
                'later' => $buckets['later'] ?? ['count' => 0, 'lei' => 0],
            ],
        ];
    }
}
