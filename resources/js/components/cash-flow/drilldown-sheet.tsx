import {
    ArrowLeft,
    ChevronDown,
    ChevronRight,
    ExternalLink,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import CashFlowReportController from '@/actions/App/Http/Controllers/CashFlowReportController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import WorkflowStatusBadge from '@/components/workflow-status-badge';
import { cn } from '@/lib/utils';
import type { WorkflowStatus } from '@/types/approvals';
import type { PastWeeks, ReportLine, ReportPayload } from '@/types/cash-flow';
import { PAST_ONLY, fmtRon, lineByCode, weekLabel } from './report-math';
import type { DerivedReport } from './report-math';
import { cellValue } from './weekly-table';
import type { Cell, Column } from './weekly-table';

export type DrillTarget = { line: ReportLine; column: Column; value: Cell };

type Expand =
    | {
          kind: 'omc';
          direction: 'in' | 'out';
          week: string;
          partner: string;
          coresp: string;
          until: string;
      }
    | {
          kind: 'etrip_receipts';
          week: string;
          connection: string;
          segment: string;
          until: string;
      };

type Piece = {
    id: number;
    week: string;
    kind: string;
    group: string | null;
    label: string;
    reference: string | null;
    date: string | null;
    currency: string | null;
    amount: number | null;
    lei: number;
    note: string | null;
    link: { href: string; label: string } | null;
    status: WorkflowStatus | null;
    department: string | null;
    expand: Expand | null;
};

type CellDetail = {
    available: boolean;
    total: number;
    count: number;
    groups: { label: string | null; lei: number; count: number }[];
    by_currency: { currency: string; amount: number; lei: number }[];
    rows: Piece[];
    explanation: string;
};

type Part = { line: ReportLine; column: Column; sign: 1 | -1; value: Cell };

type Formula = { parts: Part[]; note?: string; extra?: ReactNode };

const num = (value: Cell): number =>
    typeof value === 'number' ? value : Number(value ?? 0) || 0;

function dmy(value: string | null): string {
    if (!value) {
        return '—';
    }

    const [year, month, day] = value.slice(0, 10).split('-');

    return `${day}.${month}.${year}`;
}

function money(value: number | null, currency?: string | null): string {
    if (value === null) {
        return '—';
    }

    return `${new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 2, minimumFractionDigits: 2 }).format(value)}${currency ? ` ${currency}` : ''}`;
}

async function getJson<T>(url: string): Promise<T> {
    const res = await fetch(url, { headers: { Accept: 'application/json' } });

    if (!res.ok) {
        const body = (await res.json().catch(() => null)) as {
            message?: string;
        } | null;

        throw new Error(body?.message ?? 'Detaliul nu a putut fi încărcat.');
    }

    return (await res.json()) as T;
}

/**
 * What a cell of the report is made of. A flow line lists the pieces the
 * build laid on it (booking tranches, supplier services, rotations,
 * invoices, payments), which add up to the cell; a computed line shows its
 * formula, each part opening in turn.
 */
export default function DrilldownSheet({
    target,
    report,
    past,
    payload,
    snapshotId,
    scenarioOn,
    onClose,
}: {
    target: DrillTarget | null;
    report: DerivedReport;
    past: PastWeeks | null;
    payload: ReportPayload;
    snapshotId: number;
    scenarioOn: boolean;
    onClose: () => void;
}) {
    const [stack, setStack] = useState<DrillTarget[]>([]);
    const [base, setBase] = useState(target);

    // A new cell from the table starts a new trail.
    if (target !== base) {
        setBase(target);
        setStack([]);
    }

    const current = stack.at(-1) ?? target;

    return (
        <Sheet
            open={target !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <SheetContent className="w-full overflow-y-auto sm:max-w-4xl">
                {current && (
                    <>
                        <SheetHeader>
                            {stack.length > 0 && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="-ml-2 w-fit"
                                    onClick={() => setStack(stack.slice(0, -1))}
                                >
                                    <ArrowLeft />
                                    Înapoi
                                </Button>
                            )}
                            <SheetTitle className="pr-6">
                                <span className="mr-2 font-mono text-sm text-muted-foreground">
                                    {current.line.code}
                                </span>
                                {current.line.label}
                            </SheetTitle>
                            <SheetDescription>
                                {current.column.kind === 'past'
                                    ? 'Efectiv'
                                    : 'Prognoză'}{' '}
                                · {current.column.label} ·{' '}
                                <b className="text-foreground">
                                    {typeof current.value === 'number'
                                        ? `${fmtRon(current.value)} RON`
                                        : (current.value ?? '—')}
                                </b>
                            </SheetDescription>
                        </SheetHeader>

                        <div className="grid gap-4 px-4 pb-8 text-sm">
                            {current.line.kind === 'value' ? (
                                <PiecesView
                                    key={`${current.line.code}|${current.column.key}`}
                                    target={current}
                                    report={report}
                                    past={past}
                                    snapshotId={snapshotId}
                                    scenarioOn={scenarioOn}
                                />
                            ) : (
                                <FormulaView
                                    key={`${current.line.code}|${current.column.key}`}
                                    formula={formulaOf(current, {
                                        report,
                                        past,
                                        payload,
                                        scenarioOn,
                                    })}
                                    onOpen={(next) =>
                                        setStack([...stack, next])
                                    }
                                />
                            )}
                        </div>
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}

function weeksOf(
    column: Column,
    report: DerivedReport,
    past: PastWeeks | null,
): string[] {
    return column.kind === 'past'
        ? column.indexes.map((i) => past?.weeks[i] ?? '').filter(Boolean)
        : column.indexes.map((i) => report.weeks[i]);
}

function PiecesView({
    target,
    report,
    past,
    snapshotId,
    scenarioOn,
}: {
    target: DrillTarget;
    report: DerivedReport;
    past: PastWeeks | null;
    snapshotId: number;
    scenarioOn: boolean;
}) {
    const [data, setData] = useState<CellDetail | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [group, setGroup] = useState<string | null>(null);
    const [search, setSearch] = useState('');
    const weeks = weeksOf(target.column, report, past);
    const actual = target.column.kind === 'past';

    useEffect(() => {
        let cancelled = false;

        getJson<CellDetail>(
            CashFlowReportController.drilldown({
                query: {
                    snapshot: String(snapshotId),
                    line: target.line.code,
                    weeks,
                    actual: actual ? '1' : '0',
                },
            }).url,
        )
            .then((payload) => !cancelled && setData(payload))
            .catch((e: Error) => !cancelled && setError(e.message));

        return () => {
            cancelled = true;
        };
        // Keyed on the cell by the parent: it loads once per cell.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const rows = useMemo(() => {
        const term = search.trim().toLowerCase();

        return (data?.rows ?? []).filter(
            (row) =>
                (group === null || (row.group ?? '') === group) &&
                (term === '' ||
                    `${row.label} ${row.reference ?? ''} ${row.note ?? ''}`
                        .toLowerCase()
                        .includes(term)),
        );
    }, [data, group, search]);

    if (error) {
        return <p className="text-destructive">{error}</p>;
    }

    if (!data) {
        return (
            <div className="grid gap-2">
                {[0, 1, 2, 3].map((i) => (
                    <div
                        key={i}
                        className="h-10 animate-pulse rounded-md bg-muted"
                    />
                ))}
            </div>
        );
    }

    if (!data.available) {
        return (
            <p className="rounded-lg bg-muted/50 p-3 text-muted-foreground">
                Raportul afișat a fost calculat înainte ca aplicația să păstreze
                detaliul fiecărei celule. Recalculează raportul și celula se va
                putea deschide.
            </p>
        );
    }

    const overridden = actual
        ? []
        : target.column.indexes
              .map((i) => report.overridden[target.line.code]?.[i])
              .filter((cell) => cell !== undefined);
    const manual = overridden.reduce((sum, cell) => sum + cell.amount, 0);
    const auto = overridden.reduce((sum, cell) => sum + cell.auto, 0);
    // What the automation put in the cell, before any value set by hand.
    const automated = num(target.value) - manual + auto;
    const multiWeek = weeks.length > 1;

    return (
        <>
            <div className="grid gap-1 rounded-lg bg-muted/40 p-3">
                <div className="flex justify-between gap-3">
                    <span>Suma celor {data.count} elemente de mai jos</span>
                    <span className="font-semibold tabular-nums">
                        {fmtRon(data.total)} RON
                    </span>
                </div>
                {Math.abs(data.total - automated) > 1 && (
                    <div className="flex justify-between gap-3 text-muted-foreground">
                        <span>Valoarea calculată în raport</span>
                        <span className="tabular-nums">
                            {fmtRon(automated)} RON
                        </span>
                    </div>
                )}
                {overridden.length > 0 && (
                    <p className="rounded-md bg-amber-100/70 p-2 text-xs text-amber-900 dark:bg-amber-500/15 dark:text-amber-200">
                        Setată manual la {fmtRon(manual)} RON
                        {overridden[0].updated_by &&
                            ` de ${overridden[0].updated_by}`}
                        {overridden[0].note && ` (${overridden[0].note})`};
                        elementele de mai jos explică valoarea automată,{' '}
                        {fmtRon(auto)} RON.
                    </p>
                )}
                {target.line.scenario && !scenarioOn && (
                    <p className="text-xs text-muted-foreground">
                        Scenariul este oprit: linia nu intră acum în totaluri.
                    </p>
                )}
                {(data.explanation || target.line.note) && (
                    <p className="text-xs text-muted-foreground">
                        {data.explanation || target.line.note}
                    </p>
                )}
                {data.by_currency.length > 1 && (
                    <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs">
                        {data.by_currency.map((row) => (
                            <span key={row.currency}>
                                {money(row.amount, row.currency)} →{' '}
                                {fmtRon(row.lei)} RON
                            </span>
                        ))}
                    </div>
                )}
            </div>

            {(data.groups.length > 1 || data.rows.length > 12) && (
                <div className="flex flex-wrap items-center gap-1.5">
                    {data.groups.length > 1 &&
                        data.groups.map((row) => (
                            <Button
                                key={row.label ?? ''}
                                size="sm"
                                variant={
                                    group === (row.label ?? '')
                                        ? 'secondary'
                                        : 'outline'
                                }
                                className="h-7 text-xs"
                                onClick={() =>
                                    setGroup(
                                        group === (row.label ?? '')
                                            ? null
                                            : (row.label ?? ''),
                                    )
                                }
                            >
                                {row.label ?? 'altele'}
                                <span className="text-muted-foreground tabular-nums">
                                    {fmtRon(row.lei)} · {row.count}
                                </span>
                            </Button>
                        ))}
                    {data.rows.length > 12 && (
                        <Input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Caută (nume, dosar, factură)"
                            className="ml-auto h-7 w-56 text-xs"
                        />
                    )}
                </div>
            )}

            {data.count === 0 ? (
                <p className="text-muted-foreground">
                    Nimic pe această linie în perioada aleasă.
                </p>
            ) : (
                <div className="overflow-x-auto rounded-lg border border-sidebar-border/70 dark:border-sidebar-border">
                    <table className="w-full text-xs">
                        <thead className="bg-muted/50 text-left text-muted-foreground">
                            <tr>
                                <th className="px-2 py-1.5">Ce este</th>
                                <th className="px-2 py-1.5">
                                    {multiWeek ? 'Data · săpt.' : 'Data'}
                                </th>
                                <th className="px-2 py-1.5 text-right">Sumă</th>
                                <th className="px-2 py-1.5 text-right">
                                    În celulă (RON)
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                            {rows.map((row) => (
                                <PieceRow
                                    key={row.id}
                                    row={row}
                                    multiWeek={multiWeek}
                                />
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            {data.count > data.rows.length && (
                <p className="text-xs text-muted-foreground">
                    Se afișează cele mai mari {data.rows.length} din{' '}
                    {data.count}; totalul de sus le cuprinde pe toate.
                </p>
            )}
        </>
    );
}

function PieceRow({ row, multiWeek }: { row: Piece; multiWeek: boolean }) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <tr className="align-top">
                <td className="px-2 py-1.5">
                    <div className="flex items-start gap-1">
                        {row.expand && (
                            <button
                                type="button"
                                className="mt-0.5 text-muted-foreground hover:text-foreground"
                                aria-label={
                                    open
                                        ? 'Ascunde documentele'
                                        : 'Arată documentele'
                                }
                                title="Documentele din spatele sumei"
                                onClick={() => setOpen(!open)}
                            >
                                {open ? (
                                    <ChevronDown className="size-3.5" />
                                ) : (
                                    <ChevronRight className="size-3.5" />
                                )}
                            </button>
                        )}
                        <div className="min-w-0">
                            <span className="font-medium">{row.label}</span>
                            {row.reference && (
                                <span className="ml-1.5 text-muted-foreground">
                                    {row.reference}
                                </span>
                            )}
                            {row.link && (
                                <a
                                    href={row.link.href}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="ml-1.5 inline-flex items-center gap-0.5 text-primary hover:underline"
                                >
                                    {row.link.label}
                                    <ExternalLink className="size-3" />
                                </a>
                            )}
                            {row.note && (
                                <div className="text-muted-foreground">
                                    {row.note}
                                </div>
                            )}
                            {(row.department || row.status) && (
                                <div className="mt-0.5 flex flex-wrap items-center gap-1">
                                    {row.department && (
                                        <Badge
                                            variant="outline"
                                            className="text-[10px]"
                                        >
                                            {row.department}
                                        </Badge>
                                    )}
                                    {row.status && (
                                        <WorkflowStatusBadge
                                            status={row.status}
                                            className="text-[10px]"
                                        />
                                    )}
                                </div>
                            )}
                        </div>
                    </div>
                </td>
                <td className="px-2 py-1.5 whitespace-nowrap">
                    {row.date ? dmy(row.date) : ''}
                    {multiWeek && (
                        <div className="text-muted-foreground">
                            săpt. {weekLabel(row.week)}
                        </div>
                    )}
                </td>
                <td className="px-2 py-1.5 text-right whitespace-nowrap tabular-nums">
                    {row.amount !== null && row.currency
                        ? money(row.amount, row.currency)
                        : ''}
                </td>
                <td
                    className={cn(
                        'px-2 py-1.5 text-right whitespace-nowrap tabular-nums',
                        row.lei < 0 && 'text-destructive',
                    )}
                >
                    {fmtRon(row.lei)}
                </td>
            </tr>
            {open && row.expand && (
                <tr>
                    <td colSpan={4} className="bg-muted/30 px-2 py-2">
                        <Documents expand={row.expand} lei={row.lei} />
                    </td>
                </tr>
            )}
        </>
    );
}

type OmcDocument = {
    data_doc: string;
    tip_doc: string;
    nr_doc: string;
    currency: string;
    amount: number;
    lei: number;
    note: string | null;
};

type EtripReceipt = {
    receipt: string;
    issue_date: string;
    booking: number;
    client: string | null;
    currency: string;
    amount: number;
    lei: number;
};

/** The documents behind an aggregated piece, read live. */
function Documents({ expand, lei }: { expand: Expand; lei: number }) {
    const [rows, setRows] = useState<(OmcDocument | EtripReceipt)[] | null>(
        null,
    );
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        let cancelled = false;

        getJson<{ rows: (OmcDocument | EtripReceipt)[] }>(
            CashFlowReportController.documents({ query: { ...expand } }).url,
        )
            .then((data) => !cancelled && setRows(data.rows))
            .catch((e: Error) => !cancelled && setError(e.message));

        return () => {
            cancelled = true;
        };
    }, [expand]);

    if (error) {
        return <p className="text-destructive">{error}</p>;
    }

    if (!rows) {
        return (
            <p className="animate-pulse text-muted-foreground">
                Se citesc documentele…
            </p>
        );
    }

    const total = rows.reduce((sum, row) => sum + row.lei, 0);

    return (
        <div className="grid gap-1">
            <p className="text-muted-foreground">
                {expand.kind === 'omc'
                    ? `Documentele de bancă și casă din OMC (${rows.length})`
                    : `Încasările eTrip pe dosarele segmentului (${rows.length})`}
                , citite acum: {fmtRon(total)} RON
                {Math.abs(Math.abs(total) - Math.abs(lei)) > 1 &&
                    ' (diferă puțin de raport: documente înregistrate sau modificate după calcul)'}
                .
            </p>
            <div className="max-h-72 overflow-auto">
                <table className="w-full">
                    <tbody className="divide-y divide-sidebar-border/50">
                        {rows.map((row, index) =>
                            'tip_doc' in row ? (
                                <tr key={index}>
                                    <td className="py-1 pr-2 whitespace-nowrap">
                                        {dmy(row.data_doc)}
                                    </td>
                                    <td className="py-1 pr-2 whitespace-nowrap">
                                        {row.tip_doc} {row.nr_doc}
                                    </td>
                                    <td className="py-1 pr-2 text-muted-foreground">
                                        {row.note}
                                    </td>
                                    <td className="py-1 pr-2 text-right whitespace-nowrap tabular-nums">
                                        {money(row.amount, row.currency)}
                                    </td>
                                    <td className="py-1 text-right whitespace-nowrap tabular-nums">
                                        {fmtRon(row.lei)}
                                    </td>
                                </tr>
                            ) : (
                                <tr key={index}>
                                    <td className="py-1 pr-2 whitespace-nowrap">
                                        {dmy(row.issue_date)}
                                    </td>
                                    <td className="py-1 pr-2 whitespace-nowrap">
                                        {row.receipt} · dosar {row.booking}
                                    </td>
                                    <td className="py-1 pr-2 text-muted-foreground">
                                        {row.client}
                                    </td>
                                    <td className="py-1 pr-2 text-right whitespace-nowrap tabular-nums">
                                        {money(row.amount, row.currency)}
                                    </td>
                                    <td className="py-1 text-right whitespace-nowrap tabular-nums">
                                        {fmtRon(row.lei)}
                                    </td>
                                </tr>
                            ),
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

type FormulaContext = {
    report: DerivedReport;
    past: PastWeeks | null;
    payload: ReportPayload;
    scenarioOn: boolean;
};

function futureColumn(report: DerivedReport, index: number): Column {
    return {
        key: `drill-${report.weeks[index]}`,
        label: `S+${index + 1} ${weekLabel(report.weeks[index])}`,
        kind: 'future',
        indexes: [index],
    };
}

function pastColumn(past: PastWeeks, index: number): Column {
    return {
        key: `drill-past-${past.weeks[index]}`,
        label: weekLabel(past.weeks[index], true),
        kind: 'past',
        indexes: [index],
        partial: index === past.weeks.length - 1,
    };
}

/**
 * How a computed line is worked out for a column, as parts that open.
 */
function formulaOf(target: DrillTarget, ctx: FormulaContext): Formula {
    const { report, past, payload, scenarioOn } = ctx;
    const { line, column } = target;
    const isPast = column.kind === 'past';
    const lines = report.lines;
    const part = (
        code: string,
        sign: 1 | -1 = 1,
        at: Column = column,
    ): Part | null => {
        const found = lineByCode(lines, code);

        return found
            ? {
                  line: found,
                  column: at,
                  sign,
                  value: cellValue(found, at, past),
              }
            : null;
    };
    const parts = (codes: [string, 1 | -1][]) =>
        codes
            .map(([code, sign]) => part(code, sign))
            .filter((p): p is Part => p !== null);
    const minimum = Number(payload.params.thresholds?.minimum ?? 0);
    const comfort = Number(payload.params.thresholds?.comfort ?? 0);

    if (line.kind === 'total' && ['B', 'C', 'D'].includes(line.code)) {
        const members = lines.filter(
            (l) =>
                l.section === line.section &&
                l.kind === 'value' &&
                (isPast
                    ? past?.lines[l.code] !== undefined
                    : !PAST_ONLY.has(l.code) && (scenarioOn || !l.scenario)),
        );

        return {
            parts: members.map((l) => ({
                line: l,
                column,
                sign: 1,
                value: cellValue(l, column, past),
            })),
            note:
                !isPast &&
                !scenarioOn &&
                lines.some((l) => l.section === line.section && l.scenario)
                    ? 'Liniile de scenariu nu intră: scenariul este oprit.'
                    : undefined,
        };
    }

    if (line.kind === 'subtotal') {
        return {
            parts: lines
                .filter((l) => l.parent === line.code && l.kind === 'value')
                .map((l) => ({
                    line: l,
                    column,
                    sign: 1,
                    value: cellValue(l, column, past),
                })),
        };
    }

    switch (line.code) {
        case 'E1':
            return {
                parts: parts([
                    ['B', 1],
                    ['C', -1],
                    ['D', -1],
                ]),
            };
        case 'E2':
            return {
                parts: parts(
                    isPast
                        ? [
                              ['A', 1],
                              ['E1', 1],
                              ['EA', 1],
                          ]
                        : [
                              ['A', 1],
                              ['E1', 1],
                          ],
                ),
                note: isPast
                    ? 'Soldul efectiv: reconstituit din soldurile OMC de sfârșit de lună și fluxurile săptămânilor dintre ele; ajustarea EA închide diferența.'
                    : undefined,
            };
        case 'EA':
            return {
                parts: parts([
                    ['E2', 1],
                    ['A', -1],
                    ['E1', -1],
                ]),
                note: 'Ce nu explică documentele de bancă și casă până la soldul OMC: diferențe de curs la reevaluare, dobânzi, finanțări, decalaje de înregistrare.',
            };
        case 'E3':
            return {
                parts: [],
                note: `Pragul minim de siguranță din parametri: ${fmtRon(minimum)} RON.`,
            };
        case 'E4':
            return {
                parts: parts([
                    ['E2', 1],
                    ['E3', -1],
                ]),
            };
        case 'E5':
            return {
                parts: parts([['E2', 1]]),
                note: `DEFICIT când soldul final e sub pragul minim (${fmtRon(minimum)} RON), ATENȚIE sub pragul de confort (${fmtRon(comfort)} RON), altfel OK.`,
            };
        case 'E6':
            return {
                parts: [],
                note: isPast
                    ? 'Săptămână trecută: fluxurile efective din OMC și eTrip.'
                    : 'Săptămânile cu încasări sau plăți din rezervări existente, charter sau facturi sunt acoperite de date; după ele rămâne doar scenariul de vânzări noi.',
            };
    }

    if (line.code === 'A') {
        const first = column.indexes[0];

        if (!isPast && first === 0) {
            return {
                parts: [],
                note: 'Poziția de trezorerie la sfârșitul zilei de ieri, din OMC.',
                extra: <Opening payload={payload} />,
            };
        }

        if (isPast && past) {
            return first > 0
                ? {
                      parts: [
                          part('E2', 1, pastColumn(past, first - 1)),
                      ].filter((p): p is Part => p !== null),
                      note: 'Soldul final al săptămânii anterioare.',
                  }
                : {
                      parts: [],
                      note: 'Soldul OMC reconstituit la începutul perioadei afișate.',
                  };
        }

        return {
            parts: [part('E2', 1, futureColumn(report, first - 1))].filter(
                (p): p is Part => p !== null,
            ),
            note: 'Soldul final al săptămânii anterioare.',
        };
    }

    if (line.section === 'F') {
        const index = column.indexes[0];
        const lyWeek = payload.lastyear[index]?.ly_week ?? null;
        const k = lyWeek && past ? past.weeks.indexOf(lyWeek) : -1;
        const at = k >= 0 && past ? pastColumn(past, k) : null;

        return {
            parts: at
                ? ['B', 'C', 'D']
                      .map((code) => part(code, 1, at))
                      .filter((p): p is Part => p !== null)
                : [],
            note: lyWeek
                ? `Aceeași săptămână a anului anterior (${weekLabel(lyWeek, true)}), din documentele de bancă și casă OMC.${at ? ' Aceeași săptămână pe liniile raportului:' : ''}`
                : 'Aceeași săptămână a anului anterior, din OMC.',
        };
    }

    return { parts: [], note: line.note ?? undefined };
}

function FormulaView({
    formula,
    onOpen,
}: {
    formula: Formula;
    onOpen: (target: DrillTarget) => void;
}) {
    const [showZero, setShowZero] = useState(false);
    const visible = formula.parts.filter(
        (p) => showZero || num(p.value) !== 0 || p.line.kind !== 'value',
    );
    const hidden = formula.parts.length - visible.length;
    const total = formula.parts.reduce(
        (sum, p) => sum + p.sign * num(p.value),
        0,
    );

    return (
        <>
            {formula.note && (
                <p className="text-muted-foreground">{formula.note}</p>
            )}
            {formula.extra}
            {formula.parts.length > 0 && (
                <div className="overflow-hidden rounded-lg border border-sidebar-border/70 dark:border-sidebar-border">
                    <table className="w-full text-xs">
                        <tbody className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                            {visible.map((p) => (
                                <tr
                                    key={`${p.line.code}-${p.column.key}`}
                                    className="cursor-pointer hover:bg-muted/50"
                                    onClick={() =>
                                        onOpen({
                                            line: p.line,
                                            column: p.column,
                                            value: p.value,
                                        })
                                    }
                                >
                                    <td className="w-6 px-2 py-1.5 text-center font-mono text-muted-foreground">
                                        {p.sign < 0 ? '−' : '+'}
                                    </td>
                                    <td className="px-2 py-1.5">
                                        <span className="mr-1.5 font-mono text-[10px] text-muted-foreground">
                                            {p.line.code}
                                        </span>
                                        {p.line.label}
                                        {p.column.label && (
                                            <span className="ml-1.5 text-muted-foreground">
                                                ({p.column.label})
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-2 py-1.5 text-right whitespace-nowrap tabular-nums">
                                        {typeof p.value === 'number'
                                            ? fmtRon(p.value)
                                            : p.value}
                                    </td>
                                    <td className="w-6 pr-2 text-muted-foreground">
                                        <ChevronRight className="size-3.5" />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        {formula.parts.length > 1 && (
                            <tfoot>
                                <tr className="bg-muted/40 font-semibold">
                                    <td />
                                    <td className="px-2 py-1.5">= Total</td>
                                    <td className="px-2 py-1.5 text-right tabular-nums">
                                        {fmtRon(total)}
                                    </td>
                                    <td />
                                </tr>
                            </tfoot>
                        )}
                    </table>
                </div>
            )}
            {hidden > 0 && (
                <Button
                    variant="link"
                    size="sm"
                    className="h-auto w-fit p-0 text-xs"
                    onClick={() => setShowZero(true)}
                >
                    + {hidden} linii fără valoare
                </Button>
            )}
        </>
    );
}

function Opening({ payload }: { payload: ReportPayload }) {
    const opening = payload.opening;

    if (!opening?.rows?.length) {
        return null;
    }

    return (
        <div className="overflow-x-auto rounded-lg border border-sidebar-border/70 dark:border-sidebar-border">
            <table className="w-full text-xs">
                <thead className="bg-muted/50 text-muted-foreground">
                    <tr>
                        <th className="px-2 py-1.5 text-left" />
                        {opening.currencies.map((currency) => (
                            <th
                                key={currency}
                                className="px-2 py-1.5 text-right"
                            >
                                {currency}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                    {opening.rows.map((row) => (
                        <tr
                            key={row.key}
                            className={cn(
                                (row.key.endsWith('_now') ||
                                    row.key === 'position') &&
                                    'bg-muted/30 font-semibold',
                            )}
                        >
                            <td className="px-2 py-1.5">{row.label}</td>
                            {opening.currencies.map((currency) => (
                                <td
                                    key={currency}
                                    className="px-2 py-1.5 text-right whitespace-nowrap tabular-nums"
                                >
                                    {fmtRon(row.values[currency] ?? 0)}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
            <p className="px-2 py-1.5 text-xs text-muted-foreground">
                Total în lei: {fmtRon(opening.total)} RON
                {opening.rates &&
                    ` · curs BNR ${Object.entries(opening.rates)
                        .filter(([currency]) => currency !== 'RON')
                        .map(([currency, rate]) => `${currency} ${rate}`)
                        .join(', ')}`}
            </p>
        </div>
    );
}
