import { Deferred, Head, router } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useMemo } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    ComposedChart,
    Line,
    Pie,
    PieChart,
    ReferenceLine,
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
import { Skeleton } from '@/components/ui/skeleton';
import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import { index as cashFlowIndex } from '@/routes/reports/cash-flow';
import type {
    AgingBucket,
    CashflowPoint,
    CashflowSeries,
    Filters,
    PaymentState,
    Props,
    TopSupplier,
} from './dashboard.types';

function formatMoney(value: number, moneda: string): string {
    return `${new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(value)} ${moneda}`;
}

const STATE_COLORS: Record<PaymentState['state'], string> = {
    paid: '#1e7d3b',
    partial: '#d98a00',
    unpaid: '#74809a',
    overdue: '#c8102e',
};

const AGING_COLORS: Record<string, string> = {
    '0-30': '#e0b13a',
    '31-60': '#d98a00',
    '61-90': '#ff4200',
    '90+': '#c8102e',
};

const CASHFLOW_COLORS = {
    incoming: '#1e7d3b',
    outgoing: '#c8102e',
};

export default function Dashboard({
    filters,
    paymentBreakdown,
    agingBuckets,
    topOverdueSuppliers,
    cashflow,
}: Props) {
    const moneda = 'Lei';

    const applyFilter = (next: Partial<Filters>) => {
        const merged = { ...filters, ...next };
        router.get(
            dashboard().url,
            {
                from: merged.from ?? undefined,
                to: merged.to ?? undefined,
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
                    <CashflowCard
                        data={cashflow ?? { built_at: null, points: [] }}
                    />
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

function CashflowCard({ data }: { data: CashflowSeries }) {
    const config = useMemo<ChartConfig>(
        () => ({
            incoming: { label: 'Încasări', color: CASHFLOW_COLORS.incoming },
            outgoing: { label: 'Plăți', color: CASHFLOW_COLORS.outgoing },
            balance: { label: 'Poziție de trezorerie', color: '#1f2a44' },
        }),
        [],
    );

    const points = useMemo(
        () =>
            data.points.map((point) => ({
                ...point,
                label: `${point.week.slice(8, 10)}.${point.week.slice(5, 7)}`,
            })),
        [data.points],
    );
    const firstForecast = points.find((point) => point.kind === 'forecast');
    const actualWeeks = points.filter((p) => p.kind === 'actual').length;
    const forecastWeeks = points.length - actualWeeks;

    return (
        <Card className="md:col-span-2 lg:col-span-3">
            <CardHeader>
                <CardTitle>Flux de numerar săptămânal</CardTitle>
                <CardDescription>
                    Încasări și plăți pe săptămână, în lei: ultimele{' '}
                    {actualWeeks} săptămâni așa cum le-a înregistrat OMC (bancă
                    + casă, fără transferuri interne) și următoarele{' '}
                    {forecastWeeks} după prognoza WCFR 52 Weeks (barele
                    deschise). Linia este poziția de trezorerie la finalul
                    săptămânii.
                    {data.built_at &&
                        ` Prognoză construită ${new Date(data.built_at).toLocaleString('ro-RO')}.`}
                </CardDescription>
            </CardHeader>
            <CardContent>
                {points.length === 0 ? (
                    <div className="flex h-[260px] flex-col items-center justify-center gap-2 text-sm text-muted-foreground">
                        <span>
                            Raportul WCFR 52 Weeks nu a fost încă construit.
                        </span>
                        <Link
                            href={cashFlowIndex()}
                            className="underline underline-offset-4"
                        >
                            Deschide raportul și apasă „Recalculează”
                        </Link>
                    </div>
                ) : (
                    <ChartContainer
                        config={config}
                        className="h-[320px] w-full"
                    >
                        <ComposedChart
                            data={points}
                            margin={{ left: 0, right: 12, top: 10, bottom: 0 }}
                            barGap={2}
                        >
                            <CartesianGrid
                                strokeDasharray="3 3"
                                vertical={false}
                            />
                            <XAxis
                                dataKey="label"
                                tickLine={false}
                                axisLine={false}
                                fontSize={11}
                            />
                            <YAxis
                                yAxisId="flows"
                                tickLine={false}
                                axisLine={false}
                                width={64}
                                fontSize={11}
                                tickFormatter={(v) =>
                                    new Intl.NumberFormat('ro-RO', {
                                        notation: 'compact',
                                    }).format(Number(v))
                                }
                            />
                            <YAxis
                                yAxisId="balance"
                                orientation="right"
                                tickLine={false}
                                axisLine={false}
                                width={64}
                                fontSize={11}
                                tickFormatter={(v) =>
                                    new Intl.NumberFormat('ro-RO', {
                                        notation: 'compact',
                                    }).format(Number(v))
                                }
                            />
                            {firstForecast && (
                                <ReferenceLine
                                    x={firstForecast.label}
                                    yAxisId="flows"
                                    stroke="var(--muted-foreground)"
                                    strokeDasharray="4 4"
                                    label={{
                                        value: 'azi',
                                        position: 'insideTopLeft',
                                        fontSize: 10,
                                        fill: 'var(--muted-foreground)',
                                    }}
                                />
                            )}
                            <ChartTooltip
                                content={
                                    <ChartTooltipContent
                                        labelFormatter={(_v, payload) => {
                                            const row = payload?.[0]
                                                ?.payload as
                                                | CashflowPoint
                                                | undefined;

                                            return row
                                                ? `Săptămâna ${row.week} · ${row.kind === 'actual' ? 'efectiv (OMC)' : 'prognoză (WCFR)'}`
                                                : '';
                                        }}
                                        formatter={(value, name) => (
                                            <div className="flex min-w-[200px] items-center justify-between gap-2">
                                                <span className="text-muted-foreground">
                                                    {config[
                                                        name as keyof typeof config
                                                    ]?.label ?? String(name)}
                                                </span>
                                                <span className="font-medium tabular-nums">
                                                    {formatMoney(
                                                        Number(value),
                                                        'Lei',
                                                    )}
                                                </span>
                                            </div>
                                        )}
                                    />
                                }
                            />
                            <Bar
                                yAxisId="flows"
                                dataKey="incoming"
                                fill={CASHFLOW_COLORS.incoming}
                                radius={[3, 3, 0, 0]}
                                isAnimationActive={false}
                            >
                                {points.map((point) => (
                                    <Cell
                                        key={`in-${point.week}`}
                                        fillOpacity={
                                            point.kind === 'forecast' ? 0.4 : 1
                                        }
                                    />
                                ))}
                            </Bar>
                            <Bar
                                yAxisId="flows"
                                dataKey="outgoing"
                                fill={CASHFLOW_COLORS.outgoing}
                                radius={[3, 3, 0, 0]}
                                isAnimationActive={false}
                            >
                                {points.map((point) => (
                                    <Cell
                                        key={`out-${point.week}`}
                                        fillOpacity={
                                            point.kind === 'forecast' ? 0.4 : 1
                                        }
                                    />
                                ))}
                            </Bar>
                            <Line
                                yAxisId="balance"
                                type="monotone"
                                dataKey="balance"
                                stroke="#1f2a44"
                                strokeWidth={2}
                                dot={false}
                                connectNulls
                                isAnimationActive={false}
                            />
                        </ComposedChart>
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
