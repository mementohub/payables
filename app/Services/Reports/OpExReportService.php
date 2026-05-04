<?php

namespace App\Services\Reports;

use App\Models\Company;
use App\Models\Invoice;
use App\Services\RemoteConnection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Cache;

class OpExReportService
{
    /**
     * tip_doc values that count as Operating Expenses on the remote SeniorERP DB.
     * FactFI/FactFE = supplier invoices, BC = bon de consum (consumables),
     * Comis_B = bank commissions.
     */
    public const TIP_DOC_OPEX = ['FactFI', 'FactFE', 'BC', 'Comis_B'];

    private const CACHE_TTL_SECONDS = 3600;

    public function __construct(private RemoteConnection $connection) {}

    /**
     * @return array{
     *   year: int,
     *   months: array<int, int>,
     *   roots: array<int, array<string, mixed>>,
     *   totals_by_month: array<int, float>,
     *   grand_total: float,
     *   meta: array{generated_at: string, duration_ms: int, leaf_count: int}
     * }
     */
    public function report(Company $company, int $year, bool $forceRefresh = false): array
    {
        $key = $this->cacheKey($company, $year);

        if ($forceRefresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, self::CACHE_TTL_SECONDS, fn () => $this->compute($company, $year));
    }

    /**
     * Drill-down: every invoice line contributing to the given remote category leaves
     * across the entire year. Returns one row per (document, sediu) combination so
     * sediile pot fi văzute distinct.
     *
     * @param  array<int, string>  $leaves
     * @return array<int, array{
     *   data_doc: string, month: int, tip_doc: string, nr_doc: string,
     *   partner: string, sediu: string|null,
     *   line_total_lei: float, moneda: string|null, val_mon: float|null,
     *   invoice_id: int|null
     * }>
     */
    public function invoicesForLeaves(Company $company, int $year, array $leaves): array
    {
        $leaves = array_values(array_filter(array_unique($leaves), fn ($l) => $l !== ''));
        if ($leaves === []) {
            return [];
        }

        $remote = $this->connection->connection($company);

        $start = sprintf('%04d-01-01', $year);
        $end = sprintf('%04d-01-01', $year + 1);

        $tipPlaceholders = implode(',', array_fill(0, count(self::TIP_DOC_OPEX), '?'));
        $leafPlaceholders = implode(',', array_fill(0, count($leaves), '?'));

        $rows = $remote->select(
            "SELECT d.tip_doc,
                    d.nr_doc,
                    d.data_doc,
                    d.moneda,
                    d.val_mon,
                    d.partener AS partner_name,
                    d.eu_punct_lucru AS sediu,
                    EXTRACT(MONTH FROM d.data_doc)::int AS month,
                    SUM(dp.cant * dp.pret * COALESCE(d.curs, 1))::numeric(20,2) AS line_total_lei
             FROM doc d
             JOIN doc_poz dp ON (dp.data_doc, dp.tip_doc, dp.nr_doc) = (d.data_doc, d.tip_doc, d.nr_doc)
             WHERE d.data_doc >= ?::date AND d.data_doc < ?::date
               AND d.tip_doc IN ($tipPlaceholders)
               AND dp.gr_chelt_ven_postc IN ($leafPlaceholders)
             GROUP BY d.tip_doc, d.nr_doc, d.data_doc, d.moneda, d.val_mon, d.partener, d.eu_punct_lucru
             ORDER BY d.data_doc DESC, line_total_lei DESC NULLS LAST
             LIMIT 1000",
            array_merge([$start, $end], self::TIP_DOC_OPEX, $leaves),
        );

        $localInvoices = Invoice::query()
            ->where('company_id', $company->id)
            ->whereBetween('data_doc', [$start, $end])
            ->whereIn('tip_doc', self::TIP_DOC_OPEX)
            ->get(['id', 'data_doc', 'tip_doc', 'nr_doc'])
            ->keyBy(fn ($inv) => sprintf('%s|%s|%s', $inv->data_doc->toDateString(), $inv->tip_doc, $inv->nr_doc));

        return array_map(function ($r) use ($localInvoices) {
            $dataDoc = (string) $r->data_doc;
            $key = sprintf('%s|%s|%s', $dataDoc, $r->tip_doc, $r->nr_doc);

            return [
                'data_doc' => $dataDoc,
                'month' => (int) $r->month,
                'tip_doc' => (string) $r->tip_doc,
                'nr_doc' => (string) $r->nr_doc,
                'partner' => $r->partner_name ?? '',
                'sediu' => $r->sediu !== null && trim((string) $r->sediu) !== '' ? (string) $r->sediu : null,
                'moneda' => $r->moneda,
                'val_mon' => $r->val_mon !== null ? (float) $r->val_mon : null,
                'line_total_lei' => (float) $r->line_total_lei,
                'invoice_id' => $localInvoices->get($key)?->id,
            ];
        }, $rows);
    }

    public function clearCache(Company $company, int $year): void
    {
        Cache::forget($this->cacheKey($company, $year));
    }

    /**
     * Combine a current-year report with a previous-year one. Each node ends up with
     * totals_by_month_prev / total_prev / delta_total_pct alongside the current values.
     * Nodes that exist only on one side keep zero on the missing side.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $previous
     * @return array<string, mixed>
     */
    public function compareReports(array $current, array $previous): array
    {
        return [
            'year' => $current['year'],
            'compare_year' => $previous['year'],
            'months' => $current['months'],
            'roots' => $this->mergeNodesYoY($current['roots'], $previous['roots']),
            'totals_by_month' => $current['totals_by_month'],
            'totals_by_month_prev' => $previous['totals_by_month'],
            'grand_total' => $current['grand_total'],
            'grand_total_prev' => $previous['grand_total'],
            'delta_total_pct' => $this->deltaPct($current['grand_total'], $previous['grand_total']),
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'duration_ms' => $current['meta']['duration_ms'] + $previous['meta']['duration_ms'],
                'leaf_count' => max($current['meta']['leaf_count'], $previous['meta']['leaf_count']),
                'rejected_roots' => array_values(array_unique(array_merge(
                    $current['meta']['rejected_roots'] ?? [],
                    $previous['meta']['rejected_roots'] ?? [],
                ))),
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $current
     * @param  array<int, array<string, mixed>>  $previous
     * @return array<int, array<string, mixed>>
     */
    private function mergeNodesYoY(array $current, array $previous): array
    {
        $byCode = [];
        foreach ($current as $n) {
            $byCode[$n['code']] = ['cur' => $n, 'prev' => null];
        }
        foreach ($previous as $n) {
            if (isset($byCode[$n['code']])) {
                $byCode[$n['code']]['prev'] = $n;
            } else {
                $byCode[$n['code']] = ['cur' => null, 'prev' => $n];
            }
        }

        $emptyMonths = array_fill(1, 12, 0.0);
        $merged = [];
        foreach ($byCode as $pair) {
            $cur = $pair['cur'];
            $prev = $pair['prev'];
            $base = $cur ?? $prev;
            $totals = $cur['totals_by_month'] ?? $emptyMonths;
            $totalsPrev = $prev['totals_by_month'] ?? $emptyMonths;
            $total = (float) ($cur['total'] ?? 0);
            $totalPrev = (float) ($prev['total'] ?? 0);

            $merged[] = [
                'code' => $base['code'],
                'label' => $cur['label'] ?? $prev['label'],
                'totals_by_month' => $totals,
                'totals_by_month_prev' => $totalsPrev,
                'total' => $total,
                'total_prev' => $totalPrev,
                'delta_total_pct' => $this->deltaPct($total, $totalPrev),
                'children' => $this->mergeNodesYoY($cur['children'] ?? [], $prev['children'] ?? []),
                'is_leaf_for_drilldown' => ($cur['is_leaf_for_drilldown'] ?? false) || ($prev['is_leaf_for_drilldown'] ?? false),
                'drilldown_leaves' => array_values(array_unique(array_merge(
                    $cur['drilldown_leaves'] ?? [],
                    $prev['drilldown_leaves'] ?? [],
                ))),
            ];
        }

        usort($merged, fn ($a, $b) => max($b['total'], $b['total_prev']) <=> max($a['total'], $a['total_prev']));

        return $merged;
    }

    private function deltaPct(float $current, float $previous): ?float
    {
        if (abs($previous) < 0.005) {
            return null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }

    private function cacheKey(Company $company, int $year): string
    {
        return "opex_report:{$company->getKey()}:{$year}";
    }

    /**
     * @return array<string, mixed>
     */
    private function compute(Company $company, int $year): array
    {
        $startedAt = microtime(true);
        $remote = $this->connection->connection($company);

        $start = sprintf('%04d-01-01', $year);
        $end = sprintf('%04d-01-01', $year + 1);

        $monthly = $this->fetchMonthly($remote, $start, $end);
        $chains = $this->fetchChains($remote, $start, $end);

        [$leafChain, $rejectedRoots] = $this->indexChains($chains);

        $tree = $this->buildTree($monthly, $leafChain);

        $totalsByMonth = array_fill(1, 12, 0.0);
        $grand = 0.0;
        foreach ($tree as $root) {
            foreach ($root['totals_by_month'] as $m => $v) {
                $totalsByMonth[$m] += $v;
            }
            $grand += $root['total'];
        }

        return [
            'year' => $year,
            'months' => range(1, 12),
            'roots' => $tree,
            'totals_by_month' => $totalsByMonth,
            'grand_total' => round($grand, 2),
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                'leaf_count' => count($leafChain),
                'rejected_roots' => array_values(array_unique($rejectedRoots)),
            ],
        ];
    }

    /**
     * @return array<int, object>
     */
    private function fetchMonthly(ConnectionInterface $remote, string $start, string $end): array
    {
        $tipPlaceholders = implode(',', array_fill(0, count(self::TIP_DOC_OPEX), '?'));

        return $remote->select(
            "SELECT dp.gr_chelt_ven_postc AS leaf,
                    EXTRACT(MONTH FROM d.data_doc)::int AS month,
                    SUM(dp.cant * dp.pret * COALESCE(d.curs, 1))::numeric(20,2) AS total_lei
             FROM doc d
             JOIN doc_poz dp ON (dp.data_doc, dp.tip_doc, dp.nr_doc) = (d.data_doc, d.tip_doc, d.nr_doc)
             WHERE d.data_doc >= ?::date AND d.data_doc < ?::date
               AND d.tip_doc IN ($tipPlaceholders)
               AND dp.gr_chelt_ven_postc IS NOT NULL
               AND BTRIM(dp.gr_chelt_ven_postc) <> ''
             GROUP BY dp.gr_chelt_ven_postc, EXTRACT(MONTH FROM d.data_doc)",
            array_merge([$start, $end], self::TIP_DOC_OPEX),
        );
    }

    /**
     * @return array<int, object>
     */
    private function fetchChains(ConnectionInterface $remote, string $start, string $end): array
    {
        $tipPlaceholders = implode(',', array_fill(0, count(self::TIP_DOC_OPEX), '?'));

        return $remote->select(
            "WITH RECURSIVE chain AS (
                SELECT g.gr_chelt_ven_postc AS leaf,
                       g.gr_chelt_ven_postc AS node,
                       g.gr_chelt_ven_postc_sup AS parent,
                       (CASE
                         WHEN BTRIM(COALESCE(g.detalii_gr_chelt_ven_postc, '')) <> ''
                              AND BTRIM(g.detalii_gr_chelt_ven_postc) <> g.gr_chelt_ven_postc
                           THEN g.detalii_gr_chelt_ven_postc
                         ELSE g.gr_chelt_ven_postc
                       END)::text AS label,
                       ARRAY[g.gr_chelt_ven_postc::text] AS visited,
                       0 AS depth
                FROM gr_chelt_ven_postc g
                UNION ALL
                SELECT c.leaf, p.gr_chelt_ven_postc, p.gr_chelt_ven_postc_sup,
                       (CASE
                         WHEN BTRIM(COALESCE(p.detalii_gr_chelt_ven_postc, '')) <> ''
                              AND BTRIM(p.detalii_gr_chelt_ven_postc) <> p.gr_chelt_ven_postc
                           THEN p.detalii_gr_chelt_ven_postc
                         ELSE p.gr_chelt_ven_postc
                       END)::text,
                       c.visited || p.gr_chelt_ven_postc::text,
                       c.depth + 1
                FROM chain c
                JOIN gr_chelt_ven_postc p ON p.gr_chelt_ven_postc = c.parent
                WHERE c.parent IS NOT NULL AND c.parent <> c.node
                  AND NOT (p.gr_chelt_ven_postc::text = ANY(c.visited))
                  AND c.depth < 10
            )
            SELECT leaf, depth, node AS code, label
            FROM chain
            WHERE leaf IN (
              SELECT DISTINCT dp.gr_chelt_ven_postc
              FROM doc d
              JOIN doc_poz dp ON (dp.data_doc, dp.tip_doc, dp.nr_doc) = (d.data_doc, d.tip_doc, d.nr_doc)
              WHERE d.data_doc >= ?::date AND d.data_doc < ?::date
                AND d.tip_doc IN ($tipPlaceholders)
                AND dp.gr_chelt_ven_postc IS NOT NULL
                AND BTRIM(dp.gr_chelt_ven_postc) <> ''
            )
            ORDER BY leaf, depth",
            array_merge([$start, $end], self::TIP_DOC_OPEX),
        );
    }

    /**
     * Group chain rows by leaf, drop chains rooted in a revenue branch or in a junk-code root.
     *
     * @param  array<int, object>  $chains
     * @return array{0: array<string, array<int, array{code: string, label: string}>>, 1: array<int, string>}
     */
    private function indexChains(array $chains): array
    {
        $byLeaf = [];
        foreach ($chains as $row) {
            $byLeaf[(string) $row->leaf][] = [
                'code' => (string) $row->code,
                'label' => (string) $row->label,
            ];
        }

        $rejected = [];
        $clean = [];

        foreach ($byLeaf as $leaf => $nodes) {
            $root = end($nodes);
            $rootLabel = trim($root['label']);

            if ($this->isRevenueLabel($rootLabel) || $this->isJunkLabel($rootLabel)) {
                $rejected[] = $rootLabel;

                continue;
            }

            // Skip leaf node when its label is just a code (e.g. "2.2.6.48.Timisoara Bega")
            // and it has a parent — start the path from the parent.
            if (count($nodes) > 1 && $this->isJunkLabel(trim($nodes[0]['label']))) {
                array_shift($nodes);
            }

            $clean[$leaf] = $nodes;
        }

        return [$clean, $rejected];
    }

    private function isRevenueLabel(string $label): bool
    {
        $normalized = mb_strtolower(trim($label));

        return str_starts_with($normalized, 'venituri');
    }

    private function isJunkLabel(string $label): bool
    {
        if ($label === '') {
            return true;
        }

        // Pure code-shape like " 5.3.55.Administrativ", "1.1.34.1", etc.
        return (bool) preg_match('/^[\s\d.]/', $label);
    }

    /**
     * @param  array<int, object>  $monthly
     * @param  array<string, array<int, array{code: string, label: string}>>  $leafChain
     * @return array<int, array<string, mixed>>
     */
    private function buildTree(array $monthly, array $leafChain): array
    {
        // Map: nodeCode -> array{label, totals_by_month, total, children: [code => true], depth_min}
        $nodes = [];

        foreach ($monthly as $row) {
            $leaf = (string) $row->leaf;
            $month = (int) $row->month;
            $value = (float) $row->total_lei;

            $chain = $leafChain[$leaf] ?? null;
            if ($chain === null || $chain === []) {
                continue;
            }

            // chain is leaf → root. Walk it to register each node and parent links.
            $childCode = null;
            foreach ($chain as $node) {
                $code = $node['code'];
                if (! isset($nodes[$code])) {
                    $nodes[$code] = [
                        'code' => $code,
                        'label' => $node['label'],
                        'totals_by_month' => array_fill(1, 12, 0.0),
                        'total' => 0.0,
                        'children' => [],
                        'is_leaf_for_drilldown' => false,
                    ];
                }

                $nodes[$code]['totals_by_month'][$month] += $value;
                $nodes[$code]['total'] += $value;

                if ($childCode !== null) {
                    $nodes[$code]['children'][$childCode] = true;
                }

                $childCode = $code;
            }

            // Mark the deepest displayed node (first chain entry) as drilldown-leaf.
            $deepest = $chain[0]['code'];
            $nodes[$deepest]['is_leaf_for_drilldown'] = true;
            // Multiple raw remote leaves may collapse onto the same drilldown node
            // (e.g. several "2.2.6.48.X" rolling up to "SALUBRIZAREA").
            $nodes[$deepest]['drilldown_leaves'] ??= [];
            $nodes[$deepest]['drilldown_leaves'][] = $leaf;
        }

        // Identify roots: nodes that never appear as a child in any chain.
        $allChildren = [];
        foreach ($nodes as $node) {
            foreach (array_keys($node['children']) as $childCode) {
                $allChildren[$childCode] = true;
            }
        }

        $rootCodes = array_keys(array_diff_key($nodes, $allChildren));

        $build = function (string $code) use (&$build, &$nodes): array {
            $node = $nodes[$code];
            $children = [];
            foreach (array_keys($node['children']) as $childCode) {
                $children[] = $build($childCode);
            }
            usort($children, fn ($a, $b) => $b['total'] <=> $a['total']);

            return [
                'code' => $node['code'],
                'label' => $node['label'],
                'totals_by_month' => array_map(fn ($v) => round($v, 2), $node['totals_by_month']),
                'total' => round($node['total'], 2),
                'children' => $children,
                'is_leaf_for_drilldown' => $node['is_leaf_for_drilldown'] && $children === [],
                'drilldown_leaves' => array_values(array_unique($node['drilldown_leaves'] ?? [])),
            ];
        };

        $tree = array_map($build, $rootCodes);
        $tree = $this->mergeDuplicateSiblings($tree);
        usort($tree, fn ($a, $b) => $b['total'] <=> $a['total']);

        return $tree;
    }

    /**
     * Merge sibling nodes that share the same normalized label (trimmed, case-insensitive).
     * Real-world chart data often has the same conceptual category written with subtle
     * variants ("ENERGIE ELECTRICA " vs "ENERGIE ELECTRICA") which would otherwise show
     * up as N separate rows.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<int, array<string, mixed>>
     */
    private function mergeDuplicateSiblings(array $nodes): array
    {
        $groups = [];
        foreach ($nodes as $node) {
            $key = mb_strtolower(trim((string) $node['label']));
            $groups[$key][] = $node;
        }

        $merged = [];
        foreach ($groups as $bucket) {
            if (count($bucket) === 1) {
                $node = $bucket[0];
                $node['children'] = $this->mergeDuplicateSiblings($node['children']);
                $merged[] = $node;

                continue;
            }

            $canonical = $bucket[0];
            for ($i = 1; $i < count($bucket); $i++) {
                $other = $bucket[$i];
                foreach ($other['totals_by_month'] as $m => $v) {
                    $canonical['totals_by_month'][$m] = round(($canonical['totals_by_month'][$m] ?? 0) + $v, 2);
                }
                $canonical['total'] = round($canonical['total'] + $other['total'], 2);
                $canonical['children'] = array_merge($canonical['children'], $other['children']);
                $canonical['drilldown_leaves'] = array_values(array_unique(array_merge(
                    $canonical['drilldown_leaves'] ?? [],
                    $other['drilldown_leaves'] ?? [],
                )));
                $canonical['is_leaf_for_drilldown'] = $canonical['is_leaf_for_drilldown'] || $other['is_leaf_for_drilldown'];
            }
            $canonical['children'] = $this->mergeDuplicateSiblings($canonical['children']);
            // After merging children, drilldown is only valid if no children remain.
            $canonical['is_leaf_for_drilldown'] = $canonical['is_leaf_for_drilldown'] && $canonical['children'] === [];
            usort(
                $canonical['children'],
                fn ($a, $b) => $b['total'] <=> $a['total'],
            );
            $merged[] = $canonical;
        }

        return $merged;
    }
}
