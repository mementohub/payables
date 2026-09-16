import { cn } from '@/lib/utils';
import type { LastYearRow } from '@/types/cash-flow';
import { fmtDelta, fmtRon, weekLabel } from './report-math';
import type { DerivedReport } from './report-math';

function DeltaCell({
    current,
    previous,
}: {
    current: number;
    previous: number | null;
}) {
    const delta = previous === null ? null : current - previous;

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
            {fmtDelta(current, previous)}
        </td>
    );
}

/**
 * Week by week: this year's forecast against last year's actual (OMC) for
 * the same week — încasări, plăți produs + OPEX and the closing balance.
 */
export default function YoyTable({
    report,
    lastyear,
    horizon,
}: {
    report: DerivedReport;
    lastyear: LastYearRow[];
    horizon: number;
}) {
    const rows = report.weeks.slice(0, horizon).map((week, i) => ({
        week,
        ly: lastyear[i] ?? null,
        inflows: report.inflows[i],
        outflows: report.outflows[i] + report.opex[i],
        closing: report.closing[i],
    }));

    const total = (key: 'inflows' | 'outflows') =>
        rows.reduce((sum, row) => sum + row[key], 0);
    const totalLy = (key: 'ly_in' | 'ly_out') =>
        rows.reduce((sum, row) => sum + (row.ly?.[key] ?? 0), 0);

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
                            <>
                                <th
                                    key={`${group}-cur`}
                                    className="border-l px-2 py-1 text-right font-medium"
                                >
                                    An curent
                                </th>
                                <th
                                    key={`${group}-ly`}
                                    className="px-2 py-1 text-right font-medium"
                                >
                                    An anterior
                                </th>
                                <th
                                    key={`${group}-d`}
                                    className="border-l px-2 py-1 text-right font-medium"
                                >
                                    Δ
                                </th>
                            </>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                    {rows.map((row) => (
                        <tr key={row.week}>
                            <td className="px-2 py-1.5 whitespace-nowrap">
                                <span className="font-medium">
                                    {weekLabel(row.week, true)}
                                </span>
                                {row.ly && (
                                    <span className="ml-1 text-xs text-muted-foreground">
                                        vs {weekLabel(row.ly.ly_week, true)}
                                    </span>
                                )}
                            </td>
                            <td className="border-l px-2 py-1.5 text-right tabular-nums">
                                {fmtRon(row.inflows)}
                            </td>
                            <td className="px-2 py-1.5 text-right text-muted-foreground tabular-nums">
                                {fmtRon(row.ly?.ly_in ?? null)}
                            </td>
                            <DeltaCell
                                current={row.inflows}
                                previous={row.ly?.ly_in ?? null}
                            />
                            <td className="border-l px-2 py-1.5 text-right tabular-nums">
                                {fmtRon(row.outflows)}
                            </td>
                            <td className="px-2 py-1.5 text-right text-muted-foreground tabular-nums">
                                {fmtRon(row.ly?.ly_out ?? null)}
                            </td>
                            <DeltaCell
                                current={row.outflows}
                                previous={row.ly?.ly_out ?? null}
                            />
                            <td className="border-l px-2 py-1.5 text-right font-medium tabular-nums">
                                {fmtRon(row.closing)}
                            </td>
                            <td className="px-2 py-1.5 text-right text-muted-foreground tabular-nums">
                                {fmtRon(row.ly?.ly_bal ?? null)}
                            </td>
                            <DeltaCell
                                current={row.closing}
                                previous={row.ly?.ly_bal ?? null}
                            />
                        </tr>
                    ))}
                </tbody>
                <tfoot className="bg-muted/50 font-semibold">
                    <tr>
                        <td className="px-2 py-2">Total {horizon} săpt.</td>
                        <td className="border-l px-2 py-2 text-right tabular-nums">
                            {fmtRon(total('inflows'))}
                        </td>
                        <td className="px-2 py-2 text-right tabular-nums">
                            {fmtRon(totalLy('ly_in'))}
                        </td>
                        <DeltaCell
                            current={total('inflows')}
                            previous={totalLy('ly_in')}
                        />
                        <td className="border-l px-2 py-2 text-right tabular-nums">
                            {fmtRon(total('outflows'))}
                        </td>
                        <td className="px-2 py-2 text-right tabular-nums">
                            {fmtRon(totalLy('ly_out'))}
                        </td>
                        <DeltaCell
                            current={total('outflows')}
                            previous={totalLy('ly_out')}
                        />
                        <td className="border-l px-2 py-2" colSpan={3} />
                    </tr>
                </tfoot>
            </table>
        </div>
    );
}
