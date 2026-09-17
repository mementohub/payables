<?php

namespace App\Http\Controllers;

use App\Models\CashFlowSnapshot;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Panou principal: the received invoices of the period (every currency,
 * in lei at the document rate) and the weekly cash flow — the last weeks
 * as they happened in OMC, the coming ones as the WCFR 52 Weeks forecast.
 */
class DashboardController extends Controller
{
    public const WEEKS_BACK = 13;

    public const WEEKS_AHEAD = 13;

    /** Outstanding amount of an invoice, in lei at the document rate. */
    private const OUTSTANDING_LEI = '(val_mon - val_mon_paid) * coalesce(curs, 1)';

    public function index(Request $request): Response
    {
        $from = $this->parseDate($request->string('from')->toString());
        $to = $this->parseDate($request->string('to')->toString());

        return Inertia::render('dashboard', [
            'filters' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
            'paymentBreakdown' => Inertia::defer(fn () => $this->paymentBreakdown($from, $to)),
            'agingBuckets' => Inertia::defer(fn () => $this->agingBuckets($from, $to)),
            'topOverdueSuppliers' => Inertia::defer(fn () => $this->topOverdueSuppliers($from, $to)),
            'cashflow' => Inertia::defer(fn () => $this->weeklyCashflow()),
        ]);
    }

    /**
     * Received invoices grouped by payment state.
     *
     * @return array<int, array{state: string, label: string, count: int, total: float, outstanding: float}>
     */
    private function paymentBreakdown(?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $today = CarbonImmutable::today()->toDateString();

        $rows = Invoice::query()
            ->furnizor()
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
                coalesce(sum(".self::OUTSTANDING_LEI.'), 0) as outstanding,
                coalesce(sum(val_mon * coalesce(curs, 1)), 0) as total
            ', [$today, $today])
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
                'total' => round((float) ($byState[$state]->total ?? 0), 2),
                'outstanding' => round((float) ($byState[$state]->outstanding ?? 0), 2),
            ])
            ->values()
            ->all();
    }

    /**
     * Unpaid received invoices split into aging buckets by data_scadenta.
     *
     * @return array<int, array{bucket: string, count: int, outstanding: float}>
     */
    private function agingBuckets(?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $today = CarbonImmutable::today();

        $rows = Invoice::query()
            ->furnizor()
            ->whereColumn('val_mon_paid', '<', DB::raw('val_mon - 0.01'))
            ->where('data_scadenta', '<', $today)
            ->when($from, fn ($q, $d) => $q->where('data_doc', '>=', $d))
            ->when($to, fn ($q, $d) => $q->where('data_doc', '<=', $d))
            ->selectRaw("
                case
                    when data_scadenta >= ? then '0-30'
                    when data_scadenta >= ? then '31-60'
                    when data_scadenta >= ? then '61-90'
                    else '90+'
                end as bucket,
                count(*) as count,
                coalesce(sum(".self::OUTSTANDING_LEI.'), 0) as outstanding
            ', [$today->subDays(30)->toDateString(), $today->subDays(60)->toDateString(), $today->subDays(90)->toDateString()])
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        return collect(['0-30', '31-60', '61-90', '90+'])
            ->map(fn ($bucket) => [
                'bucket' => $bucket,
                'count' => (int) ($rows[$bucket]->count ?? 0),
                'outstanding' => round((float) ($rows[$bucket]->outstanding ?? 0), 2),
            ])
            ->values()
            ->all();
    }

    /**
     * Top 5 suppliers by outstanding overdue amount.
     *
     * @return array<int, array{partner_id: int|null, name: string, outstanding: float, invoices: int}>
     */
    private function topOverdueSuppliers(?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $today = CarbonImmutable::today();

        return Invoice::query()
            ->furnizor()
            ->whereColumn('invoices.val_mon_paid', '<', DB::raw('invoices.val_mon - 0.01'))
            ->where('invoices.data_scadenta', '<', $today)
            ->when($from, fn ($q, $d) => $q->where('invoices.data_doc', '>=', $d))
            ->when($to, fn ($q, $d) => $q->where('invoices.data_doc', '<=', $d))
            ->leftJoin('partners', 'invoices.partner_id', '=', 'partners.id')
            ->selectRaw('
                invoices.partner_id,
                coalesce(partners.name, ?) as name,
                count(*) as invoices,
                coalesce(sum((invoices.val_mon - invoices.val_mon_paid) * coalesce(invoices.curs, 1)), 0) as outstanding
            ', ['Fără partener'])
            ->groupBy('invoices.partner_id', 'partners.name')
            ->orderByDesc('outstanding')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'partner_id' => $r->partner_id ? (int) $r->partner_id : null,
                'name' => (string) $r->name,
                'invoices' => (int) $r->invoices,
                'outstanding' => round((float) $r->outstanding, 2),
            ])
            ->values()
            ->all();
    }

    /**
     * Weekly cash flow around today, in lei: the last weeks as OMC recorded
     * them (receipts, payments and the treasury position at the end of the
     * week) and the coming weeks as the latest WCFR 52 Weeks snapshot
     * forecasts them (total inflows, product payments + OPEX, closing
     * balance).
     *
     * @return array{built_at: ?string, points: list<array{week: string, kind: string, incoming: float, outgoing: float, balance: ?float}>}
     */
    private function weeklyCashflow(): array
    {
        $snapshot = CashFlowSnapshot::latest();
        $payload = $snapshot?->payload;

        if (! is_array($payload) || empty($payload['weeks'])) {
            return ['built_at' => null, 'points' => []];
        }

        $points = [];

        foreach (array_slice((array) ($payload['recent'] ?? []), -self::WEEKS_BACK) as $week) {
            $points[] = [
                'week' => (string) $week['week'],
                'kind' => 'actual',
                'incoming' => round((float) $week['in'], 2),
                'outgoing' => round((float) $week['out'], 2),
                'balance' => isset($week['balance']) ? round((float) $week['balance'], 2) : null,
            ];
        }

        $lines = collect($payload['lines'] ?? [])->keyBy('code');
        $inflows = $lines->get('B')['values'] ?? [];
        $product = $lines->get('C')['values'] ?? [];
        $opex = $lines->get('D')['values'] ?? [];
        $closing = $lines->get('E2')['values'] ?? [];

        foreach (array_slice($payload['weeks'], 0, self::WEEKS_AHEAD) as $i => $monday) {
            $points[] = [
                'week' => (string) $monday,
                'kind' => 'forecast',
                'incoming' => round((float) ($inflows[$i] ?? 0), 2),
                'outgoing' => round((float) ($product[$i] ?? 0) + (float) ($opex[$i] ?? 0), 2),
                'balance' => isset($closing[$i]) ? round((float) $closing[$i], 2) : null,
            ];
        }

        return ['built_at' => $snapshot->built_at->toIso8601String(), 'points' => $points];
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
