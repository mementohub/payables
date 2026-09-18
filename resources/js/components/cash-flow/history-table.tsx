import { Fragment, useEffect, useMemo, useRef, useState } from 'react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';
import type { HistoryRow } from '@/types/cash-flow';
import { fmtCompact, fmtRon, monthGroups, weekLabel } from './report-math';

type Flow = Exclude<keyof HistoryRow, 'week' | 'partial'>;

type Line = {
    code: string;
    label: string;
    field: Flow;
    kind: 'value' | 'total' | 'balance';
    note?: string;
};

const SECTIONS: { title: string; lines: Line[] }[] = [
    {
        title: 'A. Sold inițial de trezorerie',
        lines: [
            {
                code: 'A',
                label: 'Sold inițial (bănci + casierii + depozite)',
                field: 'opening',
                kind: 'balance',
            },
        ],
    },
    {
        title: 'B. Încasări efective',
        lines: [
            {
                code: 'B1',
                label: 'Încasări de la clienți și parteneri',
                field: 'in_partner',
                kind: 'value',
                note: 'OMC: încasări bancă + casă cu partener pe document',
            },
            {
                code: 'B2',
                label: 'Alte încasări (fără partener)',
                field: 'in_other',
                kind: 'value',
                note: 'OMC: încasări fără partener, de ex. dobânzi, restituiri',
            },
            {
                code: 'B',
                label: 'TOTAL ÎNCASĂRI',
                field: 'in',
                kind: 'total',
            },
        ],
    },
    {
        title: 'C. Plăți efective',
        lines: [
            {
                code: 'C1',
                label: 'Plăți către furnizori și parteneri',
                field: 'out_partner',
                kind: 'value',
                note: 'OMC: plăți bancă + casă cu partener pe document (furnizori, restituiri clienți)',
            },
            {
                code: 'C2',
                label: 'Plăți salarii și taxe',
                field: 'out_salaries',
                kind: 'value',
                note: 'OMC: plăți cu cont corespondent 421/425/43x/44x/457/462',
            },
            {
                code: 'C3',
                label: 'Alte plăți',
                field: 'out_other',
                kind: 'value',
            },
            {
                code: 'C',
                label: 'TOTAL PLĂȚI',
                field: 'out',
                kind: 'total',
            },
        ],
    },
    {
        title: 'E. Rezultat',
        lines: [
            {
                code: 'E1',
                label: 'FLUX NET EFECTIV (B − C)',
                field: 'net',
                kind: 'total',
            },
            {
                code: 'E2',
                label: 'Ajustări: curs valutar, dobânzi, diferențe de reconciliere',
                field: 'adjustment',
                kind: 'value',
                note: 'diferența până la soldurile de sfârșit de lună din OMC, repartizată pe săptămâni; sold inițial + flux net + ajustări = sold final',
            },
            {
                code: 'E3',
                label: 'SOLD FINAL DE TREZORERIE',
                field: 'closing',
                kind: 'balance',
            },
        ],
    },
];

type Column = {
    key: string;
    label: string;
    rows: HistoryRow[];
    partial: boolean;
};

function cell(line: Line, rows: HistoryRow[]): number | null {
    if (line.field === 'opening') {
        return rows[0]?.opening ?? null;
    }

    if (line.field === 'closing') {
        return rows[rows.length - 1]?.closing ?? null;
    }

    const values = rows.map((row) => row[line.field]);

    return values.every((value) => value === null)
        ? null
        : values.reduce<number>((sum, value) => sum + (value ?? 0), 0);
}

function Kpi({
    label,
    value,
    hint,
}: {
    label: string;
    value: string;
    hint?: string;
}) {
    return (
        <div className="rounded-xl border border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border">
            <div className="text-xs text-muted-foreground uppercase">
                {label}
            </div>
            <div className="mt-1 text-xl font-semibold tabular-nums">
                {value}
            </div>
            {hint && (
                <div className="text-xs text-muted-foreground">{hint}</div>
            )}
        </div>
    );
}

/**
 * The cash that actually came in and went out in the past weeks, as OMC
 * recorded it, laid out like the forecast: one column per week or month,
 * oldest on the left, the current (partial) week last.
 */
export default function HistoryTable({ history }: { history: HistoryRow[] }) {
    const [span, setSpan] = useState<13 | 26 | 52>(13);
    const [monthly, setMonthly] = useState(false);
    const scroller = useRef<HTMLDivElement>(null);

    const rows = useMemo(() => history.slice(-(span + 1)), [history, span]);
    const full = rows.filter((row) => !row.partial);

    const columns = useMemo<Column[]>(() => {
        if (monthly) {
            return monthGroups(rows.map((row) => row.week)).map((group) => ({
                key: group.key,
                label: group.label,
                rows: group.indexes.map((i) => rows[i]),
                partial: group.indexes.some((i) => rows[i].partial),
            }));
        }

        return rows.map((row) => ({
            key: row.week,
            label: row.partial
                ? `${weekLabel(row.week)} (în curs)`
                : weekLabel(row.week, true),
            rows: [row],
            partial: row.partial,
        }));
    }, [rows, monthly]);

    useEffect(() => {
        const element = scroller.current;

        if (element) {
            element.scrollLeft = element.scrollWidth;
        }
    }, [columns]);

    const total = (field: Flow) =>
        full.reduce((sum, row) => sum + (row[field] ?? 0), 0);
    const first = full[0]?.opening ?? null;
    const last = full[full.length - 1]?.closing ?? null;

    return (
        <Card>
            <CardHeader>
                <CardTitle>Fluxul de trezorerie efectiv</CardTitle>
                <CardDescription>
                    Încasările și plățile înregistrate în OMC (bancă și casă,
                    fără transferurile între conturile proprii), în RON la
                    cursul documentului. Soldul final se sprijină pe soldurile
                    de sfârșit de lună din OMC; săptămâna curentă conține
                    documentele înregistrate până acum.
                </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-4">
                <div className="flex flex-wrap items-center gap-4 text-sm">
                    <div className="flex items-center gap-2">
                        <span className="text-muted-foreground">Perioadă</span>
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            size="sm"
                            value={String(span)}
                            onValueChange={(value) =>
                                value && setSpan(Number(value) as 13 | 26 | 52)
                            }
                        >
                            <ToggleGroupItem value="13">
                                13 săpt.
                            </ToggleGroupItem>
                            <ToggleGroupItem value="26">
                                26 săpt.
                            </ToggleGroupItem>
                            <ToggleGroupItem value="52">
                                52 săpt.
                            </ToggleGroupItem>
                        </ToggleGroup>
                    </div>
                    <div className="flex items-center gap-2">
                        <span className="text-muted-foreground">Tabel</span>
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            size="sm"
                            value={monthly ? 'month' : 'week'}
                            onValueChange={(value) =>
                                value && setMonthly(value === 'month')
                            }
                        >
                            <ToggleGroupItem value="week">
                                săptămânal
                            </ToggleGroupItem>
                            <ToggleGroupItem value="month">
                                lunar
                            </ToggleGroupItem>
                        </ToggleGroup>
                    </div>
                </div>

                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <Kpi
                        label={`Încasări, ultimele ${full.length} săpt.`}
                        value={`${fmtCompact(total('in'))} RON`}
                        hint={`clienți și parteneri ${fmtCompact(total('in_partner'))} RON`}
                    />
                    <Kpi
                        label={`Plăți, ultimele ${full.length} săpt.`}
                        value={`${fmtCompact(total('out'))} RON`}
                        hint={`furnizori ${fmtCompact(total('out_partner'))} · salarii și taxe ${fmtCompact(total('out_salaries'))} RON`}
                    />
                    <Kpi
                        label="Flux net"
                        value={`${fmtCompact(total('net'))} RON`}
                        hint={`medie ${fmtCompact(full.length ? total('net') / full.length : 0)} RON/săpt.`}
                    />
                    <Kpi
                        label="Sold de trezorerie"
                        value={last !== null ? `${fmtCompact(last)} RON` : '–'}
                        hint={
                            first !== null && last !== null
                                ? `de la ${fmtCompact(first)} RON (${last - first >= 0 ? '+' : ''}${fmtCompact(last - first)})`
                                : undefined
                        }
                    />
                </div>

                <div
                    ref={scroller}
                    className="max-h-[70vh] overflow-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
                >
                    <table className="min-w-max text-xs">
                        <thead className="sticky top-0 z-20 bg-muted text-muted-foreground uppercase">
                            <tr>
                                <th className="sticky left-0 z-30 min-w-[320px] bg-muted px-3 py-2 text-left">
                                    Linie
                                </th>
                                <th className="bg-muted px-2 py-2 text-right">
                                    Total {full.length} săpt.
                                </th>
                                {columns.map((column) => (
                                    <th
                                        key={column.key}
                                        className={cn(
                                            'bg-muted px-2 py-2 text-right whitespace-nowrap',
                                            column.partial && 'italic',
                                        )}
                                    >
                                        {column.label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {SECTIONS.map((section) => (
                                <Fragment key={section.title}>
                                    <tr className="bg-muted/60">
                                        <td
                                            className="sticky left-0 z-10 bg-muted/60 px-3 py-1.5 font-semibold backdrop-blur"
                                            colSpan={columns.length + 2}
                                        >
                                            {section.title}
                                        </td>
                                    </tr>
                                    {section.lines.map((line) => {
                                        const sum =
                                            line.kind === 'balance'
                                                ? null
                                                : cell(line, full);

                                        return (
                                            <tr
                                                key={line.code}
                                                className={cn(
                                                    'border-t border-sidebar-border/50',
                                                    line.kind === 'total' &&
                                                        'bg-muted/30 font-semibold',
                                                    line.kind === 'balance' &&
                                                        'font-semibold',
                                                )}
                                            >
                                                <td
                                                    className={cn(
                                                        'sticky left-0 z-10 bg-background px-3 py-1.5',
                                                        line.kind === 'total' &&
                                                            'bg-muted/30',
                                                    )}
                                                    title={line.note}
                                                >
                                                    <span className="mr-2 font-mono text-[10px] text-muted-foreground">
                                                        {line.code}
                                                    </span>
                                                    {line.label}
                                                </td>
                                                <td
                                                    className={cn(
                                                        'px-2 py-1.5 text-right tabular-nums',
                                                        sum !== null &&
                                                            sum < 0 &&
                                                            'text-destructive',
                                                    )}
                                                >
                                                    {sum !== null
                                                        ? fmtRon(sum)
                                                        : ''}
                                                </td>
                                                {columns.map((column) => {
                                                    const value = cell(
                                                        line,
                                                        column.rows,
                                                    );

                                                    return (
                                                        <td
                                                            key={column.key}
                                                            className={cn(
                                                                'px-2 py-1.5 text-right whitespace-nowrap tabular-nums',
                                                                value !==
                                                                    null &&
                                                                    value < 0 &&
                                                                    'text-destructive',
                                                                column.partial &&
                                                                    'bg-muted/20 italic',
                                                            )}
                                                        >
                                                            {value === null
                                                                ? '–'
                                                                : value === 0 &&
                                                                    line.kind ===
                                                                        'value'
                                                                  ? '·'
                                                                  : fmtRon(
                                                                        value,
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
            </CardContent>
        </Card>
    );
}
