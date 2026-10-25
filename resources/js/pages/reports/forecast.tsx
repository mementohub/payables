import { Head, router } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronRight,
    Plus,
    RefreshCw,
    RotateCcw,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { index as forecastIndex } from '@/routes/reports/forecast';

const MONTHS = [
    'ian.', 'feb.', 'mar.', 'apr.', 'mai', 'iun.',
    'iul.', 'aug.', 'sep.', 'oct.', 'nov.', 'dec.',
];

type Cell = {
    id: number;
    volume: number;
    capacity: number;
    load: number;
    unit_net: number;
    unit_margin: number;
    amount: number;
    margin: number;
    manual: boolean;
};

type Row = {
    key: string;
    kind: 'category' | 'charter' | 'opex' | 'below';
    /** Pe ce se numără: pasageri (turism) sau servicii facturate (Tina). */
    unit: 'pax' | 'serviciu';
    label: string;
    months: Record<number, Cell>;
    pax: number;
    seats: number;
    net: number;
    margin: number;
    amount: number;
    manual: boolean;
};

type StatementRow = {
    key: string;
    label: string;
    kind: 'strong' | 'group' | 'line' | 'cost' | 'result';
    group: string | null;
    lines: number | null;
    months: Record<number, number>;
    total: number;
    base: number | null;
};

type Output = {
    months: Record<
        number,
        {
            net: number;
            margin: number;
            opex: number;
            below: number;
            ebitda: number;
            net_profit: number;
            pax: number;
            services: number;
            seats: number;
        }
    >;
    totals: {
        net: number;
        margin: number;
        opex: number;
        below: number;
        ebitda: number;
        net_profit: number;
        pax: number;
        services: number;
        seats: number;
    };
    statement: StatementRow[];
    categories: Row[];
    charter: Row[];
    opex: Row[];
    below: Row[];
    base: {
        year: number;
        built: boolean;
        net?: number;
        margin?: number;
        opex?: number;
        below?: number;
        ebitda?: number;
        net_profit?: number;
    };
};

type History = Record<
    string,
    Record<
        number,
        {
            volume: number;
            capacity: number;
            sold: number;
            net: number;
            margin: number;
        }
    >
>;

type Props = {
    companies: { id: number; name: string }[];
    filters: { company_id: number | null; plan: number | null };
    plans: {
        id: number;
        year: number;
        name: string;
        base_year: number;
        status: string;
        created_by: string | null;
        lines: number;
    }[];
    plan: {
        id: number;
        year: number;
        name: string;
        base_year: number;
        status: string;
        note: string | null;
    } | null;
    output: Output | null;
    history: History;
    historyYears: number[];
    years: number[];
};

const lei = (value: number, decimals = 0) =>
    new Intl.NumberFormat('ro-RO', {
        maximumFractionDigits: decimals,
        minimumFractionDigits: decimals,
    }).format(value);

const mii = (value: number) => lei(value / 1000);

const pct = (now: number, before?: number) => {
    if (before === undefined || Math.abs(before) < 0.005) {
        return null;
    }

    const change = ((now - before) / Math.abs(before)) * 100;

    return `${change > 0 ? '+' : ''}${change.toFixed(1)}%`;
};

/**
 * Planul anului, din pârghiile lui: pasageri, locuri contractate, grad de
 * ocupare, bani pe pasager, cheltuieli pe linie. Fiecare se poate schimba cu
 * mâna, iar raportul planificat se reface sub ele.
 */
export default function Forecast({
    companies,
    filters,
    plans,
    plan,
    output,
    history,
    historyYears,
    years,
}: Props) {
    const [tab, setTab] = useState<'sumar' | 'categorii' | 'charter' | 'opex'>(
        'sumar',
    );
    const [open, setOpen] = useState<Set<string>>(new Set());
    const [creating, setCreating] = useState(false);
    const [draft, setDraft] = useState({
        year: (years[0] ?? new Date().getFullYear()) as number,
        base_year: (years[1] ?? new Date().getFullYear() - 1) as number,
        name: `Buget ${years[0] ?? new Date().getFullYear()}`,
    });

    const go = (next: Partial<Props['filters']>) =>
        router.get(
            forecastIndex().url,
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true },
        );

    const toggle = (key: string) =>
        setOpen((prev) => {
            const next = new Set(prev);

            if (!next.delete(key)) {
                next.add(key);
            }

            return next;
        });

    const applyRow = (
        row: Row,
        field: string,
        value: number | null,
        growth: number | null,
    ) =>
        plan &&
        router.put(
            `/reports/forecast/${plan.id}/row`,
            { kind: row.kind, key: row.key, field, value, growth },
            { preserveScroll: true, preserveState: false },
        );

    const resetRow = (row: Row) =>
        plan &&
        router.put(
            `/reports/forecast/${plan.id}/row/reset`,
            { kind: row.kind, key: row.key },
            { preserveScroll: true, preserveState: false },
        );

    const editCell = (cell: Cell, field: string, value: number) =>
        router.put(
            `/reports/forecast/lines/${cell.id}`,
            { [field]: value },
            { preserveScroll: true, preserveState: false },
        );

    const base = output?.base;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Rapoarte', href: forecastIndex().url },
                { title: 'Forecast & Plan', href: forecastIndex().url },
            ]}
        >
            <Head title="Forecast & Plan" />

            <div className="space-y-4 p-4">
                <div className="flex flex-wrap items-end gap-3">
                    <div className="grid gap-1.5">
                        <Label>Compania</Label>
                        <Select
                            value={String(filters.company_id ?? '')}
                            onValueChange={(v) =>
                                go({ company_id: Number(v), plan: null })
                            }
                        >
                            <SelectTrigger className="w-56">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {companies.map((company) => (
                                    <SelectItem
                                        key={company.id}
                                        value={String(company.id)}
                                    >
                                        {company.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    {plans.length > 0 && (
                        <div className="grid gap-1.5">
                            <Label>Planul</Label>
                            <Select
                                value={String(plan?.id ?? '')}
                                onValueChange={(v) => go({ plan: Number(v) })}
                            >
                                <SelectTrigger className="w-64">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {plans.map((one) => (
                                        <SelectItem
                                            key={one.id}
                                            value={String(one.id)}
                                        >
                                            {one.name} · {one.year} (din{' '}
                                            {one.base_year})
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}

                    <Dialog open={creating} onOpenChange={setCreating}>
                        <DialogTrigger asChild>
                            <Button variant="outline">
                                <Plus className="size-4" />
                                Plan nou
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Plan nou</DialogTitle>
                                <DialogDescription>
                                    Pornește din anul de bază: pasagerii,
                                    banii pe pasager și cheltuielile lui, plus
                                    locurile de charter contractate pentru
                                    anul plănuit.
                                </DialogDescription>
                            </DialogHeader>
                            <div className="grid gap-3">
                                <div className="grid gap-1.5">
                                    <Label>Numele planului</Label>
                                    <Input
                                        value={draft.name}
                                        onChange={(e) =>
                                            setDraft({
                                                ...draft,
                                                name: e.target.value,
                                            })
                                        }
                                    />
                                </div>
                                <div className="grid grid-cols-2 gap-3">
                                    <div className="grid gap-1.5">
                                        <Label>Anul plănuit</Label>
                                        <Input
                                            type="number"
                                            value={draft.year}
                                            onChange={(e) =>
                                                setDraft({
                                                    ...draft,
                                                    year: Number(
                                                        e.target.value,
                                                    ),
                                                })
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label>Anul de bază</Label>
                                        <Input
                                            type="number"
                                            value={draft.base_year}
                                            onChange={(e) =>
                                                setDraft({
                                                    ...draft,
                                                    base_year: Number(
                                                        e.target.value,
                                                    ),
                                                })
                                            }
                                        />
                                    </div>
                                </div>
                            </div>
                            <DialogFooter>
                                <Button
                                    onClick={() =>
                                        router.post(
                                            '/reports/forecast',
                                            {
                                                ...draft,
                                                company_id: filters.company_id,
                                            },
                                            {
                                                onSuccess: () =>
                                                    setCreating(false),
                                            },
                                        )
                                    }
                                >
                                    Construiește planul
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>

                    {plan && (
                        <>
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        `/reports/forecast/${plan.id}/reseed`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <RefreshCw className="size-4" />
                                Împrospătează din {plan.base_year}
                            </Button>
                            <Button
                                variant="ghost"
                                onClick={() =>
                                    router.delete(
                                        `/reports/forecast/${plan.id}`,
                                    )
                                }
                            >
                                <Trash2 className="size-4" />
                            </Button>
                        </>
                    )}
                </div>

                {!plan || !output ? (
                    <Card>
                        <CardContent className="p-10 text-center text-muted-foreground">
                            Niciun plan încă. Apasă „Plan nou”: se umple
                            singur din istoric, iar pe urmă îl ajustezi.
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                            {(
                                [
                                    ['Venit net', 'net'],
                                    ['Marjă brută', 'margin'],
                                    ['Cheltuieli', 'opex'],
                                    ['EBITDA', 'ebitda'],
                                    ['Profit net', 'net_profit'],
                                ] as const
                            ).map(([label, key]) => (
                                <Card key={key}>
                                    <CardContent className="p-4">
                                        <p className="text-xs text-muted-foreground">
                                            {label}
                                        </p>
                                        <p className="text-lg font-semibold tabular-nums">
                                            {mii(output.totals[key])}
                                            <span className="ml-1 text-xs font-normal text-muted-foreground">
                                                mii lei
                                            </span>
                                        </p>
                                        {base?.built && (
                                            <p className="text-xs text-muted-foreground">
                                                {base.year}:{' '}
                                                {mii(base[key] ?? 0)} ·{' '}
                                                <span
                                                    className={
                                                        (output.totals[key] ??
                                                            0) >=
                                                        (base[key] ?? 0)
                                                            ? 'text-emerald-600 dark:text-emerald-500'
                                                            : 'text-red-600 dark:text-red-400'
                                                    }
                                                >
                                                    {pct(
                                                        output.totals[key],
                                                        base[key],
                                                    ) ?? '–'}
                                                </span>
                                            </p>
                                        )}
                                    </CardContent>
                                </Card>
                            ))}
                        </div>

                        <Tabs
                            value={tab}
                            onValueChange={(v) => setTab(v as typeof tab)}
                        >
                            <TabsList>
                                <TabsTrigger value="sumar">Sumar</TabsTrigger>
                                <TabsTrigger value="categorii">
                                    Categorii ({output.categories.length})
                                </TabsTrigger>
                                <TabsTrigger value="charter">
                                    Charter ({output.charter.length})
                                </TabsTrigger>
                                <TabsTrigger value="opex">
                                    Cheltuieli ({output.opex.length})
                                </TabsTrigger>
                            </TabsList>
                        </Tabs>

                        {tab === 'sumar' && (
                            <PlanStatement
                                output={output}
                                baseYear={plan.base_year}
                                year={plan.year}
                            />
                        )}

                        {tab !== 'sumar' && (
                            <DriverTable
                                rows={
                                    tab === 'categorii'
                                        ? output.categories.filter(
                                              (r) => r.kind === 'category',
                                          )
                                        : tab === 'charter'
                                          ? output.charter
                                          : output.opex
                                }
                                kind={tab}
                                history={history}
                                historyYears={historyYears}
                                open={open}
                                onToggle={toggle}
                                onApply={applyRow}
                                onReset={resetRow}
                                onEditCell={editCell}
                            />
                        )}
                    </>
                )}
            </div>
        </AppLayout>
    );
}

/**
 * Pârghiile, rând cu rând: anul întreg deasupra, lunile dedesubt. Ce se scrie
 * aici se scrie pe toate lunile; ce se scrie în lună rămâne doar acolo.
 */
function DriverTable({
    rows,
    kind,
    history,
    historyYears,
    open,
    onToggle,
    onApply,
    onReset,
    onEditCell,
}: {
    rows: Row[];
    kind: 'categorii' | 'charter' | 'opex';
    history: History;
    historyYears: number[];
    open: Set<string>;
    onToggle: (key: string) => void;
    onApply: (
        row: Row,
        field: string,
        value: number | null,
        growth: number | null,
    ) => void;
    onReset: (row: Row) => void;
    onEditCell: (cell: Cell, field: string, value: number) => void;
}) {
    const isCharter = kind === 'charter';
    const isOpex = kind === 'opex';

    return (
        <Card>
            <CardContent className="overflow-x-auto p-0">
                <Table className="text-xs">
                    <TableHeader>
                        <TableRow>
                            <TableHead className="w-56">
                                {isCharter
                                    ? 'Cursă'
                                    : isOpex
                                      ? 'Linie de cheltuială'
                                      : 'Categorie'}
                            </TableHead>
                            {historyYears.map((year) => (
                                <TableHead
                                    key={year}
                                    className="border-l text-right text-muted-foreground"
                                >
                                    {year}
                                </TableHead>
                            ))}
                            {isCharter && (
                                <>
                                    <TableHead className="text-right">
                                        Locuri
                                    </TableHead>
                                    <TableHead className="text-right">
                                        G.D.O.
                                    </TableHead>
                                </>
                            )}
                            {!isOpex && (
                                <>
                                    <TableHead className="text-right">
                                        Pax / servicii
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Venit / unitate
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Marjă / unitate
                                    </TableHead>
                                </>
                            )}
                            <TableHead className="text-right">
                                {isOpex ? 'Cheltuială an' : 'Venit an'}
                            </TableHead>
                            {!isOpex && (
                                <TableHead className="text-right">
                                    Marjă an
                                </TableHead>
                            )}
                            <TableHead className="w-32 text-right">
                                Creștere %
                            </TableHead>
                            <TableHead className="w-10" />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((row) => (
                            <>
                                <TableRow
                                    key={row.key}
                                    className={row.manual ? 'bg-amber-50/60 dark:bg-amber-950/20' : ''}
                                >
                                    <TableCell>
                                        <button
                                            type="button"
                                            onClick={() => onToggle(row.key)}
                                            className="inline-flex items-center gap-1 font-medium hover:text-foreground"
                                        >
                                            {open.has(row.key) ? (
                                                <ChevronDown className="size-3" />
                                            ) : (
                                                <ChevronRight className="size-3 opacity-50" />
                                            )}
                                            {row.label}
                                        </button>
                                        {row.manual && (
                                            <span className="ml-2 text-[10px] text-amber-700 dark:text-amber-500">
                                                scris de mână
                                            </span>
                                        )}
                                    </TableCell>

                                    {historyYears.map((year) => {
                                        const past =
                                            history[`${row.kind}|${row.key}`]?.[
                                                year
                                            ];

                                        if (!past) {
                                            return (
                                                <TableCell
                                                    key={year}
                                                    className="border-l text-right text-muted-foreground"
                                                >
                                                    –
                                                </TableCell>
                                            );
                                        }

                                        return (
                                            <TableCell
                                                key={year}
                                                className="border-l text-right text-[11px] text-muted-foreground tabular-nums"
                                                title={`${year}: ${lei(past.net)} lei venit, ${lei(past.margin)} marjă`}
                                            >
                                                {isOpex ? (
                                                    lei(past.net)
                                                ) : (
                                                    <>
                                                        <div>
                                                            {lei(past.volume)}
                                                            {isCharter &&
                                                                past.capacity >
                                                                    0 && (
                                                                    <span className="ml-1">
                                                                        /{' '}
                                                                        {(
                                                                            (past.sold /
                                                                                past.capacity) *
                                                                            100
                                                                        ).toFixed(
                                                                            0,
                                                                        )}
                                                                        %
                                                                    </span>
                                                                )}
                                                        </div>
                                                        <div>
                                                            {past.volume > 0
                                                                ? lei(
                                                                      past.net /
                                                                          past.volume,
                                                                  )
                                                                : '–'}{' '}
                                                            /{' '}
                                                            {past.volume > 0
                                                                ? lei(
                                                                      past.margin /
                                                                          past.volume,
                                                                  )
                                                                : '–'}
                                                        </div>
                                                    </>
                                                )}
                                            </TableCell>
                                        );
                                    })}

                                    {isCharter && (
                                        <>
                                            <TableCell className="text-right tabular-nums">
                                                {lei(row.seats)}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <RowInput
                                                    value={
                                                        row.seats > 0
                                                            ? (row.pax /
                                                                  row.seats) *
                                                              100
                                                            : 0
                                                    }
                                                    suffix="%"
                                                    onCommit={(v) =>
                                                        onApply(
                                                            row,
                                                            'load',
                                                            v / 100,
                                                            null,
                                                        )
                                                    }
                                                />
                                            </TableCell>
                                        </>
                                    )}

                                    {!isOpex && (
                                        <>
                                            <TableCell className="text-right tabular-nums">
                                                {lei(row.pax)}
                                                {row.unit === 'serviciu' && (
                                                    <span className="ml-1 text-[10px] text-muted-foreground">
                                                        servicii
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <RowInput
                                                    value={
                                                        row.pax > 0
                                                            ? row.net / row.pax
                                                            : 0
                                                    }
                                                    onCommit={(v) =>
                                                        onApply(
                                                            row,
                                                            'unit_net',
                                                            v,
                                                            null,
                                                        )
                                                    }
                                                />
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <RowInput
                                                    value={
                                                        row.pax > 0
                                                            ? row.margin /
                                                              row.pax
                                                            : 0
                                                    }
                                                    onCommit={(v) =>
                                                        onApply(
                                                            row,
                                                            'unit_margin',
                                                            v,
                                                            null,
                                                        )
                                                    }
                                                />
                                            </TableCell>
                                        </>
                                    )}

                                    <TableCell className="text-right font-semibold tabular-nums">
                                        {lei(isOpex ? row.amount : row.net)}
                                    </TableCell>
                                    {!isOpex && (
                                        <TableCell className="text-right tabular-nums">
                                            {lei(row.margin)}
                                        </TableCell>
                                    )}

                                    <TableCell className="text-right">
                                        <RowInput
                                            value={0}
                                            suffix="%"
                                            placeholder="+/–"
                                            onCommit={(v) =>
                                                onApply(
                                                    row,
                                                    isOpex
                                                        ? 'amount'
                                                        : isCharter
                                                          ? 'capacity'
                                                          : 'volume',
                                                    null,
                                                    v,
                                                )
                                            }
                                        />
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            title="Înapoi la cifrele din istoric"
                                            onClick={() => onReset(row)}
                                        >
                                            <RotateCcw className="size-3" />
                                        </Button>
                                    </TableCell>
                                </TableRow>

                                {open.has(row.key) && (
                                    <TableRow
                                        key={row.key + '-luni'}
                                        className="bg-muted/20"
                                    >
                                        <TableCell
                                            colSpan={
                                                (isOpex ? 5 : isCharter ? 9 : 7) +
                                                historyYears.length
                                            }
                                            className="p-3"
                                        >
                                            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-6">
                                                {MONTHS.map((name, i) => {
                                                    const cell =
                                                        row.months[i + 1];

                                                    if (!cell) {
                                                        return (
                                                            <div
                                                                key={name}
                                                                className="rounded-md border border-dashed p-2 text-center text-[11px] text-muted-foreground"
                                                            >
                                                                {name} · –
                                                            </div>
                                                        );
                                                    }

                                                    return (
                                                        <div
                                                            key={name}
                                                            className="rounded-md border bg-background p-2"
                                                        >
                                                            <div className="flex items-center justify-between text-[11px] text-muted-foreground">
                                                                <span>
                                                                    {name}
                                                                </span>
                                                                {cell.manual && (
                                                                    <span className="text-amber-600">
                                                                        ✎
                                                                    </span>
                                                                )}
                                                            </div>
                                                            <RowInput
                                                                value={
                                                                    isOpex
                                                                        ? cell.amount
                                                                        : isCharter
                                                                          ? cell.capacity
                                                                          : cell.volume
                                                                }
                                                                onCommit={(v) =>
                                                                    onEditCell(
                                                                        cell,
                                                                        isOpex
                                                                            ? 'amount'
                                                                            : isCharter
                                                                              ? 'capacity'
                                                                              : 'volume',
                                                                        v,
                                                                    )
                                                                }
                                                            />
                                                            <div className="mt-1 text-[11px] text-muted-foreground tabular-nums">
                                                                {isOpex
                                                                    ? 'lei'
                                                                    : `${lei(cell.amount)} lei`}
                                                            </div>
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                )}
                            </>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    );
}

/** O cifră care se scrie și se trimite când omul iese din ea. */
function RowInput({
    value,
    onCommit,
    suffix,
    placeholder,
}: {
    value: number;
    onCommit: (value: number) => void;
    suffix?: string;
    placeholder?: string;
}) {
    const [text, setText] = useState<string | null>(null);

    return (
        <span className="inline-flex items-center gap-1">
            <Input
                className="h-7 w-20 text-right text-xs"
                value={text ?? (value ? lei(value, value < 100 ? 1 : 0) : '')}
                placeholder={placeholder}
                onChange={(e) => setText(e.target.value)}
                onBlur={() => {
                    if (text !== null && text.trim() !== '') {
                        const parsed = Number(
                            text.replace(/\./g, '').replace(',', '.'),
                        );

                        if (!Number.isNaN(parsed)) {
                            onCommit(parsed);
                        }
                    }

                    setText(null);
                }}
            />
            {suffix && (
                <span className="text-[11px] text-muted-foreground">
                    {suffix}
                </span>
            )}
        </span>
    );
}

/**
 * Planul în forma contului de profit și pierdere: aceleași rânduri, în
 * aceeași ordine ca raportul adevărat, cu lunile pe coloane și cu anul de
 * bază alături — ca să se citească unul lângă altul, nu ca două tabele care
 * seamănă.
 */
function PlanStatement({
    output,
    baseYear,
    year,
}: {
    output: Output;
    baseYear: number;
    year: number;
}) {
    const [groups, setGroups] = useState(false);

    const rows = output.statement.filter((row) =>
        row.kind === 'line' || row.kind === 'group' ? groups : true,
    );

    return (
        <div className="space-y-2">
            <div className="flex items-center gap-3">
                <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setGroups(!groups)}
                >
                    {groups ? 'Doar totalurile' : 'Desfă cheltuielile'}
                </Button>
                <p className="text-xs text-muted-foreground">
                    Planul {year} are rândurile raportului; coloana din dreapta
                    e anul {baseYear}, din P&L-ul adevărat.
                </p>
            </div>

            <Card>
                <CardContent className="overflow-x-auto p-0">
                    <Table className="text-xs">
                        <TableHeader>
                            <TableRow>
                                <TableHead className="sticky left-0 z-10 w-64 bg-card">
                                    Linie
                                </TableHead>
                                {MONTHS.map((m) => (
                                    <TableHead
                                        key={m}
                                        className="text-right"
                                    >
                                        {m}
                                    </TableHead>
                                ))}
                                <TableHead className="border-l text-right font-semibold">
                                    Plan {year}
                                </TableHead>
                                <TableHead className="text-right text-muted-foreground">
                                    {baseYear}
                                </TableHead>
                                <TableHead className="text-right text-muted-foreground">
                                    vs
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow className="text-muted-foreground">
                                <TableCell className="sticky left-0 z-10 bg-card">
                                    Pasageri · servicii · locuri
                                </TableCell>
                                {MONTHS.map((_, i) => (
                                    <TableCell
                                        key={i}
                                        className="text-right tabular-nums"
                                    >
                                        {lei(
                                            output.months[i + 1]?.pax ?? 0,
                                        )}
                                    </TableCell>
                                ))}
                                <TableCell className="border-l text-right tabular-nums">
                                    {lei(output.totals.pax)} ·{' '}
                                    {lei(output.totals.services)} ·{' '}
                                    {lei(output.totals.seats)}
                                </TableCell>
                                <TableCell colSpan={2} />
                            </TableRow>

                            {rows.map((row) => (
                                <TableRow
                                    key={row.key}
                                    className={
                                        row.kind === 'result'
                                            ? 'bg-muted/40 font-semibold'
                                            : row.kind === 'strong'
                                              ? 'font-medium'
                                              : row.kind === 'line'
                                                ? 'text-muted-foreground'
                                                : ''
                                    }
                                >
                                    <TableCell
                                        className={`sticky left-0 z-10 bg-card ${row.kind === 'line' ? 'pl-7' : ''}`}
                                    >
                                        {row.label}
                                        {row.lines !== null &&
                                            row.lines > 1 && (
                                                <span className="ml-1 text-[10px] opacity-60">
                                                    ({row.lines})
                                                </span>
                                            )}
                                    </TableCell>
                                    {MONTHS.map((_, i) => (
                                        <TableCell
                                            key={i}
                                            className="text-right tabular-nums"
                                        >
                                            {mii(row.months[i + 1] ?? 0)}
                                        </TableCell>
                                    ))}
                                    <TableCell className="border-l text-right font-semibold tabular-nums">
                                        {mii(row.total)}
                                    </TableCell>
                                    <TableCell className="text-right text-muted-foreground tabular-nums">
                                        {row.base !== null
                                            ? mii(row.base)
                                            : '–'}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.base !== null && (
                                            <span
                                                className={
                                                    row.total >= row.base
                                                        ? 'text-emerald-600 dark:text-emerald-500'
                                                        : 'text-red-600 dark:text-red-400'
                                                }
                                            >
                                                {pct(row.total, row.base) ??
                                                    '–'}
                                            </span>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
            <p className="text-xs text-muted-foreground">
                Cifrele sunt în mii de lei. Rândul de sus numără pasagerii
                planificați pe lună; la capăt stau pasagerii, serviciile din
                Tina și locurile de charter ale anului.
            </p>
        </div>
    );
}
