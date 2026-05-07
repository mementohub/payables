import { Head, router } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronRight,
    Minus,
    Percent,
    RefreshCw,
    SquareSigma,
    TrendingDown,
    TrendingUp,
} from 'lucide-react';
import { Fragment, useMemo, useState } from 'react';
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
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
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
    drilldown_sediu?: string | null;
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

type Filters = {
    company_id: number | null;
    year: number;
    compare_year: number | null;
};

type CompareMode = 'pct' | 'value';

type Props = {
    companies: Company[];
    filters: Filters;
    report: OpExReport | null;
};

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

function formatLeiSigned(value: number): string {
    if (Math.abs(value) < 0.005) {
        return '0';
    }

    const sign = value > 0 ? '+' : '';

    return (
        sign +
        new Intl.NumberFormat('ro-RO', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0,
        }).format(value)
    );
}

function formatLeiPrecise(value: number): string {
    return new Intl.NumberFormat('ro-RO', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(value);
}

function deltaPct(current: number, previous: number): number | null {
    if (Math.abs(previous) < 0.005) {
        return null;
    }

    return Math.round(((current - previous) / previous) * 1000) / 10;
}

function DeltaBadge({
    pct,
    current,
    previous,
    mode,
    inline = false,
}: {
    pct: number | null | undefined;
    current: number;
    previous: number;
    mode: CompareMode;
    inline?: boolean;
}) {
    const hasCurrent = Math.abs(current) >= 0.005;
    const hasPrev = Math.abs(previous) >= 0.005;

    if (!hasCurrent && !hasPrev) {
        return null;
    }

    let iconColor = 'text-muted-foreground/50';
    let Icon = Minus;
    let text = '—';
    const diff = current - previous;
    const prevText = formatLei(previous);

    if (pct === null || pct === undefined) {
        if (hasCurrent && !hasPrev) {
            iconColor = 'text-amber-500/80';
            Icon = TrendingUp;
            text = mode === 'pct' ? 'nou' : prevText;
        } else if (!hasCurrent && hasPrev) {
            iconColor = 'text-emerald-500/80';
            Icon = TrendingDown;
            text = mode === 'pct' ? '−100%' : prevText;
        }
    } else if (pct > 0.05) {
        iconColor = 'text-red-500/70';
        Icon = TrendingUp;
        text = mode === 'pct' ? `+${pct.toFixed(1)}%` : prevText;
    } else if (pct < -0.05) {
        iconColor = 'text-emerald-500/70';
        Icon = TrendingDown;
        text = mode === 'pct' ? `${pct.toFixed(1)}%` : prevText;
    } else {
        text = mode === 'pct' ? '0%' : prevText;
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span
                    className={cn(
                        'inline-flex items-center gap-0.5 font-normal text-muted-foreground tabular-nums',
                        inline ? 'text-[11px]' : 'text-xs',
                    )}
                >
                    <Icon
                        className={cn(
                            inline ? 'size-3' : 'size-3.5',
                            iconColor,
                        )}
                    />
                    {text}
                </span>
            </TooltipTrigger>
            <TooltipContent>
                <div className="text-xs">
                    {formatLeiPrecise(current)} vs {formatLeiPrecise(previous)}{' '}
                    RON · Δ {formatLeiSigned(diff)}
                </div>
            </TooltipContent>
        </Tooltip>
    );
}

function buildInvoicesHref(
    companyId: number,
    year: number,
    leaves: string[],
    sediu: string | null | undefined,
    categoryLabel: string | null,
    month: number | null,
): string {
    const url = opexInvoicesRoute(companyId).url;
    const params = new URLSearchParams();
    params.set('year', String(year));

    if (sediu !== null && sediu !== undefined) {
        params.set('sediu', sediu);
    }

    if (categoryLabel) {
        params.set('category_label', categoryLabel);
    }

    if (month !== null) {
        params.set('month', String(month));
    }

    for (const leaf of leaves) {
        params.append('leaves[]', leaf);
    }

    return `${url}?${params.toString()}`;
}

export default function OpExIndex({ companies, filters, report }: Props) {
    const [expanded, setExpanded] = useState<Set<string>>(() => new Set());
    const [compareMode, setCompareMode] = useState<CompareMode>('pct');

    const isCompare = !!report?.compare_year;

    const yearOptions = useMemo(() => {
        const current = new Date().getFullYear();

        return [current + 1, current, current - 1, current - 2, current - 3];
    }, []);

    const toggle = (node: OpExNode) => {
        const code = node.code;
        const next = new Set(expanded);

        if (next.has(code)) {
            next.delete(code);
        } else {
            next.add(code);
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
        if (!filters.company_id) {
            return;
        }

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
                                        <SelectItem key={y} value={String(y)}>
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
                        {isCompare && (
                            <div className="grid gap-1">
                                <Label className="text-xs">Δ</Label>
                                <ToggleGroup
                                    type="single"
                                    variant="outline"
                                    size="sm"
                                    value={compareMode}
                                    onValueChange={(v) =>
                                        v && setCompareMode(v as CompareMode)
                                    }
                                    className="min-h-11"
                                >
                                    <ToggleGroupItem
                                        value="pct"
                                        aria-label="Procent"
                                    >
                                        <Percent className="size-3.5" />
                                    </ToggleGroupItem>
                                    <ToggleGroupItem
                                        value="value"
                                        aria-label="Valoare"
                                    >
                                        <SquareSigma className="size-3.5" />
                                    </ToggleGroupItem>
                                </ToggleGroup>
                            </div>
                        )}
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
                                                mode={compareMode}
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
                                            parentLabel={null}
                                            companyId={filters.company_id}
                                            year={filters.year}
                                            expanded={expanded}
                                            toggle={toggle}
                                            isCompare={isCompare}
                                            compareMode={compareMode}
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
                                                                    mode={
                                                                        compareMode
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
                                                            mode={compareMode}
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
    parentLabel,
    companyId,
    year,
    expanded,
    toggle,
    isCompare,
    compareMode,
}: {
    node: OpExNode;
    depth: number;
    parentLabel: string | null;
    companyId: number | null;
    year: number;
    expanded: Set<string>;
    toggle: (node: OpExNode) => void;
    isCompare: boolean;
    compareMode: CompareMode;
}) {
    const isOpen = expanded.has(node.code);
    const hasChildren = node.children.length > 0;
    const canDrillDown = !!companyId && node.drilldown_leaves.length > 0;

    const categoryLabel = node.is_leaf_for_drilldown
        ? parentLabel
            ? `${parentLabel} · ${node.label}`
            : node.label
        : node.label;

    const cellHref = (month: number | null): string | null => {
        if (!canDrillDown || !companyId) {
            return null;
        }

        return buildInvoicesHref(
            companyId,
            year,
            node.drilldown_leaves,
            node.drilldown_sediu ?? null,
            categoryLabel,
            month,
        );
    };

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
                        {hasChildren ? (
                            <button
                                type="button"
                                onClick={() => toggle(node)}
                                className="inline-flex size-5 items-center justify-center rounded hover:bg-muted"
                                aria-label={isOpen ? 'Restrânge' : 'Expandează'}
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
                                depth === 0 && 'tracking-wide uppercase',
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
                    const hasValue =
                        Math.abs(cur) >= 0.005 || Math.abs(prev) >= 0.005;
                    const href = hasValue ? cellHref(m) : null;

                    return (
                        <TableCell key={m} className="text-right tabular-nums">
                            {href ? (
                                <a
                                    href={href}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="-m-1 flex flex-col items-end rounded p-1 hover:bg-primary/5 hover:text-primary"
                                    title="Vezi facturi (tab nou)"
                                >
                                    <span>{formatLei(cur)}</span>
                                    {isCompare && (
                                        <DeltaBadge
                                            pct={deltaPct(cur, prev)}
                                            current={cur}
                                            previous={prev}
                                            mode={compareMode}
                                            inline
                                        />
                                    )}
                                </a>
                            ) : (
                                <div className="flex flex-col items-end">
                                    <span>{formatLei(cur)}</span>
                                    {isCompare && (
                                        <DeltaBadge
                                            pct={deltaPct(cur, prev)}
                                            current={cur}
                                            previous={prev}
                                            mode={compareMode}
                                            inline
                                        />
                                    )}
                                </div>
                            )}
                        </TableCell>
                    );
                })}
                <TableCell className="text-right font-semibold tabular-nums">
                    {(() => {
                        const href = cellHref(null);
                        const content = (
                            <>
                                <span>{formatLei(node.total)}</span>
                                {isCompare && (
                                    <DeltaBadge
                                        pct={node.delta_total_pct}
                                        current={node.total}
                                        previous={node.total_prev ?? 0}
                                        mode={compareMode}
                                    />
                                )}
                            </>
                        );

                        return href ? (
                            <a
                                href={href}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="-m-1 flex flex-col items-end rounded p-1 hover:bg-primary/5 hover:text-primary"
                                title="Vezi toate facturile (tab nou)"
                            >
                                {content}
                            </a>
                        ) : (
                            <div className="flex flex-col items-end">
                                {content}
                            </div>
                        );
                    })()}
                </TableCell>
            </TableRow>

            {isOpen &&
                hasChildren &&
                node.children.map((child) => (
                    <TreeRow
                        key={child.code}
                        node={child}
                        depth={depth + 1}
                        parentLabel={node.label}
                        companyId={companyId}
                        year={year}
                        expanded={expanded}
                        toggle={toggle}
                        isCompare={isCompare}
                        compareMode={compareMode}
                    />
                ))}
        </Fragment>
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
