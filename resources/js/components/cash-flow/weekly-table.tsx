import { Search } from 'lucide-react';
import { Fragment, useEffect, useMemo, useRef, useState } from 'react';
import { cn } from '@/lib/utils';
import type { PastWeeks, ReportLine } from '@/types/cash-flow';
import {
    fmtRon,
    isEditable,
    monthGroups,
    PAST_ONLY,
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

/** Lines whose forecast cells open the documents behind them. */
const DRILLABLE = new Set(['C10']);

type Column = {
    key: string;
    label: string;
    /** past: actual flows from OMC; future: the forecast. */
    kind: 'past' | 'future';
    indexes: number[];
    partial?: boolean;
};

type Cell = number | string | null;

/**
 * One line's value over a column: flows add up, the opening balance is the
 * first week's, the closing balance, the threshold and the signal the last
 * week's. A past line with nothing recorded reads as zero.
 */
function cellValue(
    line: ReportLine,
    column: Column,
    past: PastWeeks | null,
): Cell {
    if (column.kind === 'future' && PAST_ONLY.has(line.code)) {
        return null;
    }

    const source =
        column.kind === 'past' ? past?.lines[line.code] : line.values;
    const at = (i: number): Cell => source?.[i] ?? null;
    const last = column.indexes[column.indexes.length - 1];

    if (line.kind === 'text') {
        return String(at(last) ?? '');
    }

    if (line.kind === 'balance') {
        const value = line.code === 'A' ? at(column.indexes[0]) : at(last);

        return value === null ? null : Number(value);
    }

    if (line.kind === 'threshold') {
        const value = at(column.indexes[0]);

        return value === null ? null : Number(value);
    }

    if (column.kind === 'past' && source === undefined) {
        return line.kind === 'reference' ? null : 0;
    }

    return column.indexes.reduce((sum, i) => sum + Number(at(i) ?? 0), 0);
}

function sumOver(
    line: ReportLine,
    columns: Column[],
    past: PastWeeks | null,
): number | null {
    if (!['value', 'subtotal', 'total', 'reference'].includes(line.kind)) {
        return null;
    }

    let total = 0;
    let any = false;

    columns.forEach((column) => {
        const value = cellValue(line, column, past);

        if (typeof value === 'number') {
            total += value;
            any = true;
        }
    });

    return any ? total : null;
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
 * The report on one timeline: the past weeks as OMC recorded them, then the
 * forecast, every line on its section. Scenario lines fade out when the
 * scenario is off. In the weekly view a forecast receipt, product payment or
 * OPEX cell can be set by hand; the cells set that way are marked.
 */
export default function WeeklyTable({
    report,
    past,
    pastWeeks,
    horizon,
    monthly,
    scenarioOn,
    onOverride,
    onDrill,
}: {
    report: DerivedReport;
    /** Actual flows on the report's lines; null in an older snapshot. */
    past: PastWeeks | null;
    /** Full past weeks to show before the current one. */
    pastWeeks: number;
    horizon: number;
    monthly: boolean;
    scenarioOn: boolean;
    /** Absent keeps the grid read-only. */
    onOverride?: (
        line: ReportLine,
        week: string,
        amount: number | null,
    ) => void;
    /** Opens the documents behind a forecast cell, for the lines that have them. */
    onDrill?: (line: ReportLine, week: string, value: number) => void;
}) {
    const [editing, setEditing] = useState<{
        code: string;
        index: number;
    } | null>(null);
    const scroller = useRef<HTMLDivElement>(null);
    const firstFuture = useRef<HTMLTableCellElement>(null);

    const pastColumns = useMemo<Column[]>(() => {
        if (!past) {
            return [];
        }

        const count = past.weeks.length;
        const from = Math.max(0, count - (pastWeeks + 1));
        const indexes = past.weeks.map((_, i) => i).slice(from);

        if (monthly) {
            return monthGroups(indexes.map((i) => past.weeks[i])).map(
                (group) => ({
                    key: `past-${group.key}`,
                    label: group.indexes.some((j) => indexes[j] === count - 1)
                        ? `${group.label} până ieri`
                        : group.label,
                    kind: 'past' as const,
                    indexes: group.indexes.map((j) => indexes[j]),
                    partial: group.indexes.some(
                        (j) => indexes[j] === count - 1,
                    ),
                }),
            );
        }

        return indexes.map((i) => ({
            key: `past-${past.weeks[i]}`,
            label:
                i === count - 1
                    ? `${weekLabel(past.weeks[i])} până ieri`
                    : weekLabel(past.weeks[i], true),
            kind: 'past' as const,
            indexes: [i],
            partial: i === count - 1,
        }));
    }, [past, pastWeeks, monthly]);

    const futureColumns = useMemo<Column[]>(() => {
        const weeks = report.weeks.slice(0, horizon);

        if (monthly) {
            return monthGroups(weeks).map((group) => ({
                key: group.key,
                label: group.label,
                kind: 'future' as const,
                indexes: group.indexes,
            }));
        }

        return weeks.map((week, i) => ({
            key: week,
            label: `S+${i + 1} ${weekLabel(week)}${i === 0 && past ? ' rest' : ''}`,
            kind: 'future' as const,
            indexes: [i],
        }));
    }, [report.weeks, horizon, monthly, past]);

    const columns = useMemo(
        () => [...pastColumns, ...futureColumns],
        [pastColumns, futureColumns],
    );

    // Open on today: the last past weeks, then the forecast.
    useEffect(() => {
        const element = scroller.current;
        const boundary = firstFuture.current;

        if (element && boundary) {
            element.scrollLeft = Math.max(
                0,
                boundary.offsetLeft - element.clientWidth / 2,
            );
        }
    }, [pastColumns.length]);

    const sections = useMemo(() => {
        const order: ReportLine['section'][] = ['A', 'B', 'C', 'D', 'E', 'F'];

        return order.map((section) => ({
            section,
            lines: report.lines.filter((line) => line.section === section),
        }));
    }, [report.lines]);

    const edge = (column: Column) =>
        column.kind === 'future' &&
        column === futureColumns[0] &&
        pastColumns.length > 0 &&
        'border-l-2 border-l-primary/40';

    return (
        <div
            ref={scroller}
            className="max-h-[70vh] overflow-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <table className="min-w-max text-xs">
                <thead className="sticky top-0 z-20 bg-muted text-muted-foreground uppercase">
                    {pastColumns.length > 0 && (
                        <tr className="text-[10px] tracking-wide">
                            <th
                                className="sticky left-0 z-30 bg-muted"
                                colSpan={1}
                            />
                            <th className="bg-muted" colSpan={2} />
                            <th
                                className="bg-sky-100/70 px-2 py-1 text-left text-sky-900 dark:bg-sky-500/15 dark:text-sky-200"
                                colSpan={pastColumns.length}
                            >
                                Efectiv (OMC, până ieri)
                            </th>
                            <th
                                className="border-l-2 border-l-primary/40 bg-muted px-2 py-1 text-left"
                                colSpan={futureColumns.length}
                            >
                                Prognoză
                            </th>
                        </tr>
                    )}
                    <tr>
                        <th className="sticky left-0 z-30 min-w-[320px] bg-muted px-3 py-2 text-left">
                            Linie
                        </th>
                        <th className="bg-muted px-2 py-2 text-right whitespace-nowrap">
                            {pastColumns.length > 0
                                ? `Efectiv ${pastWeeks} săpt.`
                                : ''}
                        </th>
                        <th className="bg-muted px-2 py-2 text-right whitespace-nowrap">
                            Prognoză {horizon} săpt.
                        </th>
                        {columns.map((column) => (
                            <th
                                key={column.key}
                                ref={
                                    column === futureColumns[0]
                                        ? firstFuture
                                        : undefined
                                }
                                className={cn(
                                    'bg-muted px-2 py-2 text-right whitespace-nowrap',
                                    column.kind === 'past' &&
                                        'bg-sky-50 dark:bg-sky-500/10',
                                    column.partial && 'italic',
                                    edge(column),
                                )}
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
                                    colSpan={columns.length + 3}
                                >
                                    {SECTION_TITLES[section]}
                                </td>
                            </tr>
                            {lines.map((line) => {
                                const dim = line.scenario && !scenarioOn;
                                // The past total leaves out the current, partial week.
                                const pastTotal = sumOver(
                                    line,
                                    pastColumns.filter(
                                        (column) => !column.partial,
                                    ),
                                    past,
                                );
                                const futureTotal = sumOver(
                                    line,
                                    futureColumns,
                                    past,
                                );

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
                                        )}
                                    >
                                        <td
                                            className={cn(
                                                'sticky left-0 z-10 bg-background px-3 py-1.5',
                                                line.kind === 'total' &&
                                                    'bg-muted/30',
                                                line.parent && 'pl-8',
                                                dim &&
                                                    'text-muted-foreground/60 line-through decoration-muted-foreground/40',
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
                                        <td
                                            className={cn(
                                                'bg-sky-50/40 px-2 py-1.5 text-right tabular-nums dark:bg-sky-500/5',
                                                pastTotal !== null &&
                                                    pastTotal < 0 &&
                                                    'text-destructive',
                                            )}
                                        >
                                            {pastTotal !== null &&
                                            pastColumns.length > 0 &&
                                            line.section !== 'F'
                                                ? fmtRon(pastTotal)
                                                : ''}
                                        </td>
                                        <td
                                            className={cn(
                                                'px-2 py-1.5 text-right tabular-nums',
                                                dim &&
                                                    'text-muted-foreground/60 line-through decoration-muted-foreground/40',
                                            )}
                                        >
                                            {futureTotal !== null
                                                ? fmtRon(futureTotal)
                                                : ''}
                                        </td>
                                        {columns.map((column) => {
                                            const isPast =
                                                column.kind === 'past';
                                            const value =
                                                isPast && line.section === 'F'
                                                    ? null
                                                    : cellValue(
                                                          line,
                                                          column,
                                                          past,
                                                      );
                                            const manual = isPast
                                                ? []
                                                : column.indexes
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
                                                              cell !==
                                                              undefined,
                                                      );
                                            const index = column.indexes[0];
                                            const editable =
                                                !isPast &&
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
                                                        'group px-2 py-1.5 text-right whitespace-nowrap tabular-nums',
                                                        isPast &&
                                                            'bg-sky-50/40 dark:bg-sky-500/5',
                                                        column.partial &&
                                                            'italic',
                                                        edge(column),
                                                        typeof value ===
                                                            'number' &&
                                                            value < 0 &&
                                                            'text-destructive',
                                                        line.kind === 'text' &&
                                                            typeof value ===
                                                                'string' &&
                                                            signalClass(value),
                                                        line.kind === 'text' &&
                                                            'text-[10px] font-normal',
                                                        !isPast &&
                                                            dim &&
                                                            'text-muted-foreground/60 line-through decoration-muted-foreground/40',
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
                                                            {onDrill &&
                                                                !isPast &&
                                                                !monthly &&
                                                                DRILLABLE.has(
                                                                    line.code,
                                                                ) &&
                                                                typeof value ===
                                                                    'number' &&
                                                                value !== 0 && (
                                                                    <button
                                                                        type="button"
                                                                        title="Vezi facturile"
                                                                        aria-label="Vezi facturile"
                                                                        className="mr-1 inline-flex align-middle text-muted-foreground opacity-40 group-hover:opacity-100 hover:text-foreground"
                                                                        onClick={(
                                                                            event,
                                                                        ) => {
                                                                            event.stopPropagation();
                                                                            onDrill(
                                                                                line,
                                                                                report
                                                                                    .weeks[
                                                                                    index
                                                                                ],
                                                                                value,
                                                                            );
                                                                        }}
                                                                    >
                                                                        <Search className="size-3" />
                                                                    </button>
                                                                )}
                                                            {manual.length >
                                                                0 && (
                                                                <span
                                                                    aria-label="setat manual"
                                                                    className="mr-1 inline-block size-1.5 rounded-full bg-amber-500 align-middle"
                                                                />
                                                            )}
                                                            {value === null
                                                                ? ''
                                                                : typeof value ===
                                                                    'number'
                                                                  ? value ===
                                                                        0 &&
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
