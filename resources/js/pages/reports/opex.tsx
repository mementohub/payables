import { Head, Link, router } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronRight,
    Loader2,
    Minus,
    RefreshCw,
    TrendingDown,
    TrendingUp,
} from 'lucide-react';
import { Fragment, useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { show as invoiceShow } from '@/routes/invoices';
import {
    index as opexIndex,
    invoices as opexInvoicesRoute,
    refresh as opexRefreshRoute,
} from '@/routes/reports/opex';

type Company = { id: number; name: string };

type OpExNode = {
    code: string;
    label: string;
    totals_by_month: Record<string, number>;
    totals_by_month_prev?: Record<string, number>;
    total: number;
    total_prev?: number;
    delta_total_pct?: number | null;
    children: OpExNode[];
    is_leaf_for_drilldown: boolean;
    drilldown_leaves: string[];
};

type OpExReport = {
    year: number;
    compare_year?: number;
    months: number[];
    roots: OpExNode[];
    totals_by_month: Record<string, number>;
    totals_by_month_prev?: Record<string, number>;
    grand_total: number;
    grand_total_prev?: number;
    delta_total_pct?: number | null;
    meta: {
        generated_at: string;
        duration_ms: number;
        leaf_count: number;
        rejected_roots: string[];
    };
};

type CellInvoice = {
    data_doc: string;
    month: number;
    tip_doc: string;
    nr_doc: string;
    partner: string;
    sediu: string | null;
    moneda: string | null;
    val_mon: number | null;
    line_total_lei: number;
    invoice_id: number | null;
};

type Filters = {
    company_id: number | null;
    year: number;
    compare_year: number | null;
};

type Props = {
    companies: Company[];
    filters: Filters;
    report: OpExReport | null;
};

type LeafState =
    | { status: 'loading' }
    | {
          status: 'loaded';
          year: number;
          compare_year?: number;
          invoices: CellInvoice[];
          invoices_prev?: CellInvoice[];
      }
    | { status: 'error'; message: string };

const MONTH_LABELS = [
    'Ian',
    'Feb',
    'Mar',
    'Apr',
    'Mai',
    'Iun',
    'Iul',
    'Aug',
    'Sep',
    'Oct',
    'Noi',
    'Dec',
];

function formatLei(value: number): string {
    if (Math.abs(value) < 0.005) {
        return '—';
    }
    return new Intl.NumberFormat('ro-RO', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(value);
}

function formatLeiPrecise(value: number): string {
    return new Intl.NumberFormat('ro-RO', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(value);
}

function deltaPct(current: number, previous: number): number | null {
    if (Math.abs(previous) < 0.005) return null;
    return Math.round(((current - previous) / previous) * 1000) / 10;
}

function DeltaBadge({
    pct,
    current,
    previous,
    inline = false,
}: {
    pct: number | null | undefined;
    current: number;
    previous: number;
    inline?: boolean;
}) {
    const hasCurrent = Math.abs(current) >= 0.005;
    const hasPrev = Math.abs(previous) >= 0.005;

    if (!hasCurrent && !hasPrev) return null;

    let color = 'text-muted-foreground bg-muted';
    let Icon = Minus;
    let text = '—';

    if (pct === null || pct === undefined) {
        if (hasCurrent && !hasPrev) {
            color = 'text-amber-700 bg-amber-100 dark:bg-amber-500/20 dark:text-amber-300';
            Icon = TrendingUp;
            text = 'nou';
        } else if (!hasCurrent && hasPrev) {
            color = 'text-emerald-700 bg-emerald-100 dark:bg-emerald-500/20 dark:text-emerald-300';
            Icon = TrendingDown;
            text = '−100%';
        }
    } else if (pct > 0.05) {
        color = 'text-red-700 bg-red-100 dark:bg-red-500/20 dark:text-red-300';
        Icon = TrendingUp;
        text = `+${pct.toFixed(1)}%`;
    } else if (pct < -0.05) {
        color = 'text-emerald-700 bg-emerald-100 dark:bg-emerald-500/20 dark:text-emerald-300';
        Icon = TrendingDown;
        text = `${pct.toFixed(1)}%`;
    } else {
        text = '0%';
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span
                    className={cn(
                        'inline-flex items-center gap-0.5 rounded px-1 py-px font-medium tabular-nums',
                        inline ? 'text-[9px]' : 'text-[10px]',
                        color,
                    )}
                >
                    <Icon className="size-2.5" />
                    {text}
                </span>
            </TooltipTrigger>
            <TooltipContent>
                <div className="text-xs">
                    {formatLeiPrecise(current)} vs{' '}
                    {formatLeiPrecise(previous)} RON
                </div>
            </TooltipContent>
        </Tooltip>
    );
}

export default function OpExIndex({ companies, filters, report }: Props) {
    const [expanded, setExpanded] = useState<Set<string>>(() => new Set());
    const [leafCache, setLeafCache] = useState<Record<string, LeafState>>({});

    const isCompare = !!report?.compare_year;

    const yearOptions = useMemo(() => {
        const current = new Date().getFullYear();
        return [current + 1, current, current - 1, current - 2, current - 3];
    }, []);

    const fetchLeaf = async (code: string, leaves: string[]) => {
        if (!filters.company_id || leaves.length === 0) return;
        if (leafCache[code]?.status === 'loaded') return;

        setLeafCache((prev) => ({ ...prev, [code]: { status: 'loading' } }));

        try {
            const url = opexInvoicesRoute(filters.company_id).url;
            const params = new URLSearchParams();
            params.set('year', String(filters.year));
            if (filters.compare_year) {
                params.set('compare_year', String(filters.compare_year));
            }
            for (const leaf of leaves) {
                params.append('leaves[]', leaf);
            }
            const res = await fetch(`${url}?${params.toString()}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!res.ok) {
                throw new Error(`HTTP ${res.status}`);
            }
            const data = (await res.json()) as {
                year: number;
                compare_year?: number;
                invoices: CellInvoice[];
                invoices_prev?: CellInvoice[];
            };
            setLeafCache((prev) => ({
                ...prev,
                [code]: {
                    status: 'loaded',
                    year: data.year,
                    compare_year: data.compare_year,
                    invoices: data.invoices,
                    invoices_prev: data.invoices_prev,
                },
            }));
        } catch (err) {
            setLeafCache((prev) => ({
                ...prev,
                [code]: {
                    status: 'error',
                    message:
                        err instanceof Error
                            ? err.message
                            : 'Eroare necunoscută',
                },
            }));
        }
    };

    const toggle = (node: OpExNode) => {
        const code = node.code;
        const isOpen = expanded.has(code);
        const next = new Set(expanded);
        if (isOpen) {
            next.delete(code);
        } else {
            next.add(code);
            if (node.is_leaf_for_drilldown && node.drilldown_leaves.length) {
                void fetchLeaf(code, node.drilldown_leaves);
            }
        }
        setExpanded(next);
    };

    const apply = (next: Partial<Filters>) => {
        const merged = { ...filters, ...next };
        if (merged.compare_year === merged.year) {
            merged.compare_year = null;
        }
        router.get(
            opexIndex().url,
            {
                company_id: merged.company_id ?? undefined,
                year: merged.year,
                compare_year: merged.compare_year ?? undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const refresh = () => {
        if (!filters.company_id) return;
        setLeafCache({});
        router.post(
            opexRefreshRoute(filters.company_id).url,
            { year: filters.year },
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title={`OpEx ${filters.year}`} />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold">
                            Cheltuieli operaționale
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {isCompare
                                ? `Comparație ${filters.year} vs ${filters.compare_year}.`
                                : 'Cheltuielile operaționale grupate ierarhic, pe luni, din baza SeniorERP a companiei.'}
                        </p>
                    </div>

                    <div className="flex flex-wrap items-end gap-2">
                        <div className="grid gap-1">
                            <Label className="text-xs">Companie</Label>
                            <Select
                                value={
                                    filters.company_id
                                        ? String(filters.company_id)
                                        : ''
                                }
                                onValueChange={(v) =>
                                    apply({ company_id: Number(v) })
                                }
                            >
                                <SelectTrigger className="min-h-11 w-[260px]">
                                    <SelectValue placeholder="Alege compania" />
                                </SelectTrigger>
                                <SelectContent>
                                    {companies.map((c) => (
                                        <SelectItem
                                            key={c.id}
                                            value={String(c.id)}
                                        >
                                            {c.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-1">
                            <Label className="text-xs">An</Label>
                            <Select
                                value={String(filters.year)}
                                onValueChange={(v) =>
                                    apply({ year: Number(v) })
                                }
                            >
                                <SelectTrigger className="min-h-11 w-[110px]">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {yearOptions.map((y) => (
                                        <SelectItem
                                            key={y}
                                            value={String(y)}
                                        >
                                            {y}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-1">
                            <Label className="text-xs">Comparat cu</Label>
                            <Select
                                value={
                                    filters.compare_year
                                        ? String(filters.compare_year)
                                        : 'none'
                                }
                                onValueChange={(v) =>
                                    apply({
                                        compare_year:
                                            v === 'none' ? null : Number(v),
                                    })
                                }
                            >
                                <SelectTrigger className="min-h-11 w-[140px]">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">
                                        Fără comparație
                                    </SelectItem>
                                    {yearOptions
                                        .filter((y) => y !== filters.year)
                                        .map((y) => (
                                            <SelectItem
                                                key={y}
                                                value={String(y)}
                                            >
                                                {y}
                                            </SelectItem>
                                        ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={refresh}
                            disabled={!filters.company_id}
                            className="min-h-11"
                        >
                            <RefreshCw />
                            Reîmprospătează
                        </Button>
                    </div>
                </div>

                {!report && (
                    <Card>
                        <CardContent className="py-12 text-center text-muted-foreground">
                            Selectează o companie pentru a vedea raportul.
                        </CardContent>
                    </Card>
                )}

                {report && (
                    <>
                        <div className="grid gap-4 md:grid-cols-3">
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-xs font-semibold text-muted-foreground uppercase">
                                        Total {report.year}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="flex items-center gap-2">
                                        <div className="text-2xl font-semibold tabular-nums">
                                            {formatLeiPrecise(
                                                report.grand_total,
                                            )}{' '}
                                            <span className="text-sm font-normal text-muted-foreground">
                                                RON
                                            </span>
                                        </div>
                                        {isCompare && (
                                            <DeltaBadge
                                                pct={report.delta_total_pct}
                                                current={report.grand_total}
                                                previous={
                                                    report.grand_total_prev ?? 0
                                                }
                                            />
                                        )}
                                    </div>
                                    {isCompare && (
                                        <div className="mt-1 text-xs text-muted-foreground tabular-nums">
                                            {report.compare_year}:{' '}
                                            {formatLeiPrecise(
                                                report.grand_total_prev ?? 0,
                                            )}{' '}
                                            RON
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-xs font-semibold text-muted-foreground uppercase">
                                        Categorii (frunze active)
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="text-2xl font-semibold tabular-nums">
                                        {report.meta.leaf_count}
                                    </div>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-xs font-semibold text-muted-foreground uppercase">
                                        Generat
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="text-sm font-medium">
                                        {new Date(
                                            report.meta.generated_at,
                                        ).toLocaleString('ro-RO')}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {report.meta.duration_ms} ms
                                    </div>
                                </CardContent>
                            </Card>
                        </div>

                        <div className="overflow-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                            <Table>
                                <TableHeader className="sticky top-0 z-10 bg-background">
                                    <TableRow>
                                        <TableHead className="w-[420px] min-w-[320px]">
                                            Categorie
                                        </TableHead>
                                        {MONTH_LABELS.map((m, i) => (
                                            <TableHead
                                                key={m}
                                                className="w-24 text-right"
                                            >
                                                <span className="text-xs">
                                                    {m}
                                                </span>
                                                <span className="ml-1 text-[10px] text-muted-foreground">
                                                    {String(i + 1).padStart(
                                                        2,
                                                        '0',
                                                    )}
                                                </span>
                                            </TableHead>
                                        ))}
                                        <TableHead className="w-32 text-right font-semibold">
                                            Total
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {report.roots.length === 0 && (
                                        <TableRow>
                                            <TableCell
                                                colSpan={14}
                                                className="py-10 text-center text-muted-foreground"
                                            >
                                                Niciun rezultat pentru anul{' '}
                                                {report.year}.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {report.roots.map((root) => (
                                        <TreeRow
                                            key={root.code}
                                            node={root}
                                            depth={0}
                                            expanded={expanded}
                                            toggle={toggle}
                                            leafCache={leafCache}
                                            isCompare={isCompare}
                                            currentYear={report.year}
                                            compareYear={report.compare_year}
                                        />
                                    ))}
                                </TableBody>
                                {report.roots.length > 0 && (
                                    <tfoot>
                                        <TableRow className="border-t-2 font-semibold">
                                            <TableCell>Total general</TableCell>
                                            {MONTH_LABELS.map((_, i) => {
                                                const month = i + 1;
                                                const cur =
                                                    report.totals_by_month[
                                                        month
                                                    ] ?? 0;
                                                const prev = isCompare
                                                    ? (report
                                                          .totals_by_month_prev?.[
                                                          month
                                                      ] ?? 0)
                                                    : 0;
                                                return (
                                                    <TableCell
                                                        key={i}
                                                        className="text-right tabular-nums"
                                                    >
                                                        <div className="flex items-center justify-end gap-1">
                                                            <span>
                                                                {formatLei(cur)}
                                                            </span>
                                                            {isCompare && (
                                                                <DeltaBadge
                                                                    pct={deltaPct(
                                                                        cur,
                                                                        prev,
                                                                    )}
                                                                    current={
                                                                        cur
                                                                    }
                                                                    previous={
                                                                        prev
                                                                    }
                                                                    inline
                                                                />
                                                            )}
                                                        </div>
                                                    </TableCell>
                                                );
                                            })}
                                            <TableCell className="text-right tabular-nums">
                                                <div className="flex items-center justify-end gap-1">
                                                    <span>
                                                        {formatLei(
                                                            report.grand_total,
                                                        )}
                                                    </span>
                                                    {isCompare && (
                                                        <DeltaBadge
                                                            pct={
                                                                report.delta_total_pct
                                                            }
                                                            current={
                                                                report.grand_total
                                                            }
                                                            previous={
                                                                report.grand_total_prev ??
                                                                0
                                                            }
                                                        />
                                                    )}
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    </tfoot>
                                )}
                            </Table>
                        </div>
                    </>
                )}
            </div>
        </>
    );
}

function TreeRow({
    node,
    depth,
    expanded,
    toggle,
    leafCache,
    isCompare,
    currentYear,
    compareYear,
}: {
    node: OpExNode;
    depth: number;
    expanded: Set<string>;
    toggle: (node: OpExNode) => void;
    leafCache: Record<string, LeafState>;
    isCompare: boolean;
    currentYear: number;
    compareYear?: number;
}) {
    const isOpen = expanded.has(node.code);
    const hasChildren = node.children.length > 0;
    const canDrillDown =
        node.is_leaf_for_drilldown && node.drilldown_leaves.length > 0;
    const isExpandable = hasChildren || canDrillDown;
    const leafState = canDrillDown ? leafCache[node.code] : undefined;

    return (
        <Fragment>
            <TableRow
                className={cn(
                    depth === 0 && 'bg-muted/40 font-semibold',
                    depth === 1 && 'bg-muted/20',
                )}
            >
                <TableCell>
                    <div
                        className="flex items-center gap-1"
                        style={{ paddingLeft: `${depth * 18}px` }}
                    >
                        {isExpandable ? (
                            <button
                                type="button"
                                onClick={() => toggle(node)}
                                className="inline-flex size-5 items-center justify-center rounded hover:bg-muted"
                                aria-label={
                                    isOpen ? 'Restrânge' : 'Expandează'
                                }
                            >
                                {isOpen ? (
                                    <ChevronDown className="size-4" />
                                ) : (
                                    <ChevronRight className="size-4" />
                                )}
                            </button>
                        ) : (
                            <span className="inline-block size-5" />
                        )}
                        <span
                            className={cn(
                                'truncate',
                                depth === 0 && 'uppercase tracking-wide',
                            )}
                            title={node.label}
                        >
                            {node.label}
                        </span>
                    </div>
                </TableCell>
                {Array.from({ length: 12 }, (_, i) => i + 1).map((m) => {
                    const cur = node.totals_by_month[m] ?? 0;
                    const prev = isCompare
                        ? (node.totals_by_month_prev?.[m] ?? 0)
                        : 0;
                    return (
                        <TableCell
                            key={m}
                            className="text-right tabular-nums"
                        >
                            <div className="flex flex-col items-end">
                                <span>{formatLei(cur)}</span>
                                {isCompare && (
                                    <DeltaBadge
                                        pct={deltaPct(cur, prev)}
                                        current={cur}
                                        previous={prev}
                                        inline
                                    />
                                )}
                            </div>
                        </TableCell>
                    );
                })}
                <TableCell className="text-right font-semibold tabular-nums">
                    <div className="flex flex-col items-end">
                        <span>{formatLei(node.total)}</span>
                        {isCompare && (
                            <DeltaBadge
                                pct={node.delta_total_pct}
                                current={node.total}
                                previous={node.total_prev ?? 0}
                            />
                        )}
                    </div>
                </TableCell>
            </TableRow>

            {isOpen &&
                hasChildren &&
                node.children.map((child) => (
                    <TreeRow
                        key={child.code}
                        node={child}
                        depth={depth + 1}
                        expanded={expanded}
                        toggle={toggle}
                        leafCache={leafCache}
                        isCompare={isCompare}
                        currentYear={currentYear}
                        compareYear={compareYear}
                    />
                ))}

            {isOpen && canDrillDown && (
                <InvoiceRows
                    depth={depth + 1}
                    state={leafState}
                    currentYear={currentYear}
                    compareYear={compareYear}
                />
            )}
        </Fragment>
    );
}

function InvoiceRows({
    depth,
    state,
    currentYear,
    compareYear,
}: {
    depth: number;
    state: LeafState | undefined;
    currentYear: number;
    compareYear?: number;
}) {
    if (!state || state.status === 'loading') {
        return (
            <TableRow className="bg-muted/10">
                <TableCell colSpan={14}>
                    <div
                        className="flex items-center gap-2 py-2 text-xs text-muted-foreground"
                        style={{ paddingLeft: `${depth * 18 + 24}px` }}
                    >
                        <Loader2 className="size-3 animate-spin" />
                        Se încarcă facturile…
                    </div>
                </TableCell>
            </TableRow>
        );
    }

    if (state.status === 'error') {
        return (
            <TableRow className="bg-red-50 dark:bg-red-950/40">
                <TableCell colSpan={14}>
                    <div
                        className="py-2 text-xs text-red-700 dark:text-red-300"
                        style={{ paddingLeft: `${depth * 18 + 24}px` }}
                    >
                        Eroare: {state.message}
                    </div>
                </TableCell>
            </TableRow>
        );
    }

    const sections: { year: number; invoices: CellInvoice[] }[] = [
        { year: state.year, invoices: state.invoices },
    ];
    if (state.compare_year && state.invoices_prev) {
        sections.push({
            year: state.compare_year,
            invoices: state.invoices_prev,
        });
    }

    if (sections.every((s) => s.invoices.length === 0)) {
        return (
            <TableRow className="bg-muted/10">
                <TableCell colSpan={14}>
                    <div
                        className="py-2 text-xs text-muted-foreground"
                        style={{ paddingLeft: `${depth * 18 + 24}px` }}
                    >
                        Nicio factură.
                    </div>
                </TableCell>
            </TableRow>
        );
    }

    return (
        <>
            {sections.map((section) => (
                <Fragment key={section.year}>
                    {sections.length > 1 && (
                        <TableRow className="bg-muted/30 text-xs">
                            <TableCell colSpan={14}>
                                <div
                                    className="flex items-center gap-2 py-1 font-semibold"
                                    style={{
                                        paddingLeft: `${depth * 18 + 24}px`,
                                    }}
                                >
                                    {section.year ===
                                    (currentYear ?? state.year) ? (
                                        <Badge>An curent · {section.year}</Badge>
                                    ) : (
                                        <Badge variant="outline">
                                            Comparație · {section.year}
                                        </Badge>
                                    )}
                                    <span className="text-muted-foreground">
                                        {section.invoices.length} documente
                                    </span>
                                </div>
                            </TableCell>
                        </TableRow>
                    )}
                    {section.invoices.length === 0 && sections.length > 1 && (
                        <TableRow>
                            <TableCell colSpan={14}>
                                <div
                                    className="py-1 text-xs text-muted-foreground"
                                    style={{
                                        paddingLeft: `${depth * 18 + 32}px`,
                                    }}
                                >
                                    — fără documente —
                                </div>
                            </TableCell>
                        </TableRow>
                    )}
                    {section.invoices.map((inv) => (
                        <TableRow
                            key={`${section.year}-${inv.data_doc}-${inv.tip_doc}-${inv.nr_doc}-${inv.sediu ?? ''}`}
                            className="bg-muted/5 text-xs"
                        >
                            <TableCell>
                                <div
                                    className="flex flex-wrap items-center gap-1.5"
                                    style={{
                                        paddingLeft: `${depth * 18 + 24}px`,
                                    }}
                                >
                                    <Badge
                                        variant="secondary"
                                        className="text-[10px]"
                                    >
                                        {inv.tip_doc}
                                    </Badge>
                                    <span className="font-mono">
                                        {inv.invoice_id ? (
                                            <Link
                                                href={
                                                    invoiceShow(
                                                        inv.invoice_id,
                                                    ).url
                                                }
                                                className="text-primary hover:underline"
                                            >
                                                {inv.nr_doc}
                                            </Link>
                                        ) : (
                                            inv.nr_doc
                                        )}
                                    </span>
                                    <span className="text-muted-foreground">
                                        · {inv.data_doc}
                                    </span>
                                    <span className="truncate text-muted-foreground">
                                        · {inv.partner || '—'}
                                    </span>
                                    {inv.sediu && (
                                        <Badge
                                            variant="outline"
                                            className="text-[10px]"
                                        >
                                            {inv.sediu}
                                        </Badge>
                                    )}
                                </div>
                            </TableCell>
                            {Array.from(
                                { length: 12 },
                                (_, i) => i + 1,
                            ).map((m) => (
                                <TableCell
                                    key={m}
                                    className="text-right tabular-nums"
                                >
                                    {m === inv.month
                                        ? formatLei(inv.line_total_lei)
                                        : ''}
                                </TableCell>
                            ))}
                            <TableCell className="text-right tabular-nums">
                                {formatLei(inv.line_total_lei)}
                            </TableCell>
                        </TableRow>
                    ))}
                </Fragment>
            ))}
        </>
    );
}

OpExIndex.layout = (page: React.ReactNode) => (
    <AppLayout
        breadcrumbs={[
            { title: 'Rapoarte', href: opexIndex() },
            { title: 'OpEx', href: opexIndex() },
        ]}
    >
        {page}
    </AppLayout>
);
