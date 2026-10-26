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
    section: string;
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
    years: Record<string, number | null>;
    years_product: Record<string, Record<string, number>>;
    years_channel: Record<string, Record<string, number>>;
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
    retail: {
        key: string;
        section: string;
        label: string;
        fte: number;
        cost_per_fte: number;
        bonus: number;
        sales: number;
        amount: number;
        manual: boolean;
        weight: number | null;
    }[];
    opex: Row[];
    below: Row[];
    risk: {
        charter_share: number;
        load: number;
        break_even: number;
        empty_seats: number;
        empty_cost: number;
        routes: number;
        tax: number;
        tax_is_minimum: boolean;
        per_point: number;
    };
    years: {
        year: number;
        built: boolean;
        estimated: number[];
        pax: number;
        apart?: Record<string, number>;
        net?: number;
        margin?: number;
        opex?: number;
        ebitda?: number;
        net_profit?: number;
    }[];
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
            estimated?: number;
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

type CurrentYear = {
    year: number;
    lived: number[];
    open: number[];
    rows: {
        key: string;
        label: string;
        actual: number;
        estimate: number;
        total: number;
        before: number | null;
    }[];
    categories: {
        key: string;
        actual: number;
        estimate: number;
        total: number;
        margin: number;
        before: number | null;
    }[];
    totals: {
        actual: Record<string, number>;
        total: Record<string, number>;
        before: Record<string, number>;
    };
};

type Section = {
    key: string;
    label: string;
    kind: 'category' | 'cost';
    department: string | null;
    people: string[];
    status: 'draft' | 'submitted' | 'accepted';
    submitted_by: string | null;
    submitted_at: string | null;
    accepted_by: string | null;
    accepted_at: string | null;
    note: string | null;
    rows: number;
    net: number;
    amount: number;
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
        start: 'trend' | 'base';
        status: string;
        note: string | null;
        removed: string[];
    } | null;
    output: Output | null;
    versions: Version[];
    sections: Section[];
    /** Bucățile de buget pe care le ține omul care se uită. */
    mine: string[];
    /** Conducerea vede tot și primește sau dă înapoi bucățile. */
    all_mine: boolean;
    /** Departamentele active: din ele își alege stăpânul o categorie nouă. */
    departments: string[];
    current: CurrentYear | null;
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

/** Numele pârghiilor, pentru lista schimbărilor nesalvate. */
const FIELDS: Record<string, string> = {
    volume: 'pasageri',
    capacity: 'locuri',
    load: 'ocupare',
    unit_net: 'venit / unitate',
    unit_margin: 'marjă / unitate',
    unit_cost: 'cost / loc',
    amount: 'sumă',
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

/**
 * Reperul unei luni din istoric, scris cu aceleași mărimi ca în căsuțele de
 * deasupra: altfel omul compară pere cu mere.
 */
const reper = (
    was: { volume: number; capacity: number; net: number; margin: number },
    isOpex: boolean,
    isCharter: boolean,
    unit: string,
) => {
    if (isOpex) {
        return `${lei(was.net)} lei`;
    }

    const perUnit = was.volume > 0 ? was.net / was.volume : 0;

    if (isCharter) {
        const load = was.capacity > 0 ? (was.volume / was.capacity) * 100 : 0;

        return `${lei(was.capacity)} locuri · ${lei(load, 1)}% ocupare · ${lei(perUnit)} lei/pax · venit ${lei(was.net)}`;
    }

    const name = unit === 'serviciu' ? 'servicii' : 'pax';
    const margin = was.volume > 0 ? was.margin / was.volume : 0;

    return `${lei(was.volume)} ${name} · ${lei(perUnit)} lei/${name === 'servicii' ? 'serviciu' : 'pax'} · marjă ${lei(margin)} · venit ${lei(was.net)}`;
};

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
    sections,
    mine,
    all_mine: boss,
    departments,
    current,
    history,
    historyYears,
    years,
    openMonths,
    baseMonths,
}: Props) {
    // Întâi planul, în forma raportului; pârghiile din care iese stau
    // deoparte, sub „Configurare” — altfel tabelul de decizie e îngropat
    // sub o sută de rânduri de reglaje.
    const [tab, setTab] = useState<
        'plan' | 'curent' | 'configurare' | 'variante' | 'sectiuni'
    >(boss ? 'plan' : 'configurare');
    // Rândurile pe care le poate atinge omul care se uită: conducerea toate,
    // ceilalți numai pe-ale departamentului lor.
    const ale = <T extends { section: string }>(rows: T[]) =>
        rows.filter((one) => boss || mine.includes(one.section));

    // Fiecare categorie de produs își are fila ei — Cazare, Senior Voyage,
    // Circuite — fiindcă omul care o ține deschide categoria lui, nu un
    // tabel cu toate categoriile casei.
    const foi: Record<string, { label: string; rows: Row[] }> = {};

    for (const row of output
        ? ale(output.categories.filter((one) => one.kind === 'category'))
        : []) {
        foi[row.section] = foi[row.section] ?? {
            label: row.section.replace('category:', ''),
            rows: [],
        };

        foi[row.section].rows.push(row);
    }

    const categorii = Object.keys(foi).sort((a, b) => a.localeCompare(b));

    if (output) {
        const charter = ale(output.charter);
        const opex = ale([...output.opex, ...output.below]);

        if (charter.length > 0) {
            foi.charter = { label: 'Charter · curse', rows: charter };
        }

        if (opex.length > 0) {
            foi.opex = { label: 'Cheltuieli și sub EBITDA', rows: opex };
        }
    }

    // Agențiile au alt tabel decât restul: oameni, salariu mediu și bonus,
    // nu pasageri și bani pe pasager.
    const shops = output ? ale(output.retail) : [];

    // Ordinea filelor: categoriile pe alfabet, pe urmă cursele, agențiile și
    // la coadă cheltuielile.
    const file = [
        ...categorii,
        ...(foi.charter ? ['charter'] : []),
        ...(shops.length > 0 ? ['retail'] : []),
        ...(foi.opex ? ['opex'] : []),
    ];

    // Câte bucăți de buget au fost trimise spre consolidare. Conducerea le
    // vede pe toate; ceilalți, numai pe ale lor — altfel fila de predare ar
    // arăta cifrele întregului buget oricui are o bucată din el.
    const strânse = sections.filter((one) => one.status !== 'draft').length;
    const alemele = sections.filter((one) => mine.includes(one.key));

    // Rândul care se adaugă acum: o cursă nouă, o categorie nouă.
    const [adding, setAdding] = useState(false);
    const [row, setRow] = useState({
        key: '',
        capacity: 180,
        load: 85,
        unit_net: 0,
        unit_margin: 0,
        unit_cost: 0,
        volume: 0,
        amount: 0,
        months: [] as number[],
    });

    // Categoria de produs care se deschide acum: un produs pe care nu l-am
    // vândut niciodată, deci fără istoric — și omul care o ține de-acum.
    const [adding2, setAdding2] = useState(false);
    const [cat, setCat] = useState({
        key: '',
        department: '',
        volume: 0,
        unit_net: 0,
        unit_margin: 0,
        months: [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12],
    });

    // Categoria care iese din plan, cu tot cu sub-liniile ei.
    const [dropping, setDropping] = useState(false);

    // Varianta care se scrie acum: numele ei și de ce s-a salvat.
    const [version, setVersion] = useState({ label: '', note: '' });
    const [saving, setSaving] = useState(false);
    const [view, setView] = useState<'produs' | 'canal' | 'luna'>('produs');

    // Perioada se ține într-un singur câmp („ytd7”), dar se alege din două
    // controale: luna și felul în care se citește — ca pe P&L.
    const periodMatch = /^(ytd|mtd)(\d{1,2})$/.exec(filters.period);
    const periodMode = periodMatch?.[1] === 'mtd' ? 'mtd' : 'ytd';
    const periodMonth = periodMatch ? Number(periodMatch[2]) : 12;
    // Fila deschisă din Configurare: o categorie de produs („category:Cazare”),
    // cursele, cheltuielile sau rețeaua de agenții.
    const [sheet, setSheet] = useState<string>('charter');
    // Fila deschisă e o categorie de produs („category:Cazare”) sau una din
    // cele trei file de casă: cursele, agențiile, cheltuielile.
    const categorie = sheet.startsWith('category:');
    const numele = categorie ? sheet.replace('category:', '') : '';
    // Bucata din spatele filei. Numai categoriile de produs se scot din
    // plan: fără rețeaua de agenții și fără cheltuieli n-ar mai fi un plan.
    const sectiunea = foi[sheet]?.rows[0]?.section ?? '';
    const sescoate = sectiunea.startsWith('category:');
    const [open, setOpen] = useState<Set<string>>(new Set());
    // Ce s-a scris și încă nu s-a salvat: celule (o lună) și rânduri (tot
    // anul). Se trimit o dată, la „Salvează”.
    const [cells, setCells] = useState<
        Record<
            string,
            {
                id: number;
                field: string;
                value: number;
                label: string;
                month: string;
                before: number;
            }
        >
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
                label?: string;
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
                label: row.label,
            },
        }));

    const resetRow = (row: Row) =>
        plan &&
        router.put(
            `/reports/forecast/${plan.id}/row/reset`,
            { kind: row.kind, key: row.key },
            { preserveScroll: true, preserveState: true },
        );

    // Scoaterea unui rând nu e o ștergere de linii: planul ține minte că nu-l
    // mai vrea, altfel împrospătarea din istoric l-ar aduce înapoi.
    const removeRow = (row: Row) =>
        plan &&
        confirm(
            `Scoți „${row.label}” din planul ${plan.year}? Se poate pune înapoi de sub tabel.`,
        ) &&
        router.delete(`/reports/forecast/${plan.id}/rows`, {
            data: { kind: row.kind, key: row.key },
            preserveScroll: true,
        });

    const editCell = (
        cell: Cell,
        field: string,
        value: number,
        row?: Row,
        month?: string,
    ) =>
        setCells((prev) => ({
            ...prev,
            [`${cell.id}|${field}`]: {
                id: cell.id,
                field,
                value,
                label: row?.label ?? '',
                month: month ?? '',
                // De la ce s-a pornit: fără asta, lista schimbărilor ar
                // spune doar unde s-a scris, nu și ce s-a schimbat.
                before:
                    prev[`${cell.id}|${field}`]?.before ??
                    (cell[field as keyof Cell] as number) ??
                    0,
            },
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
                            <div className="grid gap-1.5">
                                <Label>Pornirea planului</Label>
                                <ToggleGroup
                                    type="single"
                                    value={plan.start}
                                    onValueChange={(v) =>
                                        v &&
                                        router.put(
                                            `/reports/forecast/${plan.id}/start`,
                                            { start: v },
                                            { preserveScroll: true },
                                        )
                                    }
                                    variant="outline"
                                >
                                    <ToggleGroupItem
                                        value="trend"
                                        title={`Pârghiile pornesc din ${plan.base_year} și se duc mai departe cu creșterea medie a ultimilor trei ani.`}
                                    >
                                        Trend 3 ani
                                    </ToggleGroupItem>
                                    <ToggleGroupItem
                                        value="base"
                                        title={`Pârghiile sunt cele din ${plan.base_year}, fără nicio creștere peste ele.`}
                                    >
                                        {plan.base_year} ca atare
                                    </ToggleGroupItem>
                                </ToggleGroup>
                            </div>

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
                    <div className="sticky top-0 z-30 space-y-2 rounded-md border border-amber-300 bg-amber-50 px-4 py-2 text-sm dark:border-amber-800 dark:bg-amber-950/40">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <span>
                                <strong>{pending}</strong>{' '}
                                {pending === 1
                                    ? 'schimbare nesalvată'
                                    : 'schimbări nesalvate'}
                                . Scrie liniștit mai departe; se trimit toate
                                odată.
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

                        {/* Ce anume s-a schimbat: fără lista asta, omul vede
                            doar că are „3 schimbări”, nu și care sunt. */}
                        <ul className="divide-y divide-amber-200 text-xs dark:divide-amber-900">
                            {Object.entries(rowEdits).map(([key, edit]) => (
                                <li
                                    key={key}
                                    className="flex flex-wrap items-baseline gap-x-2 py-1"
                                >
                                    <span className="font-medium">
                                        {edit.label ?? edit.key}
                                    </span>
                                    <span className="text-muted-foreground">
                                        · tot anul ·
                                    </span>
                                    <span>
                                        {FIELDS[edit.field] ?? edit.field}
                                    </span>
                                    <span className="font-medium tabular-nums">
                                        {edit.growth !== null &&
                                        edit.growth !== undefined
                                            ? `${edit.growth > 0 ? '+' : ''}${lei(edit.growth, 1)}%`
                                            : lei(edit.value ?? 0)}
                                    </span>
                                    <button
                                        type="button"
                                        className="text-muted-foreground underline hover:text-foreground"
                                        onClick={() =>
                                            setRowEdits((prev) => {
                                                const next = { ...prev };
                                                delete next[key];

                                                return next;
                                            })
                                        }
                                    >
                                        scoate
                                    </button>
                                </li>
                            ))}
                            {Object.entries(cells).map(([key, cell]) => (
                                <li
                                    key={key}
                                    className="flex flex-wrap items-baseline gap-x-2 py-1"
                                >
                                    <span className="font-medium">
                                        {cell.label}
                                    </span>
                                    <span className="text-muted-foreground">
                                        · {cell.month} ·
                                    </span>
                                    <span>
                                        {FIELDS[cell.field] ?? cell.field}
                                    </span>
                                    <span className="text-muted-foreground tabular-nums">
                                        {lei(
                                            cell.field === 'load'
                                                ? cell.before * 100
                                                : cell.before,
                                            1,
                                        )}
                                        {cell.field === 'load' ? '%' : ''}
                                    </span>
                                    <span aria-hidden="true">→</span>
                                    <span className="font-medium tabular-nums">
                                        {lei(
                                            cell.field === 'load'
                                                ? cell.value * 100
                                                : cell.value,
                                            1,
                                        )}
                                        {cell.field === 'load' ? '%' : ''}
                                    </span>
                                    <button
                                        type="button"
                                        className="text-muted-foreground underline hover:text-foreground"
                                        onClick={() =>
                                            setCells((prev) => {
                                                const next = { ...prev };
                                                delete next[key];

                                                return next;
                                            })
                                        }
                                    >
                                        scoate
                                    </button>
                                </li>
                            ))}
                        </ul>
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
                        {boss && (
                            <PlanHeader output={output} planYear={plan.year} />
                        )}

                        {!boss && (
                            <p className="text-xs text-muted-foreground">
                                Vedeți și scrieți numai bucățile bugetului
                                ținute de departamentul dumneavoastră. Când o
                                bucată e gata, trimiteți-o spre consolidare din
                                fila „Secțiuni”: de-atunci nu se mai umblă la ea
                                până n-o dă conducerea înapoi.
                            </p>
                        )}

                        {/* Fila deschisă trebuie să fie una a lui: dacă n-are
                            categorii, se trece pe curse, și așa mai departe. */}
                        {tab === 'configurare' &&
                            file.length > 0 &&
                            !file.includes(sheet) &&
                            (() => {
                                setSheet(file[0]);

                                return null;
                            })()}

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
                                creșterea medie a celor trei ani. Cu butonul
                                „Pornirea planului” se poate cere, în schimb,
                                anul de bază ca atare, fără nicio creștere.
                            </div>
                        )}

                        <Tabs
                            value={tab}
                            onValueChange={(v) => setTab(v as typeof tab)}
                        >
                            <TabsList>
                                {boss && (
                                    <TabsTrigger value="plan">
                                        Plan {plan.year}
                                    </TabsTrigger>
                                )}
                                {boss && current !== null && (
                                    <TabsTrigger value="curent">
                                        Anul în curs {current.year}
                                    </TabsTrigger>
                                )}
                                <TabsTrigger value="configurare">
                                    {boss ? 'Configurare' : 'Partea mea'}
                                </TabsTrigger>
                                <TabsTrigger value="sectiuni">
                                    {boss
                                        ? `Predarea bugetului (${strânse} din ${sections.length} trimise)`
                                        : `Trimite partea mea (${alemele.filter((one) => one.status !== 'draft').length} din ${alemele.length})`}
                                </TabsTrigger>
                                {boss && (
                                    <TabsTrigger value="variante">
                                        Variante ({versions.length})
                                    </TabsTrigger>
                                )}
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

                        {tab === 'sectiuni' && (
                            <Sections
                                sections={boss ? sections : alemele}
                                mine={mine}
                                boss={boss}
                                planId={plan.id}
                            />
                        )}

                        {tab === 'curent' && current !== null && (
                            <CurrentYear
                                current={current}
                                planYear={plan.year}
                            />
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
                            <Tabs value={sheet} onValueChange={setSheet}>
                                <TabsList className="flex-wrap">
                                    {/* Câte o filă de fiecare categorie de
                                        produs, pe urmă cursele, agențiile și
                                        cheltuielile. Cine n-are decât o
                                        categorie vede o singură filă. */}
                                    {file.map((one) => (
                                        <TabsTrigger key={one} value={one}>
                                            {one === 'retail'
                                                ? 'Rețeaua de agenții'
                                                : foi[one].label}{' '}
                                            (
                                            {one === 'retail'
                                                ? shops.length
                                                : foi[one].rows.length}
                                            )
                                        </TabsTrigger>
                                    ))}
                                </TabsList>
                            </Tabs>
                        )}

                        {tab === 'configurare' && (
                            <p className="text-xs text-muted-foreground">
                                {sheet === 'charter'
                                    ? 'Cursele se citesc pe luna plecării: locurile sunt cele contractate în eTrip pentru anul plănuit, iar ocuparea și banii pe pasager vin de pe aceeași cursă din anul de bază. Volumul lor nu urmează niciun trend — un loc contractat e o faptă, nu o presupunere.'
                                    : categorie
                                      ? 'Categoriile se citesc pe luna rezervării, ca vederea operațională din P&L: pasagerii sunt cei care au cumpărat în luna aia, oricând ar plăti sau ar pleca. Tina se numără pe servicii facturate, la data facturii.'
                                      : 'Cheltuielile se citesc pe luna din registru, ca în raport: acolo unde a căzut documentul, nu unde s-a consumat serviciul.'}{' '}
                                Pe fila Plan, tot ce nu stă pe baza aleasă sus e
                                mutat acolo, după tiparul rezervare → plecare
                                din anul de bază.
                            </p>
                        )}

                        {tab === 'configurare' && (
                            <div className="flex flex-wrap items-center gap-3">
                                <Dialog open={adding} onOpenChange={setAdding}>
                                    <DialogTrigger asChild>
                                        <Button variant="outline" size="sm">
                                            <Plus className="size-4" />
                                            {sheet === 'charter'
                                                ? 'Cursă nouă'
                                                : categorie
                                                  ? 'Sub-linie nouă'
                                                  : sheet === 'retail'
                                                    ? 'Agenție nouă'
                                                    : 'Linie nouă'}
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogHeader>
                                            <DialogTitle>
                                                {sheet === 'charter'
                                                    ? 'Cursă nouă în plan'
                                                    : categorie
                                                      ? `Sub-linie nouă la ${numele}`
                                                      : sheet === 'retail'
                                                        ? 'Agenție nouă în plan'
                                                        : 'Linie nouă de cheltuială'}
                                            </DialogTitle>
                                            <DialogDescription>
                                                {sheet === 'charter'
                                                    ? 'O cursă pe care o zburăm abia anul viitor nu are istoric: locurile, ocuparea și banii pe pasager se scriu aici. Alege lunile în care zboară.'
                                                    : categorie
                                                      ? 'O destinație sau un program nou, care n-are istoric: pasagerii și banii pe pasager se scriu aici. Rândul rămâne la categoria asta și intră în cifra ei.'
                                                      : sheet === 'retail'
                                                        ? 'O agenție care se deschide anul viitor n-are vânzări în istoric: scrie-i vânzarea așteptată pe lună, oamenii și bonusul. Alege lunile în care e deschisă.'
                                                        : 'Un rând care nu vine din istoric: se scrie de mână și nu se mai calcă la împrospătare.'}
                                            </DialogDescription>
                                        </DialogHeader>
                                        <div className="grid gap-3">
                                            <div className="grid gap-1.5">
                                                <Label>
                                                    {sheet === 'charter'
                                                        ? 'Cursa (ex. OTP→AYT)'
                                                        : categorie
                                                          ? `Sub-linia (ex. Grecia) — intră sub „${numele}”`
                                                          : sheet === 'retail'
                                                            ? 'Agenția (ex. Oradea Lotus)'
                                                            : 'Numele rândului'}
                                                </Label>
                                                <Input
                                                    value={row.key}
                                                    onChange={(e) =>
                                                        setRow({
                                                            ...row,
                                                            key: e.target.value,
                                                        })
                                                    }
                                                />
                                            </div>

                                            <div className="grid grid-cols-3 gap-3">
                                                {sheet === 'charter' ? (
                                                    <>
                                                        <div className="grid gap-1.5">
                                                            <Label>
                                                                Locuri / lună
                                                            </Label>
                                                            <Input
                                                                type="number"
                                                                value={
                                                                    row.capacity
                                                                }
                                                                onChange={(e) =>
                                                                    setRow({
                                                                        ...row,
                                                                        capacity:
                                                                            Number(
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                            ),
                                                                    })
                                                                }
                                                            />
                                                        </div>
                                                        <div className="grid gap-1.5">
                                                            <Label>
                                                                Ocupare (%)
                                                            </Label>
                                                            <Input
                                                                type="number"
                                                                value={row.load}
                                                                onChange={(e) =>
                                                                    setRow({
                                                                        ...row,
                                                                        load: Number(
                                                                            e
                                                                                .target
                                                                                .value,
                                                                        ),
                                                                    })
                                                                }
                                                            />
                                                        </div>
                                                        <div className="grid gap-1.5">
                                                            <Label>
                                                                Cost / loc (lei)
                                                            </Label>
                                                            <Input
                                                                type="number"
                                                                value={
                                                                    row.unit_cost
                                                                }
                                                                onChange={(e) =>
                                                                    setRow({
                                                                        ...row,
                                                                        unit_cost:
                                                                            Number(
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                            ),
                                                                    })
                                                                }
                                                            />
                                                        </div>
                                                    </>
                                                ) : sheet === 'retail' ? (
                                                    <>
                                                        <div className="grid gap-1.5">
                                                            <Label>
                                                                Oameni (FTE)
                                                            </Label>
                                                            <Input
                                                                type="number"
                                                                value={
                                                                    row.volume
                                                                }
                                                                onChange={(e) =>
                                                                    setRow({
                                                                        ...row,
                                                                        volume: Number(
                                                                            e
                                                                                .target
                                                                                .value,
                                                                        ),
                                                                    })
                                                                }
                                                            />
                                                        </div>
                                                        <div className="grid gap-1.5">
                                                            <Label>
                                                                Cost / om
                                                                (lei/lună)
                                                            </Label>
                                                            <Input
                                                                type="number"
                                                                value={
                                                                    row.unit_cost
                                                                }
                                                                onChange={(e) =>
                                                                    setRow({
                                                                        ...row,
                                                                        unit_cost:
                                                                            Number(
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                            ),
                                                                    })
                                                                }
                                                            />
                                                        </div>
                                                        <div className="grid gap-1.5">
                                                            <Label>
                                                                Vânzări
                                                                așteptate
                                                                (lei/lună)
                                                            </Label>
                                                            <Input
                                                                type="number"
                                                                value={
                                                                    row.unit_net
                                                                }
                                                                onChange={(e) =>
                                                                    setRow({
                                                                        ...row,
                                                                        unit_net:
                                                                            Number(
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                            ),
                                                                    })
                                                                }
                                                            />
                                                        </div>
                                                    </>
                                                ) : categorie ? (
                                                    <div className="grid gap-1.5">
                                                        <Label>
                                                            Volum / lună (nr.)
                                                        </Label>
                                                        <Input
                                                            type="number"
                                                            value={row.volume}
                                                            onChange={(e) =>
                                                                setRow({
                                                                    ...row,
                                                                    volume: Number(
                                                                        e.target
                                                                            .value,
                                                                    ),
                                                                })
                                                            }
                                                        />
                                                    </div>
                                                ) : (
                                                    <div className="grid gap-1.5">
                                                        <Label>
                                                            Sumă / lună (lei)
                                                        </Label>
                                                        <Input
                                                            type="number"
                                                            value={row.amount}
                                                            onChange={(e) =>
                                                                setRow({
                                                                    ...row,
                                                                    amount: Number(
                                                                        e.target
                                                                            .value,
                                                                    ),
                                                                })
                                                            }
                                                        />
                                                    </div>
                                                )}

                                                {sheet === 'retail' && (
                                                    <div className="grid gap-1.5">
                                                        <Label>
                                                            Bonus (% din
                                                            vânzări)
                                                        </Label>
                                                        <Input
                                                            type="number"
                                                            value={row.load}
                                                            onChange={(e) =>
                                                                setRow({
                                                                    ...row,
                                                                    load: Number(
                                                                        e.target
                                                                            .value,
                                                                    ),
                                                                })
                                                            }
                                                        />
                                                    </div>
                                                )}

                                                {sheet !== 'opex' &&
                                                    sheet !== 'retail' && (
                                                        <>
                                                            <div className="grid gap-1.5">
                                                                <Label>
                                                                    Venit /
                                                                    unitate
                                                                    (lei)
                                                                </Label>
                                                                <Input
                                                                    type="number"
                                                                    value={
                                                                        row.unit_net
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        setRow({
                                                                            ...row,
                                                                            unit_net:
                                                                                Number(
                                                                                    e
                                                                                        .target
                                                                                        .value,
                                                                                ),
                                                                        })
                                                                    }
                                                                />
                                                            </div>
                                                            <div className="grid gap-1.5">
                                                                <Label>
                                                                    Marjă /
                                                                    unitate
                                                                    (lei)
                                                                </Label>
                                                                <Input
                                                                    type="number"
                                                                    value={
                                                                        row.unit_margin
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        setRow({
                                                                            ...row,
                                                                            unit_margin:
                                                                                Number(
                                                                                    e
                                                                                        .target
                                                                                        .value,
                                                                                ),
                                                                        })
                                                                    }
                                                                />
                                                            </div>
                                                        </>
                                                    )}
                                            </div>

                                            <div className="grid gap-1.5">
                                                <Label>
                                                    Lunile în care se întâmplă
                                                </Label>
                                                <div className="flex flex-wrap gap-1">
                                                    {MONTHS.map((name, i) => (
                                                        <Button
                                                            key={name}
                                                            type="button"
                                                            size="sm"
                                                            variant={
                                                                row.months.includes(
                                                                    i + 1,
                                                                )
                                                                    ? 'default'
                                                                    : 'outline'
                                                            }
                                                            onClick={() =>
                                                                setRow({
                                                                    ...row,
                                                                    months: row.months.includes(
                                                                        i + 1,
                                                                    )
                                                                        ? row.months.filter(
                                                                              (
                                                                                  m,
                                                                              ) =>
                                                                                  m !==
                                                                                  i +
                                                                                      1,
                                                                          )
                                                                        : [
                                                                              ...row.months,
                                                                              i +
                                                                                  1,
                                                                          ],
                                                                })
                                                            }
                                                        >
                                                            {name}
                                                        </Button>
                                                    ))}
                                                </div>
                                            </div>
                                        </div>
                                        <DialogFooter>
                                            <Button
                                                disabled={
                                                    row.key.trim() === '' ||
                                                    row.months.length === 0
                                                }
                                                onClick={() =>
                                                    router.post(
                                                        `/reports/forecast/${plan.id}/rows`,
                                                        {
                                                            kind:
                                                                sheet ===
                                                                'charter'
                                                                    ? 'charter'
                                                                    : categorie
                                                                      ? 'category'
                                                                      : sheet ===
                                                                          'retail'
                                                                        ? 'retail'
                                                                        : 'opex',
                                                            // Pe fila unei categorii, rândul
                                                            // nou e o sub-linie a ei: așa
                                                            // rămâne la același stăpân.
                                                            key: categorie
                                                                ? `${numele} · ${row.key}`
                                                                : row.key,
                                                            label: categorie
                                                                ? `${numele} · ${row.key}`
                                                                : row.key,
                                                            months: row.months,
                                                            capacity:
                                                                sheet ===
                                                                'charter'
                                                                    ? row.capacity
                                                                    : null,
                                                            load:
                                                                sheet ===
                                                                    'charter' ||
                                                                sheet ===
                                                                    'retail'
                                                                    ? row.load /
                                                                      100
                                                                    : null,
                                                            volume:
                                                                categorie ||
                                                                sheet ===
                                                                    'retail'
                                                                    ? row.volume
                                                                    : null,
                                                            amount:
                                                                sheet === 'opex'
                                                                    ? row.amount
                                                                    : null,
                                                            // La o agenție, „venitul pe unitate”
                                                            // e vânzarea ei așteptată pe lună: din
                                                            // ea se naște bonusul și tot după ea se
                                                            // împarte masa salarială.
                                                            unit_net:
                                                                sheet !== 'opex'
                                                                    ? row.unit_net
                                                                    : null,
                                                            unit_margin:
                                                                sheet !== 'opex'
                                                                    ? row.unit_margin
                                                                    : null,
                                                            unit_cost:
                                                                sheet ===
                                                                    'charter' ||
                                                                sheet ===
                                                                    'retail'
                                                                    ? row.unit_cost
                                                                    : null,
                                                        },
                                                        {
                                                            preserveScroll: true,
                                                            onSuccess: () =>
                                                                setAdding(
                                                                    false,
                                                                ),
                                                        },
                                                    )
                                                }
                                            >
                                                Adaugă în plan
                                            </Button>
                                        </DialogFooter>
                                    </DialogContent>
                                </Dialog>

                                {/* Categoriile de produs se deschid și se
                                    închid: un produs nou n-are istoric, iar
                                    unul pe care nu-l mai vindem n-are ce
                                    căuta în planul anului viitor. */}
                                {boss && (
                                    <Dialog
                                        open={adding2}
                                        onOpenChange={setAdding2}
                                    >
                                        <DialogTrigger asChild>
                                            <Button variant="outline" size="sm">
                                                <Plus className="size-4" />
                                                Categorie nouă
                                            </Button>
                                        </DialogTrigger>
                                        <DialogContent>
                                            <DialogHeader>
                                                <DialogTitle>
                                                    Categorie de produs nouă
                                                </DialogTitle>
                                                <DialogDescription>
                                                    Un produs pe care nu l-am
                                                    vândut niciodată n-are
                                                    istoric: pasagerii și banii
                                                    pe pasager se scriu aici.
                                                    Categoria își primește fila
                                                    ei și departamentul care o
                                                    ține — șeful lui o scrie și
                                                    o trimite spre consolidare.
                                                </DialogDescription>
                                            </DialogHeader>
                                            <div className="grid gap-3">
                                                <div className="grid gap-1.5">
                                                    <Label>
                                                        Categoria (ex. Croaziere
                                                        fluviale)
                                                    </Label>
                                                    <Input
                                                        value={cat.key}
                                                        onChange={(e) =>
                                                            setCat({
                                                                ...cat,
                                                                key: e.target
                                                                    .value,
                                                            })
                                                        }
                                                    />
                                                </div>

                                                <div className="grid gap-1.5">
                                                    <Label>
                                                        Departamentul care o
                                                        ține
                                                    </Label>
                                                    <Select
                                                        value={cat.department}
                                                        onValueChange={(v) =>
                                                            setCat({
                                                                ...cat,
                                                                department: v,
                                                            })
                                                        }
                                                    >
                                                        <SelectTrigger>
                                                            <SelectValue placeholder="Alege departamentul" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {departments.map(
                                                                (one) => (
                                                                    <SelectItem
                                                                        key={
                                                                            one
                                                                        }
                                                                        value={
                                                                            one
                                                                        }
                                                                    >
                                                                        {one}
                                                                    </SelectItem>
                                                                ),
                                                            )}
                                                        </SelectContent>
                                                    </Select>
                                                </div>

                                                <div className="grid grid-cols-3 gap-3">
                                                    <div className="grid gap-1.5">
                                                        <Label>
                                                            Pasageri / lună
                                                            (nr.)
                                                        </Label>
                                                        <Input
                                                            type="number"
                                                            value={cat.volume}
                                                            onChange={(e) =>
                                                                setCat({
                                                                    ...cat,
                                                                    volume: Number(
                                                                        e.target
                                                                            .value,
                                                                    ),
                                                                })
                                                            }
                                                        />
                                                    </div>
                                                    <div className="grid gap-1.5">
                                                        <Label>
                                                            Venit / pax (lei)
                                                        </Label>
                                                        <Input
                                                            type="number"
                                                            value={cat.unit_net}
                                                            onChange={(e) =>
                                                                setCat({
                                                                    ...cat,
                                                                    unit_net:
                                                                        Number(
                                                                            e
                                                                                .target
                                                                                .value,
                                                                        ),
                                                                })
                                                            }
                                                        />
                                                    </div>
                                                    <div className="grid gap-1.5">
                                                        <Label>
                                                            Marjă / pax (lei)
                                                        </Label>
                                                        <Input
                                                            type="number"
                                                            value={
                                                                cat.unit_margin
                                                            }
                                                            onChange={(e) =>
                                                                setCat({
                                                                    ...cat,
                                                                    unit_margin:
                                                                        Number(
                                                                            e
                                                                                .target
                                                                                .value,
                                                                        ),
                                                                })
                                                            }
                                                        />
                                                    </div>
                                                </div>

                                                <div className="grid gap-1.5">
                                                    <Label>
                                                        Lunile în care se vinde
                                                    </Label>
                                                    <div className="flex flex-wrap gap-1">
                                                        {MONTHS.map(
                                                            (name, i) => (
                                                                <Button
                                                                    key={name}
                                                                    type="button"
                                                                    size="sm"
                                                                    variant={
                                                                        cat.months.includes(
                                                                            i +
                                                                                1,
                                                                        )
                                                                            ? 'default'
                                                                            : 'outline'
                                                                    }
                                                                    onClick={() =>
                                                                        setCat({
                                                                            ...cat,
                                                                            months: cat.months.includes(
                                                                                i +
                                                                                    1,
                                                                            )
                                                                                ? cat.months.filter(
                                                                                      (
                                                                                          m,
                                                                                      ) =>
                                                                                          m !==
                                                                                          i +
                                                                                              1,
                                                                                  )
                                                                                : [
                                                                                      ...cat.months,
                                                                                      i +
                                                                                          1,
                                                                                  ],
                                                                        })
                                                                    }
                                                                >
                                                                    {name}
                                                                </Button>
                                                            ),
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                            <DialogFooter>
                                                <Button
                                                    disabled={
                                                        cat.key.trim() === '' ||
                                                        cat.months.length === 0
                                                    }
                                                    onClick={() =>
                                                        router.post(
                                                            `/reports/forecast/${plan.id}/rows`,
                                                            {
                                                                kind: 'category',
                                                                key: cat.key.trim(),
                                                                label: cat.key.trim(),
                                                                department:
                                                                    cat.department ||
                                                                    null,
                                                                months: cat.months,
                                                                volume: cat.volume,
                                                                unit_net:
                                                                    cat.unit_net,
                                                                unit_margin:
                                                                    cat.unit_margin,
                                                            },
                                                            {
                                                                preserveScroll: true,
                                                                onSuccess:
                                                                    () => {
                                                                        setSheet(
                                                                            `category:${cat.key.trim()}`,
                                                                        );
                                                                        setAdding2(
                                                                            false,
                                                                        );
                                                                    },
                                                            },
                                                        )
                                                    }
                                                >
                                                    Deschide categoria
                                                </Button>
                                            </DialogFooter>
                                        </DialogContent>
                                    </Dialog>
                                )}

                                {sescoate && (
                                    <Dialog
                                        open={dropping}
                                        onOpenChange={setDropping}
                                    >
                                        <DialogTrigger asChild>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                className="text-destructive"
                                            >
                                                <Trash2 className="size-4" />
                                                Scoate{' '}
                                                {foi[sheet]?.label ?? 'fila'}
                                            </Button>
                                        </DialogTrigger>
                                        <DialogContent>
                                            <DialogHeader>
                                                <DialogTitle>
                                                    Scoatem „{foi[sheet]?.label}
                                                    ” din plan?
                                                </DialogTitle>
                                                <DialogDescription>
                                                    Ies toate cele{' '}
                                                    {foi[sheet]?.rows.length}{' '}
                                                    rânduri ale ei, cu tot cu
                                                    sub-linii, și fila se
                                                    închide. Planul ține minte,
                                                    deci nici împrospătarea din
                                                    istoric nu le mai aduce
                                                    înapoi — dar se pot pune la
                                                    loc de sub tabel.
                                                </DialogDescription>
                                            </DialogHeader>
                                            <DialogFooter>
                                                <Button
                                                    variant="outline"
                                                    onClick={() =>
                                                        setDropping(false)
                                                    }
                                                >
                                                    Lasă-o în plan
                                                </Button>
                                                <Button
                                                    variant="destructive"
                                                    onClick={() =>
                                                        router.delete(
                                                            `/reports/forecast/${plan.id}/sections`,
                                                            {
                                                                data: {
                                                                    key: sectiunea,
                                                                },
                                                                preserveScroll: true,
                                                                onSuccess:
                                                                    () => {
                                                                        setDropping(
                                                                            false,
                                                                        );
                                                                        // Fila s-a închis: se
                                                                        // deschide prima
                                                                        // rămasă.
                                                                        setSheet(
                                                                            '',
                                                                        );
                                                                    },
                                                            },
                                                        )
                                                    }
                                                >
                                                    Scoate categoria
                                                </Button>
                                            </DialogFooter>
                                        </DialogContent>
                                    </Dialog>
                                )}

                                {plan.removed.length > 0 && (
                                    <span className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                        Scoase din plan:
                                        {plan.removed.map((one) => (
                                            <Button
                                                key={one}
                                                variant="ghost"
                                                size="sm"
                                                className="h-6 text-xs"
                                                title="Pune rândul înapoi, cu cifrele din istoric."
                                                onClick={() =>
                                                    router.put(
                                                        `/reports/forecast/${plan.id}/rows/restore`,
                                                        {
                                                            kind: one.split(
                                                                '|',
                                                            )[0],
                                                            key: one
                                                                .split('|')
                                                                .slice(1)
                                                                .join('|'),
                                                        },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                <RotateCcw className="size-3" />
                                                {one
                                                    .split('|')
                                                    .slice(1)
                                                    .join('|')}
                                            </Button>
                                        ))}
                                    </span>
                                )}
                            </div>
                        )}

                        {tab === 'configurare' && sheet === 'retail' && (
                            <RetailTable
                                shops={shops}
                                planId={plan.id}
                                planYear={plan.year}
                            />
                        )}

                        {tab === 'configurare' && sheet !== 'retail' && (
                            <DriverTable
                                rows={foi[sheet]?.rows ?? []}
                                kind={
                                    categorie
                                        ? 'categorii'
                                        : (sheet as 'charter' | 'opex')
                                }
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
                                onRemove={removeRow}
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
    onRemove,
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
    cells: Record<
        string,
        {
            id: number;
            field: string;
            value: number;
            label: string;
            month: string;
            before: number;
        }
    >;
    rowEdits: Record<
        string,
        {
            kind: string;
            key: string;
            field: string;
            value: number | null;
            growth: number | null;
            label?: string;
        }
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
    onRemove: (row: Row) => void;
    onEditCell: (
        cell: Cell,
        field: string,
        value: number,
        row?: Row,
        month?: string,
    ) => void;
}) {
    const isCharter = kind === 'charter';
    const isOpex = kind === 'opex';

    // Ce arată o căsuță: cifra scrisă acum, dacă s-a scris ceva și nu s-a
    // salvat încă, altfel cea din plan. Altfel omul tastează, cifra sare
    // înapoi la ce era și nu mai știe ce a schimbat.
    const cellValue = (cell: Cell, field: string, saved: number) =>
        cells[`${cell.id}|${field}`]?.value ?? saved;

    const cellChanged = (cell: Cell, field: string) =>
        cells[`${cell.id}|${field}`] !== undefined;

    const rowValue = (row: Row, field: string, saved: number) =>
        rowEdits[`${row.kind}|${row.key}|${field}`]?.value ?? saved;

    const rowChanged = (row: Row, field: string) =>
        rowEdits[`${row.kind}|${row.key}|${field}`] !== undefined;

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
                            <TableHead className="w-20" />
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
                                                title={
                                                    past.estimated
                                                        ? `${year}: ${lei(past.net)} lei, din care ${past.estimated} luni estimate — anul n-a venit încă până la capăt.`
                                                        : `${year}: ${lei(past.net)} lei venit, ${lei(past.margin)} marjă`
                                                }
                                            >
                                                {isOpex ? (
                                                    <>
                                                        {lei(past.net)}
                                                        {past.estimated ? (
                                                            <span className="ml-1 text-[10px] opacity-70">
                                                                (
                                                                {past.estimated}{' '}
                                                                est.)
                                                            </span>
                                                        ) : null}
                                                    </>
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
                                                        rowValue(
                                                            row,
                                                            'load',
                                                            row.seats > 0
                                                                ? row.pax /
                                                                      row.seats
                                                                : 0,
                                                        ) * 100
                                                    }
                                                    pending={rowChanged(
                                                        row,
                                                        'load',
                                                    )}
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
                                                    value={rowValue(
                                                        row,
                                                        'unit_net',
                                                        row.pax > 0
                                                            ? row.net / row.pax
                                                            : 0,
                                                    )}
                                                    pending={rowChanged(
                                                        row,
                                                        'unit_net',
                                                    )}
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
                                                    value={rowValue(
                                                        row,
                                                        'unit_margin',
                                                        row.pax > 0
                                                            ? row.margin /
                                                                  row.pax
                                                            : 0,
                                                    )}
                                                    pending={rowChanged(
                                                        row,
                                                        'unit_margin',
                                                    )}
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
                                            value={
                                                rowEdits[
                                                    `${row.kind}|${row.key}|${isOpex ? 'amount' : isCharter ? 'capacity' : 'volume'}`
                                                ]?.growth ?? 0
                                            }
                                            pending={
                                                rowEdits[
                                                    `${row.kind}|${row.key}|${isOpex ? 'amount' : isCharter ? 'capacity' : 'volume'}`
                                                ]?.growth !== undefined &&
                                                rowEdits[
                                                    `${row.kind}|${row.key}|${isOpex ? 'amount' : isCharter ? 'capacity' : 'volume'}`
                                                ]?.growth !== null
                                            }
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
                                    <TableCell className="text-right whitespace-nowrap">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            title="Înapoi la cifrele din istoric"
                                            onClick={() => onReset(row)}
                                        >
                                            <RotateCcw className="size-3" />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            title={
                                                isCharter
                                                    ? 'Scoate cursa din plan: nu o mai zburăm anul viitor.'
                                                    : 'Scoate rândul din plan.'
                                            }
                                            onClick={() => onRemove(row)}
                                        >
                                            <Trash2 className="size-3" />
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
                                                ce a fost în aceeași lună din{' '}
                                                {historyYears.join(', ')}, ca
                                                reper.
                                            </div>
                                            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                                {MONTHS.map((name, i) => {
                                                    const cell =
                                                        row.months[i + 1];

                                                    if (!cell) {
                                                        // Luna n-a existat în
                                                        // istoric, dar poate
                                                        // că la anul o vrem:
                                                        // se adaugă aici.
                                                        const any =
                                                            Object.values(
                                                                row.months,
                                                            )[0];

                                                        return (
                                                            <div
                                                                key={name}
                                                                className="flex flex-col items-center justify-center gap-2 rounded-md border border-dashed p-3 text-center text-[11px] text-muted-foreground"
                                                            >
                                                                <span>
                                                                    {name}{' '}
                                                                    {planYear} ·
                                                                    n-a fost
                                                                    niciodată
                                                                </span>
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    className="h-7 text-[11px]"
                                                                    onClick={() =>
                                                                        router.post(
                                                                            `/reports/forecast/${planId}/rows`,
                                                                            {
                                                                                kind: row.kind,
                                                                                key: row.key,
                                                                                label: row.label,
                                                                                unit: row.unit,
                                                                                months: [
                                                                                    i +
                                                                                        1,
                                                                                ],
                                                                                capacity:
                                                                                    any?.capacity ??
                                                                                    null,
                                                                                load:
                                                                                    any?.load ??
                                                                                    null,
                                                                                volume:
                                                                                    any?.volume ??
                                                                                    null,
                                                                                amount: isOpex
                                                                                    ? (any?.amount ??
                                                                                      null)
                                                                                    : null,
                                                                                unit_net:
                                                                                    any?.unit_net ??
                                                                                    null,
                                                                                unit_margin:
                                                                                    any?.unit_margin ??
                                                                                    null,
                                                                                unit_cost:
                                                                                    any?.unit_cost ??
                                                                                    null,
                                                                                note: `Lună adăugată cu mâna: ${row.key} n-a avut ${name} în istoric.`,
                                                                            },
                                                                            {
                                                                                preserveScroll: true,
                                                                            },
                                                                        )
                                                                    }
                                                                >
                                                                    <Plus className="size-3" />
                                                                    Adaugă luna
                                                                </Button>
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
                                                                        value={cellValue(
                                                                            cell,
                                                                            isOpex
                                                                                ? 'amount'
                                                                                : isCharter
                                                                                  ? 'capacity'
                                                                                  : 'volume',
                                                                            isOpex
                                                                                ? cell.amount
                                                                                : isCharter
                                                                                  ? cell.capacity
                                                                                  : cell.volume,
                                                                        )}
                                                                        pending={cellChanged(
                                                                            cell,
                                                                            isOpex
                                                                                ? 'amount'
                                                                                : isCharter
                                                                                  ? 'capacity'
                                                                                  : 'volume',
                                                                        )}
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
                                                                                row,
                                                                                name,
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
                                                                                        ? cellValue(
                                                                                              cell,
                                                                                              'load',
                                                                                              cell.load,
                                                                                          ) *
                                                                                          100
                                                                                        : cellValue(
                                                                                              cell,
                                                                                              'unit_net',
                                                                                              cell.unit_net,
                                                                                          )
                                                                                }
                                                                                pending={cellChanged(
                                                                                    cell,
                                                                                    isCharter
                                                                                        ? 'load'
                                                                                        : 'unit_net',
                                                                                )}
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
                                                                                        row,
                                                                                        name,
                                                                                    )
                                                                                }
                                                                            />
                                                                        </label>
                                                                        <label className="text-[10px] text-muted-foreground">
                                                                            {isCharter
                                                                                ? 'venit/pax (lei)'
                                                                                : 'marjă/unit. (lei)'}
                                                                            <RowInput
                                                                                value={cellValue(
                                                                                    cell,
                                                                                    isCharter
                                                                                        ? 'unit_net'
                                                                                        : 'unit_margin',
                                                                                    isCharter
                                                                                        ? cell.unit_net
                                                                                        : cell.unit_margin,
                                                                                )}
                                                                                pending={cellChanged(
                                                                                    cell,
                                                                                    isCharter
                                                                                        ? 'unit_net'
                                                                                        : 'unit_margin',
                                                                                )}
                                                                                onCommit={(
                                                                                    v,
                                                                                ) =>
                                                                                    onEditCell(
                                                                                        cell,
                                                                                        isCharter
                                                                                            ? 'unit_net'
                                                                                            : 'unit_margin',
                                                                                        v,
                                                                                        row,
                                                                                        name,
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
                                                                {historyYears.map(
                                                                    (year) => {
                                                                        const was =
                                                                            history[
                                                                                `${row.kind}|${row.key}`
                                                                            ]?.[
                                                                                year
                                                                            ]
                                                                                ?.months?.[
                                                                                i +
                                                                                    1
                                                                            ];

                                                                        return (
                                                                            <div
                                                                                key={
                                                                                    year
                                                                                }
                                                                            >
                                                                                {
                                                                                    year
                                                                                }

                                                                                :{' '}
                                                                                {was
                                                                                    ? reper(
                                                                                          was,
                                                                                          isOpex,
                                                                                          isCharter,
                                                                                          row.unit,
                                                                                      )
                                                                                    : '–'}
                                                                            </div>
                                                                        );
                                                                    },
                                                                )}
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
                                                                            {historyYears
                                                                                .filter(
                                                                                    (
                                                                                        year,
                                                                                    ) =>
                                                                                        year <
                                                                                        baseYear,
                                                                                )
                                                                                .map(
                                                                                    (
                                                                                        year,
                                                                                    ) => {
                                                                                        const was =
                                                                                            history[
                                                                                                `${row.kind}|${row.key}`
                                                                                            ]?.[
                                                                                                year
                                                                                            ]
                                                                                                ?.months?.[
                                                                                                month
                                                                                            ];

                                                                                        return (
                                                                                            <div
                                                                                                key={
                                                                                                    year
                                                                                                }
                                                                                            >
                                                                                                {
                                                                                                    year
                                                                                                }

                                                                                                :{' '}
                                                                                                {was
                                                                                                    ? reper(
                                                                                                          was,
                                                                                                          isOpex,
                                                                                                          isCharter,
                                                                                                          row.unit,
                                                                                                      )
                                                                                                    : '–'}
                                                                                            </div>
                                                                                        );
                                                                                    },
                                                                                )}
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
    pending = false,
}: {
    value: number;
    onCommit: (value: number) => void;
    suffix?: string;
    placeholder?: string;
    /** Cifra din căsuță e scrisă acum și încă nu s-a salvat. */
    pending?: boolean;
}) {
    const [text, setText] = useState<string | null>(null);

    return (
        <span className="inline-flex items-center gap-1">
            <Input
                className={cn(
                    'h-7 w-20 text-right text-xs',
                    pending &&
                        'border-amber-400 bg-amber-50 font-medium text-amber-900 dark:bg-amber-950/40 dark:text-amber-200',
                )}
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
 * Antetul filei Plan: patru ani de context și ce atârnă greu.
 *
 * Cinci casete cu totalurile anului nu spuneau nimic ce nu se vede în
 * tabelul de dedesubt. Aici stă ce nu se vede: cum s-a mișcat fiecare cifră
 * în ultimii trei ani, și din ce stă planul în picioare — cât din venit
 * vine din curse, cât de plin trebuie să fie avionul, câte locuri rămân
 * goale și cât costă, și cât ia statul chiar când profitul e mic.
 */
function PlanHeader({
    output,
    planYear,
}: {
    output: Output;
    planYear: number;
}) {
    const years = output.years.filter((one) => one.built);
    const risk = output.risk;

    const share = (value: number, of: number) =>
        of > 0 ? (value / of) * 100 : 0;

    // Fiecare casetă: cifra planului, cei trei ani dinainte și o bară pe an.
    const cards = [
        {
            key: 'net',
            label: 'Venit net',
            now: mii(output.totals.net),
            unit: 'mii lei',
            series: years.map((one) => one.net ?? 0),
            plan: output.totals.net,
            good: true,
            foot: years.map((one) => mii(one.net ?? 0)).join(' · '),
        },
        {
            key: 'margin',
            label: 'Marjă brută',
            now: `${share(output.totals.margin, output.totals.net).toFixed(1)}%`,
            unit: 'din venit',
            series: years.map((one) => share(one.margin ?? 0, one.net ?? 0)),
            plan: share(output.totals.margin, output.totals.net),
            good: true,
            foot: years
                .map(
                    (one) =>
                        `${share(one.margin ?? 0, one.net ?? 0).toFixed(1)}%`,
                )
                .join(' · '),
        },
        {
            key: 'ebitda',
            label: 'EBITDA',
            now: mii(output.totals.ebitda),
            unit: 'mii lei',
            series: years.map((one) => one.ebitda ?? 0),
            plan: output.totals.ebitda,
            good: true,
            foot: years.map((one) => mii(one.ebitda ?? 0)).join(' · '),
        },
        {
            key: 'net_profit',
            label: 'Profit net',
            now: mii(output.totals.net_profit),
            unit: 'mii lei',
            series: years.map((one) => one.net_profit ?? 0),
            plan: output.totals.net_profit,
            good: true,
            foot: years.map((one) => mii(one.net_profit ?? 0)).join(' · '),
        },
        {
            key: 'pax',
            label: 'Pasageri',
            now: lei(output.totals.pax),
            unit: 'persoane',
            series: years.map((one) => one.pax ?? 0),
            plan: output.totals.pax,
            good: true,
            foot: `${lei(output.totals.services)} servicii Tina · ${lei(output.totals.seats)} locuri`,
        },
    ];

    return (
        <div className="space-y-3">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                {cards.map((card) => {
                    const last = card.series[card.series.length - 1] ?? 0;
                    const top = Math.max(
                        ...card.series.map(Math.abs),
                        Math.abs(card.plan),
                        1,
                    );
                    const up = card.plan >= last;

                    return (
                        <Card key={card.key}>
                            <CardContent className="space-y-2 p-4">
                                <p className="text-xs text-muted-foreground uppercase">
                                    {card.label}
                                </p>
                                <p className="flex items-baseline gap-2">
                                    <span className="text-xl font-semibold tabular-nums">
                                        {card.now}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {card.unit}
                                    </span>
                                    <span
                                        className={cn(
                                            'text-xs font-medium',
                                            up
                                                ? 'text-emerald-600 dark:text-emerald-500'
                                                : 'text-red-600 dark:text-red-400',
                                        )}
                                    >
                                        {pct(card.plan, last) ?? ''}
                                    </span>
                                </p>
                                <div
                                    className="flex h-10 items-end gap-1"
                                    aria-hidden="true"
                                >
                                    {[...card.series, card.plan].map(
                                        (value, i) => (
                                            <div
                                                key={i}
                                                className={cn(
                                                    'flex-1 rounded-sm',
                                                    i === card.series.length
                                                        ? up
                                                            ? 'bg-primary'
                                                            : 'bg-red-500'
                                                        : 'bg-muted-foreground/25',
                                                )}
                                                style={{
                                                    height: `${Math.max(2, (Math.abs(value) / top) * 40)}px`,
                                                }}
                                            />
                                        ),
                                    )}
                                </div>
                                <p className="text-[11px] text-muted-foreground tabular-nums">
                                    {years.map((one) => one.year).join(' · ')} ·{' '}
                                    <strong className="text-foreground">
                                        {planYear}
                                    </strong>
                                </p>
                                <p className="truncate text-[11px] text-muted-foreground tabular-nums">
                                    {card.foot}
                                </p>
                            </CardContent>
                        </Card>
                    );
                })}
            </div>

            <Card className="border-l-4 border-l-orange-500">
                <CardContent className="flex flex-wrap items-center gap-x-10 gap-y-3 p-4">
                    <span className="text-xs text-muted-foreground uppercase">
                        Ce atârnă greu
                    </span>

                    <span>
                        <span className="block text-base font-semibold tabular-nums">
                            {risk.charter_share}%
                        </span>
                        <span className="text-xs text-muted-foreground">
                            din venit vine din cele {risk.routes} de curse
                        </span>
                    </span>

                    <span>
                        <span className="block text-base font-semibold tabular-nums">
                            {risk.load}%
                        </span>
                        <span className="text-xs text-muted-foreground">
                            ocupare planificată, față de {risk.break_even}%
                            pragul mediu
                        </span>
                    </span>

                    <span>
                        <span className="block text-base font-semibold text-red-600 tabular-nums dark:text-red-400">
                            {lei(risk.empty_seats)}
                        </span>
                        <span className="text-xs text-muted-foreground">
                            locuri rămase goale · {mii(risk.empty_cost)} mii lei
                            plătiți degeaba
                        </span>
                    </span>

                    <span>
                        <span className="block text-base font-semibold tabular-nums">
                            {mii(risk.per_point)}
                        </span>
                        <span className="text-xs text-muted-foreground">
                            mii lei venit la fiecare punct de ocupare
                        </span>
                    </span>

                    <span>
                        <span className="block text-base font-semibold tabular-nums">
                            {mii(risk.tax)}
                        </span>
                        <span className="text-xs text-muted-foreground">
                            impozit
                            {risk.tax_is_minimum
                                ? ' — minimul pe cifra de afaceri, plătit chiar pe profit mic'
                                : ' — 16% pe profitul impozabil'}
                        </span>
                    </span>
                </CardContent>
            </Card>
        </div>
    );
}

/**
 * Rețeaua de agenții, magazin cu magazin.
 *
 * Costul unei agenții nu se poartă dintr-o linie de cheltuială: e numărul de
 * oameni înmulțit cu cât costă unul, plus bonusul din ce vinde magazinul.
 * Cât timp nu scrie nimeni câți oameni lucrează acolo, rămâne partea lui din
 * salariile repartizate pe retail în anul de bază — ca planul să nu pornească
 * cu agenții fără salarii.
 */
function RetailTable({
    shops,
    planId,
    planYear,
}: {
    shops: Output['retail'];
    planId: number;
    planYear: number;
}) {
    const apply = (key: string, field: string, value: number) =>
        router.put(
            `/reports/forecast/${planId}/row`,
            { kind: 'retail', key, field, value },
            { preserveScroll: true, preserveState: true },
        );

    const oameni = shops.reduce((sum, one) => sum + one.fte, 0);
    const cost = shops.reduce((sum, one) => sum + one.amount, 0);
    const sales = shops.reduce((sum, one) => sum + one.sales, 0);

    return (
        <div className="space-y-2">
            <p className="text-xs text-muted-foreground">
                Costul fiecărei agenții în {planYear} ={' '}
                <strong>oameni × cost pe om</strong> +{' '}
                <strong>bonus × vânzările magazinului</strong>. Vânzarea
                planificată a magazinului se desface din venitul canalului
                retail, după cât a vândut fiecare în anul de bază. Cât timp
                numărul de oameni e zero, costul rămâne cel purtat din istoric,
                iar cifrele scrise aici iau locul părții de salarii repartizate
                pe retail — nu se adună peste ea.
            </p>

            <div className="grid gap-3 sm:grid-cols-3">
                <Card>
                    <CardContent className="p-4">
                        <p className="text-xs text-muted-foreground">
                            Oameni în rețea
                        </p>
                        <p className="text-lg font-semibold tabular-nums">
                            {lei(oameni)}
                            <span className="ml-1 text-xs font-normal text-muted-foreground">
                                FTE pe lună
                            </span>
                        </p>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="p-4">
                        <p className="text-xs text-muted-foreground">
                            Costul rețelei
                        </p>
                        <p className="text-lg font-semibold tabular-nums">
                            {mii(cost)}
                            <span className="ml-1 text-xs font-normal text-muted-foreground">
                                mii lei
                            </span>
                        </p>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="p-4">
                        <p className="text-xs text-muted-foreground">
                            Cât ține din vânzări
                        </p>
                        <p className="text-lg font-semibold tabular-nums">
                            {sales > 0
                                ? `${((cost / sales) * 100).toFixed(1)}%`
                                : '–'}
                            <span className="ml-1 text-xs font-normal text-muted-foreground">
                                din {mii(sales)} mii lei vânduți
                            </span>
                        </p>
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardContent className="overflow-x-auto p-0">
                    <Table className="text-xs">
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-56">Agenția</TableHead>
                                <TableHead className="text-right">
                                    Vânzări {planYear} (lei)
                                </TableHead>
                                <TableHead className="text-right">
                                    Oameni (FTE)
                                </TableHead>
                                <TableHead className="text-right">
                                    Cost / om (lei/lună)
                                </TableHead>
                                <TableHead className="text-right">
                                    Bonus (% din vânzări)
                                </TableHead>
                                <TableHead className="text-right">
                                    Cost an (lei)
                                </TableHead>
                                <TableHead className="text-right">
                                    % din vânzări
                                </TableHead>
                                <TableHead className="w-10" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {shops.map((one) => (
                                <TableRow
                                    key={one.key}
                                    className={cn(
                                        one.manual &&
                                            'bg-amber-50/60 dark:bg-amber-950/20',
                                    )}
                                >
                                    <TableCell className="font-medium">
                                        {one.label}
                                        {one.fte === 0 && (
                                            <span className="ml-2 text-[10px] text-muted-foreground">
                                                fără oameni scriși
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {lei(one.sales)}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <RowInput
                                            value={one.fte}
                                            onCommit={(v) =>
                                                apply(one.key, 'volume', v)
                                            }
                                        />
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <RowInput
                                            value={one.cost_per_fte}
                                            onCommit={(v) =>
                                                apply(one.key, 'unit_cost', v)
                                            }
                                        />
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <RowInput
                                            value={one.bonus * 100}
                                            suffix="%"
                                            onCommit={(v) =>
                                                apply(one.key, 'load', v / 100)
                                            }
                                        />
                                    </TableCell>
                                    <TableCell className="text-right font-semibold tabular-nums">
                                        {lei(one.amount)}
                                    </TableCell>
                                    <TableCell className="text-right text-muted-foreground tabular-nums">
                                        {one.weight !== null
                                            ? `${one.weight}%`
                                            : '–'}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            title="Scoate agenția din plan: n-o mai ținem deschisă anul viitor."
                                            onClick={() =>
                                                confirm(
                                                    `Scoți agenția „${one.label}” din planul ${planYear}? Se poate pune înapoi de sub tabel.`,
                                                ) &&
                                                router.delete(
                                                    `/reports/forecast/${planId}/rows`,
                                                    {
                                                        data: {
                                                            kind: 'retail',
                                                            key: one.key,
                                                        },
                                                        preserveScroll: true,
                                                    },
                                                )
                                            }
                                        >
                                            <Trash2 className="size-3" />
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
        </div>
    );
}

/**
 * Bucățile bugetului și starea lor.
 *
 * Bugetul se face în mai multe mâini: fiecare categorie are departamentul
 * ei, rețeaua de agenții își bugetează oamenii, marketingul cheltuielile
 * lui, administrativul restul. Aici se vede cine ce ține, ce a trimis și pe
 * cine se mai așteaptă — iar conducerea primește bucata sau o dă înapoi.
 */
function Sections({
    sections,
    mine,
    boss,
    planId,
}: {
    sections: Section[];
    mine: string[];
    boss: boolean;
    planId: number;
}) {
    const [note, setNote] = useState('');
    const [open, setOpen] = useState<string | null>(null);

    const stare = {
        draft: { label: 'în lucru', className: 'text-muted-foreground' },
        submitted: {
            label: 'trimisă',
            className: 'text-amber-700 dark:text-amber-500',
        },
        accepted: {
            label: 'primită',
            className: 'text-emerald-600 dark:text-emerald-500',
        },
    } as const;

    return (
        <div className="space-y-2">
            <p className="text-xs text-muted-foreground">
                {boss
                    ? 'Bugetul se strânge pe bucăți: fiecare categorie de produs și fiecare bloc de cheltuieli are departamentul care îl scrie. Aici se vede ce a venit, ce se mai așteaptă și de la cine — iar bucata trimisă se primește sau se dă înapoi cu un cuvânt de ce.'
                    : 'Astea sunt bucățile de buget pe care le scrieți dumneavoastră. Când una e gata, trimiteți-o spre consolidare: de-atunci se încuie și n-o mai schimbă nimeni, nici dumneavoastră, până n-o dă conducerea înapoi.'}
            </p>

            <Card>
                <CardContent className="overflow-x-auto p-0">
                    <Table className="text-xs">
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-64">Bucata</TableHead>
                                {boss && <TableHead>Departamentul</TableHead>}
                                <TableHead className="text-right">
                                    Rânduri
                                </TableHead>
                                <TableHead className="text-right">
                                    Cifra (mii lei)
                                </TableHead>
                                <TableHead>Starea</TableHead>
                                <TableHead className="w-72" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {sections.map((one) => {
                                const owned = mine.includes(one.key);

                                return (
                                    <TableRow
                                        key={one.key}
                                        className={cn(
                                            owned && 'bg-muted/40',
                                            one.status === 'submitted' &&
                                                'border-l-2 border-l-amber-400',
                                        )}
                                    >
                                        <TableCell className="font-medium">
                                            {one.label}
                                            {owned && (
                                                <span className="ml-2 text-[10px] text-muted-foreground">
                                                    partea mea
                                                </span>
                                            )}
                                        </TableCell>
                                        {boss && (
                                            <TableCell className="text-muted-foreground">
                                                {one.department ?? '–'}
                                                {one.people.length > 0 && (
                                                    <span className="block text-[10px]">
                                                        {one.people
                                                            .slice(0, 3)
                                                            .join(', ')}
                                                    </span>
                                                )}
                                            </TableCell>
                                        )}
                                        <TableCell className="text-right tabular-nums">
                                            {one.rows}
                                        </TableCell>
                                        <TableCell className="text-right font-medium tabular-nums">
                                            {mii(one.net || one.amount)}
                                        </TableCell>
                                        <TableCell>
                                            <span
                                                className={
                                                    stare[one.status].className
                                                }
                                            >
                                                {stare[one.status].label}
                                            </span>
                                            {one.submitted_at && (
                                                <span className="block text-[10px] text-muted-foreground">
                                                    {one.submitted_by} ·{' '}
                                                    {one.submitted_at}
                                                </span>
                                            )}
                                            {one.note && (
                                                <span className="block max-w-xs text-[10px] whitespace-pre-line text-muted-foreground">
                                                    {one.note}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right whitespace-nowrap">
                                            {owned &&
                                                one.status === 'draft' && (
                                                    <span className="inline-flex items-center gap-1">
                                                        <Input
                                                            className="h-7 w-40 text-xs"
                                                            placeholder="pe ce s-a sprijinit"
                                                            value={
                                                                open === one.key
                                                                    ? note
                                                                    : ''
                                                            }
                                                            onFocus={() => {
                                                                setOpen(
                                                                    one.key,
                                                                );
                                                                setNote('');
                                                            }}
                                                            onChange={(e) =>
                                                                setNote(
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                        />
                                                        <Button
                                                            size="sm"
                                                            onClick={() =>
                                                                router.post(
                                                                    `/reports/forecast/${planId}/sections/submit`,
                                                                    {
                                                                        key: one.key,
                                                                        note:
                                                                            open ===
                                                                            one.key
                                                                                ? note
                                                                                : null,
                                                                    },
                                                                    {
                                                                        preserveScroll: true,
                                                                        onSuccess:
                                                                            () => {
                                                                                setOpen(
                                                                                    null,
                                                                                );
                                                                                setNote(
                                                                                    '',
                                                                                );
                                                                            },
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            Trimite
                                                        </Button>
                                                    </span>
                                                )}

                                            {boss &&
                                                one.status === 'submitted' && (
                                                    <span className="inline-flex gap-1">
                                                        <Button
                                                            size="sm"
                                                            onClick={() =>
                                                                router.post(
                                                                    `/reports/forecast/${planId}/sections/review`,
                                                                    {
                                                                        key: one.key,
                                                                        status: 'accepted',
                                                                    },
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            Primesc
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() => {
                                                                const why =
                                                                    prompt(
                                                                        `De ce se întoarce „${one.label}” la departament?`,
                                                                    );

                                                                if (
                                                                    why !== null
                                                                ) {
                                                                    router.post(
                                                                        `/reports/forecast/${planId}/sections/review`,
                                                                        {
                                                                            key: one.key,
                                                                            status: 'draft',
                                                                            note: why,
                                                                        },
                                                                        {
                                                                            preserveScroll: true,
                                                                        },
                                                                    );
                                                                }
                                                            }}
                                                        >
                                                            Înapoi
                                                        </Button>
                                                    </span>
                                                )}

                                            {boss &&
                                                one.status === 'accepted' && (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            router.post(
                                                                `/reports/forecast/${planId}/sections/review`,
                                                                {
                                                                    key: one.key,
                                                                    status: 'draft',
                                                                },
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        Redeschide
                                                    </Button>
                                                )}
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
        </div>
    );
}

/**
 * Anul în curs, așa cum se va încheia.
 *
 * Bugetul anului următor se face în octombrie–decembrie, când anul curent
 * are nouă-zece luni trăite. Dacă premisele pleacă de la atât, tot ce se
 * plănuiește e strâmb de la început — așa că aici stă anul întreg: ce s-a
 * realizat, ce se mai așteaptă și cât iese împreună.
 */
function CurrentYear({
    current,
    planYear,
}: {
    current: CurrentYear;
    planYear: number;
}) {
    const cards = [
        ['Venituri nete', 'net'],
        ['Marjă brută', 'margin'],
        ['EBITDA', 'ebitda'],
        ['Profit net', 'net_profit'],
    ] as const;

    return (
        <div className="space-y-3">
            <p className="text-xs text-muted-foreground">
                {current.year} are{' '}
                <strong className="text-foreground">
                    {current.lived.length} luni realizate
                </strong>{' '}
                ({MONTHS[current.lived[0] - 1]}–
                {MONTHS[current.lived[current.lived.length - 1] - 1]}) și{' '}
                <strong className="text-foreground">
                    {current.open.length} de estimat
                </strong>{' '}
                ({current.open.map((m) => MONTHS[m - 1]).join(', ')}). Planul{' '}
                {planYear} pornește din coloana „Total an”, nu din lunile trăite
                — altfel premisele anului următor ar pleca de la trei sferturi
                de an. Lunile estimate se scriu de mână în Configurare, pe
                fiecare rând.
            </p>

            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {cards.map(([label, key]) => (
                    <Card key={key}>
                        <CardContent className="p-4">
                            <p className="text-xs text-muted-foreground">
                                {label}
                            </p>
                            <p className="text-lg font-semibold tabular-nums">
                                {mii(current.totals.total[key] ?? 0)}
                                <span className="ml-1 text-xs font-normal text-muted-foreground">
                                    mii lei, anul întreg
                                </span>
                            </p>
                            <p className="text-xs text-muted-foreground tabular-nums">
                                {mii(current.totals.actual[key] ?? 0)} realizat
                                +{' '}
                                {mii(
                                    (current.totals.total[key] ?? 0) -
                                        (current.totals.actual[key] ?? 0),
                                )}{' '}
                                estimat
                            </p>
                            {current.totals.before[key] !== undefined && (
                                <p className="text-xs text-muted-foreground tabular-nums">
                                    {current.year - 1}:{' '}
                                    {mii(current.totals.before[key] ?? 0)} ·{' '}
                                    <span
                                        className={
                                            (current.totals.total[key] ?? 0) >=
                                            (current.totals.before[key] ?? 0)
                                                ? 'text-emerald-600 dark:text-emerald-500'
                                                : 'text-red-600 dark:text-red-400'
                                        }
                                    >
                                        {pct(
                                            current.totals.total[key] ?? 0,
                                            current.totals.before[key],
                                        ) ?? '–'}
                                    </span>
                                </p>
                            )}
                        </CardContent>
                    </Card>
                ))}
            </div>

            <Card>
                <CardContent className="overflow-x-auto p-0">
                    <Table className="text-xs">
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-72">Linie</TableHead>
                                <TableHead className="border-l text-right">
                                    Realizat ({current.lived.length} luni)
                                </TableHead>
                                <TableHead className="text-right">
                                    Estimat ({current.open.length} luni)
                                </TableHead>
                                <TableHead className="text-right font-semibold">
                                    Total {current.year}
                                </TableHead>
                                <TableHead className="border-l text-right text-muted-foreground">
                                    {current.year - 1}
                                </TableHead>
                                <TableHead className="text-right text-muted-foreground">
                                    vs
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {current.rows.map((row) => (
                                <TableRow
                                    key={row.key}
                                    className={cn(
                                        (row.key === 'net' ||
                                            row.key === 'ebitda') &&
                                            'bg-muted/40 font-semibold',
                                    )}
                                >
                                    <TableCell>{row.label}</TableCell>
                                    <TableCell className="border-l text-right tabular-nums">
                                        {mii(row.actual)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {mii(row.estimate)}
                                    </TableCell>
                                    <TableCell className="text-right font-semibold tabular-nums">
                                        {mii(row.total)}
                                    </TableCell>
                                    <TableCell className="border-l text-right text-muted-foreground tabular-nums">
                                        {row.before === null
                                            ? '–'
                                            : mii(row.before)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.before !== null && (
                                            <span
                                                className={
                                                    row.total >= row.before
                                                        ? 'text-emerald-600 dark:text-emerald-500'
                                                        : 'text-red-600 dark:text-red-400'
                                                }
                                            >
                                                {pct(row.total, row.before) ??
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

            <Card>
                <CardContent className="overflow-x-auto p-0">
                    <Table className="text-xs">
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-72">
                                    Categorie de produs
                                </TableHead>
                                <TableHead className="border-l text-right">
                                    Venit realizat
                                </TableHead>
                                <TableHead className="text-right">
                                    Venit estimat
                                </TableHead>
                                <TableHead className="text-right font-semibold">
                                    Venit {current.year}
                                </TableHead>
                                <TableHead className="text-right">
                                    Marjă {current.year}
                                </TableHead>
                                <TableHead className="border-l text-right text-muted-foreground">
                                    Venit {current.year - 1}
                                </TableHead>
                                <TableHead className="text-right text-muted-foreground">
                                    vs
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {current.categories.map((row) => (
                                <TableRow key={row.key}>
                                    <TableCell className="font-medium">
                                        {row.key === '- -'
                                            ? 'Fără categorie'
                                            : row.key}
                                    </TableCell>
                                    <TableCell className="border-l text-right tabular-nums">
                                        {mii(row.actual)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {mii(row.estimate)}
                                    </TableCell>
                                    <TableCell className="text-right font-semibold tabular-nums">
                                        {mii(row.total)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {mii(row.margin)}
                                    </TableCell>
                                    <TableCell className="border-l text-right text-muted-foreground tabular-nums">
                                        {row.before === null
                                            ? '–'
                                            : mii(row.before)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.before !== null && (
                                            <span
                                                className={
                                                    row.total >= row.before
                                                        ? 'text-emerald-600 dark:text-emerald-500'
                                                        : 'text-red-600 dark:text-red-400'
                                                }
                                            >
                                                {pct(row.total, row.before) ??
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
                Cifrele sunt în mii de lei, citite pe anul întreg, pe baza și pe
                recunoașterea alese sus. Estimatul lunilor rămase e cel din care
                pornește planul: volumul lunii din anul trecut, scalat cu cât a
                crescut anul, cu banii pe unitate ai anului curent — sau cifra
                scrisă de mână, dacă s-a scris.
            </p>
        </div>
    );
}

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
    // Trei ani de istoric lățesc tabelul: cine vrea doar anul de bază îl
    // poate strânge la loc.
    const [wide, setWide] = useState(true);

    const built = output.years.filter((one) => one.built);
    const history = wide ? built : built.filter((one) => one.year === baseYear);
    const open = built.find((one) => one.estimated.length > 0);

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

    // Anii dinainte, pe aceeași coloană: istoricul stă la stânga, planul la
    // dreapta, ca să se citească evoluția dintr-o privire.
    const past = (
        row: StatementRow,
        year: number,
        key: string | null,
    ): number | null => {
        if (key === null) {
            return row.years?.[String(year)] ?? null;
        }

        const byYear =
            axis === 'produs' ? row.years_product : row.years_channel;

        return byYear?.[String(year)]?.[key] ?? null;
    };

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
                <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setWide(!wide)}
                >
                    {wide
                        ? `Doar ${baseYear}`
                        : `Toți anii (${built[0]?.year ?? baseYear}–${baseYear})`}
                </Button>
                <p className="text-xs text-muted-foreground">
                    Planul {year} are rândurile raportului, pe {label}, în mii
                    de lei. Pe fiecare coloană stau anii dinainte, din
                    P&amp;L-ul adevărat, apoi {year}: evoluția se citește de la
                    stânga la dreapta.
                    {open !== undefined &&
                        ` ${open.year} nu e încheiat: ${12 - open.estimated.length} luni sunt realizate, iar ${open.estimated
                            .map((m) => MONTHS[m - 1])
                            .join(
                                ', ',
                            )} sunt estimate — altfel s-ar compara ${12 - open.estimated.length} luni cu un an întreg.`}
                    {Object.entries(
                        output.years.find(
                            (one) => Object.keys(one.apart ?? {}).length > 0,
                        )?.apart ?? {},
                    ).map(([name, net]) => (
                        <span key={name}>
                            {' '}
                            Rezervările {name} stau în baza lor de eTrip, pe
                            care vederea operațională n-o citește: sunt adăugate
                            aici ({mii(net)} mii lei), ca anul de bază să fie
                            întreg.
                        </span>
                    ))}
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
                                {history.map((one) => (
                                    <TableHead
                                        key={one.year}
                                        className="border-l text-right text-muted-foreground"
                                        title={
                                            one.estimated.length > 0
                                                ? `${one.year} nu e încheiat: ${12 - one.estimated.length} luni realizate și ${one.estimated.length} estimate.`
                                                : `${one.year}, an încheiat.`
                                        }
                                    >
                                        {one.year}
                                        <span className="block text-[10px] font-normal opacity-70">
                                            {one.estimated.length > 0
                                                ? `${12 - one.estimated.length} real + ${one.estimated.length} est.`
                                                : 'mii lei'}
                                        </span>
                                    </TableHead>
                                ))}
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
                                        {axis !== 'luna' &&
                                            history.map((one, index) => (
                                                <TableHead
                                                    key={one.year}
                                                    className={cn(
                                                        'text-right font-normal text-muted-foreground',
                                                        index === 0 &&
                                                            'border-l',
                                                    )}
                                                >
                                                    {column.label} {one.year}
                                                </TableHead>
                                            ))}
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
                                {history.map((one) => (
                                    <TableCell
                                        key={one.year}
                                        className="border-l"
                                    />
                                ))}
                                <TableCell className="text-right tabular-nums">
                                    {lei(output.totals.pax)} ·{' '}
                                    {lei(output.totals.services)} ·{' '}
                                    {lei(output.totals.seats)}
                                </TableCell>
                                <TableCell />
                                {axis !== 'luna' && <TableCell />}
                                {columns.map((column, i) => (
                                    <Fragment key={column.key}>
                                        {axis !== 'luna' &&
                                            history.map((one, index) => (
                                                <TableCell
                                                    key={one.year}
                                                    className={cn(
                                                        index === 0 &&
                                                            'border-l',
                                                    )}
                                                />
                                            ))}
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
                                    {history.map((one) => (
                                        <TableCell
                                            key={one.year}
                                            className="border-l text-right text-muted-foreground tabular-nums"
                                        >
                                            {past(row, one.year, null) !== null
                                                ? mii(
                                                      past(
                                                          row,
                                                          one.year,
                                                          null,
                                                      ) as number,
                                                  )
                                                : '–'}
                                        </TableCell>
                                    ))}
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
                                            {axis !== 'luna' &&
                                                history.map((one, index) => (
                                                    <TableCell
                                                        key={one.year}
                                                        className={cn(
                                                            'text-right text-muted-foreground tabular-nums',
                                                            index === 0 &&
                                                                'border-l',
                                                        )}
                                                    >
                                                        {past(
                                                            row,
                                                            one.year,
                                                            column.key,
                                                        ) !== null
                                                            ? mii(
                                                                  past(
                                                                      row,
                                                                      one.year,
                                                                      column.key,
                                                                  ) as number,
                                                              )
                                                            : '–'}
                                                    </TableCell>
                                                ))}
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
                    : 'Cheltuielile se împart pe coloane după cum s-au împărțit aceleași linii în anul de bază; liniile noi, după venit.'}{' '}
                {open !== undefined &&
                    `Anii dinainte sunt citiți pe aceeași perioadă și pe aceeași bază ca planul; ${open.year} e singurul neîncheiat, cu ${open.estimated.length} luni estimate.`}
            </p>
        </div>
    );
}
