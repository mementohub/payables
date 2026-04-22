<?php

namespace App\Http\Controllers;

use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $companyId = $request->integer('company_id');
        $from = $this->parseDate($request->string('from')->toString());
        $to = $this->parseDate($request->string('to')->toString());
        $moneda = $request->string('moneda')->toString() ?: 'Lei';

        $filters = [
            'company_id' => $companyId ?: null,
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
            'moneda' => $moneda,
        ];

        return Inertia::render('dashboard', [
            'filters' => $filters,
            'companies' => Company::orderBy('name')->get(['id', 'name']),
            'paymentBreakdown' => Inertia::defer(fn () => $this->paymentBreakdown($companyId, $from, $to, $moneda)),
            'agingBuckets' => Inertia::defer(fn () => $this->agingBuckets($companyId, $from, $to, $moneda)),
            'topOverdueSuppliers' => Inertia::defer(fn () => $this->topOverdueSuppliers($companyId, $from, $to, $moneda)),
            'cashflow' => Inertia::defer(fn () => $this->weeklyCashflow($companyId, $from, $to, $moneda)),
        ]);
    }

    /**
     * Received invoices grouped by payment state.
     *
     * @return array<int, array{state: string, label: string, count: int, total: float}>
     */
    private function paymentBreakdown(?int $companyId, ?CarbonImmutable $from, ?CarbonImmutable $to, string $moneda): array
    {
        $today = CarbonImmutable::today()->toDateString();

        $rows = Invoice::query()
            ->furnizor()
            ->where('moneda', $moneda)
            ->when($companyId, fn ($q, $id) => $q->where('company_id', $id))
            ->when($from, fn ($q, $d) => $q->where('data_doc', '>=', $d))
            ->when($to, fn ($q, $d) => $q->where('data_doc', '<=', $d))
            ->selectRaw("
                case
                    when val_mon_paid >= val_mon - 0.01 then 'paid'
                    when val_mon_paid <= 0.009 and data_scadenta < ? then 'overdue'
                    when val_mon_paid <= 0.009 then 'unpaid'
                    when val_mon_paid > 0.009 and val_mon_paid < val_mon - 0.01 and data_scadenta < ? then 'overdue'
                    else 'partial'
                end as state,
                count(*) as count,
                coalesce(sum(val_mon - val_mon_paid), 0) as outstanding,
                coalesce(sum(val_mon), 0) as total
            ", [$today, $today])
            ->groupBy('state')
            ->get();

        $labels = [
            'paid' => 'Achitate',
            'partial' => 'Parțial achitate',
            'unpaid' => 'Neachitate',
            'overdue' => 'Restante',
        ];

        $byState = $rows->keyBy('state');

        return collect(['paid', 'partial', 'unpaid', 'overdue'])
            ->map(fn ($state) => [
                'state' => $state,
                'label' => $labels[$state],
                'count' => (int) ($byState[$state]->count ?? 0),
                'total' => (float) ($byState[$state]->total ?? 0),
                'outstanding' => (float) ($byState[$state]->outstanding ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * Unpaid received invoices split into aging buckets by data_scadenta.
     *
     * @return array<int, array{bucket: string, count: int, outstanding: float}>
     */
    private function agingBuckets(?int $companyId, ?CarbonImmutable $from, ?CarbonImmutable $to, string $moneda): array
    {
        $today = CarbonImmutable::today();

        $rows = Invoice::query()
            ->furnizor()
            ->where('moneda', $moneda)
            ->whereColumn('val_mon_paid', '<', DB::raw('val_mon - 0.01'))
            ->where('data_scadenta', '<', $today)
            ->when($companyId, fn ($q, $id) => $q->where('company_id', $id))
            ->when($from, fn ($q, $d) => $q->where('data_doc', '>=', $d))
            ->when($to, fn ($q, $d) => $q->where('data_doc', '<=', $d))
            ->selectRaw("
                case
                    when datediff(?, data_scadenta) <= 30 then '0-30'
                    when datediff(?, data_scadenta) <= 60 then '31-60'
                    when datediff(?, data_scadenta) <= 90 then '61-90'
                    else '90+'
                end as bucket,
                count(*) as count,
                coalesce(sum(val_mon - val_mon_paid), 0) as outstanding
            ", [$today, $today, $today])
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        return collect(['0-30', '31-60', '61-90', '90+'])
            ->map(fn ($bucket) => [
                'bucket' => $bucket,
                'count' => (int) ($rows[$bucket]->count ?? 0),
                'outstanding' => (float) ($rows[$bucket]->outstanding ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * Top 5 suppliers by outstanding overdue amount.
     *
     * @return array<int, array{partner_id: int|null, name: string, outstanding: float, invoices: int}>
     */
    private function topOverdueSuppliers(?int $companyId, ?CarbonImmutable $from, ?CarbonImmutable $to, string $moneda): array
    {
        $today = CarbonImmutable::today();

        return Invoice::query()
            ->furnizor()
            ->where('moneda', $moneda)
            ->whereColumn('val_mon_paid', '<', DB::raw('val_mon - 0.01'))
            ->where('data_scadenta', '<', $today)
            ->when($companyId, fn ($q, $id) => $q->where('company_id', $id))
            ->when($from, fn ($q, $d) => $q->where('data_doc', '>=', $d))
            ->when($to, fn ($q, $d) => $q->where('data_doc', '<=', $d))
            ->leftJoin('partners', 'invoices.partner_id', '=', 'partners.id')
            ->selectRaw('
                invoices.partner_id,
                coalesce(partners.name, ?) as name,
                count(*) as invoices,
                coalesce(sum(val_mon - val_mon_paid), 0) as outstanding
            ', ['Fără partener'])
            ->groupBy('invoices.partner_id', 'partners.name')
            ->orderByDesc('outstanding')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'partner_id' => $r->partner_id ? (int) $r->partner_id : null,
                'name' => (string) $r->name,
                'invoices' => (int) $r->invoices,
                'outstanding' => (float) $r->outstanding,
            ])
            ->values()
            ->all();
    }

    /**
     * Weekly incoming vs outgoing cashflow from bank-statement lines.
     * Uses the filter date range or defaults to the last 12 weeks.
     *
     * @return array<int, array{week: string, incoming: float, outgoing: float}>
     */
    private function weeklyCashflow(?int $companyId, ?CarbonImmutable $from, ?CarbonImmutable $to, string $moneda): array
    {
        $rangeTo = $to ?? CarbonImmutable::today();
        $rangeFrom = $from ?? $rangeTo->subWeeks(12)->startOfWeek();

        return BankStatementLine::query()
            ->join('bank_statements', 'bank_statement_lines.bank_statement_id', '=', 'bank_statements.id')
            ->where('bank_statement_lines.moneda', $moneda)
            ->whereBetween('bank_statement_lines.data_doc', [$rangeFrom, $rangeTo])
            ->when($companyId, fn ($q, $id) => $q->where('bank_statements.company_id', $id))
            ->selectRaw("
                date_format(bank_statement_lines.data_doc, '%x-W%v') as week,
                min(bank_statement_lines.data_doc) as week_start,
                coalesce(sum(case when direction = 'incoming' then val_mon else 0 end), 0) as incoming,
                coalesce(sum(case when direction = 'outgoing' then val_mon else 0 end), 0) as outgoing
            ")
            ->groupBy('week')
            ->orderBy('week_start')
            ->get()
            ->map(fn ($r) => [
                'week' => (string) $r->week,
                'week_start' => CarbonImmutable::parse($r->week_start)->toDateString(),
                'incoming' => (float) $r->incoming,
                'outgoing' => (float) $r->outgoing,
            ])
            ->values()
            ->all();
    }

    private function parseDate(?string $value): ?CarbonImmutable
    {
        if (! $value) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
