import { Head, router } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronRight,
    FileSpreadsheet,
    FileText,
    Plus,
    Presentation,
    RefreshCw,
    RotateCcw,
    Save,
    Trash2,
} from 'lucide-react';
import { Fragment, useState } from 'react';
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
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { index as forecastIndex } from '@/routes/reports/forecast';

const MONTHS = [
    'ian.',
    'feb.',
    'mar.',
    'apr.',
    'mai',
    'iun.',
    'iul.',
    'aug.',
    'sep.',
    'oct.',
    'nov.',
    'dec.',
];

type Cell = {
    id: number;
    note: string | null;
    unit_cost: number;
    empty_seats: number;
    empty_cost: number;
    break_even: number | null;
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
    /** Charter: costul locului, pragul de rentabilitate și locurile goale. */
    unit_cost: number;
    break_even: number | null;
    empty_seats: number;
    empty_cost: number;
    manual: boolean;
};

type PlanColumn = {
    key: string;
    net: number;
    margin: number;
    pax: number;
    services: number;
    months: Record<number, number>;
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
    product: Record<string, number>;
    channel: Record<string, number>;
    base_product: Record<string, number>;
    base_channel: Record<string, number>;
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
    columns: {
        product: PlanColumn[];
        channel: PlanColumn[];
    };
    period: string;
    basis: string;
    mode: string;
    shifted: boolean;
    leases: {
        leases: number;
        rent: number;
        depreciation: number;
        interest: number;
    } | null;
    channels: {
        channel: string;
        net: number;
        margin: number;
        months: Record<number, number>;
    }[];
    categories: Row[];
    charter: Row[];
    opex: Row[];
    below: Row[];
    base: {
        year: number;
        built: boolean;
        estimated?: number[];
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
            months: Record<
                number,
                {
                    volume: number;
                    capacity: number;
                    net: number;
                    margin: number;
                }
            >;
        }
    >
>;

type Version = {
    id: number;
    label: string;
    note: string | null;
    created_by: string | null;
    created_at: string | null;
    period: string;
    basis: string;
    mode: string;
    lines: number;
    summary: {
        net: number;
        margin: number;
        opex: number;
        ebitda: number;
        net_profit: number;
        pax: number;
        services: number;
        seats: number;
        base: {
            year: number;
            built: boolean;
            net?: number;
            net_profit?: number;
        };
    };
};

type Props = {
    companies: { id: number; name: string }[];
    filters: {
        company_id: number | null;
        plan: number | null;
        period: string;
        basis: string;
        mode: string;
    };
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
    versions: Version[];
    history: History;
    // Lunile pe care anul de bază nu le-a trăit încă și cifrele cu care
    // pornește planul pentru ele.
    openMonths: number[];
    baseMonths: Record<
        string,
        {
            volume: number | null;
            capacity: number | null;
            load: number | null;
            unit_net: number | null;
            unit_margin: number | null;
            amount: number | null;
            source: 'hand' | 'auto';
        }
    >;
    historyYears: number[];
    years: number[];
};

const CHANNELS: Record<string, string> = {
    retail: 'Agenții proprii',
    b2b: 'B2B · agenții partenere',
    site: 'Site',
    cc: 'Call center',
    franciza: 'Francize',
    corporate: 'Corporate',
    other: 'Altele',
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
    versions,
    history,
    historyYears,
    years,
    openMonths,
    baseMonths,
}: Props) {
    // Întâi planul, în forma raportului; pârghiile din care iese stau
    // deoparte, sub „Configurare” — altfel tabelul de decizie e îngropat
    // sub o sută de rânduri de reglaje.
    const [tab, setTab] = useState<'plan' | 'configurare' | 'variante'>('plan');
    // Varianta care se scrie acum: numele ei și de ce s-a salvat.
    const [version, setVersion] = useState({ label: '', note: '' });
    const [saving, setSaving] = useState(false);
    const [view, setView] = useState<'produs' | 'canal' | 'luna'>('produs');

    // Perioada se ține într-un singur câmp („ytd7”), dar se alege din două
    // controale: luna și felul în care se citește — ca pe P&L.
    const periodMatch = /^(ytd|mtd)(\d{1,2})$/.exec(filters.period);
    const periodMode = periodMatch?.[1] === 'mtd' ? 'mtd' : 'ytd';
    const periodMonth = periodMatch ? Number(periodMatch[2]) : 12;
    const [sheet, setSheet] = useState<'categorii' | 'charter' | 'opex'>(
        'categorii',
    );
    const [open, setOpen] = useState<Set<string>>(new Set());
    // Ce s-a scris și încă nu s-a salvat: celule (o lună) și rânduri (tot
    // anul). Se trimit o dată, la „Salvează”.
    const [cells, setCells] = useState<
        Record<string, { id: number; field: string; value: number }>
    >({});
    const [rowEdits, setRowEdits] = useState<
        Record<
            string,
            {
                kind: string;
                key: string;
                field: string;
                value: number | null;
                growth: number | null;
            }
        >
    >({});
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
        setRowEdits((prev) => ({
            ...prev,
            [`${row.kind}|${row.key}|${field}`]: {
                kind: row.kind,
                key: row.key,
                field,
                value,
                growth,
            },
        }));

    const resetRow = (row: Row) =>
        plan &&
        router.put(
            `/reports/forecast/${plan.id}/row/reset`,
            { kind: row.kind, key: row.key },
            { preserveScroll: true, preserveState: true },
        );

    const editCell = (cell: Cell, field: string, value: number) =>
        setCells((prev) => ({
            ...prev,
            [`${cell.id}|${field}`]: { id: cell.id, field, value },
        }));

    const pending = Object.keys(cells).length + Object.keys(rowEdits).length;

    const save = () =>
        plan &&
        router.put(
            `/reports/forecast/${plan.id}/bulk`,
            {
                cells: Object.values(cells),
                rows: Object.values(rowEdits),
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setCells({});
                    setRowEdits({});
                    router.reload({ only: ['output'] });
                },
            },
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

                    {plan && (
                        <>
                            <div className="grid gap-1.5">
                                <Label htmlFor="period">
                                    {view === 'luna' ? 'Până în luna' : 'Luna'}
                                </Label>
                                <Select
                                    value={String(periodMonth)}
                                    onValueChange={(v) =>
                                        go({ period: `${periodMode}${v}` })
                                    }
                                >
                                    <SelectTrigger id="period" className="w-36">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {MONTHS.map((name, i) => (
                                            <SelectItem
                                                key={name}
                                                value={String(i + 1)}
                                            >
                                                {name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div
                                className={cn(
                                    'grid gap-1.5',
                                    view === 'luna' && 'hidden',
                                )}
                            >
                                <Label>Cumul</Label>
                                <ToggleGroup
                                    type="single"
                                    value={periodMode}
                                    onValueChange={(v) =>
                                        v &&
                                        go({ period: `${v}${periodMonth}` })
                                    }
                                    variant="outline"
                                >
                                    <ToggleGroupItem
                                        value="ytd"
                                        title="Cumulat de la începutul anului până în luna aleasă."
                                    >
                                        YTD
                                    </ToggleGroupItem>
                                    <ToggleGroupItem
                                        value="mtd"
                                        title="Doar luna aleasă."
                                    >
                                        MTD
                                    </ToggleGroupItem>
                                </ToggleGroup>
                            </div>

                            <div className="grid gap-1.5">
                                <Label>Venit recunoscut</Label>
                                <ToggleGroup
                                    type="single"
                                    value={filters.mode}
                                    onValueChange={(v) => v && go({ mode: v })}
                                    variant="outline"
                                >
                                    <ToggleGroupItem
                                        value="operational"
                                        title="La data rezervării — ca vederea operațională din P&L."
                                    >
                                        Operațional
                                    </ToggleGroupItem>
                                    <ToggleGroupItem
                                        value="financial"
                                        title="La data plecării — ca vederea financiară din P&L."
                                    >
                                        Financiar
                                    </ToggleGroupItem>
                                </ToggleGroup>
                            </div>
                            <div className="grid gap-1.5">
                                <Label>Vedere</Label>
                                <ToggleGroup
                                    type="single"
                                    value={view}
                                    onValueChange={(v) =>
                                        v && setView(v as typeof view)
                                    }
                                    variant="outline"
                                >
                                    <ToggleGroupItem
                                        value="produs"
                                        title="Rândurile raportului, pe categorii de produs — ca în P&L."
                                    >
                                        Categorie de produs
                                    </ToggleGroupItem>
                                    <ToggleGroupItem
                                        value="canal"
                                        title="Rândurile raportului, pe canale de vânzare — ca în P&L."
                                    >
                                        Canal de vânzare
                                    </ToggleGroupItem>
                                    <ToggleGroupItem
                                        value="luna"
                                        title="Rândurile raportului, lună cu lună."
                                    >
                                        Lunar
                                    </ToggleGroupItem>
                                </ToggleGroup>
                            </div>
                            <div className="grid gap-1.5">
                                <Label>Chirii</Label>
                                <ToggleGroup
                                    type="single"
                                    value={filters.basis}
                                    onValueChange={(v) => v && go({ basis: v })}
                                    variant="outline"
                                >
                                    <ToggleGroupItem value="ras">
                                        Statutar
                                    </ToggleGroupItem>
                                    <ToggleGroupItem value="ifrs16">
                                        IFRS 16
                                    </ToggleGroupItem>
                                </ToggleGroup>
                            </div>
                        </>
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
                                    Pornește din anul de bază: pasagerii, banii
                                    pe pasager și cheltuielile lui, plus
                                    locurile de charter contractate pentru anul
                                    plănuit.
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
                            <Dialog open={saving} onOpenChange={setSaving}>
                                <DialogTrigger asChild>
                                    <Button variant="outline">
                                        <Save className="size-4" />
                                        Salvează varianta
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogHeader>
                                        <DialogTitle>
                                            Salvează varianta planului
                                        </DialogTitle>
                                        <DialogDescription>
                                            Se păstrează pârghiile întregi —
                                            pasageri, locuri, grad de ocupare,
                                            bani pe pasager, cheltuieli — așa
                                            cum sunt acum. Varianta se poate
                                            încărca înapoi oricând sau scoate în
                                            Excel, pe hârtie și în prezentare.
                                        </DialogDescription>
                                    </DialogHeader>
                                    <div className="grid gap-3">
                                        <div className="grid gap-1.5">
                                            <Label>Numele variantei</Label>
                                            <Input
                                                placeholder="Buget inițial, Varianta prudentă, După ședința din 3 noiembrie…"
                                                value={version.label}
                                                onChange={(e) =>
                                                    setVersion({
                                                        ...version,
                                                        label: e.target.value,
                                                    })
                                                }
                                            />
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label>
                                                Ce s-a schimbat și de ce
                                            </Label>
                                            <Textarea
                                                rows={4}
                                                placeholder="Ipotezele variantei: ce s-a presupus, pe ce s-a sprijinit. Textul ajunge în exporturi, lângă cifre."
                                                value={version.note}
                                                onChange={(e) =>
                                                    setVersion({
                                                        ...version,
                                                        note: e.target.value,
                                                    })
                                                }
                                            />
                                        </div>
                                    </div>
                                    <DialogFooter>
                                        <Button
                                            disabled={
                                                version.label.trim() === ''
                                            }
                                            onClick={() =>
                                                router.post(
                                                    `/reports/forecast/${plan.id}/versions`,
                                                    {
                                                        ...version,
                                                        period: filters.period,
                                                        basis: filters.basis,
                                                        mode: filters.mode,
                                                    },
                                                    {
                                                        preserveScroll: true,
                                                        onSuccess: () => {
                                                            setSaving(false);
                                                            setVersion({
                                                                label: '',
                                                                note: '',
                                                            });
                                                        },
                                                    },
                                                )
                                            }
                                        >
                                            Salvează varianta
                                        </Button>
                                    </DialogFooter>
                                </DialogContent>
                            </Dialog>

                            <ExportButtons
                                href={(format) =>
                                    `/reports/forecast/${plan.id}/export?format=${format}&period=${filters.period}&basis=${filters.basis}&mode=${filters.mode}`
                                }
                            />

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

                {plan && pending > 0 && (
                    <div className="sticky top-0 z-30 flex flex-wrap items-center justify-between gap-3 rounded-md border border-amber-300 bg-amber-50 px-4 py-2 text-sm dark:border-amber-800 dark:bg-amber-950/40">
                        <span>
                            <strong>{pending}</strong>{' '}
                            {pending === 1
                                ? 'schimbare nesalvată'
                                : 'schimbări nesalvate'}
                            . Scrie liniștit mai departe; se trimit toate odată.
                        </span>
                        <span className="flex gap-2">
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => {
                                    setCells({});
                                    setRowEdits({});
                                }}
                            >
                                Renunță
                            </Button>
                            <Button size="sm" onClick={save}>
                                Salvează
                            </Button>
                        </span>
                    </div>
                )}

                {!plan || !output ? (
                    <Card>
                        <CardContent className="p-10 text-center text-muted-foreground">
                            Niciun plan încă. Apasă „Plan nou”: se umple singur
                            din istoric, iar pe urmă îl ajustezi.
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

                        {tab === 'configurare' && (
                            <div className="rounded-md border bg-muted/30 p-3 text-xs text-muted-foreground">
                                <strong className="text-foreground">
                                    Cum se citesc cifrele.
                                </strong>{' '}
                                Categoriile se plănuiesc pe <em>pasageri</em>,
                                la data rezervării — aceeași bază ca vederea
                                operațională din P&amp;L. Charterul se
                                plănuiește pe{' '}
                                <em>locuri contractate × grad de ocupare</em>,
                                la data plecării, fiindcă așa se cumpără
                                avionul. Tina se numără pe{' '}
                                <em>servicii facturate</em>, la data facturii.
                                Toate sumele sunt în <strong>lei</strong> — ce
                                s-a vândut în altă monedă e adus la lei cu
                                cursul zilei — volumele sunt{' '}
                                <strong>
                                    număr de pasageri, servicii sau locuri
                                </strong>
                                , iar ocuparea și pragul sunt{' '}
                                <strong>procente</strong>. Coloanele din dreapta
                                numelui arată aceleași mărimi în ultimii trei
                                ani: din ele iese pornirea implicită a planului
                                — cifra anului de bază, dusă mai departe cu
                                creșterea medie a celor trei ani.
                            </div>
                        )}

                        <Tabs
                            value={tab}
                            onValueChange={(v) => setTab(v as typeof tab)}
                        >
                            <TabsList>
                                <TabsTrigger value="plan">
                                    Plan {plan.year}
                                </TabsTrigger>
                                <TabsTrigger value="configurare">
                                    Configurare
                                </TabsTrigger>
                                <TabsTrigger value="variante">
                                    Variante ({versions.length})
                                </TabsTrigger>
                            </TabsList>
                        </Tabs>

                        {tab === 'plan' && (
                            <p className="text-xs text-muted-foreground">
                                {filters.mode === 'operational'
                                    ? 'Venitul e trecut în luna rezervării. Cursele charter, plănuite pe luna plecării, sunt date înapoi în lunile în care se vând locurile.'
                                    : 'Venitul e trecut în luna plecării. Categoriile, plănuite pe luna rezervării, sunt duse înainte în lunile în care se pleacă.'}{' '}
                                {output.shifted
                                    ? `Tiparul rezervare → plecare e cel din ${plan.base_year}.`
                                    : `Nu există încă tiparul rezervare → plecare din ${plan.base_year}, așa că cele două vederi arată la fel.`}
                            </p>
                        )}

                        {tab === 'plan' && (
                            <PlanStatement
                                output={output}
                                baseYear={plan.base_year}
                                year={plan.year}
                                axis={view}
                            />
                        )}

                        {tab === 'variante' && (
                            <Card>
                                <CardContent className="overflow-x-auto p-0">
                                    {versions.length === 0 ? (
                                        <p className="p-10 text-center text-sm text-muted-foreground">
                                            Nicio variantă salvată. Apasă
                                            „Salvează varianta” ca să îngheți
                                            planul de acum — pârghii cu tot —
                                            înainte să încerci altceva.
                                        </p>
                                    ) : (
                                        <Table className="text-xs">
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead className="w-72">
                                                        Varianta
                                                    </TableHead>
                                                    <TableHead>
                                                        Citită pe
                                                    </TableHead>
                                                    <TableHead className="text-right">
                                                        Venit
                                                    </TableHead>
                                                    <TableHead className="text-right">
                                                        Marjă
                                                    </TableHead>
                                                    <TableHead className="text-right">
                                                        EBITDA
                                                    </TableHead>
                                                    <TableHead className="text-right">
                                                        Profit net
                                                    </TableHead>
                                                    <TableHead className="text-right">
                                                        Pârghii
                                                    </TableHead>
                                                    <TableHead className="text-right">
                                                        Scoate
                                                    </TableHead>
                                                    <TableHead />
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {versions.map((one) => (
                                                    <TableRow key={one.id}>
                                                        <TableCell>
                                                            <span className="font-medium">
                                                                {one.label}
                                                            </span>
                                                            <span className="block text-muted-foreground">
                                                                {one.created_by ??
                                                                    'cineva'}{' '}
                                                                ·{' '}
                                                                {one.created_at}
                                                            </span>
                                                            {one.note && (
                                                                <span className="mt-1 block max-w-md whitespace-pre-line text-muted-foreground">
                                                                    {one.note}
                                                                </span>
                                                            )}
                                                        </TableCell>
                                                        <TableCell className="text-muted-foreground">
                                                            {one.mode ===
                                                            'financial'
                                                                ? 'la plecare'
                                                                : 'la rezervare'}
                                                            {' · '}
                                                            {one.basis ===
                                                            'ifrs16'
                                                                ? 'IFRS 16'
                                                                : 'statutar'}
                                                            {' · '}
                                                            {one.period}
                                                        </TableCell>
                                                        <TableCell className="text-right tabular-nums">
                                                            {mii(
                                                                one.summary.net,
                                                            )}
                                                        </TableCell>
                                                        <TableCell className="text-right tabular-nums">
                                                            {mii(
                                                                one.summary
                                                                    .margin,
                                                            )}
                                                        </TableCell>
                                                        <TableCell className="text-right tabular-nums">
                                                            {mii(
                                                                one.summary
                                                                    .ebitda,
                                                            )}
                                                        </TableCell>
                                                        <TableCell className="text-right font-semibold tabular-nums">
                                                            {mii(
                                                                one.summary
                                                                    .net_profit,
                                                            )}
                                                        </TableCell>
                                                        <TableCell className="text-right text-muted-foreground tabular-nums">
                                                            {one.lines}
                                                        </TableCell>
                                                        <TableCell className="text-right">
                                                            <ExportButtons
                                                                compact
                                                                href={(
                                                                    format,
                                                                ) =>
                                                                    `/reports/forecast/${plan.id}/export?format=${format}&version=${one.id}`
                                                                }
                                                            />
                                                        </TableCell>
                                                        <TableCell className="text-right whitespace-nowrap">
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                title="Încarcă varianta în plan; ce e acum se pierde dacă nu e salvat."
                                                                onClick={() =>
                                                                    confirm(
                                                                        `Încarci „${one.label}” peste planul de acum? Ce nu e salvat se pierde.`,
                                                                    ) &&
                                                                    router.post(
                                                                        `/reports/forecast/${plan.id}/versions/${one.id}/restore`,
                                                                        {},
                                                                        {
                                                                            preserveScroll: true,
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                <RotateCcw className="size-4" />
                                                            </Button>
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() =>
                                                                    confirm(
                                                                        `Ștergi varianta „${one.label}”?`,
                                                                    ) &&
                                                                    router.delete(
                                                                        `/reports/forecast/${plan.id}/versions/${one.id}`,
                                                                        {
                                                                            preserveScroll: true,
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                <Trash2 className="size-4" />
                                                            </Button>
                                                        </TableCell>
                                                    </TableRow>
                                                ))}
                                            </TableBody>
                                        </Table>
                                    )}
                                </CardContent>
                            </Card>
                        )}

                        {tab === 'variante' && (
                            <p className="text-xs text-muted-foreground">
                                Cifrele variantei sunt cele de la salvare, în
                                mii de lei, citite pe perioada și pe baza de
                                atunci. Exportul unei variante pleacă din
                                pârghiile ei, nu din planul de acum: Excel cu
                                toate lunile, PDF cu ipotezele scrise și
                                PowerPoint de dus în ședință.
                            </p>
                        )}

                        {tab === 'configurare' && (
                            <Tabs
                                value={sheet}
                                onValueChange={(v) =>
                                    setSheet(v as typeof sheet)
                                }
                            >
                                <TabsList>
                                    <TabsTrigger value="categorii">
                                        Categorii de produs (
                                        {
                                            output.categories.filter(
                                                (r) => r.kind === 'category',
                                            ).length
                                        }
                                        )
                                    </TabsTrigger>
                                    <TabsTrigger value="charter">
                                        Charter · curse ({output.charter.length}
                                        )
                                    </TabsTrigger>
                                    <TabsTrigger value="opex">
                                        Cheltuieli și sub EBITDA (
                                        {output.opex.length +
                                            output.below.length}
                                        )
                                    </TabsTrigger>
                                </TabsList>
                            </Tabs>
                        )}

                        {tab === 'configurare' && (
                            <DriverTable
                                rows={
                                    sheet === 'categorii'
                                        ? output.categories.filter(
                                              (r) => r.kind === 'category',
                                          )
                                        : sheet === 'charter'
                                          ? output.charter
                                          : [...output.opex, ...output.below]
                                }
                                kind={sheet}
                                history={history}
                                openMonths={openMonths}
                                baseMonths={baseMonths}
                                planId={plan.id}
                                historyYears={historyYears}
                                planYear={plan.year}
                                baseYear={plan.base_year}
                                cells={cells}
                                rowEdits={rowEdits}
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
    openMonths,
    baseMonths,
    planId,
    historyYears,
    planYear,
    baseYear,
    cells,
    rowEdits,
    open,
    onToggle,
    onApply,
    onReset,
    onEditCell,
}: {
    rows: Row[];
    kind: 'categorii' | 'charter' | 'opex';
    history: History;
    openMonths: number[];
    baseMonths: Props['baseMonths'];
    planId: number;
    historyYears: number[];
    planYear: number;
    baseYear: number;
    cells: Record<string, { id: number; field: string; value: number }>;
    rowEdits: Record<
        string,
        { kind: string; key: string; field: string; value: number | null }
    >;
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
                                    title={
                                        isOpex
                                            ? `Cât s-a cheltuit pe linia asta în ${year}, în lei.`
                                            : `${year}: volumul (pax sau servicii) și, sub el, venitul și marja pe unitate, în lei.`
                                    }
                                >
                                    {year}
                                    <span className="block text-[10px] font-normal opacity-70">
                                        {isOpex
                                            ? 'lei'
                                            : isCharter
                                              ? 'pax / ocupare · lei/pax'
                                              : 'volum · lei/unit.'}
                                    </span>
                                </TableHead>
                            ))}
                            {isCharter && (
                                <>
                                    <TableHead className="text-right">
                                        Locuri (nr.)
                                    </TableHead>
                                    <TableHead
                                        className="text-right"
                                        title="Gradul de ocupare: câte locuri din avion se vând."
                                    >
                                        G.D.O. (%)
                                    </TableHead>
                                </>
                            )}
                            {isCharter && (
                                <>
                                    <TableHead
                                        className="text-right"
                                        title="Gradul de ocupare de la care cursa își acoperă avionul."
                                    >
                                        Prag (%)
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Loc. goale (nr.)
                                    </TableHead>
                                </>
                            )}
                            {!isOpex && (
                                <>
                                    <TableHead className="text-right">
                                        Pax / servicii (nr.)
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Venit / unitate (lei)
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Marjă / unitate (lei)
                                    </TableHead>
                                </>
                            )}
                            <TableHead className="text-right">
                                {isOpex
                                    ? 'Cheltuială an (lei)'
                                    : 'Venit an (lei)'}
                            </TableHead>
                            {!isOpex && (
                                <TableHead className="text-right">
                                    Marjă an (lei)
                                </TableHead>
                            )}
                            <TableHead
                                className="w-32 text-right"
                                title="Crește tot rândul, pe toate lunile, cu procentul scris."
                            >
                                Creștere (%)
                            </TableHead>
                            <TableHead className="w-10" />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((row) => (
                            <>
                                <TableRow
                                    key={row.key}
                                    className={cn(
                                        row.manual &&
                                            'bg-amber-50/60 dark:bg-amber-950/20',
                                        Object.values(rowEdits).some(
                                            (r) =>
                                                `${row.kind}|${row.key}` ===
                                                `${r.kind}|${r.key}`,
                                        ) && 'ring-1 ring-amber-400',
                                    )}
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
                                            <span className="text-[10px] font-normal opacity-60">
                                                {open.has(row.key)
                                                    ? 'strânge lunile'
                                                    : 'vezi lunile'}
                                            </span>
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

                                    {isCharter && (
                                        <>
                                            <TableCell
                                                className="text-right tabular-nums"
                                                title={`Costul zborului: ${lei(row.unit_cost)} lei pe loc. De la gradul ăsta de ocupare cursa își acoperă avionul.`}
                                            >
                                                {row.break_even !== null ? (
                                                    <span
                                                        className={
                                                            row.seats > 0 &&
                                                            row.pax /
                                                                row.seats <
                                                                row.break_even
                                                                ? 'font-semibold text-red-600 dark:text-red-400'
                                                                : ''
                                                        }
                                                    >
                                                        {(
                                                            row.break_even * 100
                                                        ).toFixed(0)}
                                                        %
                                                    </span>
                                                ) : (
                                                    '–'
                                                )}
                                            </TableCell>
                                            <TableCell
                                                className="text-right text-[11px] tabular-nums"
                                                title="Locurile rămase nevândute și cât costă: avionul se plătește întreg."
                                            >
                                                <div>
                                                    {lei(row.empty_seats)}
                                                </div>
                                                <div className="text-muted-foreground">
                                                    {lei(row.empty_cost)} lei
                                                </div>
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
                                                (isOpex
                                                    ? 5
                                                    : isCharter
                                                      ? 11
                                                      : 7) + historyYears.length
                                            }
                                            className="p-3"
                                        >
                                            <div className="mb-2 text-[11px] text-muted-foreground">
                                                Lunile planului{' '}
                                                <strong className="text-foreground">
                                                    {planYear}
                                                </strong>
                                                : scrie în căsuțe ce vrei să se
                                                întâmple. Sub fiecare lună stă
                                                ce a fost în {baseYear}, ca
                                                reper.
                                            </div>
                                            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                                {MONTHS.map((name, i) => {
                                                    const cell =
                                                        row.months[i + 1];
                                                    const past =
                                                        history[
                                                            `${row.kind}|${row.key}`
                                                        ]?.[baseYear]?.months?.[
                                                            i + 1
                                                        ];

                                                    if (!cell) {
                                                        return (
                                                            <div
                                                                key={name}
                                                                className="rounded-md border border-dashed p-2 text-center text-[11px] text-muted-foreground"
                                                            >
                                                                {name} · fără
                                                                plan
                                                            </div>
                                                        );
                                                    }

                                                    return (
                                                        <div
                                                            key={name}
                                                            className={cn(
                                                                'rounded-md border bg-background p-2',
                                                                Object.values(
                                                                    cells,
                                                                ).some(
                                                                    (c) =>
                                                                        c.id ===
                                                                        cell.id,
                                                                ) &&
                                                                    'border-amber-400 bg-amber-50/60 dark:bg-amber-950/20',
                                                            )}
                                                        >
                                                            <div className="flex items-center justify-between">
                                                                <span className="text-xs font-medium">
                                                                    {name}{' '}
                                                                    {planYear}
                                                                </span>
                                                                {cell.note && (
                                                                    <span
                                                                        className="text-[10px] text-amber-600"
                                                                        title={
                                                                            cell.note
                                                                        }
                                                                    >
                                                                        estimat
                                                                    </span>
                                                                )}
                                                            </div>

                                                            <div className="mt-1 grid grid-cols-3 gap-1">
                                                                <label className="text-[10px] text-muted-foreground">
                                                                    {isOpex
                                                                        ? 'cheltuială (lei)'
                                                                        : isCharter
                                                                          ? 'locuri (nr.)'
                                                                          : row.unit ===
                                                                              'serviciu'
                                                                            ? 'servicii (nr.)'
                                                                            : 'pasageri (nr.)'}
                                                                    <RowInput
                                                                        value={
                                                                            isOpex
                                                                                ? cell.amount
                                                                                : isCharter
                                                                                  ? cell.capacity
                                                                                  : cell.volume
                                                                        }
                                                                        onCommit={(
                                                                            v,
                                                                        ) =>
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
                                                                </label>

                                                                {!isOpex && (
                                                                    <>
                                                                        <label className="text-[10px] text-muted-foreground">
                                                                            {isCharter
                                                                                ? 'ocupare (%)'
                                                                                : 'venit/unit. (lei)'}
                                                                            <RowInput
                                                                                value={
                                                                                    isCharter
                                                                                        ? cell.load *
                                                                                          100
                                                                                        : cell.unit_net
                                                                                }
                                                                                onCommit={(
                                                                                    v,
                                                                                ) =>
                                                                                    onEditCell(
                                                                                        cell,
                                                                                        isCharter
                                                                                            ? 'load'
                                                                                            : 'unit_net',
                                                                                        isCharter
                                                                                            ? v /
                                                                                                  100
                                                                                            : v,
                                                                                    )
                                                                                }
                                                                            />
                                                                        </label>
                                                                        <label className="text-[10px] text-muted-foreground">
                                                                            {isCharter
                                                                                ? 'venit/pax (lei)'
                                                                                : 'marjă/unit. (lei)'}
                                                                            <RowInput
                                                                                value={
                                                                                    isCharter
                                                                                        ? cell.unit_net
                                                                                        : cell.unit_margin
                                                                                }
                                                                                onCommit={(
                                                                                    v,
                                                                                ) =>
                                                                                    onEditCell(
                                                                                        cell,
                                                                                        isCharter
                                                                                            ? 'unit_net'
                                                                                            : 'unit_margin',
                                                                                        v,
                                                                                    )
                                                                                }
                                                                            />
                                                                        </label>
                                                                    </>
                                                                )}
                                                            </div>

                                                            {!isOpex && (
                                                                <div className="mt-1 text-[11px] tabular-nums">
                                                                    <span className="text-foreground">
                                                                        {isCharter
                                                                            ? `${lei(cell.volume)} pax · `
                                                                            : ''}
                                                                        venit{' '}
                                                                        {lei(
                                                                            cell.amount,
                                                                        )}
                                                                    </span>
                                                                    <span className="text-muted-foreground">
                                                                        {' '}
                                                                        · marjă{' '}
                                                                        {lei(
                                                                            cell.margin,
                                                                        )}
                                                                    </span>
                                                                </div>
                                                            )}

                                                            <div className="mt-1 border-t pt-1 text-[11px] text-muted-foreground tabular-nums">
                                                                {baseYear}:{' '}
                                                                {past
                                                                    ? isOpex
                                                                        ? `${lei(past.net)} lei`
                                                                        : `${lei(past.volume)} ${isCharter ? 'pax' : row.unit === 'serviciu' ? 'servicii' : 'pax'}${isCharter && past.capacity > 0 ? ` din ${lei(past.capacity)} locuri` : ''} · ${lei(past.net)} lei`
                                                                    : '–'}
                                                            </div>
                                                        </div>
                                                    );
                                                })}
                                            </div>

                                            {openMonths.length > 0 && (
                                                <div className="mt-4 border-t pt-3">
                                                    <div className="mb-2 flex items-center justify-between gap-3">
                                                        <div className="text-[11px] text-muted-foreground">
                                                            Ultimele luni din{' '}
                                                            <strong className="text-foreground">
                                                                {baseYear}
                                                            </strong>{' '}
                                                            n-au venit încă:
                                                            planul lui{' '}
                                                            {planYear} pornește
                                                            din ele, așa că se
                                                            pot scrie aici.
                                                            Nescrise, se
                                                            socotesc singure din{' '}
                                                            {baseYear - 1},
                                                            crescut cu cât a
                                                            crescut anul.
                                                        </div>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            className="shrink-0 text-[11px]"
                                                            onClick={() =>
                                                                router.put(
                                                                    `/reports/forecast/${planId}/base/reset`,
                                                                    {
                                                                        kind: row.kind,
                                                                        key: row.key,
                                                                    },
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            <RotateCcw className="size-3" />
                                                            Înapoi la socoteala
                                                            aplicației
                                                        </Button>
                                                    </div>
                                                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                                        {openMonths.map(
                                                            (month) => {
                                                                const base =
                                                                    baseMonths[
                                                                        `${row.kind}|${row.key}|${month}`
                                                                    ];
                                                                const write = (
                                                                    field: string,
                                                                    value: number,
                                                                ) =>
                                                                    router.put(
                                                                        `/reports/forecast/${planId}/base`,
                                                                        {
                                                                            kind: row.kind,
                                                                            key: row.key,
                                                                            label: row.label,
                                                                            unit: row.unit,
                                                                            month,
                                                                            volume:
                                                                                base?.volume ??
                                                                                null,
                                                                            capacity:
                                                                                base?.capacity ??
                                                                                null,
                                                                            load:
                                                                                base?.load ??
                                                                                null,
                                                                            unit_net:
                                                                                base?.unit_net ??
                                                                                null,
                                                                            unit_margin:
                                                                                base?.unit_margin ??
                                                                                null,
                                                                            amount:
                                                                                base?.amount ??
                                                                                null,
                                                                            [field]:
                                                                                value,
                                                                        },
                                                                        {
                                                                            preserveScroll: true,
                                                                        },
                                                                    );

                                                                return (
                                                                    <div
                                                                        key={
                                                                            month
                                                                        }
                                                                        className={cn(
                                                                            'rounded-md border border-dashed p-2',
                                                                            base?.source ===
                                                                                'hand' &&
                                                                                'border-solid border-sky-400 bg-sky-50/60 dark:bg-sky-950/20',
                                                                        )}
                                                                    >
                                                                        <div className="flex items-center justify-between">
                                                                            <span className="text-xs font-medium">
                                                                                {
                                                                                    MONTHS[
                                                                                        month -
                                                                                            1
                                                                                    ]
                                                                                }{' '}
                                                                                {
                                                                                    baseYear
                                                                                }
                                                                            </span>
                                                                            <span className="text-[10px] text-muted-foreground">
                                                                                {base?.source ===
                                                                                'hand'
                                                                                    ? 'scris de mână'
                                                                                    : 'socotit'}
                                                                            </span>
                                                                        </div>

                                                                        <div className="mt-1 grid grid-cols-3 gap-1">
                                                                            <label className="text-[10px] text-muted-foreground">
                                                                                {isOpex
                                                                                    ? 'cheltuială (lei)'
                                                                                    : isCharter
                                                                                      ? 'locuri (nr.)'
                                                                                      : row.unit ===
                                                                                          'serviciu'
                                                                                        ? 'servicii (nr.)'
                                                                                        : 'pasageri (nr.)'}
                                                                                <RowInput
                                                                                    value={
                                                                                        (isOpex
                                                                                            ? base?.amount
                                                                                            : isCharter
                                                                                              ? base?.capacity
                                                                                              : base?.volume) ??
                                                                                        0
                                                                                    }
                                                                                    onCommit={(
                                                                                        v,
                                                                                    ) =>
                                                                                        write(
                                                                                            isOpex
                                                                                                ? 'amount'
                                                                                                : isCharter
                                                                                                  ? 'capacity'
                                                                                                  : 'volume',
                                                                                            v,
                                                                                        )
                                                                                    }
                                                                                />
                                                                            </label>
                                                                            {!isOpex && (
                                                                                <>
                                                                                    <label className="text-[10px] text-muted-foreground">
                                                                                        {isCharter
                                                                                            ? 'ocupare (%)'
                                                                                            : 'venit/unit. (lei)'}
                                                                                        <RowInput
                                                                                            value={
                                                                                                isCharter
                                                                                                    ? (base?.load ??
                                                                                                          0) *
                                                                                                      100
                                                                                                    : (base?.unit_net ??
                                                                                                      0)
                                                                                            }
                                                                                            onCommit={(
                                                                                                v,
                                                                                            ) =>
                                                                                                write(
                                                                                                    isCharter
                                                                                                        ? 'load'
                                                                                                        : 'unit_net',
                                                                                                    isCharter
                                                                                                        ? v /
                                                                                                              100
                                                                                                        : v,
                                                                                                )
                                                                                            }
                                                                                        />
                                                                                    </label>
                                                                                    <label className="text-[10px] text-muted-foreground">
                                                                                        {isCharter
                                                                                            ? 'venit/pax (lei)'
                                                                                            : 'marjă/unit. (lei)'}
                                                                                        <RowInput
                                                                                            value={
                                                                                                (isCharter
                                                                                                    ? base?.unit_net
                                                                                                    : base?.unit_margin) ??
                                                                                                0
                                                                                            }
                                                                                            onCommit={(
                                                                                                v,
                                                                                            ) =>
                                                                                                write(
                                                                                                    isCharter
                                                                                                        ? 'unit_net'
                                                                                                        : 'unit_margin',
                                                                                                    v,
                                                                                                )
                                                                                            }
                                                                                        />
                                                                                    </label>
                                                                                </>
                                                                            )}
                                                                        </div>

                                                                        <div className="mt-1 border-t pt-1 text-[11px] text-muted-foreground tabular-nums">
                                                                            {baseYear -
                                                                                1}
                                                                            :{' '}
                                                                            {(() => {
                                                                                const was =
                                                                                    history[
                                                                                        `${row.kind}|${row.key}`
                                                                                    ]?.[
                                                                                        baseYear -
                                                                                            1
                                                                                    ]
                                                                                        ?.months?.[
                                                                                        month
                                                                                    ];

                                                                                if (
                                                                                    !was
                                                                                ) {
                                                                                    return '–';
                                                                                }

                                                                                return isOpex
                                                                                    ? `${lei(was.net)} lei`
                                                                                    : `${lei(was.volume)} ${isCharter ? 'pax' : row.unit === 'serviciu' ? 'servicii' : 'pax'} · ${lei(was.net)} lei`;
                                                                            })()}
                                                                        </div>
                                                                    </div>
                                                                );
                                                            },
                                                        )}
                                                    </div>
                                                </div>
                                            )}
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
/**
 * Cele trei formate în care pleacă planul din aplicație.
 *
 * Descărcările sunt legături adevărate, nu cereri Inertia: altfel browserul
 * n-ar ști ce să facă cu fișierul care vine înapoi.
 */
function ExportButtons({
    href,
    compact = false,
}: {
    href: (format: 'xlsx' | 'pdf' | 'pptx') => string;
    compact?: boolean;
}) {
    const formats = [
        {
            format: 'xlsx' as const,
            label: 'Excel',
            icon: FileSpreadsheet,
            title: 'Toate lunile, pârghie cu pârghie, cu grafice pe foile de sinteză.',
        },
        {
            format: 'pdf' as const,
            label: 'PDF',
            icon: FileText,
            title: 'Planul pe hârtie, cu ipotezele scrise în cuvinte.',
        },
        {
            format: 'pptx' as const,
            label: 'PowerPoint',
            icon: Presentation,
            title: 'Prezentarea de dus în ședință: cifrele mari, tabelele și ipotezele.',
        },
    ];

    if (compact) {
        return (
            <span className="inline-flex">
                {formats.map(({ format, icon: Icon, title }) => (
                    <Button key={format} variant="ghost" size="sm" asChild>
                        <a href={href(format)} title={title}>
                            <Icon className="size-4" />
                        </a>
                    </Button>
                ))}
            </span>
        );
    }

    return (
        <span className="inline-flex gap-1">
            {formats.map(({ format, label, icon: Icon, title }) => (
                <Button key={format} variant="outline" asChild>
                    <a href={href(format)} title={title}>
                        <Icon className="size-4" />
                        {label}
                    </a>
                </Button>
            ))}
        </span>
    );
}

function PlanStatement({
    output,
    baseYear,
    year,
    axis,
}: {
    output: Output;
    baseYear: number;
    year: number;
    axis: 'luna' | 'produs' | 'canal';
}) {
    const [groups, setGroups] = useState(false);

    const rows = output.statement.filter((row) =>
        row.kind === 'line' || row.kind === 'group' ? groups : true,
    );

    // Coloanele raportului: lunile anului, categoriile de produs sau canalele
    // de vânzare. Rândurile sunt aceleași pe toate trei.
    const columns =
        axis === 'luna'
            ? MONTHS.map((name, i) => ({ key: String(i + 1), label: name }))
            : output.columns[axis === 'produs' ? 'product' : 'channel'].map(
                  (column) => ({
                      key: column.key,
                      label:
                          axis === 'canal'
                              ? (CHANNELS[column.key] ?? column.key)
                              : column.key,
                  }),
              );

    const value = (row: StatementRow, key: string) =>
        axis === 'luna'
            ? (row.months[Number(key)] ?? 0)
            : ((axis === 'produs' ? row.product : row.channel)[key] ?? 0);

    // Anul de bază, pe aceeași coloană: istoricul stă la stânga, planul la
    // dreapta, ca să se citească evoluția dintr-o privire.
    const was = (row: StatementRow, key: string): number | null =>
        axis === 'luna'
            ? null
            : ((axis === 'produs' ? row.base_product : row.base_channel)[key] ??
              null);

    const revenue = output.statement.find((row) => row.key === 'revenue');

    // „% venit”, ca în P&L: cât ține rândul din venitul coloanei lui.
    const share = (row: StatementRow, key: string | null) => {
        if (revenue === undefined || row.kind === 'result') {
            return null;
        }

        const base = key === null ? revenue.total : value(revenue, key);
        const cell = key === null ? row.total : value(row, key);

        return base === 0 ? null : `${((cell / base) * 100).toFixed(1)}%`;
    };

    const label =
        axis === 'luna'
            ? `lunile anului ${year}`
            : axis === 'produs'
              ? 'categoriile de produs'
              : 'canalele de vânzare';

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
                    Planul {year} are rândurile raportului, pe {label}. Pe
                    fiecare coloană stă întâi {baseYear}, din P&amp;L-ul
                    adevărat, apoi {year}: evoluția se citește de la stânga la
                    dreapta.
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
                                <TableHead className="border-l text-right text-muted-foreground">
                                    {baseYear}
                                    <span className="block text-[10px] font-normal opacity-70">
                                        mii lei
                                    </span>
                                </TableHead>
                                <TableHead className="text-right font-semibold">
                                    Plan {year}
                                    <span className="block text-[10px] font-normal opacity-70">
                                        mii lei
                                    </span>
                                </TableHead>
                                <TableHead className="text-right text-muted-foreground">
                                    vs
                                </TableHead>
                                {axis !== 'luna' && (
                                    <TableHead className="text-right text-muted-foreground">
                                        % venit
                                    </TableHead>
                                )}
                                {columns.map((column) => (
                                    <Fragment key={column.key}>
                                        {axis !== 'luna' && (
                                            <TableHead className="border-l text-right font-normal text-muted-foreground">
                                                {column.label} {baseYear}
                                            </TableHead>
                                        )}
                                        <TableHead
                                            className={`text-right ${axis === 'luna' ? '' : 'font-semibold'}`}
                                        >
                                            {axis === 'luna'
                                                ? column.label
                                                : `${column.label} ${year}`}
                                        </TableHead>
                                        {axis !== 'luna' && (
                                            <TableHead className="text-right text-muted-foreground">
                                                % venit
                                            </TableHead>
                                        )}
                                    </Fragment>
                                ))}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow className="text-muted-foreground">
                                <TableCell className="sticky left-0 z-10 bg-card">
                                    Pasageri · servicii · locuri (nr.)
                                </TableCell>
                                <TableCell className="border-l" />
                                <TableCell className="text-right tabular-nums">
                                    {lei(output.totals.pax)} ·{' '}
                                    {lei(output.totals.services)} ·{' '}
                                    {lei(output.totals.seats)}
                                </TableCell>
                                <TableCell />
                                {axis !== 'luna' && <TableCell />}
                                {columns.map((column, i) => (
                                    <Fragment key={column.key}>
                                        {axis !== 'luna' && (
                                            <TableCell className="border-l" />
                                        )}
                                        <TableCell className="text-right tabular-nums">
                                            {axis === 'luna'
                                                ? lei(
                                                      output.months[i + 1]
                                                          ?.pax ?? 0,
                                                  )
                                                : axis === 'produs'
                                                  ? lei(
                                                        (output.columns.product.find(
                                                            (c) =>
                                                                c.key ===
                                                                column.key,
                                                        )?.pax ?? 0) +
                                                            (output.columns.product.find(
                                                                (c) =>
                                                                    c.key ===
                                                                    column.key,
                                                            )?.services ?? 0),
                                                    )
                                                  : '—'}
                                        </TableCell>
                                        {axis !== 'luna' && <TableCell />}
                                    </Fragment>
                                ))}
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
                                    <TableCell className="border-l text-right text-muted-foreground tabular-nums">
                                        {row.base !== null
                                            ? mii(row.base)
                                            : '–'}
                                    </TableCell>
                                    <TableCell className="text-right font-semibold tabular-nums">
                                        {mii(row.total)}
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
                                    {axis !== 'luna' && (
                                        <TableCell className="text-right text-muted-foreground tabular-nums">
                                            {share(row, null) ?? ''}
                                        </TableCell>
                                    )}
                                    {columns.map((column) => (
                                        <Fragment key={column.key}>
                                            {axis !== 'luna' && (
                                                <TableCell className="border-l text-right text-muted-foreground tabular-nums">
                                                    {was(row, column.key) !==
                                                    null
                                                        ? mii(
                                                              was(
                                                                  row,
                                                                  column.key,
                                                              ) as number,
                                                          )
                                                        : '–'}
                                                </TableCell>
                                            )}
                                            <TableCell className="text-right tabular-nums">
                                                {mii(value(row, column.key))}
                                            </TableCell>
                                            {axis !== 'luna' && (
                                                <TableCell className="text-right text-muted-foreground tabular-nums">
                                                    {share(row, column.key) ??
                                                        ''}
                                                </TableCell>
                                            )}
                                        </Fragment>
                                    ))}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
            <p className="text-xs text-muted-foreground">
                Cifrele sunt în mii de lei.{' '}
                {axis === 'luna'
                    ? 'Rândul de sus numără pasagerii planificați pe lună; la capăt stau pasagerii, serviciile din Tina și locurile de charter ale anului.'
                    : 'Cheltuielile se împart pe coloane după cum s-au împărțit aceleași linii în anul de bază; liniile noi, după venit.'}
            </p>
        </div>
    );
}
