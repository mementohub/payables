import { useMemo } from 'react';
import {
    CartesianGrid,
    ComposedChart,
    Line,
    ReferenceArea,
    ReferenceLine,
    XAxis,
    YAxis,
} from 'recharts';
import {
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
import type { LastYearRow } from '@/types/cash-flow';
import { fmtCompact, fmtRon, weekLabel } from './report-math';
import type { DerivedReport } from './report-math';

const config = {
    closing: { label: 'Sold final', color: 'var(--foreground)' },
    inflows: { label: 'Încasări', color: '#059669' },
    outflows: { label: 'Plăți produs + OPEX', color: '#dc2626' },
    ly_closing: {
        label: 'Sold final an anterior',
        color: 'var(--muted-foreground)',
    },
    ly_inflows: { label: 'Încasări an anterior', color: '#059669' },
    ly_outflows: { label: 'Plăți an anterior', color: '#dc2626' },
} satisfies ChartConfig;

type Point = {
    week: string;
    label: string;
    closing: number;
    inflows: number;
    outflows: number;
    ly_closing: number | null;
    ly_inflows: number | null;
    ly_outflows: number | null;
    scenarioOnly: boolean;
};

/**
 * Sold final (left axis) with the week's inflows and outflows (right axis),
 * the safety threshold, last year's values dashed when the comparison is
 * on, and a hatch over the weeks that only the scenario covers.
 */
export default function BalanceChart({
    report,
    lastyear,
    coverage,
    horizon,
    compare,
}: {
    report: DerivedReport;
    lastyear: LastYearRow[];
    coverage: ('existing' | 'scenario')[];
    horizon: number;
    compare: boolean;
}) {
    const data = useMemo<Point[]>(
        () =>
            report.weeks.slice(0, horizon).map((week, i) => ({
                week,
                label: weekLabel(week),
                closing: report.closing[i],
                inflows: report.inflows[i],
                outflows: report.outflows[i] + report.opex[i],
                ly_closing: lastyear[i]?.ly_bal ?? null,
                ly_inflows: lastyear[i]?.ly_in ?? null,
                ly_outflows: lastyear[i]?.ly_out ?? null,
                scenarioOnly: coverage[i] === 'scenario',
            })),
        [report, lastyear, coverage, horizon],
    );

    const scenarioSpan = useMemo(() => {
        const first = data.findIndex((point) => point.scenarioOnly);

        return first === -1
            ? null
            : { from: data[first].label, to: data[data.length - 1].label };
    }, [data]);

    return (
        <ChartContainer config={config} className="h-[360px] w-full">
            <ComposedChart
                data={data}
                margin={{ left: 8, right: 8, top: 12, bottom: 0 }}
            >
                <CartesianGrid strokeDasharray="3 3" vertical={false} />
                {scenarioSpan && (
                    <ReferenceArea
                        x1={scenarioSpan.from}
                        x2={scenarioSpan.to}
                        yAxisId="balance"
                        fill="var(--muted)"
                        fillOpacity={0.5}
                        ifOverflow="extendDomain"
                    />
                )}
                <XAxis
                    dataKey="label"
                    tickLine={false}
                    axisLine={false}
                    interval={horizon > 26 ? 3 : 0}
                    fontSize={11}
                />
                <YAxis
                    yAxisId="balance"
                    tickLine={false}
                    axisLine={false}
                    width={64}
                    fontSize={11}
                    tickFormatter={(value: number) => fmtCompact(value)}
                />
                <YAxis
                    yAxisId="flows"
                    orientation="right"
                    tickLine={false}
                    axisLine={false}
                    width={64}
                    fontSize={11}
                    tickFormatter={(value: number) => fmtCompact(value)}
                />
                <ReferenceLine
                    y={report.minimum}
                    yAxisId="balance"
                    stroke="var(--destructive)"
                    strokeDasharray="6 4"
                    label={{
                        value: 'prag minim',
                        position: 'insideTopLeft',
                        fontSize: 10,
                        fill: 'var(--destructive)',
                    }}
                />
                {report.comfort > report.minimum && (
                    <ReferenceLine
                        y={report.comfort}
                        yAxisId="balance"
                        stroke="var(--chart-4)"
                        strokeDasharray="2 4"
                        label={{
                            value: 'confort',
                            position: 'insideTopLeft',
                            fontSize: 10,
                            fill: 'var(--chart-4)',
                        }}
                    />
                )}
                <ChartTooltip
                    content={
                        <ChartTooltipContent
                            labelFormatter={(_label, payload) => {
                                const point = payload?.[0]?.payload as
                                    | Point
                                    | undefined;

                                return point
                                    ? `Săptămâna ${weekLabel(point.week, true)}${point.scenarioOnly ? ' · doar scenariu' : ''}`
                                    : '';
                            }}
                            formatter={(value, name) => (
                                <div className="flex w-full items-center justify-between gap-3">
                                    <span className="text-muted-foreground">
                                        {config[name as keyof typeof config]
                                            ?.label ?? name}
                                    </span>
                                    <span className="font-medium tabular-nums">
                                        {fmtRon(Number(value))} RON
                                    </span>
                                </div>
                            )}
                        />
                    }
                />
                <ChartLegend content={<ChartLegendContent />} />
                <Line
                    yAxisId="flows"
                    type="monotone"
                    dataKey="inflows"
                    stroke="var(--color-inflows)"
                    strokeWidth={1.5}
                    dot={false}
                    isAnimationActive={false}
                />
                <Line
                    yAxisId="flows"
                    type="monotone"
                    dataKey="outflows"
                    stroke="var(--color-outflows)"
                    strokeWidth={1.5}
                    dot={false}
                    isAnimationActive={false}
                />
                <Line
                    yAxisId="balance"
                    type="monotone"
                    dataKey="closing"
                    stroke="var(--color-closing)"
                    strokeWidth={2.5}
                    dot={false}
                    isAnimationActive={false}
                />
                {compare && (
                    <>
                        <Line
                            yAxisId="flows"
                            type="monotone"
                            dataKey="ly_inflows"
                            stroke="var(--color-ly_inflows)"
                            strokeWidth={1.2}
                            strokeDasharray="2 4"
                            dot={false}
                            connectNulls
                            isAnimationActive={false}
                        />
                        <Line
                            yAxisId="flows"
                            type="monotone"
                            dataKey="ly_outflows"
                            stroke="var(--color-ly_outflows)"
                            strokeWidth={1.2}
                            strokeDasharray="2 4"
                            dot={false}
                            connectNulls
                            isAnimationActive={false}
                        />
                        <Line
                            yAxisId="balance"
                            type="monotone"
                            dataKey="ly_closing"
                            stroke="var(--color-ly_closing)"
                            strokeWidth={2}
                            strokeDasharray="6 4"
                            dot={false}
                            connectNulls
                            isAnimationActive={false}
                        />
                    </>
                )}
            </ComposedChart>
        </ChartContainer>
    );
}
