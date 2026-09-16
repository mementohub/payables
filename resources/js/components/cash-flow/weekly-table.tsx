import { Fragment, useMemo } from 'react';
import { cn } from '@/lib/utils';
import type { ReportLine } from '@/types/cash-flow';
import { fmtRon, monthGroups, weekLabel } from './report-math';
import type { DerivedReport } from './report-math';

const SECTION_TITLES: Record<ReportLine['section'], string> = {
    A: 'A. Sold inițial de trezorerie',
    B: 'B. Încasări operaționale',
    C: 'C. Plăți directe de produs',
    D: 'D. Costuri corporate și indirecte (OPEX)',
    E: 'E. Rezultat și semnal',
    F: 'F. Referință: fluxuri efective anul anterior (OMC)',
};

type Column = { key: string; label: string; indexes: number[] };

function cellValue(line: ReportLine, column: Column): number | string {
    if (line.kind === 'text') {
        return String(line.values[column.indexes[column.indexes.length - 1]]);
    }

    const values = column.indexes.map((i) => Number(line.values[i] ?? 0));

    if (line.kind === 'balance') {
        return line.code === 'A' ? values[0] : values[values.length - 1];
    }

    if (line.kind === 'threshold') {
        return values[0];
    }

    return values.reduce((a, b) => a + b, 0);
}

function signalClass(value: number | string): string {
    if (value === 'DEFICIT') {
        return 'text-destructive font-semibold';
    }

    if (value === 'ATENȚIE') {
        return 'text-amber-600 dark:text-amber-400 font-semibold';
    }

    return '';
}

/**
 * The full report grid: every line on its section, one column per week or
 * per month. Scenario lines fade out when the scenario is off.
 */
export default function WeeklyTable({
    report,
    horizon,
    monthly,
    scenarioOn,
}: {
    report: DerivedReport;
    horizon: number;
    monthly: boolean;
    scenarioOn: boolean;
}) {
    const columns = useMemo<Column[]>(() => {
        const weeks = report.weeks.slice(0, horizon);

        if (monthly) {
            return monthGroups(weeks).map((group) => ({
                key: group.key,
                label: group.label,
                indexes: group.indexes,
            }));
        }

        return weeks.map((week, i) => ({
            key: week,
            label: `S+${i + 1} ${weekLabel(week)}`,
            indexes: [i],
        }));
    }, [report.weeks, horizon, monthly]);

    const sections = useMemo(() => {
        const order: ReportLine['section'][] = ['A', 'B', 'C', 'D', 'E', 'F'];

        return order.map((section) => ({
            section,
            lines: report.lines.filter((line) => line.section === section),
        }));
    }, [report.lines]);

    return (
        <div className="max-h-[70vh] overflow-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
            <table className="min-w-max text-xs">
                <thead className="sticky top-0 z-20 bg-muted text-muted-foreground uppercase">
                    <tr>
                        <th className="sticky left-0 z-30 min-w-[320px] bg-muted px-3 py-2 text-left">
                            Linie
                        </th>
                        <th className="bg-muted px-2 py-2 text-right">
                            Total {horizon} săpt.
                        </th>
                        {columns.map((column) => (
                            <th
                                key={column.key}
                                className="bg-muted px-2 py-2 text-right whitespace-nowrap"
                            >
                                {column.label}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {sections.map(({ section, lines }) => (
                        <Fragment key={section}>
                            <tr className="bg-muted/60">
                                <td
                                    className="sticky left-0 z-10 bg-muted/60 px-3 py-1.5 font-semibold backdrop-blur"
                                    colSpan={columns.length + 2}
                                >
                                    {SECTION_TITLES[section]}
                                </td>
                            </tr>
                            {lines.map((line) => {
                                const dim = line.scenario && !scenarioOn;
                                const total =
                                    line.kind === 'value' ||
                                    line.kind === 'total' ||
                                    line.kind === 'reference'
                                        ? line.values
                                              .slice(0, horizon)
                                              .reduce<number>(
                                                  (a, b) => a + Number(b),
                                                  0,
                                              )
                                        : null;

                                return (
                                    <tr
                                        key={line.code}
                                        className={cn(
                                            'border-t border-sidebar-border/50',
                                            line.kind === 'total' &&
                                                'bg-muted/30 font-semibold',
                                            line.kind === 'balance' &&
                                                'font-semibold',
                                            dim &&
                                                'text-muted-foreground/60 line-through decoration-muted-foreground/40',
                                        )}
                                    >
                                        <td
                                            className={cn(
                                                'sticky left-0 z-10 bg-background px-3 py-1.5',
                                                line.kind === 'total' &&
                                                    'bg-muted/30',
                                            )}
                                            title={line.note ?? undefined}
                                        >
                                            <span className="mr-2 font-mono text-[10px] text-muted-foreground">
                                                {line.code}
                                            </span>
                                            {line.label}
                                            {line.scenario && (
                                                <span className="ml-1 rounded bg-muted px-1 text-[10px] text-muted-foreground uppercase">
                                                    scenariu
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-2 py-1.5 text-right tabular-nums">
                                            {total !== null
                                                ? fmtRon(total)
                                                : ''}
                                        </td>
                                        {columns.map((column) => {
                                            const value = cellValue(
                                                line,
                                                column,
                                            );

                                            return (
                                                <td
                                                    key={column.key}
                                                    className={cn(
                                                        'px-2 py-1.5 text-right whitespace-nowrap tabular-nums',
                                                        typeof value ===
                                                            'number' &&
                                                            value < 0 &&
                                                            'text-destructive',
                                                        line.kind === 'text' &&
                                                            signalClass(value),
                                                        line.kind === 'text' &&
                                                            'text-[10px] font-normal',
                                                    )}
                                                >
                                                    {typeof value === 'number'
                                                        ? value === 0 &&
                                                          line.kind === 'value'
                                                            ? '·'
                                                            : fmtRon(value)
                                                        : value}
                                                </td>
                                            );
                                        })}
                                    </tr>
                                );
                            })}
                        </Fragment>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
