import { Fragment } from 'react';
import { cn } from '@/lib/utils';
import type { LastYearRow, PastWeeks } from '@/types/cash-flow';
import { fmtDelta, fmtRon, weekLabel } from './report-math';
import type { DerivedReport } from './report-math';

type Row = {
    week: string;
    lyWeek: string | null;
    inflows: number;
    outflows: number;
    closing: number | null;
    lyIn: number | null;
    lyOut: number | null;
    lyBal: number | null;
};

function DeltaCell({
    current,
    previous,
}: {
    current: number | null;
    previous: number | null;
}) {
    const delta =
        previous === null || current === null ? null : current - previous;

    return (
        <td
            className={cn(
                'border-l px-2 py-1.5 text-right whitespace-nowrap tabular-nums',
                delta === null
                    ? 'text-muted-foreground'
                    : delta < 0
                      ? 'text-destructive'
                      : 'text-emerald-700 dark:text-emerald-500',
            )}
        >
            {current === null ? '–' : fmtDelta(current, previous)}
        </td>
    );
}

const num = (value: number | string | null | undefined): number =>
    typeof value === 'number' ? value : Number(value ?? 0);

/** The full past weeks as OMC recorded them, against the year before. */
function pastRows(past: PastWeeks | null, weeks: number): Row[] {
    if (!past || !past.lastyear) {
        return [];
    }

    const lines = past.lines;
    const rows: Row[] = [];

    past.weeks.forEach((week, i) => {
        const ly = past.lastyear?.[i] ?? null;

        // The current week is still partial; the forecast covers it.
        if (ly === null) {
            return;
        }

        rows.push({
            week,
            lyWeek: ly.ly_week,
            inflows: num(lines.B?.[i]),
            outflows: num(lines.C?.[i]) + num(lines.D?.[i]),
            closing:
                lines.E2?.[i] === null || lines.E2?.[i] === undefined
                    ? null
                    : num(lines.E2[i]),
            lyIn: ly.ly_in,
            lyOut: ly.ly_out,
            lyBal: ly.ly_bal,
        });
    });

    return rows.slice(-weeks);
}

function Totals({ rows, label }: { rows: Row[]; label: string }) {
    const sum = (pick: (row: Row) => number | null) =>
        rows.reduce((total, row) => total + (pick(row) ?? 0), 0);

    return (
        <tr className="bg-muted/50 font-semibold">
            <td className="px-2 py-2">{label}</td>
            <td className="border-l px-2 py-2 text-right tabular-nums">
                {fmtRon(sum((r) => r.inflows))}
            </td>
            <td className="px-2 py-2 text-right tabular-nums">
                {fmtRon(sum((r) => r.lyIn))}
            </td>
            <DeltaCell
                current={sum((r) => r.inflows)}
                previous={sum((r) => r.lyIn)}
            />
            <td className="border-l px-2 py-2 text-right tabular-nums">
                {fmtRon(sum((r) => r.outflows))}
            </td>
            <td className="px-2 py-2 text-right tabular-nums">
                {fmtRon(sum((r) => r.lyOut))}
            </td>
            <DeltaCell
                current={sum((r) => r.outflows)}
                previous={sum((r) => r.lyOut)}
            />
            <td className="border-l px-2 py-2" colSpan={3} />
        </tr>
    );
}

function RowCells({ row }: { row: Row }) {
    return (
        <tr>
            <td className="px-2 py-1.5 whitespace-nowrap">
                <span className="font-medium">{weekLabel(row.week, true)}</span>
                {row.lyWeek && (
                    <span className="ml-1 text-xs text-muted-foreground">
                        vs {weekLabel(row.lyWeek, true)}
                    </span>
                )}
            </td>
            <td className="border-l px-2 py-1.5 text-right tabular-nums">
                {fmtRon(row.inflows)}
            </td>
            <td className="px-2 py-1.5 text-right text-muted-foreground tabular-nums">
                {fmtRon(row.lyIn)}
            </td>
            <DeltaCell current={row.inflows} previous={row.lyIn} />
            <td className="border-l px-2 py-1.5 text-right tabular-nums">
                {fmtRon(row.outflows)}
            </td>
            <td className="px-2 py-1.5 text-right text-muted-foreground tabular-nums">
                {fmtRon(row.lyOut)}
            </td>
            <DeltaCell current={row.outflows} previous={row.lyOut} />
            <td className="border-l px-2 py-1.5 text-right font-medium tabular-nums">
                {fmtRon(row.closing)}
            </td>
            <td className="px-2 py-1.5 text-right text-muted-foreground tabular-nums">
                {fmtRon(row.lyBal)}
            </td>
            <DeltaCell current={row.closing} previous={row.lyBal} />
        </tr>
    );
}

function SectionRow({ title, note }: { title: string; note: string }) {
    return (
        <tr className="bg-muted/30">
            <td
                colSpan={10}
                className="px-2 py-1.5 text-xs font-semibold tracking-wide uppercase"
            >
                {title}
                <span className="ml-2 font-normal tracking-normal text-muted-foreground normal-case">
                    {note}
                </span>
            </td>
        </tr>
    );
}

/**
 * Week by week against the same week a year before: the past weeks as OMC
 * recorded them (actuals on both sides), then this year's forecast against
 * last year's actuals — încasări, plăți produs + OPEX and the closing
 * balance.
 */
export default function YoyTable({
    report,
    lastyear,
    horizon,
    past = null,
    pastWeeks = 0,
}: {
    report: DerivedReport;
    lastyear: LastYearRow[];
    horizon: number;
    past?: PastWeeks | null;
    pastWeeks?: number;
}) {
    const actual = pastRows(past, pastWeeks);
    const forecast: Row[] = report.weeks.slice(0, horizon).map((week, i) => {
        const ly = lastyear[i] ?? null;

        return {
            week,
            lyWeek: ly?.ly_week ?? null,
            inflows: report.inflows[i],
            outflows: report.outflows[i] + report.opex[i],
            closing: report.closing[i],
            lyIn: ly?.ly_in ?? null,
            lyOut: ly?.ly_out ?? null,
            lyBal: ly?.ly_bal ?? null,
        };
    });

    return (
        <div className="overflow-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-xs text-muted-foreground uppercase">
                    <tr>
                        <th rowSpan={2} className="px-2 py-2 text-left">
                            Săpt.
                        </th>
                        <th colSpan={3} className="border-l px-2 py-1.5">
                            Încasări
                        </th>
                        <th colSpan={3} className="border-l px-2 py-1.5">
                            Plăți produs + OPEX
                        </th>
                        <th colSpan={3} className="border-l px-2 py-1.5">
                            Sold final
                        </th>
                    </tr>
                    <tr>
                        {['inc', 'out', 'bal'].map((group) => (
                            <Fragment key={group}>
                                <th className="border-l px-2 py-1 text-right font-medium">
                                    An curent
                                </th>
                                <th className="px-2 py-1 text-right font-medium">
                                    An anterior
                                </th>
                                <th className="border-l px-2 py-1 text-right font-medium">
                                    Δ
                                </th>
                            </Fragment>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                    {actual.length > 0 && (
                        <>
                            <SectionRow
                                title="Efectiv"
                                note="ambii ani din OMC; soldul anului anterior reconstituit din soldurile de sfârșit de lună"
                            />
                            {actual.map((row) => (
                                <RowCells key={`past-${row.week}`} row={row} />
                            ))}
                            <Totals
                                rows={actual}
                                label={`Total efectiv ${actual.length} săpt.`}
                            />
                            <SectionRow
                                title="Prognoză"
                                note="anul curent din acest raport, anul anterior efectiv din OMC"
                            />
                        </>
                    )}
                    {forecast.map((row) => (
                        <RowCells key={row.week} row={row} />
                    ))}
                </tbody>
                <tfoot>
                    <Totals
                        rows={forecast}
                        label={`Total prognoză ${horizon} săpt.`}
                    />
                </tfoot>
            </table>
        </div>
    );
}
