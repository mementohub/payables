import { Head, router } from '@inertiajs/react';
import {
    CheckCircle2,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    FileText,
    GripVertical,
    Loader2,
    RefreshCw,
    TriangleAlert,
} from 'lucide-react';
import { Fragment, useMemo, useState } from 'react';
import {
    CostDetailsSheet,
    movePnlCost,
    UndoButton,
} from '@/components/pnl/cost-details-sheet';
import {
    MoveChannelButton,
    MoveCostButton,
    MoveProductButton,
} from '@/components/pnl/move-cost-button';
import { useCostDetails } from '@/components/pnl/use-cost-details';
import { ExportMenu } from '@/components/reports/export-menu';
import type { CostDetails } from '@/components/pnl/use-cost-details';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import {
    exportMethod as pnlExport,
    index as pnlIndex,
    refresh as pnlRefresh,
} from '@/routes/reports/pnl';

type Company = { id: number; name: string };

type Money = { net: number; margin: number; bookings: number };

type PnlLine = {
    reclassified?: boolean;
    saf: string;
    group: string;
    label: string;
    total: number;
    direct: number;
    by_channel: Record<string, number>;
    by_product: Record<string, number>;
    by_month: Record<string, number>;
    matched_by: Record<string, number>;
};

type PnlReport = {
    year: number;
    company: { id: number; name: string };
    error?: string;
    channels: string[];
    products: string[];
    revenue: {
        by_channel: Record<string, Money>;
        by_product: Record<string, Money>;
        total: Money;
        cogs?: number;
        /** Costul născut dintr-o plecare, deja scăzut din marjă. */
        trip_costs?: {
            label: string;
            total: number;
            by_channel: Record<string, number>;
            by_product: Record<string, number>;
        };
        /** Comisionul de curs, deja cuprins în marjă; rândul lui îl arată separat. */
        fx_commission?: {
            label: string;
            total: number;
            by_channel: Record<string, number>;
            by_product: Record<string, number>;
        };
    };
    basis: string;
    mode: string;
    expand: string | null;
    expandable: string[];
    ifrs16: {
        lease_expense: number;
        liability: number;
        depreciation: number;
        interest: number;
        effect_on_ebitda: number;
        effect_on_net: number;
        term_years: number;
        discount_rate: number;
        lines: string[];
    } | null;
    lines: PnlLine[];
    below: Record<
        string,
        {
            total: number;
            by_channel: Record<string, number>;
            by_product: Record<string, number>;
            /** Conturile din care e făcută cifra, pentru perioada afișată. */
            accounts?: { account: string; label: string; lei: number }[];
        }
    >;
    groups: Record<string, string[]>;
    totals: {
        total: number;
        by_channel: Record<string, number>;
        by_product: Record<string, number>;
    };
    meta: {
        generated_at: string;
        duration_ms: number;
        cost_rows: number;
        direct_lei: number;
        allocated_lei: number;
        unmapped_lei: number;
        excluded_accounts: string[];
        channels_without_costs?: string[];
        reconciles: boolean;
    };
};

type Run = {
    running: boolean;
    stale: boolean;
    started_at: string | null;
    exit_code: number | null;
    log: string;
};

type Override = {
    id: number;
    scope: string;
    match_key: string;
    saf: string;
    label: string | null;
    target_label: string;
    by: string | null;
};

type Props = {
    companies: Company[];
    filters: {
        company_id: number | null;
        year: number;
        view: 'channel' | 'product';
        period: string;
        basis: string;
        mode: string;
        expand: string | null;
        compare: boolean;
        key: string;
    };
    report: PnlReport | null;
    previous: PnlReport | null;
    lines: Record<string, { group: string; label: string }>;
    overrides: Override[];
    /** Corecturi făcute după ultima construcție: se văd abia după „Aplică”. */
    pending: number;
    run: Run | null;
};

const MONTHS = [
    'ianuarie',
    'februarie',
    'martie',
    'aprilie',
    'mai',
    'iunie',
    'iulie',
    'august',
    'septembrie',
    'octombrie',
    'noiembrie',
    'decembrie',
];

const CHANNEL_LABELS: Record<string, string> = {
    b2b: 'B2B (agenții partenere)',
    retail: 'Retail (sucursale proprii)',
    site: 'Site',
    cc: 'Call Center (Online B2C)',
    franciza: 'Franciză',
    other: 'Altele',
};

const MONTH_NAMES = [
    'Ian',
    'Feb',
    'Mar',
    'Apr',
    'Mai',
    'Iun',
    'Iul',
    'Aug',
    'Sep',
    'Oct',
    'Noi',
    'Dec',
];

const lei = (value: number) =>
    new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 0 }).format(value);

const pct = (value: number, of: number) =>
    of === 0 ? '' : `${((value / of) * 100).toFixed(1)}%`;

export default function Pnl({
    companies,
    filters,
    report,
    previous,
    lines,
    overrides,
    pending,
    run,
}: Props) {
    const [open, setOpen] = useState<{
        saf: string;
        column: string | null;
    } | null>(null);
    const [documentsSaf, setDocumentsSaf] = useState<string | null>(null);

    // Perioada se ține într-un singur câmp („ytd7”), dar se alege din două
    // controale: luna și felul în care se citește.
    const periodMatch = /^(ytd|mtd|m)(\d{1,2})$/.exec(filters.period);
    const periodMode = periodMatch?.[1] === 'mtd' ? 'mtd' : 'ytd';
    const periodMonth = periodMatch ? Number(periodMatch[2]) : 12;
    const [view, setView] = useState<'channel' | 'product'>(filters.view);

    // Apăsând pe o celulă, detaliul e al celulei: documentele care au ajuns
    // chiar în coloana aia, nu ale întregii linii.
    const detailsAxis: 'channel' | 'product' | 'branch' =
        filters.expand !== null && view === 'channel' ? 'branch' : view;

    const { details, loading } = useCostDetails(
        filters.company_id,
        filters.year,
        filters.period,
        open?.saf ?? null,
        open?.column ?? null,
        detailsAxis,
    );

    const go = (next: Partial<Props['filters']>) =>
        router.get(
            pnlIndex().url,
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true },
        );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Rapoarte', href: pnlIndex().url },
                { title: 'P&L', href: pnlIndex().url },
            ]}
        >
            <Head title="P&L" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-end gap-3">
                    <div className="grid gap-1.5">
                        <Label htmlFor="company">Companie</Label>
                        <Select
                            value={String(filters.company_id ?? '')}
                            onValueChange={(value) =>
                                go({ company_id: Number(value) })
                            }
                        >
                            <SelectTrigger id="company" className="w-64">
                                <SelectValue placeholder="Alege compania" />
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

                    <div className="grid gap-1.5">
                        <Label htmlFor="year">An</Label>
                        <Select
                            value={String(filters.year)}
                            onValueChange={(value) =>
                                go({ year: Number(value) })
                            }
                        >
                            <SelectTrigger id="year" className="w-28">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {Array.from(
                                    { length: 6 },
                                    (_, i) => new Date().getFullYear() - i,
                                ).map((year) => (
                                    <SelectItem key={year} value={String(year)}>
                                        {year}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="grid gap-1.5">
                        <Label>Vedere</Label>
                        <ToggleGroup
                            type="single"
                            value={view}
                            onValueChange={(value) =>
                                value && setView(value as 'channel' | 'product')
                            }
                            variant="outline"
                        >
                            <ToggleGroupItem value="channel">
                                Canal de vânzare
                            </ToggleGroupItem>
                            <ToggleGroupItem value="product">
                                Categorie de produs
                            </ToggleGroupItem>
                        </ToggleGroup>
                    </div>

                    <div className="grid gap-1.5">
                        <Label>Venit și COGS</Label>
                        <ToggleGroup
                            type="single"
                            value={filters.mode}
                            onValueChange={(value) =>
                                value && go({ mode: value })
                            }
                            variant="outline"
                        >
                            <ViewChoice
                                value="operational"
                                label="Operațional"
                                title="Venit și COGS din eTrip, la data vânzării"
                                lines={[
                                    'Venit net = brut + taxe − comision, pe pozițiile confirmate ale rezervărilor, în lei la cursul BNR din ziua rezervării.',
                                    'COGS = costul furnizorului de pe aceleași poziții; nu apare ca linie separată, e scăzut deja în marja brută.',
                                    'Recunoașterea e la vânzare: o rezervare făcută în decembrie pentru martie intră în decembrie.',
                                    'Nu se potrivește cu situațiile financiare — pentru asta folosește „Financiar”.',
                                ]}
                            />
                            <ViewChoice
                                value="financial"
                                label="Financiar"
                                title="Venit și COGS din contabilitate, la data facturării"
                                lines={[
                                    'Venit = rulajul creditor al clasei 70 (cifra de afaceri) plus 74x și 75x (subvenții și alte venituri din exploatare).',
                                    'COGS = rulajul debitor al contului 628, costul serviciilor cumpărate de la furnizori; se vede pe linie proprie.',
                                    'Recunoașterea e la facturare, ca în situațiile financiare: pe 2024 se potrivește la leu cu venitul auditat și cu impozitul.',
                                    'Coloanele pe canal și produs împart totalul contabil după structura vânzărilor din eTrip — contabilitatea nu știe de canale.',
                                ]}
                            />
                        </ToggleGroup>
                    </div>

                    <div className="grid gap-1.5">
                        <Label>Chirii</Label>
                        <ToggleGroup
                            type="single"
                            value={filters.basis}
                            onValueChange={(value) =>
                                value && go({ basis: value })
                            }
                            variant="outline"
                        >
                            <ViewChoice
                                value="ras"
                                label="Statutar"
                                title="Chiriile ca în contabilitatea românească"
                                lines={[
                                    'Chiria stă pe 612, ca o cheltuială de exploatare, deci intră în EBITDA.',
                                    'Așa e ținută în OMC și așa apare în situațiile financiare depuse.',
                                ]}
                            />
                            <ViewChoice
                                value="ifrs16"
                                label="IFRS 16"
                                title="Chiriile scoase din EBITDA — estimare, nu retratare"
                                lines={[
                                    'Contractele de închiriere nu sunt în OMC, așa că datoria se deduce din chiria plătită, pe durata și rata din configurare (implicit 5 ani la 8%).',
                                    'Chiria iese din exploatare (EBITDA crește cu ea) și se întoarce ca amortizare plus dobândă.',
                                    'Liniile mutate rămân în tabel, marcate „reclasificat IFRS 16”.',
                                    'Cifrele diferă de o retratare adevărată; pentru aceea ar trebui contractele.',
                                ]}
                            />
                        </ToggleGroup>
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="period">Luna</Label>
                        <Select
                            value={String(periodMonth)}
                            onValueChange={(value) =>
                                go({ period: `${periodMode}${value}` })
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

                    <div className="grid gap-1.5">
                        <Label>Cumul</Label>
                        <ToggleGroup
                            type="single"
                            value={periodMode}
                            onValueChange={(value) =>
                                value &&
                                go({ period: `${value}${periodMonth}` })
                            }
                            variant="outline"
                        >
                            <ToggleGroupItem value="ytd">YTD</ToggleGroupItem>
                            <ToggleGroupItem value="mtd">MTD</ToggleGroupItem>
                        </ToggleGroup>
                    </div>

                    {filters.expand !== null && (
                        <div className="grid gap-1.5">
                            <Label>Cheia de repartizare</Label>
                            <ToggleGroup
                                type="single"
                                value={filters.key}
                                onValueChange={(value) =>
                                    value && go({ key: value })
                                }
                                variant="outline"
                            >
                                <ViewChoice
                                    value="venit"
                                    label="Venit net"
                                    title="Costurile comune se împart după cât vinde fiecare magazin"
                                    lines={[
                                        'Cheltuiala care nu se poate lipi de un magazin — publicitate, centrala, sediul — se împarte pe magazine proporțional cu venitul lor net din luna aia.',
                                        'E cheia implicită a raportului și aceeași cu cea folosită pe canale.',
                                    ]}
                                />
                                <ViewChoice
                                    value="salarii"
                                    label="Masă salarială"
                                    title="Costurile comune se împart după masa salarială a fiecărui magazin"
                                    lines={[
                                        'Cheia folosită de Financiar: cât efectiv ține magazinul, nu cât vinde. Un magazin nou sau unul cu sezon slab iese altfel decât pe cheia de venit.',
                                        'Salariile poartă magazinul în analiticul contabil (".1.Plaza", ".Sun Plaza"), nu în punctul de lucru — acolo scrie sediul central, unde se face statul de plată.',
                                        'Un magazin cu venit, dar fără salarii identificate, primește o masă estimată din venitul lui, la raportul mediu al canalului.',
                                    ]}
                                />
                            </ToggleGroup>
                        </div>
                    )}

                    <div className="grid gap-1.5">
                        <Label>Comparație</Label>
                        <ToggleGroup
                            type="single"
                            value={filters.compare ? 'on' : 'off'}
                            onValueChange={(value) =>
                                value && go({ compare: value === 'on' })
                            }
                            variant="outline"
                        >
                            <ToggleGroupItem value="off">Fără</ToggleGroupItem>
                            <ToggleGroupItem value="on">
                                vs {filters.year - 1}
                            </ToggleGroupItem>
                        </ToggleGroup>
                    </div>

                    {filters.company_id && (
                        <ExportMenu
                            disabled={report === null}
                            href={(format) =>
                                pnlExport.url(
                                    { company: filters.company_id as number },
                                    {
                                        query: {
                                            format,
                                            year: filters.year,
                                            view,
                                            period: filters.period,
                                            basis: filters.basis,
                                            mode: filters.mode,
                                            expand: filters.expand ?? undefined,
                                            key: filters.key,
                                        },
                                    },
                                )
                            }
                        />
                    )}

                    {filters.company_id && (
                        <Button
                            variant={pending > 0 ? 'default' : 'outline'}
                            onClick={() =>
                                router.post(
                                    pnlRefresh({
                                        company: filters.company_id as number,
                                    }).url,
                                    { year: filters.year },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <RefreshCw className="size-4" />
                            {pending > 0
                                ? `Aplică ${pending} ${pending === 1 ? 'corectură' : 'corecturi'}`
                                : 'Reconstruiește'}
                        </Button>
                    )}
                </div>

                {!filters.company_id ? (
                    <p className="text-muted-foreground">
                        Nicio companie selectată.
                    </p>
                ) : report ? (
                    <>
                        {pending > 0 && (
                            <div className="flex flex-wrap items-center gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-900 dark:bg-amber-950/40">
                                <TriangleAlert className="size-4 text-amber-600" />
                                <span>
                                    <span className="font-medium">
                                        {pending}{' '}
                                        {pending === 1
                                            ? 'corectură păstrată'
                                            : 'corecturi păstrate'}
                                    </span>{' '}
                                    care nu se văd încă în cifre. Fă-le pe
                                    toate, apoi apasă „Aplică” — raportul se
                                    reconstruiește o singură dată.
                                </span>
                            </div>
                        )}
                        <PnlTable
                            report={report}
                            previous={previous}
                            view={view}
                            open={open}
                            details={details}
                            loading={loading}
                            catalogue={lines}
                            onDocuments={setDocumentsSaf}
                            onMoveChannel={(payload, channel) =>
                                router.post(
                                    `/reports/pnl/${filters.company_id}/move`,
                                    {
                                        ...payload,
                                        channel,
                                        year: filters.year,
                                    },
                                    { preserveScroll: true },
                                )
                            }
                            onMoveProduct={(payload, product) =>
                                router.post(
                                    `/reports/pnl/${filters.company_id}/move`,
                                    {
                                        ...payload,
                                        product,
                                        year: filters.year,
                                    },
                                    { preserveScroll: true },
                                )
                            }
                            onExpand={(channel) =>
                                go({ expand: channel ?? '' })
                            }
                            onOpen={(saf, column) =>
                                setOpen((current) =>
                                    current?.saf === saf &&
                                    current.column === column
                                        ? null
                                        : { saf, column },
                                )
                            }
                            onDrop={(payload, saf) => {
                                // Lăsată înapoi pe linia ei, mutarea nu
                                // înseamnă nimic; nu scriem o corectură goală.
                                if (open?.saf === saf) {
                                    return;
                                }

                                movePnlCost(
                                    filters.company_id as number,
                                    filters.year,
                                    payload,
                                    saf,
                                );
                            }}
                        />
                        {overrides.length > 0 && (
                            <PnlOverrides
                                overrides={overrides}
                                companyId={filters.company_id as number}
                                year={filters.year}
                            />
                        )}
                    </>
                ) : (
                    <PnlBuilding run={run} />
                )}

                {filters.company_id && (
                    <CostDetailsSheet
                        companyId={filters.company_id}
                        year={filters.year}
                        period={filters.period}
                        saf={documentsSaf}
                        column={open?.column ?? null}
                        detailsAxis={detailsAxis}
                        catalogue={lines}
                        axis={view}
                        products={report?.products ?? []}
                        onClose={() => setDocumentsSaf(null)}
                    />
                )}
            </div>
        </AppLayout>
    );
}

/**
 * Ce s-a adunat într-o linie de cheltuială, deschis sub ea: cât e în registru,
 * cât a ajuns în celula pe care ai apăsat și din ce e făcut. Fiecare sursă se
 * poate trage peste altă linie din tabel.
 */
function CostBreakdown({
    line,
    column,
    columnLabel,
    columnValue,
    details,
    loading,
    span,
    catalogue,
    onDocuments,
    onMove,
    onMoveChannel,
    onMoveProduct,
    axis,
    products,
}: {
    line: PnlLine;
    column: string | null;
    columnLabel: (key: string) => string;
    columnValue: number | null;
    details: CostDetails | null;
    loading: boolean;
    span: number;
    catalogue: Record<string, { group: string; label: string }>;
    onDocuments: (saf: string) => void;
    onMove: (payload: MovePayload, saf: string) => void;
    onMoveChannel: (payload: MovePayload, channel: string) => void;
    onMoveProduct: (payload: MovePayload, product: string) => void;
    axis: 'channel' | 'product';
    products: string[];
}) {
    const share =
        columnValue !== null && line.total !== 0
            ? columnValue / line.total
            : null;

    // Pe o coloană, lunile sunt ale coloanei; pe toată linia, ale liniei.
    const months =
        column !== null && details?.by_month ? details.by_month : line.by_month;

    return (
        <TableRow className="bg-muted/20 hover:bg-muted/20">
            <TableCell colSpan={span} className="p-0">
                <div className="space-y-3 border-l-2 border-primary/40 p-4">
                    <div className="flex flex-wrap items-baseline gap-x-6 gap-y-1 text-sm">
                        <span>
                            <span className="text-muted-foreground">
                                În registru:{' '}
                            </span>
                            <span className="font-semibold tabular-nums">
                                {lei(line.total)} lei
                            </span>
                        </span>
                        {columnValue !== null && column && (
                            <span>
                                <span className="text-muted-foreground">
                                    În {columnLabel(column)}:{' '}
                                </span>
                                <span className="font-semibold tabular-nums">
                                    {lei(columnValue)} lei
                                </span>
                                {share !== null && (
                                    <span className="ml-1 text-muted-foreground">
                                        ({(share * 100).toFixed(1)}% din linie)
                                    </span>
                                )}
                            </span>
                        )}
                        <span>
                            <span className="text-muted-foreground">
                                Direct pe sucursală:{' '}
                            </span>
                            <span className="tabular-nums">
                                {lei(line.direct)} lei
                            </span>
                        </span>
                        <span>
                            <span className="text-muted-foreground">
                                Pe cheia de venit:{' '}
                            </span>
                            <span className="tabular-nums">
                                {lei(line.total - line.direct)} lei
                            </span>
                        </span>
                        {details && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={(event) => {
                                    event.stopPropagation();
                                    onDocuments(line.saf);
                                }}
                            >
                                <FileText className="size-3.5" />
                                {details.documents_total} documente
                            </Button>
                        )}
                    </div>

                    {Object.keys(months).length > 1 && (
                        <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                            {Object.entries(months).map(([month, value]) => (
                                <span key={month} className="tabular-nums">
                                    {MONTH_NAMES[Number(month) - 1]}{' '}
                                    <span className="text-foreground">
                                        {lei(value)}
                                    </span>
                                </span>
                            ))}
                        </div>
                    )}

                    {loading && (
                        <p className="flex items-center gap-2 text-xs text-muted-foreground">
                            <Loader2 className="size-3.5 animate-spin" />
                            Se citesc înregistrările din registru…
                        </p>
                    )}

                    {details && details.items.length > 0 && (
                        <div className="space-y-1.5">
                            <p className="text-xs text-muted-foreground">
                                {column
                                    ? `Cheltuielile care au ajuns în ${columnLabel(column)}, cu partea fiecăreia. `
                                    : ''}
                                Apasă „Mută” ca să alegi linia pe care trebuie
                                să stea cheltuiala, sau trage rândul peste o
                                linie din tabel. Corectura se păstrează.
                            </p>
                            <div className="overflow-hidden rounded-md border">
                                {details.items.map((item) => (
                                    <div
                                        key={item.key}
                                        draggable
                                        onDragStart={(event) => {
                                            event.stopPropagation();
                                            event.dataTransfer.setData(
                                                'application/x-pnl-move',
                                                JSON.stringify({
                                                    scope: 'item',
                                                    match_key: item.key,
                                                    label: [
                                                        item.account,
                                                        item.partner ||
                                                            item.sediu,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · '),
                                                }),
                                            );
                                            event.dataTransfer.effectAllowed =
                                                'move';
                                        }}
                                        className="flex cursor-grab items-center gap-3 border-b bg-background px-3 py-1.5 text-xs last:border-b-0 hover:bg-muted/50 active:cursor-grabbing"
                                    >
                                        <GripVertical className="size-3.5 shrink-0 text-muted-foreground" />
                                        <span className="w-16 shrink-0 font-mono">
                                            {item.account}
                                        </span>
                                        <span className="w-48 shrink-0 truncate">
                                            {item.sediu || '—'}
                                        </span>
                                        <span className="flex-1 truncate">
                                            {item.partner || '—'}
                                        </span>
                                        <span className="w-20 shrink-0 text-right text-muted-foreground tabular-nums">
                                            {item.documents} doc
                                        </span>
                                        <span className="w-28 shrink-0 text-right font-medium tabular-nums">
                                            {lei(item.lei)}
                                        </span>
                                        {axis === 'channel' ? (
                                            <MoveChannelButton
                                                payload={{
                                                    scope: 'item',
                                                    match_key: item.key,
                                                    label: [
                                                        item.account,
                                                        item.partner ||
                                                            item.sediu,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · '),
                                                }}
                                                onMove={onMoveChannel}
                                            />
                                        ) : (
                                            <MoveProductButton
                                                products={products}
                                                payload={{
                                                    scope: 'item',
                                                    match_key: item.key,
                                                    label: [
                                                        item.account,
                                                        item.partner ||
                                                            item.sediu,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · '),
                                                }}
                                                onMove={onMoveProduct}
                                            />
                                        )}
                                        <MoveCostButton
                                            lines={catalogue}
                                            currentSaf={line.saf}
                                            payload={{
                                                scope: 'item',
                                                match_key: item.key,
                                                label: [
                                                    item.account,
                                                    item.partner || item.sediu,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · '),
                                            }}
                                            onMove={onMove}
                                        />
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </TableCell>
        </TableRow>
    );
}

function PnlOverrides({
    overrides,
    companyId,
    year,
}: {
    overrides: Override[];
    companyId: number;
    year: number;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">
                    Cheltuieli mutate de mână ({overrides.length})
                </CardTitle>
            </CardHeader>
            <CardContent className="p-0">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Ce s-a mutat</TableHead>
                            <TableHead>Unde</TableHead>
                            <TableHead>De</TableHead>
                            <TableHead className="w-28" />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {overrides.map((override) => (
                            <TableRow key={override.id}>
                                <TableCell className="text-xs">
                                    {override.label ?? override.match_key}
                                    <span className="ml-2 text-muted-foreground">
                                        {override.scope === 'line'
                                            ? '(linie întreagă)'
                                            : '(o cheltuială)'}
                                    </span>
                                </TableCell>
                                <TableCell className="text-xs">
                                    {override.target_label}
                                </TableCell>
                                <TableCell className="text-xs text-muted-foreground">
                                    {override.by ?? '—'}
                                </TableCell>
                                <TableCell className="text-right">
                                    <UndoButton
                                        companyId={companyId}
                                        overrideId={override.id}
                                        year={year}
                                    />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    );
}

function PnlBuilding({ run }: { run: Run | null }) {
    const failed = run?.exit_code !== null && run?.exit_code !== 0;

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">
                    {run?.running
                        ? 'Raportul se construiește…'
                        : failed
                          ? 'Ultima construcție a eșuat'
                          : 'Raportul nu e construit încă'}
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-3 text-sm">
                {/* O construcție căzută arată altfel decât una în curs: altfel
                    omul reîncarcă pagina o oră, degeaba. */}
                <p className="text-muted-foreground">
                    {failed
                        ? 'Construcția s-a oprit cu o eroare, deci raportul a rămas cel dinainte (sau niciunul). Pornește-o din nou după ce se rezolvă cauza de mai jos.'
                        : 'Citirea din eTrip a unui an întreg trece de un minut, deci raportul se face în fundal și se ține în cache o oră. Se construiește automat în fiecare noapte la 05:15; pentru un an mai vechi, pornirea se face la prima deschidere. Reîncarcă pagina în câteva minute.'}
                </p>
                {run?.log && (
                    <pre className="max-h-64 overflow-auto rounded bg-muted p-3 text-xs">
                        {run.log}
                    </pre>
                )}
            </CardContent>
        </Card>
    );
}

type MovePayload = { scope: string; match_key: string; label?: string };

/**
 * Cât s-a schimbat față de anul trecut, în lei și în procente. Fără anul
 * trecut nu se desenează nimic: o comparație cu nimic ar fi o minciună.
 */
/**
 * Un buton de vedere, cu explicația lui: de unde vine cifra de sus și cum se
 * calculează. Fără asta, „Operațional” și „Financiar” arată ca două gusturi,
 * nu ca două recunoașteri diferite ale venitului.
 */
function ViewChoice({
    value,
    label,
    title,
    lines,
}: {
    value: string;
    label: string;
    title: string;
    lines: string[];
}) {
    return (
        // Declanșatorul stă ÎNĂUNTRUL butonului, nu invers: `TooltipTrigger
        // asChild` își scrie propriul `data-state` peste copil, iar acela e
        // chiar atributul pe care se sprijină butonul ca să se arate apăsat
        // (`data-[state=on]`). Cu tooltipul pe dinafară, vederea aleasă nu se
        // mai vedea aleasă.
        <ToggleGroupItem value={value}>
            <Tooltip>
                <TooltipTrigger asChild>
                    <span className="flex h-full w-full items-center justify-center">
                        {label}
                    </span>
                </TooltipTrigger>
                <TooltipContent className="max-w-sm space-y-1">
                    <p className="font-medium">{title}</p>
                    {lines.map((line) => (
                        <p key={line} className="text-xs">
                            {line}
                        </p>
                    ))}
                </TooltipContent>
            </Tooltip>
        </ToggleGroupItem>
    );
}

/**
 * Un rând de sub EBITDA, care se deschide și arată conturile din spatele
 * cifrei. „Amortizare (net de reluări)” poate ieși negativă când se reiau
 * provizioane mai mari decât amortizarea anului — fără defalcare, arată ca o
 * greșeală de calcul.
 */
function BelowRow({
    bucket,
    label,
    explanation,
    report,
    previous,
    columns,
    belowOf,
    prevBelowOf,
    open,
    onToggle,
    span,
}: {
    bucket: string;
    label: string;
    explanation: string;
    report: PnlReport;
    previous: PnlReport | null;
    columns: string[];
    belowOf: (bucket: string, key?: string) => number;
    prevBelowOf: (bucket: string, key?: string) => number | null;
    open: boolean;
    onToggle: () => void;
    span: number;
}) {
    const total = belowOf(bucket);
    const accounts = report.below?.[bucket]?.accounts ?? [];
    const previousAccounts = previous?.below?.[bucket]?.accounts ?? [];
    const before = (account: string) =>
        previousAccounts.find((row) => row.account === account)?.lei ?? null;

    return (
        <Fragment>
            <Row
                label={label}
                total={total}
                values={columns.map((key) => belowOf(bucket, key))}
                previousTotal={prevBelowOf(bucket)}
                previousValues={columns.map((key) => prevBelowOf(bucket, key))}
                costLike
                onToggleGroup={accounts.length > 0 ? onToggle : undefined}
                groupOpen={open}
            />
            {open && (
                <TableRow className="bg-muted/20 hover:bg-muted/20">
                    <TableCell colSpan={span} className="p-0">
                        <div className="space-y-3 border-l-2 border-primary/40 p-4">
                            <p className="max-w-3xl text-xs text-muted-foreground">
                                {explanation}
                            </p>
                            <div className="overflow-hidden rounded-md border">
                                {accounts.map((row) => (
                                    <div
                                        key={row.account}
                                        className="flex items-center gap-3 border-b bg-background px-3 py-1.5 text-xs last:border-b-0"
                                    >
                                        <span className="w-16 shrink-0 font-mono">
                                            {row.account}
                                        </span>
                                        <span className="flex-1 truncate">
                                            {row.label}
                                        </span>
                                        <span className="w-32 shrink-0 text-right font-medium tabular-nums">
                                            {lei(row.lei)}
                                        </span>
                                        <span className="w-40 shrink-0 text-right">
                                            <Delta
                                                now={row.lei}
                                                before={before(row.account)}
                                                invert
                                            />
                                        </span>
                                    </div>
                                ))}
                                <div className="flex items-center gap-3 bg-muted/60 px-3 py-1.5 text-xs font-semibold">
                                    <span className="w-16 shrink-0" />
                                    <span className="flex-1">Total</span>
                                    <span className="w-32 shrink-0 text-right tabular-nums">
                                        {lei(total)}
                                    </span>
                                    <span className="w-40 shrink-0" />
                                </div>
                            </div>
                            <p className="text-xs text-muted-foreground">
                                Cifrele sunt ale companiei întregi; pe coloane
                                se împart pe cheia de venit, ca toate
                                cheltuielile generale.
                            </p>
                        </div>
                    </TableCell>
                </TableRow>
            )}
        </Fragment>
    );
}

function Delta({
    now,
    before,
    invert,
}: {
    now: number;
    before?: number | null;
    /** Pe cheltuieli, creșterea e vestea proastă: culorile se întorc. */
    invert?: boolean;
}) {
    if (before === null || before === undefined) {
        return null;
    }

    const diff = now - before;
    const pct = before === 0 ? null : (diff / Math.abs(before)) * 100;

    return (
        <div
            className={cn(
                'text-xs font-normal tabular-nums',
                Math.abs(diff) < 0.5
                    ? 'text-muted-foreground'
                    : diff > 0 === !invert
                      ? 'text-emerald-600 dark:text-emerald-400'
                      : 'text-rose-600 dark:text-rose-400',
            )}
            title={`Anul trecut: ${lei(before)} lei`}
        >
            {diff >= 0 ? '+' : '−'}
            {lei(Math.abs(diff))}
            {pct !== null &&
                ` · ${diff >= 0 ? '+' : '−'}${Math.abs(pct).toFixed(1)}%`}
        </div>
    );
}

function PnlTable({
    report,
    previous,
    view,
    open,
    details,
    loading,
    catalogue,
    onOpen,
    onDrop,
    onDocuments,
    onExpand,
    onMoveChannel,
    onMoveProduct,
}: {
    report: PnlReport;
    previous: PnlReport | null;
    view: 'channel' | 'product';
    open: { saf: string; column: string | null } | null;
    details: CostDetails | null;
    loading: boolean;
    catalogue: Record<string, { group: string; label: string }>;
    onOpen: (saf: string, column: string | null) => void;
    onDrop: (payload: MovePayload, saf: string) => void;
    onDocuments: (saf: string) => void;
    onExpand: (channel: string | null) => void;
    onMoveChannel: (payload: MovePayload, channel: string) => void;
    onMoveProduct: (payload: MovePayload, product: string) => void;
}) {
    // Rândurile de sub EBITDA se deschid ca să se vadă conturile din spate.
    const [openBelow, setOpenBelow] = useState<string | null>(null);

    const columns = view === 'channel' ? report.channels : report.products;
    const columnLabel = (key: string) =>
        view === 'channel' ? (CHANNEL_LABELS[key] ?? key) : key;

    const revenueOf = (key: string) =>
        (view === 'channel'
            ? report.revenue.by_channel[key]
            : report.revenue.by_product[key]) ?? {
            net: 0,
            margin: 0,
            bookings: 0,
        };

    const costOf = (line: PnlLine, key: string) =>
        (view === 'channel' ? line.by_channel[key] : line.by_product[key]) ?? 0;

    const prevLines = useMemo(
        () => new Map((previous?.lines ?? []).map((l) => [l.saf, l])),
        [previous],
    );

    const prevRevenueOf = (key: string) =>
        (view === 'channel'
            ? previous?.revenue.by_channel[key]
            : previous?.revenue.by_product[key]) ?? null;

    const prevCostOf = (saf: string, key: string) => {
        const line = prevLines.get(saf);

        if (!line) {
            return null;
        }

        return (
            (view === 'channel'
                ? line.by_channel[key]
                : line.by_product[key]) ?? 0
        );
    };

    /**
     * Cum a ajuns cheltuiala pe coloana asta: direct, dacă are punctul ei de
     * lucru, sau pe cheia de venit — și atunci se spune cât face coloana din
     * venitul perioadei, ca să se vadă de unde iese procentul.
     */
    // Canalele care nu duc cheltuieli (franciza) ies și din cheia de venit,
    // deci procentele din tooltip se citesc pe venitul rămas, nu pe tot.
    const costFreeChannels = report.meta.channels_without_costs ?? [];

    // Cheia unei sucursale desfășurate e „canal|sucursală”; canalul decide.
    const bearsCost = (key: string) =>
        view !== 'channel' || !costFreeChannels.includes(key.split('|')[0]);

    const costBearingNet =
        view === 'channel'
            ? Object.entries(report.revenue.by_channel).reduce(
                  (sum, [key, row]) => (bearsCost(key) ? sum + row.net : sum),
                  0,
              )
            : report.revenue.total.net;

    const allocationTitle = (line: PnlLine, key: string) => {
        const cell = costOf(line, key);
        const ofLine = line.total === 0 ? 0 : (cell / line.total) * 100;

        if (!bearsCost(key)) {
            return [
                `${line.saf} ${line.label} · ${columnLabel(key)}`,
                `${columnLabel(key)} nu poartă cheltuieli: le suportă cel care deține franciza.`,
                'Venitul net și marja rămân ale noastre, dar cheia de repartizare sare peste canal, iar partea lui se împarte pe celelalte.',
                'O cheltuială ajunge aici doar mutată de mână.',
            ].join('\n');
        }

        const share =
            costBearingNet === 0
                ? 0
                : (revenueOf(key).net / costBearingNet) * 100;

        return [
            `${line.saf} ${line.label} · ${columnLabel(key)}`,
            `${lei(cell)} lei — ${ofLine.toFixed(1)}% din linie`,
            line.direct > 0
                ? `Linia are ${lei(line.direct)} lei alocați direct (punct de lucru care e o sucursală); restul merge pe cheia de venit.`
                : 'Linia nu are cheltuieli legate de un punct de lucru, deci se împarte toată pe cheia de venit.',
            `Cheia: ${columnLabel(key)} face ${share.toFixed(1)}% din venitul net care duce cheltuieli (${lei(revenueOf(key).net)} din ${lei(costBearingNet)} lei).`,
        ].join('\n');
    };

    const tripOf = (key: string) => {
        const row = report.revenue.trip_costs;

        return row === undefined
            ? 0
            : ((view === 'channel'
                  ? row.by_channel[key]
                  : row.by_product[key]) ?? 0);
    };

    const prevTripOf = (key: string) => {
        const row = previous?.revenue.trip_costs;

        return row === undefined
            ? null
            : ((view === 'channel'
                  ? row.by_channel[key]
                  : row.by_product[key]) ?? 0);
    };

    const fxOf = (key: string) => {
        const row = report.revenue.fx_commission;

        return row === undefined
            ? 0
            : ((view === 'channel'
                  ? row.by_channel[key]
                  : row.by_product[key]) ?? 0);
    };

    const prevFxOf = (key: string) => {
        const row = previous?.revenue.fx_commission;

        return row === undefined
            ? null
            : ((view === 'channel'
                  ? row.by_channel[key]
                  : row.by_product[key]) ?? 0);
    };

    const prevCostTotalOf = (key: string) =>
        previous === null
            ? null
            : ((view === 'channel'
                  ? previous.totals.by_channel[key]
                  : previous.totals.by_product[key]) ?? 0);

    const prevBelowOf = (bucket: string, key?: string) => {
        const row = previous?.below?.[bucket];

        if (!row) {
            return null;
        }

        if (key === undefined) {
            return row.total;
        }

        return (
            (view === 'channel' ? row.by_channel[key] : row.by_product[key]) ??
            0
        );
    };

    const costTotalOf = (key: string) =>
        (view === 'channel'
            ? report.totals.by_channel[key]
            : report.totals.by_product[key]) ?? 0;

    // Implicit, raportul se vede pe grupe: 26 de rânduri în loc de 140. Grupa
    // se deschide la un clic, iar în timpul unei mutări se deschide singură
    // când treci peste ea, ca să ai unde lăsa cheltuiala.
    const [openGroups, setOpenGroups] = useState<Set<string>>(new Set());

    const toggleGroup = (group: string) =>
        setOpenGroups((current) => {
            const next = new Set(current);

            if (!next.delete(group)) {
                next.add(group);
            }

            return next;
        });

    const groups = useMemo(() => {
        const byGroup = new Map<string, PnlLine[]>();

        for (const line of report.lines) {
            if (line.total === 0) {
                continue;
            }

            byGroup.set(line.group, [...(byGroup.get(line.group) ?? []), line]);
        }

        return [...byGroup.entries()].sort(
            (a, b) =>
                b[1].reduce((s, l) => s + l.total, 0) -
                a[1].reduce((s, l) => s + l.total, 0),
        );
    }, [report.lines]);

    const margin = report.revenue.total.margin;
    const ebitda = margin - report.totals.total;

    const belowOf = (bucket: string, key?: string) => {
        const row = report.below?.[bucket];

        if (!row) {
            return 0;
        }

        if (key === undefined) {
            return row.total;
        }

        return (
            (view === 'channel' ? row.by_channel[key] : row.by_product[key]) ??
            0
        );
    };

    const ebit = ebitda - belowOf('amortizare');
    const ebt = ebit - belowOf('financiar');
    const netProfit = ebt - belowOf('impozit');

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-3 text-sm">
                {report.meta.reconciles ? (
                    <span className="inline-flex items-center gap-1.5 rounded-md bg-emerald-50 px-2 py-1 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                        <CheckCircle2 className="size-4" />
                        Cele două vederi închid pe același total
                    </span>
                ) : (
                    <span className="inline-flex items-center gap-1.5 rounded-md bg-amber-50 px-2 py-1 text-amber-700 dark:bg-amber-950 dark:text-amber-300">
                        <TriangleAlert className="size-4" />
                        Vederile nu închid — verifică alocarea
                    </span>
                )}
                <Tooltip>
                    <TooltipTrigger className="text-muted-foreground underline decoration-dotted">
                        {lei(report.meta.direct_lei)} lei direct ·{' '}
                        {lei(report.meta.allocated_lei)} lei pe cheia de venit
                    </TooltipTrigger>
                    <TooltipContent className="max-w-sm">
                        Direct = cheltuiala are un punct de lucru care e o
                        sucursală adevărată. Restul se împarte pe canale după
                        ponderea fiecăruia în venitul net, apoi pe produsele
                        vândute de acel canal.
                    </TooltipContent>
                </Tooltip>
                {report.meta.unmapped_lei > 0 && (
                    <span className="text-amber-700 dark:text-amber-300">
                        {lei(report.meta.unmapped_lei)} lei nemapați
                    </span>
                )}
                <span className="text-muted-foreground">
                    conturi excluse din EBITDA:{' '}
                    {report.meta.excluded_accounts.join(', ')}
                </span>
                <div className="ml-auto flex items-center gap-1">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() =>
                            setOpenGroups(
                                new Set(groups.map(([group]) => group)),
                            )
                        }
                    >
                        <ChevronDown className="size-3.5" />
                        Extinde tot
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setOpenGroups(new Set())}
                    >
                        <ChevronRight className="size-3.5" />
                        Restrânge tot
                    </Button>
                </div>
            </div>

            {report.expand && (
                <div className="flex items-center gap-2 text-sm">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => onExpand(null)}
                    >
                        <ChevronLeft className="size-3.5" />
                        Înapoi la canale
                    </Button>
                    <span className="text-muted-foreground">
                        {CHANNEL_LABELS[report.expand] ?? report.expand} ·{' '}
                        {columns.length} sucursale · totalurile de mai jos sunt
                        ale canalului, nu ale companiei
                    </span>
                </div>
            )}

            {report.ifrs16 && (
                <div className="rounded-md border border-sky-200 bg-sky-50 p-3 text-sm dark:border-sky-900 dark:bg-sky-950/40">
                    <p className="font-medium">
                        IFRS 16 — estimare, nu retratare
                    </p>
                    <p className="mt-1 text-muted-foreground">
                        Contractele de închiriere nu sunt în OMC, așa că datoria
                        e dedusă din chiria plătită, pe{' '}
                        {report.ifrs16.term_years} ani la{' '}
                        {(report.ifrs16.discount_rate * 100).toFixed(1)}%.
                        Chiria de {lei(report.ifrs16.lease_expense)} lei iese
                        din exploatare (EBITDA crește cu ea) și se întoarce ca{' '}
                        {lei(report.ifrs16.depreciation)} lei amortizare și{' '}
                        {lei(report.ifrs16.interest)} lei dobândă. Datorie
                        estimată: {lei(report.ifrs16.liability)} lei. Efect pe
                        profitul net: {lei(report.ifrs16.effect_on_net)} lei.
                    </p>
                </div>
            )}

            <Card>
                <CardContent className="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="sticky left-0 z-20 w-80 max-w-80 min-w-80 bg-card">
                                    Linie
                                </TableHead>
                                <TableHead className="text-right">
                                    Total
                                </TableHead>
                                {columns.map((key) => (
                                    <TableHead key={key} className="text-right">
                                        {view === 'channel' &&
                                        !report.expand &&
                                        report.expandable.includes(key) ? (
                                            <button
                                                type="button"
                                                onClick={() => onExpand(key)}
                                                className="inline-flex items-center gap-1 underline decoration-dotted underline-offset-2 hover:text-foreground"
                                                title="Deschide pe sucursale"
                                            >
                                                {columnLabel(key)}
                                                <ChevronRight className="size-3.5" />
                                            </button>
                                        ) : (
                                            columnLabel(key)
                                        )}
                                    </TableHead>
                                ))}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <Row
                                label="Venit net"
                                total={report.revenue.total.net}
                                values={columns.map(
                                    (key) => revenueOf(key).net,
                                )}
                                emphasis
                                previousTotal={
                                    previous?.revenue.total.net ?? null
                                }
                                previousValues={columns.map(
                                    (key) => prevRevenueOf(key)?.net ?? null,
                                )}
                            />
                            {report.revenue.cogs !== undefined && (
                                <Row
                                    label="Cost servicii vândute (628)"
                                    total={report.revenue.cogs}
                                    values={columns.map(
                                        (key) =>
                                            revenueOf(key).net -
                                            revenueOf(key).margin,
                                    )}
                                    previousTotal={
                                        previous?.revenue.cogs ?? null
                                    }
                                    previousValues={columns.map((key) => {
                                        const r = prevRevenueOf(key);

                                        return r === null
                                            ? null
                                            : r.net - r.margin;
                                    })}
                                    costLike
                                />
                            )}
                            {report.revenue.trip_costs !== undefined && (
                                <Row
                                    label={report.revenue.trip_costs.label}
                                    total={report.revenue.trip_costs.total}
                                    values={columns.map((key) => tripOf(key))}
                                    previousTotal={
                                        previous?.revenue.trip_costs?.total ??
                                        null
                                    }
                                    previousValues={columns.map((key) =>
                                        prevTripOf(key),
                                    )}
                                    costLike
                                />
                            )}
                            {report.revenue.fx_commission !== undefined &&
                                Math.abs(report.revenue.fx_commission.total) >
                                    0.5 && (
                                    <Row
                                        label={
                                            report.revenue.fx_commission.label
                                        }
                                        total={
                                            report.revenue.fx_commission.total
                                        }
                                        values={columns.map((key) => fxOf(key))}
                                        previousTotal={
                                            previous?.revenue.fx_commission
                                                ?.total ?? null
                                        }
                                        previousValues={columns.map((key) =>
                                            prevFxOf(key),
                                        )}
                                    />
                                )}
                            <Row
                                label="Marjă brută"
                                total={margin}
                                values={columns.map(
                                    (key) => revenueOf(key).margin,
                                )}
                                previousTotal={
                                    previous?.revenue.total.margin ?? null
                                }
                                previousValues={columns.map(
                                    (key) => prevRevenueOf(key)?.margin ?? null,
                                )}
                                hint={columns.map((key) =>
                                    pct(
                                        revenueOf(key).margin,
                                        revenueOf(key).net,
                                    ),
                                )}
                                totalHint={pct(
                                    margin,
                                    report.revenue.total.net,
                                )}
                                emphasis
                            />

                            {groups.map(([group, lines]) => (
                                <Fragment key={group}>
                                    <Row
                                        label={group}
                                        total={lines.reduce(
                                            (s, l) => s + l.total,
                                            0,
                                        )}
                                        values={columns.map((key) =>
                                            lines.reduce(
                                                (s, l) => s + costOf(l, key),
                                                0,
                                            ),
                                        )}
                                        previousTotal={
                                            previous === null
                                                ? null
                                                : lines.reduce(
                                                      (s, l) =>
                                                          s +
                                                          (prevLines.get(l.saf)
                                                              ?.total ?? 0),
                                                      0,
                                                  )
                                        }
                                        previousValues={columns.map((key) =>
                                            previous === null
                                                ? null
                                                : lines.reduce(
                                                      (s, l) =>
                                                          s +
                                                          (prevCostOf(
                                                              l.saf,
                                                              key,
                                                          ) ?? 0),
                                                      0,
                                                  ),
                                        )}
                                        group
                                        groupOpen={openGroups.has(group)}
                                        lineCount={lines.length}
                                        onToggleGroup={() => toggleGroup(group)}
                                        onDragOverGroup={() =>
                                            !openGroups.has(group) &&
                                            toggleGroup(group)
                                        }
                                        costLike
                                    />
                                    {openGroups.has(group) &&
                                        lines
                                            .sort((a, b) => b.total - a.total)
                                            .map((line) => (
                                                <Fragment key={line.saf}>
                                                    <Row
                                                        label={line.label}
                                                        code={line.saf}
                                                        total={line.total}
                                                        values={columns.map(
                                                            (key) =>
                                                                costOf(
                                                                    line,
                                                                    key,
                                                                ),
                                                        )}
                                                        saf={line.saf}
                                                        columns={columns}
                                                        expanded={
                                                            open?.saf ===
                                                            line.saf
                                                        }
                                                        onOpen={onOpen}
                                                        onDrop={onDrop}
                                                        previousTotal={
                                                            previous === null
                                                                ? null
                                                                : (prevLines.get(
                                                                      line.saf,
                                                                  )?.total ?? 0)
                                                        }
                                                        previousValues={columns.map(
                                                            (key) =>
                                                                prevCostOf(
                                                                    line.saf,
                                                                    key,
                                                                ),
                                                        )}
                                                        valueTitles={columns.map(
                                                            (key) =>
                                                                allocationTitle(
                                                                    line,
                                                                    key,
                                                                ),
                                                        )}
                                                        costLike
                                                    />
                                                    {open?.saf === line.saf && (
                                                        <CostBreakdown
                                                            line={line}
                                                            column={open.column}
                                                            columnLabel={
                                                                columnLabel
                                                            }
                                                            columnValue={
                                                                open.column
                                                                    ? costOf(
                                                                          line,
                                                                          open.column,
                                                                      )
                                                                    : null
                                                            }
                                                            details={details}
                                                            loading={loading}
                                                            catalogue={
                                                                catalogue
                                                            }
                                                            onMove={onDrop}
                                                            onMoveChannel={
                                                                onMoveChannel
                                                            }
                                                            onMoveProduct={
                                                                onMoveProduct
                                                            }
                                                            axis={view}
                                                            products={
                                                                report.products
                                                            }
                                                            span={
                                                                columns.length +
                                                                2
                                                            }
                                                            onDocuments={
                                                                onDocuments
                                                            }
                                                        />
                                                    )}
                                                </Fragment>
                                            ))}
                                </Fragment>
                            ))}

                            <Row
                                label="Cheltuieli totale operaționale"
                                total={report.totals.total}
                                values={columns.map((key) => costTotalOf(key))}
                                previousTotal={previous?.totals.total ?? null}
                                previousValues={columns.map((key) =>
                                    prevCostTotalOf(key),
                                )}
                                emphasis
                                costLike
                            />
                            <Row
                                label="EBITDA"
                                total={ebitda}
                                previousTotal={
                                    previous === null
                                        ? null
                                        : previous.revenue.total.margin -
                                          previous.totals.total
                                }
                                previousValues={columns.map((key) =>
                                    previous === null
                                        ? null
                                        : (prevRevenueOf(key)?.margin ?? 0) -
                                          (prevCostTotalOf(key) ?? 0),
                                )}
                                values={columns.map(
                                    (key) =>
                                        revenueOf(key).margin -
                                        costTotalOf(key),
                                )}
                                hint={columns.map((key) =>
                                    pct(
                                        revenueOf(key).margin -
                                            costTotalOf(key),
                                        revenueOf(key).net,
                                    ),
                                )}
                                totalHint={pct(
                                    ebitda,
                                    report.revenue.total.net,
                                )}
                                emphasis
                            />
                            <BelowRow
                                bucket="amortizare"
                                label="Amortizare (net de reluări)"
                                explanation="Rulajul debitor al conturilor 681 (amortizări, provizioane, ajustări) minus rulajul creditor al conturilor 781 și 786 (reluările lor). De aceea e „net”: într-un an în care se reiau provizioane mai mari decât amortizarea, linia iese negativă și adaugă la profit."
                                report={report}
                                previous={previous}
                                columns={columns}
                                belowOf={belowOf}
                                prevBelowOf={prevBelowOf}
                                open={openBelow === 'amortizare'}
                                onToggle={() =>
                                    setOpenBelow(
                                        openBelow === 'amortizare'
                                            ? null
                                            : 'amortizare',
                                    )
                                }
                                span={columns.length + 2}
                            />
                            <Row
                                label="EBIT"
                                total={ebit}
                                values={columns.map(
                                    (key) =>
                                        revenueOf(key).margin -
                                        costTotalOf(key) -
                                        belowOf('amortizare', key),
                                )}
                                emphasis
                                previousTotal={
                                    previous === null
                                        ? null
                                        : previous.revenue.total.margin -
                                          previous.totals.total -
                                          (prevBelowOf('amortizare') ?? 0)
                                }
                                previousValues={columns.map((key) =>
                                    previous === null
                                        ? null
                                        : (prevRevenueOf(key)?.margin ?? 0) -
                                          (prevCostTotalOf(key) ?? 0) -
                                          (prevBelowOf('amortizare', key) ?? 0),
                                )}
                            />
                            <BelowRow
                                bucket="financiar"
                                label="Rezultat financiar (net)"
                                explanation="Rulajul debitor al conturilor 665–668 (diferențe de curs, dobânzi, sconturi) minus rulajul creditor al conturilor 765–768. Comisionul de curs de pe încasările clientului nu e aici: e venit și stă sus, în marjă."
                                report={report}
                                previous={previous}
                                columns={columns}
                                belowOf={belowOf}
                                prevBelowOf={prevBelowOf}
                                open={openBelow === 'financiar'}
                                onToggle={() =>
                                    setOpenBelow(
                                        openBelow === 'financiar'
                                            ? null
                                            : 'financiar',
                                    )
                                }
                                span={columns.length + 2}
                            />
                            <Row
                                label="Profit înainte de impozitare"
                                total={ebt}
                                values={columns.map(
                                    (key) =>
                                        revenueOf(key).margin -
                                        costTotalOf(key) -
                                        belowOf('amortizare', key) -
                                        belowOf('financiar', key),
                                )}
                                emphasis
                                previousTotal={
                                    previous === null
                                        ? null
                                        : previous.revenue.total.margin -
                                          previous.totals.total -
                                          (prevBelowOf('amortizare') ?? 0) -
                                          (prevBelowOf('financiar') ?? 0)
                                }
                                previousValues={columns.map((key) =>
                                    previous === null
                                        ? null
                                        : (prevRevenueOf(key)?.margin ?? 0) -
                                          (prevCostTotalOf(key) ?? 0) -
                                          (prevBelowOf('amortizare', key) ??
                                              0) -
                                          (prevBelowOf('financiar', key) ?? 0),
                                )}
                            />
                            <BelowRow
                                bucket="impozit"
                                label="Impozit pe profit"
                                explanation="Rulajul debitor al conturilor 691 și 698. E impozitul înregistrat în contabilitate în perioada afișată, nu unul recalculat de raport."
                                report={report}
                                previous={previous}
                                columns={columns}
                                belowOf={belowOf}
                                prevBelowOf={prevBelowOf}
                                open={openBelow === 'impozit'}
                                onToggle={() =>
                                    setOpenBelow(
                                        openBelow === 'impozit'
                                            ? null
                                            : 'impozit',
                                    )
                                }
                                span={columns.length + 2}
                            />
                            <Row
                                label="Profit net"
                                total={netProfit}
                                previousTotal={
                                    previous === null
                                        ? null
                                        : previous.revenue.total.margin -
                                          previous.totals.total -
                                          (prevBelowOf('amortizare') ?? 0) -
                                          (prevBelowOf('financiar') ?? 0) -
                                          (prevBelowOf('impozit') ?? 0)
                                }
                                values={columns.map(
                                    (key) =>
                                        revenueOf(key).margin -
                                        costTotalOf(key) -
                                        belowOf('amortizare', key) -
                                        belowOf('financiar', key) -
                                        belowOf('impozit', key),
                                )}
                                hint={columns.map((key) =>
                                    pct(
                                        revenueOf(key).margin -
                                            costTotalOf(key) -
                                            belowOf('amortizare', key) -
                                            belowOf('financiar', key) -
                                            belowOf('impozit', key),
                                        revenueOf(key).net,
                                    ),
                                )}
                                totalHint={pct(
                                    netProfit,
                                    report.revenue.total.net,
                                )}
                                emphasis
                                previousValues={columns.map((key) =>
                                    previous === null
                                        ? null
                                        : (prevRevenueOf(key)?.margin ?? 0) -
                                          (prevCostTotalOf(key) ?? 0) -
                                          (prevBelowOf('amortizare', key) ??
                                              0) -
                                          (prevBelowOf('financiar', key) ?? 0) -
                                          (prevBelowOf('impozit', key) ?? 0),
                                )}
                            />
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
        </div>
    );
}

function Row({
    label,
    code,
    total,
    values,
    previousTotal,
    previousValues,
    valueTitles,
    costLike,
    hint,
    totalHint,
    group,
    emphasis,
    saf,
    columns,
    expanded,
    reclassified,
    groupOpen,
    lineCount,
    onToggleGroup,
    onDragOverGroup,
    onOpen,
    onDrop,
}: {
    label: string;
    code?: string;
    total: number;
    values: number[];
    previousTotal?: number | null;
    previousValues?: (number | null)[];
    /** Explicația fiecărei celule: cum a ajuns cheltuiala pe coloana aceea. */
    valueTitles?: string[];
    /** Rând de cheltuială: plusul se colorează roșu, minusul verde. */
    costLike?: boolean;
    hint?: string[];
    totalHint?: string;
    group?: boolean;
    emphasis?: boolean;
    saf?: string;
    columns?: string[];
    expanded?: boolean;
    reclassified?: boolean;
    groupOpen?: boolean;
    lineCount?: number;
    onToggleGroup?: () => void;
    onDragOverGroup?: () => void;
    onOpen?: (saf: string, column: string | null) => void;
    onDrop?: (payload: MovePayload, saf: string) => void;
}) {
    const [over, setOver] = useState(false);
    // Liniile de cheltuială primesc cheltuieli mutate din alte linii; ele
    // însele nu se trag nicăieri — se mută cheltuiala, nu rândul.
    const interactive = saf !== undefined && onOpen !== undefined;

    return (
        <TableRow
            onDragOver={(event) => {
                // O grupă închisă se deschide singură când treci peste ea cu o
                // cheltuială în mână: altfel n-ai unde s-o lași.
                if (onDragOverGroup) {
                    event.preventDefault();
                    onDragOverGroup();

                    return;
                }

                if (!interactive) {
                    return;
                }

                event.preventDefault();
                event.dataTransfer.dropEffect = 'move';
                setOver(true);
            }}
            onDragLeave={() => setOver(false)}
            onDrop={(event) => {
                setOver(false);

                if (!saf || !onDrop) {
                    return;
                }

                const raw = event.dataTransfer.getData(
                    'application/x-pnl-move',
                );

                if (!raw) {
                    return;
                }

                event.preventDefault();
                onDrop(JSON.parse(raw) as MovePayload, saf);
            }}
            onClick={() =>
                onToggleGroup ? onToggleGroup() : saf && onOpen?.(saf, null)
            }
            className={cn(
                (interactive || onToggleGroup) &&
                    'cursor-pointer hover:bg-muted/40',
                over && 'outline outline-2 outline-primary',
                expanded && 'bg-muted/30',
                emphasis && 'bg-muted/60 font-semibold',
                group && 'bg-muted/30 font-medium',
            )}
        >
            <TableCell
                className={cn(
                    // Numele liniei rămâne vizibil cât timp coloanele
                    // defilează lateral; fundalul e opac ca să nu se vadă
                    // cifrele pe dedesubt, iar lățimea e fixă, altfel un nume
                    // lung iese din celulă peste coloana de total.
                    'sticky left-0 z-10 w-80 max-w-80 min-w-80 overflow-hidden bg-card',
                    emphasis && 'bg-muted',
                    group && 'bg-muted',
                    !group && !emphasis && 'pl-6',
                )}
                title={label}
            >
                <span className="flex w-72 min-w-0 items-center">
                    {onToggleGroup &&
                        (groupOpen ? (
                            <ChevronDown className="mr-1.5 size-4 shrink-0 text-muted-foreground" />
                        ) : (
                            <ChevronRight className="mr-1.5 size-4 shrink-0 text-muted-foreground" />
                        ))}
                    {code && (
                        <span className="mr-2 shrink-0 font-mono text-xs text-muted-foreground">
                            {code}
                        </span>
                    )}
                    <span className="truncate">{label}</span>
                    {reclassified && (
                        <span className="ml-2 shrink-0 rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-medium text-sky-800 dark:bg-sky-950 dark:text-sky-300">
                            reclasificat IFRS 16
                        </span>
                    )}
                    {onToggleGroup && lineCount !== undefined && (
                        <span className="ml-2 shrink-0 text-xs font-normal text-muted-foreground">
                            {lineCount} {lineCount === 1 ? 'linie' : 'linii'}
                        </span>
                    )}
                </span>
            </TableCell>
            <TableCell className="text-right whitespace-nowrap tabular-nums">
                {lei(total)}
                {totalHint && (
                    <span className="ml-2 text-xs font-normal text-muted-foreground">
                        {totalHint}
                    </span>
                )}
                <Delta now={total} before={previousTotal} invert={costLike} />
            </TableCell>
            {values.map((value, i) => (
                <TableCell
                    key={i}
                    onClick={(event) => {
                        if (!saf || !onOpen) {
                            return;
                        }

                        // Apăsând chiar pe cifră, detaliul se deschide pe coloana ei.
                        event.stopPropagation();
                        onOpen(saf, columns?.[i] ?? null);
                    }}
                    title={valueTitles?.[i]}
                    className={cn(
                        'text-right whitespace-nowrap tabular-nums',
                        interactive && 'hover:underline',
                    )}
                >
                    {lei(value)}
                    {hint?.[i] && (
                        <span className="ml-2 text-xs font-normal text-muted-foreground">
                            {hint[i]}
                        </span>
                    )}
                    <Delta
                        now={value}
                        before={previousValues?.[i]}
                        invert={costLike}
                    />
                </TableCell>
            ))}
        </TableRow>
    );
}
