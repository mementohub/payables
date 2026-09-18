import { Link } from '@inertiajs/react';
import {
    CircleCheck,
    ExternalLink,
    FileWarning,
    Info,
    Search,
    TriangleAlert,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import PaymentCheckController from '@/actions/App/Http/Controllers/PaymentCheckController';
import DatePicker from '@/components/date-picker';
import EtripSupplierPicker from '@/components/etrip-supplier-picker';
import type { EtripSupplierOption } from '@/components/etrip-supplier-picker';
import {
    LEVEL_TEXT,
    Tile,
    VerdictIcon,
    dmy,
    fmt,
} from '@/components/payment-checks/check-ui';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import WorkflowStatusBadge from '@/components/workflow-status-badge';
import { cn } from '@/lib/utils';
import type {
    Reconciliation,
    ReconciliationAlertKind,
    ReconciliationInvoice,
    ReconciliationKpi,
} from '@/pages/payment-checks/types';
import { primite, show as invoiceShow } from '@/routes/invoices';
import { show as partnerShow } from '@/routes/partners';

type Grid = 'month' | 'type' | 'future';

type InvoiceFilter = 'all' | 'open' | 'overdue' | 'correction' | 'draft';

type Request = {
    key: string;
    connection: string;
    supplier: string;
    from: string;
    to: string;
};

function ask(
    connection: string,
    supplier: string,
    start: string,
    end: string,
): Request {
    const [from, to] = end < start ? [end, start] : [start, end];

    return { key: `${connection}|${supplier}`, connection, supplier, from, to };
}

const MONTHS = [
    'ian',
    'feb',
    'mar',
    'apr',
    'mai',
    'iun',
    'iul',
    'aug',
    'sep',
    'oct',
    'nov',
    'dec',
];

const ALERT_TITLES: Record<ReconciliationAlertKind, string> = {
    types: 'Tipuri de serviciu aproape nefacturate',
    months: 'Luni încheiate cu diferență peste prag',
    overdue: 'Facturi neachitate trecute de scadență',
    draft: 'Facturi nefinalizate în eTrip',
    invalid_due: 'Scadență invalidă în eTrip',
    omc_missing: 'Facturi din eTrip negăsite în OMC',
    unlinked: 'Furnizor nelegat de OMC',
};

function monthLabel(month: string): string {
    const [year, number] = month.split('-');

    return `${MONTHS[Number(number) - 1]} ${year}`;
}

function monthEnd(month: string): string {
    const [year, number] = month.split('-').map(Number);
    const last = new Date(Date.UTC(year, number, 0)).getUTCDate();

    return `${month}-${String(last).padStart(2, '0')}`;
}

function isoToday(): string {
    const now = new Date();

    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}

function monthsBack(months: number): string {
    const now = new Date();
    const start = new Date(now.getFullYear(), now.getMonth() - months + 1, 1);

    return `${start.getFullYear()}-${String(start.getMonth() + 1).padStart(2, '0')}-01`;
}

function signed(value: number, decimals = 0): string {
    return `${value > 0 ? '+' : ''}${fmt(value, decimals)}`;
}

function diffTone(pct: number | null, limit: number): string {
    if (pct === null) {
        return 'text-muted-foreground';
    }

    return Math.abs(pct) > limit
        ? 'text-destructive'
        : 'text-emerald-700 dark:text-emerald-500';
}

/** One line per currency: amounts are never added across currencies. */
function PerCurrency({
    kpis,
    value,
    empty = '0',
}: {
    kpis: ReconciliationKpi[];
    value: (kpi: ReconciliationKpi) => number | null;
    empty?: string;
}) {
    const rows = kpis.filter((kpi) => (value(kpi) ?? 0) !== 0);

    if (rows.length === 0) {
        return <span className="text-muted-foreground">{empty}</span>;
    }

    return (
        <span className="text-xl leading-snug">
            {rows.map((kpi) => (
                <span key={kpi.currency} className="block">
                    {fmt(value(kpi) ?? 0)}{' '}
                    <span className="text-sm font-medium text-muted-foreground">
                        {kpi.currency}
                    </span>
                </span>
            ))}
        </span>
    );
}

function Num({
    value,
    className,
}: {
    value: number | null;
    className?: string;
}) {
    return (
        <td
            className={cn(
                'px-3 py-2 text-right whitespace-nowrap tabular-nums',
                className,
            )}
        >
            {value === null ? '–' : fmt(value)}
        </td>
    );
}

function TableShell({
    head,
    children,
}: {
    head: ReactNode;
    children: ReactNode;
}) {
    return (
        <div className="max-h-[520px] overflow-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
            <table className="w-full text-sm">
                <thead className="sticky top-0 z-10 bg-muted/80 text-left text-xs text-muted-foreground uppercase backdrop-blur">
                    {head}
                </thead>
                <tbody className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                    {children}
                </tbody>
            </table>
        </div>
    );
}

function DueCell({
    invoice,
    termDays,
}: {
    invoice: ReconciliationInvoice;
    termDays: number | null;
}) {
    const source =
        invoice.due_source === 'term'
            ? `termen ${termDays ?? ''} zile`
            : invoice.due_source === 'omc'
              ? 'din OMC'
              : null;

    return (
        <td className="px-3 py-2 whitespace-nowrap">
            {dmy(invoice.due)}
            {source && (
                <span className="block text-xs text-muted-foreground">
                    {source}
                </span>
            )}
            {invoice.invalid_due && (
                <span
                    className="block text-xs text-amber-600 dark:text-amber-400"
                    title="Scadența din eTrip este invalidă (an greșit sau înaintea datei facturii)."
                >
                    eTrip: {invoice.due_date}
                </span>
            )}
            {invoice.days_overdue > 0 && (
                <span className="block text-xs text-destructive">
                    {invoice.days_overdue} zile întârziere
                </span>
            )}
        </td>
    );
}

/**
 * Verificare plăți → Facturi vs. servicii: the supplier's eTrip invoices
 * against the services it delivered, per check-in month and service type,
 * with the invoices themselves and what is left to pay.
 */
export default function SupplierReconciliationPanel({
    active,
    bases,
    connection,
    supplierCode,
    onSupplierChange,
    initialFrom,
    initialTo,
    onCheckin,
}: {
    active: boolean;
    bases: { key: string; label: string }[];
    connection: string | null;
    supplierCode: string | null;
    onSupplierChange: (option: EtripSupplierOption | null) => void;
    initialFrom: string | null;
    initialTo: string | null;
    /** Open the check-in check on a range, all services. */
    onCheckin: (from: string, to: string) => void;
}) {
    const [from, setFrom] = useState(
        initialFrom ?? `${new Date().getFullYear()}-01-01`,
    );
    const [to, setTo] = useState(initialTo ?? isoToday());
    const [request, setRequest] = useState<Request | null>(null);
    const [answer, setAnswer] = useState<{
        request: Request;
        result: Reconciliation | null;
        error: string | null;
    } | null>(null);
    const [grid, setGrid] = useState<Grid>('month');
    const [filter, setFilter] = useState<InvoiceFilter>('all');

    const supplierKey =
        connection !== null && supplierCode
            ? `${connection}|${supplierCode}`
            : null;

    // A supplier picked here or on the check-in tab is checked as soon as
    // this tab shows, on the range already set.
    if (
        active &&
        connection !== null &&
        supplierCode &&
        supplierKey !== request?.key
    ) {
        setRequest(ask(connection, supplierCode, from, to));
    }

    useEffect(() => {
        if (!request) {
            return;
        }

        let cancelled = false;
        const settle = (
            result: Reconciliation | null,
            error: string | null,
        ) => {
            if (!cancelled) {
                setAnswer({ request, result, error });
                setFilter('all');
            }
        };

        fetch(
            PaymentCheckController.reconcile({
                query: {
                    connection: request.connection,
                    supplier: request.supplier,
                    from: request.from,
                    to: request.to,
                },
            }).url,
            { headers: { Accept: 'application/json' } },
        )
            .then(async (res) => {
                if (!res.ok) {
                    const body = (await res.json().catch(() => null)) as {
                        message?: string;
                    } | null;

                    throw new Error(body?.message ?? 'Verificarea a eșuat.');
                }

                return (await res.json()) as Reconciliation;
            })
            .then((data) => settle(data, null))
            .catch((err: Error) => settle(null, err.message));

        return () => {
            cancelled = true;
        };
    }, [request]);

    function run(start: string, end: string) {
        if (connection === null || !supplierCode) {
            return;
        }

        setRequest(ask(connection, supplierCode, start, end));
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        run(from, to);
    }

    function preset(start: string) {
        const end = isoToday();

        setFrom(start);
        setTo(end);
        run(start, end);
    }

    const loading = request !== null && answer?.request !== request;
    const error =
        connection === null || !supplierCode
            ? null
            : answer?.request.key === supplierKey
              ? answer.error
              : null;
    const result = answer?.result ?? null;

    const shown =
        result && supplierKey === `${connection}|${result.supplier.code}`
            ? result
            : null;

    return (
        <div className="flex flex-col gap-4">
            <Card>
                <CardHeader>
                    <CardTitle>
                        Facturile furnizorului față de servicii
                    </CardTitle>
                    <CardDescription>
                        Facturile emise de furnizor în eTrip, confruntate cu
                        serviciile efectiv consumate (check-in efectuat), pe
                        luna de check-in și pe tip de serviciu, separat pe
                        monedă.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <form
                        onSubmit={submit}
                        className="grid gap-4 md:grid-cols-2 xl:grid-cols-6 xl:items-end"
                    >
                        <div className="grid min-w-0 gap-1.5 xl:col-span-6">
                            <Label htmlFor="reconcile-supplier">Furnizor</Label>
                            <EtripSupplierPicker
                                id="reconcile-supplier"
                                bases={bases}
                                value={
                                    supplierCode && connection !== null
                                        ? { connection, code: supplierCode }
                                        : null
                                }
                                onChange={onSupplierChange}
                            />
                        </div>
                        <div className="grid min-w-0 gap-1.5">
                            <Label htmlFor="reconcile-from">
                                Perioada de la
                            </Label>
                            <DatePicker
                                id="reconcile-from"
                                name="from"
                                value={from}
                                onChange={setFrom}
                                required
                            />
                        </div>
                        <div className="grid min-w-0 gap-1.5">
                            <Label htmlFor="reconcile-to">
                                până la (inclusiv)
                            </Label>
                            <DatePicker
                                id="reconcile-to"
                                name="to"
                                value={to}
                                onChange={setTo}
                                required
                            />
                        </div>
                        <div className="flex flex-wrap gap-1.5 xl:col-span-3">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    preset(`${new Date().getFullYear()}-01-01`)
                                }
                            >
                                Anul curent
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => preset(monthsBack(12))}
                            >
                                Ultimele 12 luni
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => preset(monthsBack(3))}
                            >
                                Ultimele 3 luni
                            </Button>
                        </div>
                        <Button
                            type="submit"
                            disabled={loading || connection === null}
                        >
                            <Search />
                            Verifică
                        </Button>
                    </form>
                    <p className="mt-3 text-xs text-muted-foreground">
                        Facturile se iau după data facturii, serviciile după
                        data de check-in; ambele în perioada aleasă. Costul
                        angajat pentru check-in-urile viitoare se adaugă
                        separat.
                    </p>
                    {error && (
                        <p className="mt-3 rounded-md bg-destructive/10 p-3 text-sm text-destructive">
                            {error}
                        </p>
                    )}
                </CardContent>
            </Card>

            {loading && !shown && (
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                    {[0, 1, 2, 3, 4].map((index) => (
                        <Skeleton
                            key={index}
                            className="h-28 animate-pulse rounded-xl"
                        />
                    ))}
                </div>
            )}

            {!loading && !shown && !error && (
                <Card>
                    <CardContent className="py-8 text-center text-sm text-muted-foreground">
                        Alege un furnizor pentru a-i confrunta facturile cu
                        serviciile din eTrip.
                    </CardContent>
                </Card>
            )}

            {shown && (
                <div
                    className={cn(
                        'flex flex-col gap-4',
                        loading && 'opacity-60',
                    )}
                >
                    <ReconciliationResult
                        result={shown}
                        grid={grid}
                        onGrid={setGrid}
                        filter={filter}
                        onFilter={setFilter}
                        onCheckin={onCheckin}
                    />
                </div>
            )}
        </div>
    );
}

function ReconciliationResult({
    result,
    grid,
    onGrid,
    filter,
    onFilter,
    onCheckin,
}: {
    result: Reconciliation;
    grid: Grid;
    onGrid: (grid: Grid) => void;
    filter: InvoiceFilter;
    onFilter: (filter: InvoiceFilter) => void;
    onCheckin: (from: string, to: string) => void;
}) {
    const { supplier, kpis, thresholds } = result;
    const fromOmc = result.payments_source === 'omc';
    const openUnknown = kpis.reduce((n, kpi) => n + kpi.open_unknown, 0);

    const invoices = result.invoices.filter((invoice) => {
        switch (filter) {
            case 'open':
                return (invoice.open ?? 0) > 0.01;
            case 'overdue':
                return invoice.days_overdue > 0;
            case 'correction':
                return invoice.correction;
            case 'draft':
                return !invoice.finalized;
            default:
                return true;
        }
    });

    const counts: Record<InvoiceFilter, number> = {
        all: result.invoices.length,
        open: result.invoices.filter((i) => (i.open ?? 0) > 0.01).length,
        overdue: result.invoices.filter((i) => i.days_overdue > 0).length,
        correction: result.invoices.filter((i) => i.correction).length,
        draft: result.invoices.filter((i) => !i.finalized).length,
    };

    return (
        <>
            <Card>
                <CardContent className="flex flex-col gap-3">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 className="text-lg font-semibold">
                                {supplier.name}
                                <span className="ml-2 font-mono text-sm text-muted-foreground">
                                    {supplier.code}
                                </span>
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {[
                                    supplier.country,
                                    supplier.currency &&
                                        `moneda de bază ${supplier.currency}`,
                                    supplier.balance_due_days
                                        ? `termen de plată ${supplier.balance_due_days} zile`
                                        : 'fără termen de plată în eTrip',
                                    supplier.self_billing && 'autofacturare',
                                    supplier.created_at &&
                                        `creat ${dmy(supplier.created_at)}`,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </p>
                        </div>
                        <div className="flex flex-wrap items-center gap-1.5">
                            {!supplier.active && (
                                <Badge variant="destructive">inactiv</Badge>
                            )}
                            {supplier.manual ? (
                                <Badge variant="outline">
                                    manual / contract
                                </Badge>
                            ) : (
                                supplier.sources
                                    .filter((row) => row.source !== null)
                                    .map((row) => (
                                        <Badge
                                            key={row.source}
                                            variant="outline"
                                        >
                                            API {row.source}
                                        </Badge>
                                    ))
                            )}
                            {supplier.web_access && (
                                <Badge variant="outline">extranet</Badge>
                            )}
                            {supplier.partner_id && (
                                <Button asChild size="sm" variant="ghost">
                                    <Link
                                        href={partnerShow(supplier.partner_id)}
                                    >
                                        <ExternalLink />
                                        Furnizorul în OMC
                                    </Link>
                                </Button>
                            )}
                        </div>
                    </div>

                    {supplier.secondary_omc && (
                        <Alert>
                            <Info />
                            <AlertTitle>
                                Facturile acestui furnizor sunt în a doua bază
                                OMC (memento_bus)
                            </AlertTitle>
                            <AlertDescription>
                                Nu apar în OMC Christian Tour și nici în Facturi
                                primite, deci verificarea se face integral pe
                                eTrip: facturi, plăți alocate și rest de plată
                                de mai jos vin din eTrip.
                            </AlertDescription>
                        </Alert>
                    )}
                    {supplier.manual && (
                        <p className="text-xs text-muted-foreground">
                            Niciun serviciu nu are sursă de rezervare: furnizor
                            manual / contractual, fără integrare API — costurile
                            din eTrip sunt introduse de noi, nu primite de la
                            furnizor.
                        </p>
                    )}
                </CardContent>
            </Card>

            <AlertsCard result={result} />

            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                <Tile
                    label="Cost eTrip · check-in efectuate"
                    value={<PerCurrency kpis={kpis} value={(k) => k.cost} />}
                    detail={`${fmt(kpis.reduce((n, k) => n + k.services, 0))} servicii · ${dmy(result.from)} – ${dmy(result.to < result.today ? result.to : result.today)}`}
                />
                <Tile
                    label="Facturat pe aceste servicii"
                    value={<PerCurrency kpis={kpis} value={(k) => k.billed} />}
                    detail={
                        <span className="flex flex-col">
                            {kpis
                                .filter((k) => k.cost !== 0 || k.billed !== 0)
                                .map((k) => (
                                    <span
                                        key={k.currency}
                                        className={diffTone(
                                            k.diff_pct,
                                            thresholds.month_pct,
                                        )}
                                    >
                                        {signed(k.diff)} {k.currency}
                                        {k.diff_pct !== null &&
                                            ` (${signed(k.diff_pct, 2)}%)`}
                                    </span>
                                ))}
                        </span>
                    }
                />
                <Tile
                    label="Servicii nefacturate"
                    level={
                        kpis.some((k) => k.unbilled_cost > 0) ? 'warn' : null
                    }
                    value={
                        <PerCurrency
                            kpis={kpis}
                            value={(k) => k.unbilled_cost}
                        />
                    }
                    detail="cost eTrip fără nicio linie de factură"
                />
                <Tile
                    label={`Rest de plată · ${fromOmc ? 'OMC' : 'eTrip'}`}
                    level={kpis.some((k) => k.overdue > 0) ? 'crit' : null}
                    value={<PerCurrency kpis={kpis} value={(k) => k.open} />}
                    detail={
                        <span className="flex flex-col">
                            {kpis
                                .filter((k) => k.overdue > 0)
                                .map((k) => (
                                    <span
                                        key={k.currency}
                                        className="text-destructive"
                                    >
                                        {fmt(k.overdue)} {k.currency} restant
                                    </span>
                                ))}
                            <span>
                                pe facturile din perioadă
                                {openUnknown > 0 &&
                                    ` · ${openUnknown} fără status în OMC`}
                            </span>
                        </span>
                    }
                />
                <Tile
                    label="Cost angajat · check-in viitoare"
                    value={
                        <PerCurrency kpis={kpis} value={(k) => k.future_cost} />
                    }
                    detail={`${fmt(kpis.reduce((n, k) => n + k.future_services, 0))} servicii încă nefacturabile`}
                />
            </div>

            <Card>
                <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3 space-y-0">
                    <div>
                        <CardTitle>Servicii consumate vs. facturat</CardTitle>
                        <CardDescription>
                            Facturatul vine din liniile facturilor, legate de
                            fiecare serviciu. Prag: {thresholds.month_pct}% pe o
                            lună încheiată; un tip de serviciu sub{' '}
                            {thresholds.type_min_billed_pct}% facturat în
                            ultimele {thresholds.type_window_months} luni
                            încheiate.
                        </CardDescription>
                    </div>
                    <Tabs value={grid} onValueChange={(v) => onGrid(v as Grid)}>
                        <TabsList>
                            <TabsTrigger value="month">
                                Pe lună de check-in
                            </TabsTrigger>
                            <TabsTrigger value="type">
                                Pe tip de serviciu
                            </TabsTrigger>
                            <TabsTrigger value="future">
                                Check-in viitoare
                            </TabsTrigger>
                        </TabsList>
                    </Tabs>
                </CardHeader>
                <CardContent>
                    {grid === 'month' && (
                        <TableShell
                            head={
                                <tr>
                                    <th className="px-3 py-2">Luna</th>
                                    <th className="px-3 py-2 text-right">
                                        Servicii
                                    </th>
                                    <th className="px-3 py-2 text-right">
                                        Cost eTrip
                                    </th>
                                    <th className="px-3 py-2 text-right">
                                        Facturat
                                    </th>
                                    <th className="px-3 py-2 text-right">
                                        Diferență
                                    </th>
                                    <th className="px-3 py-2 text-right">
                                        Nefacturat
                                    </th>
                                    <th className="px-3 py-2" />
                                </tr>
                            }
                        >
                            {result.months.length === 0 && (
                                <EmptyRow colSpan={7} />
                            )}
                            {result.months.map((row) => (
                                <tr
                                    key={`${row.month}-${row.currency}`}
                                    className={cn(
                                        row.alert &&
                                            'bg-amber-500/5 dark:bg-amber-400/5',
                                    )}
                                >
                                    <td className="px-3 py-2 whitespace-nowrap">
                                        <span className="font-medium">
                                            {monthLabel(row.month)}
                                        </span>{' '}
                                        <span className="text-xs text-muted-foreground">
                                            {row.currency}
                                        </span>
                                        {row.status === 'billing' && (
                                            <Badge
                                                variant="outline"
                                                className="ml-2"
                                                title="Furnizorii de tip DMC facturează în urmă: un gol în luna curentă este de așteptat."
                                            >
                                                în curs de facturare
                                            </Badge>
                                        )}
                                    </td>
                                    <Num value={row.services} />
                                    <Num value={row.cost} />
                                    <Num value={row.billed} />
                                    <td
                                        className={cn(
                                            'px-3 py-2 text-right whitespace-nowrap tabular-nums',
                                            row.status === 'billing'
                                                ? 'text-muted-foreground'
                                                : diffTone(
                                                      row.diff_pct,
                                                      thresholds.month_pct,
                                                  ),
                                        )}
                                    >
                                        {signed(row.diff)}
                                        {row.diff_pct !== null && (
                                            <span className="block text-xs">
                                                {signed(row.diff_pct, 2)}%
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2 text-right whitespace-nowrap tabular-nums">
                                        {row.unbilled_services > 0 ? (
                                            <>
                                                {fmt(row.unbilled_cost)}
                                                <span className="block text-xs text-muted-foreground">
                                                    {row.unbilled_services}{' '}
                                                    servicii
                                                </span>
                                            </>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                –
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            title="Deschide serviciile lunii în verificarea pe check-in"
                                            onClick={() =>
                                                onCheckin(
                                                    `${row.month}-01`,
                                                    monthEnd(row.month) <
                                                        result.today
                                                        ? monthEnd(row.month)
                                                        : result.today,
                                                )
                                            }
                                        >
                                            servicii
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </TableShell>
                    )}

                    {grid === 'type' && (
                        <TableShell
                            head={
                                <tr>
                                    <th className="px-3 py-2">
                                        Tip de serviciu
                                    </th>
                                    <th className="px-3 py-2 text-right">
                                        Servicii
                                    </th>
                                    <th className="px-3 py-2 text-right">
                                        Cost eTrip
                                    </th>
                                    <th className="px-3 py-2 text-right">
                                        Facturat
                                    </th>
                                    <th className="px-3 py-2">% facturat</th>
                                    <th className="px-3 py-2 text-right">
                                        Ultimele {thresholds.type_window_months}{' '}
                                        luni
                                    </th>
                                </tr>
                            }
                        >
                            {result.types.length === 0 && (
                                <EmptyRow colSpan={6} />
                            )}
                            {result.types.map((row) => (
                                <tr
                                    key={`${row.product_type}-${row.currency}`}
                                    className={cn(
                                        row.alert && 'bg-destructive/5',
                                    )}
                                >
                                    <td className="px-3 py-2">
                                        <span className="font-medium">
                                            {row.label}
                                        </span>{' '}
                                        <span className="text-xs text-muted-foreground">
                                            {row.currency}
                                        </span>
                                        {row.alert && (
                                            <span className="block text-xs text-destructive">
                                                aproape nefacturat: fie e inclus
                                                în alt tip (cost dublu în
                                                eTrip), fie nu a fost facturat
                                            </span>
                                        )}
                                    </td>
                                    <Num value={row.services} />
                                    <Num value={row.cost} />
                                    <Num value={row.billed} />
                                    <td className="min-w-36 px-3 py-2">
                                        <div className="flex items-center gap-2">
                                            <div className="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                                                <div
                                                    className={cn(
                                                        'h-full rounded-full',
                                                        row.alert
                                                            ? 'bg-destructive'
                                                            : 'bg-emerald-600',
                                                    )}
                                                    style={{
                                                        width: `${Math.min(100, Math.max(0, row.billed_pct ?? 0))}%`,
                                                    }}
                                                />
                                            </div>
                                            <span className="w-14 text-right text-xs tabular-nums">
                                                {row.billed_pct === null
                                                    ? '–'
                                                    : `${fmt(row.billed_pct, 1)}%`}
                                            </span>
                                        </div>
                                    </td>
                                    <td
                                        className={cn(
                                            'px-3 py-2 text-right whitespace-nowrap tabular-nums',
                                            row.alert && 'text-destructive',
                                        )}
                                    >
                                        {row.window_billed_pct === null
                                            ? '–'
                                            : `${fmt(row.window_billed_pct, 1)}%`}
                                        <span className="block text-xs text-muted-foreground">
                                            din {fmt(row.window_cost)}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </TableShell>
                    )}

                    {grid === 'future' && (
                        <TableShell
                            head={
                                <tr>
                                    <th className="px-3 py-2">Luna</th>
                                    <th className="px-3 py-2 text-right">
                                        Servicii
                                    </th>
                                    <th className="px-3 py-2 text-right">
                                        Cost angajat
                                    </th>
                                    <th className="px-3 py-2 text-right">
                                        Deja facturat
                                    </th>
                                    <th className="px-3 py-2" />
                                </tr>
                            }
                        >
                            {result.future.length === 0 && (
                                <EmptyRow colSpan={5} />
                            )}
                            {result.future.map((row) => (
                                <tr key={`${row.month}-${row.currency}`}>
                                    <td className="px-3 py-2 whitespace-nowrap">
                                        <span className="font-medium">
                                            {monthLabel(row.month)}
                                        </span>{' '}
                                        <span className="text-xs text-muted-foreground">
                                            {row.currency}
                                        </span>
                                    </td>
                                    <Num value={row.services} />
                                    <Num value={row.cost} />
                                    <Num
                                        value={row.billed}
                                        className="text-muted-foreground"
                                    />
                                    <td className="px-3 py-2 text-right">
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                onCheckin(
                                                    `${row.month}-01` >
                                                        result.today
                                                        ? `${row.month}-01`
                                                        : result.today,
                                                    monthEnd(row.month),
                                                )
                                            }
                                        >
                                            servicii
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </TableShell>
                    )}
                </CardContent>
            </Card>

            <Card>
                <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3 space-y-0">
                    <div>
                        <CardTitle>Facturile furnizorului</CardTitle>
                        <CardDescription>
                            {result.invoices_total} facturi în eTrip, datate{' '}
                            {dmy(result.from)} – {dmy(result.to)}; plățile{' '}
                            {fromOmc
                                ? 'și scadența din OMC, factura găsită după număr'
                                : 'alocate în eTrip'}
                            .
                            {result.invoices_total > result.invoices.length &&
                                ` Se afișează cele mai recente ${result.invoices.length}.`}
                        </CardDescription>
                    </div>
                    {fromOmc && supplier.partner_id && (
                        <Button asChild size="sm" variant="outline">
                            <Link
                                href={primite({
                                    query: { partner_id: supplier.partner_id },
                                })}
                            >
                                <ExternalLink />
                                În Facturi primite
                            </Link>
                        </Button>
                    )}
                </CardHeader>
                <CardContent className="space-y-3">
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        size="sm"
                        value={filter}
                        onValueChange={(value) =>
                            value && onFilter(value as InvoiceFilter)
                        }
                        className="flex-wrap"
                    >
                        {(
                            [
                                ['all', 'Toate'],
                                ['open', 'Neachitate'],
                                ['overdue', 'Restante'],
                                ['correction', 'Corecții'],
                                ['draft', 'Nefinalizate'],
                            ] as [InvoiceFilter, string][]
                        ).map(([value, label]) => (
                            <ToggleGroupItem
                                key={value}
                                value={value}
                                disabled={counts[value] === 0}
                            >
                                {label}
                                <span className="text-xs text-muted-foreground">
                                    {counts[value]}
                                </span>
                            </ToggleGroupItem>
                        ))}
                    </ToggleGroup>

                    <TableShell
                        head={
                            <tr>
                                <th className="px-3 py-2">Factura</th>
                                <th className="px-3 py-2">Data</th>
                                <th className="px-3 py-2">Scadență</th>
                                <th className="px-3 py-2 text-right">
                                    Valoare
                                </th>
                                <th className="px-3 py-2 text-right">
                                    Rest de plată
                                </th>
                                <th className="px-3 py-2">Check-in acoperit</th>
                                <th
                                    className="px-3 py-2 text-right"
                                    title="Costul întreg al serviciilor legate de factură"
                                >
                                    Cost eTrip servicii
                                </th>
                                {fromOmc && (
                                    <th className="px-3 py-2">În OMC</th>
                                )}
                            </tr>
                        }
                    >
                        {invoices.length === 0 && (
                            <EmptyRow colSpan={fromOmc ? 8 : 7} />
                        )}
                        {invoices.map((invoice) => (
                            <InvoiceRow
                                key={invoice.id}
                                invoice={invoice}
                                fromOmc={fromOmc}
                                termDays={supplier.balance_due_days}
                            />
                        ))}
                    </TableShell>

                    <div className="flex flex-wrap gap-x-6 gap-y-1 text-sm">
                        {result.invoice_totals.map((total) => (
                            <span key={total.currency}>
                                <b>{total.currency}</b>: {total.invoices}{' '}
                                facturi · {fmt(total.amount)} facturat ·{' '}
                                {fmt(total.paid)} plătit ·{' '}
                                <span
                                    className={
                                        total.open > 0.01 ? 'font-semibold' : ''
                                    }
                                >
                                    {fmt(total.open)} rest
                                </span>{' '}
                                ({total.unpaid} neachitate
                                {total.unknown > 0 &&
                                    `, ${total.unknown} fără status`}
                                )
                            </span>
                        ))}
                    </div>
                    {counts.correction > 0 && (
                        <p className="flex items-start gap-1.5 text-xs text-muted-foreground">
                            <FileWarning className="mt-0.5 size-3.5 shrink-0" />
                            Facturile de corecție acoperă doar o parte din
                            serviciile legate, dar „Cost eTrip servicii” le
                            arată costul întreg, deci pe rândul lor diferența nu
                            spune nimic. Comparația de încredere este cea pe
                            serviciu, din tabelul de mai sus.
                        </p>
                    )}
                </CardContent>
            </Card>

            <PaymentsCard result={result} />

            <Card>
                <CardHeader>
                    <CardTitle>Cum se citește</CardTitle>
                </CardHeader>
                <CardContent className="space-y-2 text-sm text-muted-foreground">
                    <p>
                        <b className="text-foreground">Servicii:</b> dosar
                        confirmat, serviciu confirmat la client și neanulat la
                        furnizor; cost = brut + taxe − comision, în moneda
                        furnizorului, pe data de check-in. Anulările cu
                        penalizare intră ca tip separat și se facturează normal.
                    </p>
                    <p>
                        <b className="text-foreground">Facturat:</b> suma
                        liniilor de factură legate de fiecare serviciu, oricând
                        ar fi fost emisă factura. Nu se adună peste monede.
                    </p>
                    <p>
                        <b className="text-foreground">Limite:</b> costul din
                        eTrip nu include ajustările făcute direct în OMC, iar
                        plățile cu cardul fără factură înregistrată nu apar
                        nicăieri.
                    </p>
                </CardContent>
            </Card>
        </>
    );
}

function EmptyRow({ colSpan }: { colSpan: number }) {
    return (
        <tr>
            <td
                className="px-3 py-6 text-center text-muted-foreground"
                colSpan={colSpan}
            >
                Nimic în perioadă.
            </td>
        </tr>
    );
}

function InvoiceRow({
    invoice,
    fromOmc,
    termDays,
}: {
    invoice: ReconciliationInvoice;
    fromOmc: boolean;
    termDays: number | null;
}) {
    return (
        <tr className={cn(invoice.days_overdue > 0 && 'bg-destructive/5')}>
            <td className="px-3 py-2">
                <span className="font-medium">{invoice.number}</span>
                <span className="mt-0.5 flex flex-wrap gap-1">
                    {invoice.correction && (
                        <Badge variant="outline" className="text-xs">
                            corecție
                        </Badge>
                    )}
                    {!invoice.finalized && (
                        <Badge
                            variant="outline"
                            className="border-amber-500/50 text-xs text-amber-700 dark:text-amber-400"
                        >
                            nefinalizată
                        </Badge>
                    )}
                    {invoice.finalized && invoice.good_for_payment && (
                        <Badge variant="outline" className="text-xs">
                            bună de plată
                        </Badge>
                    )}
                </span>
            </td>
            <td className="px-3 py-2 whitespace-nowrap">{dmy(invoice.date)}</td>
            <DueCell invoice={invoice} termDays={termDays} />
            <td className="px-3 py-2 text-right whitespace-nowrap tabular-nums">
                {fmt(invoice.amount, 2)}{' '}
                <span className="text-xs text-muted-foreground">
                    {invoice.currency}
                </span>
            </td>
            <td className="px-3 py-2 text-right whitespace-nowrap tabular-nums">
                {invoice.open === null ? (
                    <span
                        className="text-muted-foreground"
                        title="Factura nu a fost găsită în OMC după număr"
                    >
                        ?
                    </span>
                ) : invoice.open > 0.01 ? (
                    <span className="font-semibold">
                        {fmt(invoice.open, 2)}
                    </span>
                ) : (
                    <span className="inline-flex items-center gap-1 text-emerald-700 dark:text-emerald-500">
                        <CircleCheck className="size-3.5" />
                        plătită
                    </span>
                )}
            </td>
            <td className="px-3 py-2 whitespace-nowrap">
                {invoice.checkin_from ? (
                    <>
                        {dmy(invoice.checkin_from)}
                        {invoice.checkin_to !== invoice.checkin_from &&
                            ` – ${dmy(invoice.checkin_to)}`}
                        <span className="block text-xs text-muted-foreground">
                            {invoice.bookings} dosare · {invoice.lines} linii
                        </span>
                    </>
                ) : (
                    <span className="text-muted-foreground">
                        fără servicii legate
                    </span>
                )}
            </td>
            <td
                className={cn(
                    'px-3 py-2 text-right whitespace-nowrap tabular-nums',
                    invoice.correction && 'text-muted-foreground italic',
                )}
            >
                {invoice.etrip_cost === null ? '–' : fmt(invoice.etrip_cost, 2)}
            </td>
            {fromOmc && (
                <td className="px-3 py-2">
                    {invoice.omc ? (
                        <span className="flex flex-col items-start gap-1">
                            <a
                                href={invoiceShow(invoice.omc.id).url}
                                target="_blank"
                                rel="noreferrer"
                                className="inline-flex items-center gap-1 text-xs hover:underline"
                            >
                                {invoice.omc.nr_doc}
                                <ExternalLink className="size-3" />
                            </a>
                            <WorkflowStatusBadge
                                status={invoice.omc.approval_status}
                                className="text-xs"
                            />
                        </span>
                    ) : (
                        <span className="text-xs text-amber-700 dark:text-amber-400">
                            negăsită
                        </span>
                    )}
                </td>
            )}
        </tr>
    );
}

function AlertsCard({ result }: { result: Reconciliation }) {
    if (result.alerts.length === 0) {
        return (
            <div className="flex items-center gap-2 rounded-xl border border-emerald-600/30 bg-emerald-600/5 p-3 text-sm">
                <VerdictIcon level="ok" />
                Nimic de semnalat: lunile încheiate sunt în prag, toate tipurile
                de serviciu sunt facturate, nicio factură restantă.
            </div>
        );
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <TriangleAlert className="size-4 text-amber-600 dark:text-amber-400" />
                    De verificat
                </CardTitle>
            </CardHeader>
            <CardContent>
                <ul className="grid gap-2 md:grid-cols-2">
                    {result.alerts.map((alert) => (
                        <li
                            key={alert.kind}
                            className="flex items-start gap-2 rounded-lg border border-sidebar-border/70 p-3 text-sm dark:border-sidebar-border"
                        >
                            <VerdictIcon level={alert.level} />
                            <span className="min-w-0">
                                <span
                                    className={cn(
                                        'font-medium',
                                        LEVEL_TEXT[alert.level],
                                    )}
                                >
                                    {ALERT_TITLES[alert.kind]}
                                    {alert.kind !== 'unlinked' &&
                                        ` (${alert.count})`}
                                </span>
                                <span className="block break-words text-muted-foreground">
                                    {alert.detail}
                                </span>
                            </span>
                        </li>
                    ))}
                </ul>
            </CardContent>
        </Card>
    );
}

function PaymentsCard({ result }: { result: Reconciliation }) {
    if (result.payments_source === 'omc') {
        const totals = result.omc?.totals ?? [];

        return (
            <Card>
                <CardHeader>
                    <CardTitle>În OMC</CardTitle>
                    <CardDescription>
                        Furnizorul este plătit din OMC Christian Tour: toate
                        facturile lui din OMC în aceeași perioadă, inclusiv cele
                        fără pereche în eTrip.
                    </CardDescription>
                </CardHeader>
                <CardContent className="text-sm">
                    {result.omc?.partner_id === null ? (
                        <p className="text-muted-foreground">
                            Furnizorul eTrip nu este legat de un partener OMC.
                            Leagă-l din pagina furnizorului, secțiunea eTrip, ca
                            plățile să poată fi citite.
                        </p>
                    ) : totals.length === 0 ? (
                        <p className="text-muted-foreground">
                            Nicio factură în OMC în perioadă.
                        </p>
                    ) : (
                        <div className="flex flex-wrap gap-x-6 gap-y-1">
                            {totals.map((total) => (
                                <span key={total.currency}>
                                    <b>{total.currency}</b>: {total.invoices}{' '}
                                    facturi · {fmt(total.amount)} ·{' '}
                                    {fmt(total.open)} rest de plată
                                </span>
                            ))}
                        </div>
                    )}
                </CardContent>
            </Card>
        );
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>Plăți către furnizor (eTrip)</CardTitle>
                <CardDescription>
                    Plățile emise în perioadă față de cât este alocat pe
                    facturile perioadei. Diferența e normală când o plată
                    dintr-un an acoperă facturi din altul.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <TableShell
                    head={
                        <tr>
                            <th className="px-3 py-2">Moneda</th>
                            <th className="px-3 py-2 text-right">Plăți</th>
                            <th className="px-3 py-2 text-right">
                                Plătit în perioadă
                            </th>
                            <th className="px-3 py-2 text-right">
                                Alocat pe facturile perioadei
                            </th>
                            <th className="px-3 py-2 text-right">Diferență</th>
                            <th className="px-3 py-2">Interval</th>
                        </tr>
                    }
                >
                    {result.payments.length === 0 && <EmptyRow colSpan={6} />}
                    {result.payments.map((row) => (
                        <tr key={row.currency}>
                            <td className="px-3 py-2 font-medium">
                                {row.currency}
                            </td>
                            <Num value={row.payments} />
                            <Num value={row.paid} />
                            <Num value={row.allocated} />
                            <td className="px-3 py-2 text-right whitespace-nowrap tabular-nums">
                                {Math.abs(row.difference) < 0.01 ? (
                                    <span className="text-muted-foreground">
                                        –
                                    </span>
                                ) : (
                                    <span
                                        title={
                                            row.difference > 0
                                                ? 'Alocat mai mult decât s-a plătit în perioadă: o parte vine din plăți anterioare perioadei.'
                                                : 'Plătit mai mult decât s-a alocat: o parte acoperă facturi din afara perioadei sau e încă nealocată.'
                                        }
                                    >
                                        {signed(row.difference)}
                                    </span>
                                )}
                            </td>
                            <td className="px-3 py-2 whitespace-nowrap text-muted-foreground">
                                {row.first
                                    ? `${dmy(row.first)} – ${dmy(row.last)}`
                                    : '–'}
                            </td>
                        </tr>
                    ))}
                </TableShell>
            </CardContent>
        </Card>
    );
}
