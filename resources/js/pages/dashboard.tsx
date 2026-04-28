import { Deferred, Head, router } from '@inertiajs/react';
import { useMemo } from 'react';
import type { ReactNode } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    Line,
    LineChart,
    Pie,
    PieChart,
    XAxis,
    YAxis,
} from 'recharts';
import DateRangePicker from '@/components/date-range-picker';
import type { DateRangeValue } from '@/components/date-range-picker';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import type {
    AgingBucket,
    CashflowPoint,
    Filters,
    PaymentState,
    Props,
    TopSupplier,
} from './dashboard.types';

function formatMoney(value: number, moneda: string): string {
    return `${new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(value)} ${moneda}`;
}

const STATE_COLORS: Record<PaymentState['state'], string> = {
    paid: 'oklch(0.62 0.17 150)',
    partial: 'oklch(0.80 0.17 85)',
    unpaid: 'oklch(0.68 0.03 250)',
    overdue: 'oklch(0.58 0.22 27)',
};

const AGING_COLORS: Record<string, string> = {
    '0-30': 'oklch(0.82 0.16 95)',
    '31-60': 'oklch(0.75 0.17 65)',
    '61-90': 'oklch(0.68 0.19 40)',
    '90+': 'oklch(0.58 0.22 27)',
};

const CASHFLOW_COLORS = {
    incoming: 'oklch(0.62 0.17 150)',
    outgoing: 'oklch(0.58 0.22 27)',
};

export default function Dashboard({
    filters,
    companies,
    paymentBreakdown,
    agingBuckets,
    topOverdueSuppliers,
    cashflow,
}: Props) {
    const moneda = filters.moneda || 'Lei';

    const applyFilter = (next: Partial<Filters>) => {
        const merged = { ...filters, ...next };
        router.get(
            dashboard().url,
            {
                company_id: merged.company_id ?? undefined,
                from: merged.from ?? undefined,
                to: merged.to ?? undefined,
                moneda: merged.moneda ?? undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const dateRange: DateRangeValue = { from: filters.from, to: filters.to };

    return (
        <>
            <Head title="Panou principal" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">Panou principal</h1>
                    <p className="text-sm text-muted-foreground">
                        Privire de ansamblu asupra facturilor primite și a
                        fluxului de numerar.
                    </p>
                </div>

                <form
                    aria-label="Filtre panou"
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(e) => e.preventDefault()}
                >
                    <div className="grid gap-1">
                        <Label className="text-xs">Companie</Label>
                        <Select
                            value={
                                filters.company_id
                                    ? String(filters.company_id)
                                    : 'all'
                            }
                            onValueChange={(v) =>
                                applyFilter({
                                    company_id: v === 'all' ? null : Number(v),
                                })
                            }
                        >
                            <SelectTrigger className="min-h-11 w-[200px]">
                                <SelectValue placeholder="Companie" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    Toate companiile
                                </SelectItem>
                                {companies.map((c) => (
                                    <SelectItem key={c.id} value={String(c.id)}>
                                        {c.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-1">
                        <Label className="text-xs">Perioadă</Label>
                        <DateRangePicker
                            className="w-[260px]"
                            value={dateRange}
                            onChange={(v) =>
                                applyFilter({
                                    from: v.from ?? null,
                                    to: v.to ?? null,
                                })
                            }
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label className="text-xs">Monedă</Label>
                        <Select
                            value={moneda}
                            onValueChange={(v) => applyFilter({ moneda: v })}
                        >
                            <SelectTrigger className="min-h-11 w-[100px]">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="Lei">Lei</SelectItem>
                                <SelectItem value="EUR">EUR</SelectItem>
                                <SelectItem value="USD">USD</SelectItem>
                                <SelectItem value="GBP">GBP</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </form>

                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    <Deferred
                        data="paymentBreakdown"
                        fallback={
                            <ChartSkeleton title="Facturi primite după stare" />
                        }
                    >
                        <PaymentBreakdownCard
                            data={paymentBreakdown ?? []}
                            moneda={moneda}
                        />
                    </Deferred>

                    <Deferred
                        data="agingBuckets"
                        fallback={
                            <ChartSkeleton title="Vechime facturi restante" />
                        }
                    >
                        <AgingBucketsCard
                            data={agingBuckets ?? []}
                            moneda={moneda}
                        />
                    </Deferred>

                    <Deferred
                        data="topOverdueSuppliers"
                        fallback={
                            <ChartSkeleton title="Top furnizori restanți" />
                        }
                    >
                        <TopSuppliersCard
                            data={topOverdueSuppliers ?? []}
                            moneda={moneda}
                        />
                    </Deferred>
                </div>

                <Deferred
                    data="cashflow"
                    fallback={
                        <ChartSkeleton
                            title="Flux de numerar săptămânal"
                            wide
                        />
                    }
                >
                    <CashflowCard data={cashflow ?? []} moneda={moneda} />
                </Deferred>
            </div>
        </>
    );
}

function ChartSkeleton({
    title,
    wide = false,
}: {
    title: string;
    wide?: boolean;
}) {
    return (
        <Card className={wide ? 'md:col-span-2 lg:col-span-3' : undefined}>
            <CardHeader>
                <CardTitle>{title}</CardTitle>
            </CardHeader>
            <CardContent>
                <Skeleton className="h-[220px] w-full" />
            </CardContent>
        </Card>
    );
}

function PaymentBreakdownCard({
    data,
    moneda,
}: {
    data: PaymentState[];
    moneda: string;
}) {
    const config = useMemo<ChartConfig>(
        () => ({
            paid: { label: 'Achitate', color: STATE_COLORS.paid },
            partial: { label: 'Parțial', color: STATE_COLORS.partial },
            unpaid: { label: 'Neachitate', color: STATE_COLORS.unpaid },
            overdue: { label: 'Restante', color: STATE_COLORS.overdue },
        }),
        [],
    );
    const totalOutstanding = data.reduce((s, r) => s + r.outstanding, 0);
    const totalCount = data.reduce((s, r) => s + r.count, 0);
    const pieData = data.map((r) => ({ ...r, fill: STATE_COLORS[r.state] }));

    return (
        <Card>
            <CardHeader>
                <CardTitle>Facturi primite după stare</CardTitle>
                <CardDescription>
                    {totalCount.toLocaleString('ro-RO')} facturi · neachitat:{' '}
                    {formatMoney(totalOutstanding, moneda)}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <ChartContainer
                    config={config}
                    className="mx-auto aspect-square max-h-[240px]"
                >
                    <PieChart>
                        <ChartTooltip
                            content={
                                <ChartTooltipContent
                                    nameKey="label"
                                    formatter={(value, _name, item) => (
                                        <div className="flex min-w-[180px] items-center justify-between gap-2">
                                            <span className="text-muted-foreground">
                                                {
                                                    (
                                                        item.payload as PaymentState
                                                    ).label
                                                }
                                            </span>
                                            <span className="font-medium tabular-nums">
                                                {Number(value).toLocaleString(
                                                    'ro-RO',
                                                )}{' '}
                                                facturi
                                            </span>
                                        </div>
                                    )}
                                />
                            }
                        />
                        <Pie
                            data={pieData}
                            dataKey="count"
                            nameKey="label"
                            innerRadius={60}
                            outerRadius={95}
                            strokeWidth={2}
                        >
                            {pieData.map((entry) => (
                                <Cell key={entry.state} fill={entry.fill} />
                            ))}
                        </Pie>
                    </PieChart>
                </ChartContainer>
                <div className="mt-3 grid grid-cols-2 gap-2 text-sm">
                    {data.map((row) => (
                        <div
                            key={row.state}
                            className="flex items-center gap-2"
                        >
                            <span
                                className="size-2 shrink-0 rounded-full"
                                style={{
                                    backgroundColor: STATE_COLORS[row.state],
                                }}
                            />
                            <span className="text-muted-foreground">
                                {row.label}
                            </span>
                            <span className="ml-auto font-medium tabular-nums">
                                {row.count}
                            </span>
                        </div>
                    ))}
                </div>
            </CardContent>
        </Card>
    );
}

function AgingBucketsCard({
    data,
    moneda,
}: {
    data: AgingBucket[];
    moneda: string;
}) {
    const config = useMemo<ChartConfig>(
        () => ({
            outstanding: { label: 'Restant', color: AGING_COLORS['31-60'] },
        }),
        [],
    );
    const total = data.reduce((s, r) => s + r.outstanding, 0);
    const chartData = data.map((r) => ({
        ...r,
        fill: AGING_COLORS[r.bucket] ?? 'var(--chart-1)',
    }));

    return (
        <Card>
            <CardHeader>
                <CardTitle>Vechime facturi restante</CardTitle>
                <CardDescription>
                    Sumă restantă pe intervale de întârziere · total:{' '}
                    {formatMoney(total, moneda)}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <ChartContainer config={config} className="h-[240px] w-full">
                    <BarChart
                        data={chartData}
                        margin={{ left: 0, right: 10, top: 10, bottom: 0 }}
                    >
                        <CartesianGrid strokeDasharray="3 3" vertical={false} />
                        <XAxis
                            dataKey="bucket"
                            tickLine={false}
                            axisLine={false}
                        />
                        <YAxis
                            tickLine={false}
                            axisLine={false}
                            width={80}
                            tickFormatter={(v) =>
                                new Intl.NumberFormat('ro-RO', {
                                    notation: 'compact',
                                }).format(Number(v))
                            }
                        />
                        <ChartTooltip
                            content={
                                <ChartTooltipContent
                                    formatter={(_value, _name, item) => {
                                        const row = item.payload as AgingBucket;

                                        return (
                                            <div className="flex min-w-[200px] flex-col">
                                                <span className="text-muted-foreground">
                                                    {row.bucket} zile
                                                </span>
                                                <span className="font-medium tabular-nums">
                                                    {formatMoney(
                                                        row.outstanding,
                                                        moneda,
                                                    )}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    {row.count} facturi
                                                </span>
                                            </div>
                                        );
                                    }}
                                />
                            }
                        />
                        <Bar dataKey="outstanding" radius={[6, 6, 0, 0]}>
                            {chartData.map((entry) => (
                                <Cell key={entry.bucket} fill={entry.fill} />
                            ))}
                        </Bar>
                    </BarChart>
                </ChartContainer>
            </CardContent>
        </Card>
    );
}

function TopSuppliersCard({
    data,
    moneda,
}: {
    data: TopSupplier[];
    moneda: string;
}) {
    const config = useMemo<ChartConfig>(
        () => ({
            outstanding: { label: 'Restant', color: STATE_COLORS.overdue },
        }),
        [],
    );

    return (
        <Card>
            <CardHeader>
                <CardTitle>Top furnizori restanți</CardTitle>
                <CardDescription>
                    Sumă restantă pe primii 5 furnizori
                </CardDescription>
            </CardHeader>
            <CardContent>
                {data.length === 0 ? (
                    <div className="flex h-[240px] items-center justify-center text-sm text-muted-foreground">
                        Niciun furnizor cu facturi restante în perioada
                        selectată.
                    </div>
                ) : (
                    <ChartContainer
                        config={config}
                        className="h-[240px] w-full"
                    >
                        <BarChart
                            data={data}
                            layout="vertical"
                            margin={{ left: 10, right: 10, top: 10, bottom: 0 }}
                        >
                            <CartesianGrid
                                strokeDasharray="3 3"
                                horizontal={false}
                            />
                            <XAxis
                                type="number"
                                tickLine={false}
                                axisLine={false}
                                tickFormatter={(v) =>
                                    new Intl.NumberFormat('ro-RO', {
                                        notation: 'compact',
                                    }).format(Number(v))
                                }
                            />
                            <YAxis
                                dataKey="name"
                                type="category"
                                tickLine={false}
                                axisLine={false}
                                width={170}
                                tickFormatter={(v: string) =>
                                    v.length > 24 ? `${v.slice(0, 24)}…` : v
                                }
                            />
                            <ChartTooltip
                                content={
                                    <ChartTooltipContent
                                        formatter={(_value, _name, item) => {
                                            const row =
                                                item.payload as TopSupplier;

                                            return (
                                                <div className="flex min-w-[220px] flex-col">
                                                    <span className="font-medium">
                                                        {row.name}
                                                    </span>
                                                    <span className="text-muted-foreground tabular-nums">
                                                        {formatMoney(
                                                            row.outstanding,
                                                            moneda,
                                                        )}
                                                    </span>
                                                    <span className="text-xs text-muted-foreground">
                                                        {row.invoices} facturi
                                                        restante
                                                    </span>
                                                </div>
                                            );
                                        }}
                                    />
                                }
                            />
                            <Bar
                                dataKey="outstanding"
                                fill={STATE_COLORS.overdue}
                                radius={[0, 6, 6, 0]}
                            />
                        </BarChart>
                    </ChartContainer>
                )}
            </CardContent>
        </Card>
    );
}

function CashflowCard({
    data,
    moneda,
}: {
    data: CashflowPoint[];
    moneda: string;
}) {
    const config = useMemo<ChartConfig>(
        () => ({
            incoming: { label: 'Încasări', color: CASHFLOW_COLORS.incoming },
            outgoing: { label: 'Plăți', color: CASHFLOW_COLORS.outgoing },
        }),
        [],
    );

    return (
        <Card className="md:col-span-2 lg:col-span-3">
            <CardHeader>
                <CardTitle>Flux de numerar săptămânal</CardTitle>
                <CardDescription>
                    Total încasări vs. plăți pe săptămână din extrasele bancare
                </CardDescription>
            </CardHeader>
            <CardContent>
                {data.length === 0 ? (
                    <div className="flex h-[260px] items-center justify-center text-sm text-muted-foreground">
                        Niciun extras bancar în perioada selectată.
                    </div>
                ) : (
                    <ChartContainer
                        config={config}
                        className="h-[280px] w-full"
                    >
                        <LineChart
                            data={data}
                            margin={{ left: 0, right: 20, top: 10, bottom: 0 }}
                        >
                            <CartesianGrid
                                strokeDasharray="3 3"
                                vertical={false}
                            />
                            <XAxis
                                dataKey="week"
                                tickLine={false}
                                axisLine={false}
                                tickFormatter={(v: string) =>
                                    v.replace(/^\d{4}-/, '')
                                }
                            />
                            <YAxis
                                tickLine={false}
                                axisLine={false}
                                width={80}
                                tickFormatter={(v) =>
                                    new Intl.NumberFormat('ro-RO', {
                                        notation: 'compact',
                                    }).format(Number(v))
                                }
                            />
                            <ChartTooltip
                                content={
                                    <ChartTooltipContent
                                        labelFormatter={(_v, payload) => {
                                            const row = payload?.[0]
                                                ?.payload as
                                                | CashflowPoint
                                                | undefined;

                                            return row
                                                ? `Săptămâna ${row.week}`
                                                : '';
                                        }}
                                        formatter={(value, name) => (
                                            <div className="flex min-w-[180px] items-center justify-between gap-2">
                                                <span className="text-muted-foreground capitalize">
                                                    {String(name)}
                                                </span>
                                                <span className="font-medium tabular-nums">
                                                    {formatMoney(
                                                        Number(value),
                                                        moneda,
                                                    )}
                                                </span>
                                            </div>
                                        )}
                                    />
                                }
                            />
                            <Line
                                type="monotone"
                                dataKey="incoming"
                                stroke={CASHFLOW_COLORS.incoming}
                                strokeWidth={2}
                                dot={false}
                            />
                            <Line
                                type="monotone"
                                dataKey="outgoing"
                                stroke={CASHFLOW_COLORS.outgoing}
                                strokeWidth={2}
                                dot={false}
                            />
                        </LineChart>
                    </ChartContainer>
                )}
            </CardContent>
        </Card>
    );
}

Dashboard.layout = (page: ReactNode) => (
    <AppLayout breadcrumbs={[{ title: 'Panou principal', href: dashboard() }]}>
        {page}
    </AppLayout>
);
