import { Fragment, useMemo, useRef, useState } from 'react';
import { cn } from '@/lib/utils';
import type { ReportLine } from '@/types/cash-flow';
import {
    fmtRon,
    isEditable,
    monthGroups,
    parseAmount,
    weekLabel,
} from './report-math';
import type { DerivedReport, OverriddenCell } from './report-math';

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

function overrideTitle(cells: OverriddenCell[]): string {
    return cells
        .map((cell) =>
            [
                `${weekLabel(cell.week, true)}: ${fmtRon(cell.amount)} setat manual`,
                `calcul automat ${fmtRon(cell.auto)}`,
                cell.updated_by ? `de ${cell.updated_by}` : null,
                cell.updated_at
                    ? new Date(cell.updated_at).toLocaleDateString('ro-RO')
                    : null,
            ]
                .filter(Boolean)
                .join(' · '),
        )
        .join('\n');
}

/**
 * The amount being typed into one cell: Enter or leaving the cell saves it,
 * Escape gives up, an empty box goes back to the automated value.
 */
function CellInput({
    initial,
    auto,
    onSave,
    onCancel,
}: {
    initial: number;
    auto: number | null;
    onSave: (amount: number | null) => void;
    onCancel: () => void;
}) {
    const [text, setText] = useState(String(Math.round(initial * 100) / 100));
    const amount = parseAmount(text);
    const done = useRef(false);

    const commit = () => {
        if (done.current) {
            return;
        }

        done.current = true;

        const unchanged =
            amount === undefined ||
            amount === initial ||
            (amount === null && auto === null);

        if (unchanged) {
            onCancel();

            return;
        }

        onSave(amount === null || amount === auto ? null : amount);
    };

    return (
        <input
            autoFocus
            inputMode="decimal"
            aria-label="Valoare manuală (gol = calcul automat)"
            className={cn(
                'w-28 rounded border bg-background px-1 py-0.5 text-right text-xs tabular-nums outline-none focus:ring-2 focus:ring-ring',
                amount === undefined && 'border-destructive',
            )}
            value={text}
            onChange={(event) => setText(event.target.value)}
            onFocus={(event) => event.target.select()}
            onBlur={commit}
            onKeyDown={(event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    commit();
                }

                if (event.key === 'Escape') {
                    event.preventDefault();
                    done.current = true;
                    onCancel();
                }
            }}
        />
    );
}

/**
 * The full report grid: every line on its section, one column per week or
 * per month. Scenario lines fade out when the scenario is off. In the weekly
 * view a receipt, product payment or OPEX cell can be set by hand; the cells
 * set that way are marked, in either view.
 */
export default function WeeklyTable({
    report,
    horizon,
    monthly,
    scenarioOn,
    onOverride,
}: {
    report: DerivedReport;
    horizon: number;
    monthly: boolean;
    scenarioOn: boolean;
    /** Absent keeps the grid read-only. */
    onOverride?: (
        line: ReportLine,
        week: string,
        amount: number | null,
    ) => void;
}) {
    const [editing, setEditing] = useState<{
        code: string;
        index: number;
    } | null>(null);

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
                                    line.kind === 'subtotal' ||
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
                                            line.kind === 'subtotal' &&
                                                'font-medium',
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
                                                line.parent && 'pl-8',
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
                                            const manual = column.indexes
                                                .map(
                                                    (i) =>
                                                        report.overridden[
                                                            line.code
                                                        ]?.[i],
                                                )
                                                .filter(
                                                    (
                                                        cell,
                                                    ): cell is OverriddenCell =>
                                                        cell !== undefined,
                                                );
                                            const index = column.indexes[0];
                                            const editable =
                                                !monthly &&
                                                onOverride !== undefined &&
                                                isEditable(line);
                                            const isEditing =
                                                editable &&
                                                editing?.code === line.code &&
                                                editing.index === index;

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
                                                        manual.length > 0 &&
                                                            'bg-amber-100/70 font-medium text-amber-900 no-underline dark:bg-amber-500/15 dark:text-amber-200',
                                                        editable &&
                                                            !isEditing &&
                                                            'cursor-text hover:bg-muted hover:ring-1 hover:ring-sidebar-border hover:ring-inset',
                                                        isEditing && 'py-0.5',
                                                    )}
                                                    title={
                                                        manual.length > 0
                                                            ? overrideTitle(
                                                                  manual,
                                                              )
                                                            : editable
                                                              ? 'Click pentru a seta valoarea manual'
                                                              : undefined
                                                    }
                                                    onClick={
                                                        editable && !isEditing
                                                            ? () =>
                                                                  setEditing({
                                                                      code: line.code,
                                                                      index,
                                                                  })
                                                            : undefined
                                                    }
                                                >
                                                    {isEditing ? (
                                                        <CellInput
                                                            initial={Number(
                                                                value,
                                                            )}
                                                            auto={
                                                                manual[0]
                                                                    ?.auto ??
                                                                null
                                                            }
                                                            onCancel={() =>
                                                                setEditing(null)
                                                            }
                                                            onSave={(
                                                                amount,
                                                            ) => {
                                                                setEditing(
                                                                    null,
                                                                );
                                                                onOverride?.(
                                                                    line,
                                                                    report
                                                                        .weeks[
                                                                        index
                                                                    ],
                                                                    amount,
                                                                );
                                                            }}
                                                        />
                                                    ) : (
                                                        <>
                                                            {manual.length >
                                                                0 && (
                                                                <span
                                                                    aria-label="setat manual"
                                                                    className="mr-1 inline-block size-1.5 rounded-full bg-amber-500 align-middle"
                                                                />
                                                            )}
                                                            {typeof value ===
                                                            'number'
                                                                ? value === 0 &&
                                                                  manual.length ===
                                                                      0 &&
                                                                  (line.kind ===
                                                                      'value' ||
                                                                      line.kind ===
                                                                          'subtotal')
                                                                    ? '·'
                                                                    : fmtRon(
                                                                          value,
                                                                      )
                                                                : value}
                                                        </>
                                                    )}
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
