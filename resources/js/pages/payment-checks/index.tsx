import { Form, Head, Link } from '@inertiajs/react';
import { ExternalLink, RefreshCw, Search } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import EtripSupplierController from '@/actions/App/Http/Controllers/EtripSupplierController';
import PaymentCheckController from '@/actions/App/Http/Controllers/PaymentCheckController';
import DatePicker from '@/components/date-picker';
import EtripSupplierPicker from '@/components/etrip-supplier-picker';
import type { EtripSupplierOption } from '@/components/etrip-supplier-picker';
import {
    Tile,
    VerdictIcon,
    dmy,
    fmt,
} from '@/components/payment-checks/check-ui';
import SupplierReconciliationPanel from '@/components/payment-checks/supplier-reconciliation';
import SavePaymentRequest from '@/components/save-payment-request';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { show as partnerShow } from '@/routes/partners';
import { index as paymentChecksIndex } from '@/routes/payment-checks';
import type {
    CheckinBreakdownRow,
    CheckinCategory,
    CheckinCheck,
    CheckinLine,
    CheckView,
    ExpectedPayload,
    ExpectedSupplier,
    Props,
} from './types';

type ExpectedRow = ExpectedSupplier & {
    connection: string;
    base: string | null;
};

type ExpectedAll = Omit<ExpectedPayload, 'suppliers'> & {
    suppliers: ExpectedRow[];
};

const CURRENCIES = ['EUR', 'USD', 'RON', 'GBP'];

type BreakdownMode = 'hotel' | 'day' | 'product';

function today(): string {
    return new Date().toISOString().slice(0, 10);
}

function addDays(date: string, days: number): string {
    const d = new Date(`${date}T00:00:00`);
    d.setDate(d.getDate() + days);

    return d.toISOString().slice(0, 10);
}

function Bars({
    rows,
    mode,
}: {
    rows: CheckinBreakdownRow[];
    mode: BreakdownMode;
}) {
    if (rows.length === 0) {
        return (
            <p className="rounded-md bg-muted/50 p-3 text-sm text-muted-foreground">
                Nimic de afișat pentru această selecție.
            </p>
        );
    }

    const max = Math.max(1, ...rows.map((row) => Math.abs(row.cost)));
    const shown = rows.slice(0, 40);

    return (
        <div className="space-y-1.5">
            {shown.map((row, index) => {
                const name =
                    mode === 'day'
                        ? dmy(row.date ?? null)
                        : mode === 'product'
                          ? (row.label ?? String(row.product_type))
                          : (row.name ?? '—');

                return (
                    <div
                        key={`${name}-${row.currency}-${index}`}
                        className="grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)_auto] items-center gap-3 text-sm"
                    >
                        <span className="truncate" title={name}>
                            {name}{' '}
                            <span className="text-xs text-muted-foreground">
                                {row.bookings} dos.
                            </span>
                        </span>
                        <span className="h-2.5 overflow-hidden rounded-full bg-muted">
                            <span
                                className="block h-full rounded-full bg-chart-2"
                                style={{
                                    width: `${(100 * Math.abs(row.cost)) / max}%`,
                                }}
                            />
                        </span>
                        <span className="text-right whitespace-nowrap tabular-nums">
                            {fmt(row.cost)} {row.currency}
                        </span>
                    </div>
                );
            })}
            {rows.length > shown.length && (
                <p className="text-xs text-muted-foreground">
                    +{rows.length - shown.length} rânduri mai mici
                </p>
            )}
        </div>
    );
}

function LinesTable({ check }: { check: CheckinCheck }) {
    const [query, setQuery] = useState('');

    const rows = useMemo(() => {
        const term = query.trim().toLowerCase();

        if (!term) {
            return check.lines;
        }

        return check.lines.filter((line: CheckinLine) =>
            `${line.booking} ${line.lead ?? ''} ${line.service} ${line.room ?? ''}`
                .toLowerCase()
                .includes(term),
        );
    }, [check.lines, query]);

    const totals = useMemo(() => {
        const sums = new Map<string, number>();

        for (const line of rows) {
            sums.set(line.currency, (sums.get(line.currency) ?? 0) + line.cost);
        }

        return [...sums.entries()]
            .map(([currency, cost]) => `${fmt(cost, 2)} ${currency}`)
            .join(' + ');
    }, [rows]);

    const otherCount = check.lines.filter(
        (line) => line.category === 'other',
    ).length;

    return (
        <Card>
            <CardHeader>
                <CardTitle>Detaliu pe dosar</CardTitle>
                <CardDescription>
                    Un rând = un serviciu (cameră / transfer) din dosar.
                    {check.lines_total > check.lines.length &&
                        ` Afișate ${check.lines.length} din ${check.lines_total}.`}
                    {otherCount > 0 &&
                        ` ${otherCount} servicii de alt tip (zbor, excursii, asigurări) apar cu tipul de produs în loc de hotel.`}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">
                <div className="flex flex-wrap items-center gap-3">
                    <Input
                        placeholder="caută dosar, nume, hotel…"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        className="max-w-xs"
                    />
                    <span className="text-xs text-muted-foreground">
                        {rows.length !== check.lines.length
                            ? `${rows.length} din ${check.lines.length} servicii`
                            : `${check.lines.length} servicii`}
                    </span>
                </div>
                <div className="max-h-[560px] overflow-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <table className="w-full text-sm">
                        <thead className="sticky top-0 bg-muted/50 text-left text-xs text-muted-foreground uppercase backdrop-blur">
                            <tr>
                                <th className="px-3 py-2">Dosar</th>
                                <th className="px-3 py-2">Turist (lider)</th>
                                <th className="px-3 py-2">Hotel / serviciu</th>
                                <th className="px-3 py-2">Cameră</th>
                                <th className="px-3 py-2">Masă</th>
                                <th className="px-3 py-2">Check-in</th>
                                <th className="px-3 py-2 text-right">Nopți</th>
                                <th className="px-3 py-2 text-right">Pax</th>
                                <th className="px-3 py-2 text-right">Cost</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                            {rows.length === 0 && (
                                <tr>
                                    <td
                                        className="px-3 py-6 text-center text-muted-foreground"
                                        colSpan={9}
                                    >
                                        Niciun serviciu cu detaliu în acest
                                        interval.
                                    </td>
                                </tr>
                            )}
                            {rows.map((line) => (
                                <tr key={line.item}>
                                    <td className="px-3 py-2 font-medium tabular-nums">
                                        {line.booking}
                                    </td>
                                    <td className="px-3 py-2">
                                        {line.lead ?? ''}
                                    </td>
                                    <td className="px-3 py-2">
                                        {line.service}
                                        {line.category !== 'hotel' && (
                                            <Badge
                                                variant="outline"
                                                className="ml-2"
                                            >
                                                {line.product_label}
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="px-3 py-2 text-muted-foreground">
                                        {line.room ?? ''}
                                    </td>
                                    <td className="px-3 py-2 text-muted-foreground">
                                        {line.meal ?? ''}
                                    </td>
                                    <td className="px-3 py-2 whitespace-nowrap">
                                        {dmy(line.start_date)}
                                    </td>
                                    <td className="px-3 py-2 text-right tabular-nums">
                                        {line.nights ?? ''}
                                    </td>
                                    <td className="px-3 py-2 text-right tabular-nums">
                                        {line.pax}
                                    </td>
                                    <td className="px-3 py-2 text-right whitespace-nowrap tabular-nums">
                                        {fmt(line.cost, 2)} {line.currency}
                                    </td>
                                </tr>
                            ))}
                            {rows.length > 0 && (
                                <tr className="bg-muted/50 font-semibold">
                                    <td className="px-3 py-2" colSpan={8}>
                                        Total {rows.length} servicii
                                    </td>
                                    <td className="px-3 py-2 text-right whitespace-nowrap">
                                        {totals}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>
    );
}

function fetchJson<T>(url: string): Promise<T> {
    return fetch(url, { headers: { Accept: 'application/json' } }).then(
        async (res) => {
            if (!res.ok) {
                const body = (await res.json().catch(() => null)) as {
                    message?: string;
                } | null;

                throw new Error(body?.message ?? 'Cererea a eșuat.');
            }

            return (await res.json()) as T;
        },
    );
}

export default function PaymentChecksIndex({
    bases,
    company_id: partnersCompanyId,
    categories,
    windows,
    filters,
}: Props) {
    const [connection, setConnection] = useState<string | null>(
        filters.connection,
    );
    const [supplier, setSupplier] = useState<EtripSupplierOption | null>(null);
    const [supplierCode, setSupplierCode] = useState<string | null>(
        filters.supplier,
    );
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);
    const [category, setCategory] = useState<CheckinCategory>(filters.category);
    const [amount, setAmount] = useState(filters.amount ?? '');
    const [currency, setCurrency] = useState(filters.currency ?? 'EUR');

    const [check, setCheck] = useState<CheckinCheck | null>(null);
    const [checking, setChecking] = useState(
        Boolean(
            filters.supplier &&
            filters.connection !== null &&
            filters.view === 'checkin',
        ),
    );
    const [checkError, setCheckError] = useState<string | null>(null);
    const [mode, setMode] = useState<BreakdownMode>('hotel');
    const [view, setView] = useState<CheckView>(filters.view);

    const [expected, setExpected] = useState<ExpectedAll | null>(null);
    const [expectedDays, setExpectedDays] = useState(windows[0] ?? 2);
    const [expectedKey, setExpectedKey] = useState('');
    const [expectedError, setExpectedError] = useState<string | null>(null);

    const pickerBases = useMemo(
        () => bases.map((item) => ({ key: item.key, label: item.label })),
        [bases],
    );
    const baseKey = bases.map((item) => item.key).join(',');
    const suppliersSyncedAt = bases
        .map((item) => item.suppliers_synced_at)
        .filter((value): value is string => value !== null)
        .sort()[0];

    type CheckParams = {
        connection: string;
        supplier: string;
        from: string;
        to: string;
        category: CheckinCategory;
        amount: string;
        currency: string;
    };

    function checkUrl(params: CheckParams): string {
        const query: Record<string, string> = {
            connection: params.connection,
            supplier: params.supplier,
            from: params.from,
            to: params.to,
            category: params.category,
        };

        if (params.amount.trim()) {
            query.amount = params.amount.trim();
            query.currency = params.currency;
        }

        return PaymentCheckController.check({ query }).url;
    }

    function runCheck(params: CheckParams) {
        const url = checkUrl(params);

        setChecking(true);
        setCheckError(null);

        fetchJson<CheckinCheck>(url)
            .then(setCheck)
            .catch((err: Error) => setCheckError(err.message))
            .finally(() => setChecking(false));
    }

    /**
     * The expected requests of every eTrip base, merged and sorted by cost;
     * a base that fails is reported without hiding the others.
     */
    function loadExpected(refresh: boolean): Promise<ExpectedAll | null> {
        return Promise.allSettled(
            bases.map((item) =>
                fetchJson<ExpectedPayload>(
                    PaymentCheckController.expected({
                        query: {
                            connection: item.key,
                            days: String(expectedDays),
                            ...(refresh ? { refresh: '1' } : {}),
                        },
                    }).url,
                ).then((payload) => ({ base: item, payload })),
            ),
        ).then((results) => {
            const loaded = results.flatMap((result) =>
                result.status === 'fulfilled' ? [result.value] : [],
            );
            const failures = results.flatMap((result) =>
                result.status === 'rejected'
                    ? [(result.reason as Error).message]
                    : [],
            );

            if (loaded.length === 0) {
                throw new Error(failures[0] ?? 'Cererea a eșuat.');
            }

            setExpectedError(
                failures.length > 0
                    ? `O parte din bazele eTrip nu au răspuns: ${failures.join('; ')}`
                    : null,
            );

            const suppliers = loaded
                .flatMap(({ base, payload }) =>
                    payload.suppliers.map((row) => ({
                        ...row,
                        connection: base.key,
                        base: base.label,
                    })),
                )
                .sort((a, b) => Math.abs(b.cost) - Math.abs(a.cost));
            const first = loaded[0].payload;

            return {
                days: first.days,
                from: first.from,
                to: first.to,
                cached_at: loaded
                    .map(({ payload }) => payload.cached_at)
                    .sort()
                    .at(-1)!,
                suppliers,
            };
        });
    }

    function refreshExpected() {
        if (bases.length === 0) {
            return;
        }

        const key = `${baseKey}:${expectedDays}`;

        setExpectedKey('');
        setExpectedError(null);

        loadExpected(true)
            .then((payload) => setExpected(payload))
            .catch((err: Error) => setExpectedError(err.message))
            .finally(() => setExpectedKey(key));
    }

    useEffect(() => {
        // A deep link (e.g. from the supplier page) runs its check once on
        // mount; on the invoices tab, that tab runs its own.
        if (
            !filters.supplier ||
            filters.connection === null ||
            filters.view !== 'checkin'
        ) {
            return;
        }

        const url = checkUrl({
            connection: filters.connection,
            supplier: filters.supplier,
            from: filters.from,
            to: filters.to,
            category: filters.category,
            amount: filters.amount ?? '',
            currency: filters.currency ?? 'EUR',
        });

        let cancelled = false;

        fetchJson<CheckinCheck>(url)
            .then((data) => {
                if (!cancelled) {
                    setCheck(data);
                }
            })
            .catch((err: Error) => {
                if (!cancelled) {
                    setCheckError(err.message);
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setChecking(false);
                }
            });

        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        if (bases.length === 0) {
            return;
        }

        const key = `${baseKey}:${expectedDays}`;
        let cancelled = false;

        loadExpected(false)
            .then((payload) => {
                if (!cancelled) {
                    setExpected(payload);
                }
            })
            .catch((err: Error) => {
                if (!cancelled) {
                    setExpectedError(err.message);
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setExpectedKey(key);
                }
            });

        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [baseKey, expectedDays]);

    const expectedLoading =
        bases.length > 0 && expectedKey !== `${baseKey}:${expectedDays}`;

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (!supplierCode || connection === null) {
            setCheckError('Alege un furnizor eTrip.');

            return;
        }

        const [start, end] = to < from ? [to, from] : [from, to];

        runCheck({
            connection,
            supplier: supplierCode,
            from: start,
            to: end,
            category,
            amount,
            currency,
        });
    }

    function chooseSupplier(option: EtripSupplierOption | null) {
        setSupplier(option);
        setConnection(option?.connection ?? null);
        setSupplierCode(option?.code ?? null);

        if (option?.currency && !amount) {
            setCurrency(option.currency.toUpperCase());
        }
    }

    /** Keeps the tab in the address, so a reload or a shared link opens it. */
    function changeView(next: CheckView) {
        setView(next);

        const url = new URL(window.location.href);

        if (next === 'checkin') {
            url.searchParams.delete('view');
        } else {
            url.searchParams.set('view', next);
        }

        window.history.replaceState(window.history.state, '', url);
    }

    /** From the invoices tab: the services of a check-in range, all types. */
    function openCheckin(start: string, end: string) {
        if (!supplierCode || connection === null) {
            return;
        }

        changeView('checkin');
        setFrom(start);
        setTo(end);
        setCategory('all');
        setAmount('');
        runCheck({
            connection,
            supplier: supplierCode,
            from: start,
            to: end,
            category: 'all',
            amount: '',
            currency,
        });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function pickExpected(row: ExpectedRow) {
        const start = today();
        const end = addDays(start, expectedDays - 1);

        setConnection(row.connection);
        setSupplierCode(row.supplier_code);
        setFrom(start);
        setTo(end);
        setCategory('all');
        setAmount('');
        runCheck({
            connection: row.connection,
            supplier: row.supplier_code,
            from: start,
            to: end,
            category: 'all',
            amount: '',
            currency,
        });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    const mainTotal = check
        ? (check.totals.find((total) => total.currency === currency) ??
          check.totals[0] ??
          null)
        : null;

    const composition = check
        ? check.by_product
              .filter(
                  (row) =>
                      row.currency === (mainTotal?.currency ?? row.currency),
              )
              .slice(0, 3)
        : [];

    return (
        <>
            <Head
                title={
                    view === 'invoices'
                        ? 'Facturi furnizor vs. servicii'
                        : 'Verificare plăți pe check-in'
                }
            />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold">
                            Verificare plăți pe check-in
                        </h1>
                        <p className="max-w-3xl text-sm text-muted-foreground">
                            {view === 'invoices'
                                ? 'Facturile furnizorului din eTrip față de serviciile efectiv consumate: ce s-a facturat, ce lipsește și ce a rămas de plată.'
                                : 'Alege furnizorul și intervalul de check-in din cerere; pagina adună costul de furnizor al serviciilor confirmate din eTrip (net de comision, în moneda furnizorului) și îl compară cu suma cerută.'}
                        </p>
                    </div>
                    {bases.length > 0 && (
                        <Form
                            {...EtripSupplierController.syncAll.form()}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="outline"
                                    size="sm"
                                    disabled={processing}
                                    title={
                                        suppliersSyncedAt
                                            ? `Furnizori sincronizați la ${new Date(suppliersSyncedAt).toLocaleString('ro-RO')}`
                                            : 'Furnizorii eTrip nu au fost încă sincronizați'
                                    }
                                >
                                    <RefreshCw />
                                    Sincronizează furnizorii eTrip
                                </Button>
                            )}
                        </Form>
                    )}
                </div>

                {bases.length > 0 && (
                    <Tabs
                        value={view}
                        onValueChange={(value) =>
                            changeView(value as CheckView)
                        }
                    >
                        <TabsList>
                            <TabsTrigger value="checkin">
                                Cerere pe check-in
                            </TabsTrigger>
                            <TabsTrigger value="invoices">
                                Facturi vs. servicii
                            </TabsTrigger>
                        </TabsList>
                    </Tabs>
                )}

                {bases.length > 0 && (
                    <div className={cn(view !== 'invoices' && 'hidden')}>
                        <SupplierReconciliationPanel
                            active={view === 'invoices'}
                            bases={pickerBases}
                            connection={connection}
                            supplierCode={supplierCode}
                            onSupplierChange={chooseSupplier}
                            initialFrom={
                                filters.view === 'invoices'
                                    ? filters.period_from
                                    : null
                            }
                            initialTo={
                                filters.view === 'invoices'
                                    ? filters.period_to
                                    : null
                            }
                            onCheckin={openCheckin}
                        />
                    </div>
                )}

                <div
                    className={cn(
                        'flex flex-col gap-4',
                        view !== 'checkin' && 'hidden',
                    )}
                >
                    {bases.length === 0 ? (
                        <Card>
                            <CardContent className="py-8 text-center text-sm text-muted-foreground">
                                Nicio bază eTrip nu este definită în
                                configurația aplicației.
                            </CardContent>
                        </Card>
                    ) : (
                        <Card>
                            <CardHeader>
                                <CardTitle>Cererea de plată</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <form
                                    onSubmit={submit}
                                    className="grid gap-4 md:grid-cols-2 xl:grid-cols-6 xl:items-end"
                                >
                                    <div className="grid min-w-0 gap-1.5 xl:col-span-6">
                                        <Label htmlFor="check-supplier">
                                            Furnizor
                                        </Label>
                                        <EtripSupplierPicker
                                            id="check-supplier"
                                            bases={pickerBases}
                                            value={
                                                supplierCode &&
                                                connection !== null
                                                    ? {
                                                          connection,
                                                          code: supplierCode,
                                                      }
                                                    : null
                                            }
                                            onChange={chooseSupplier}
                                        />
                                    </div>
                                    <div className="grid min-w-0 gap-1.5 xl:col-span-1">
                                        <Label htmlFor="check-from">
                                            Check-in de la
                                        </Label>
                                        <DatePicker
                                            id="check-from"
                                            name="from"
                                            value={from}
                                            onChange={setFrom}
                                            required
                                        />
                                    </div>
                                    <div className="grid min-w-0 gap-1.5 xl:col-span-1">
                                        <Label htmlFor="check-to">
                                            până la (inclusiv)
                                        </Label>
                                        <DatePicker
                                            id="check-to"
                                            name="to"
                                            value={to}
                                            onChange={setTo}
                                            required
                                        />
                                    </div>
                                    <div className="grid min-w-0 gap-1.5 xl:col-span-1">
                                        <Label htmlFor="check-category">
                                            Categorie
                                        </Label>
                                        <Select
                                            value={category}
                                            onValueChange={(value) =>
                                                setCategory(
                                                    value as CheckinCategory,
                                                )
                                            }
                                        >
                                            <SelectTrigger
                                                id="check-category"
                                                className="w-full"
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {Object.entries(categories).map(
                                                    ([value, label]) => (
                                                        <SelectItem
                                                            key={value}
                                                            value={value}
                                                        >
                                                            {label}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="grid min-w-0 gap-1.5 xl:col-span-2">
                                        <Label htmlFor="check-amount">
                                            Suma cerută
                                        </Label>
                                        <div className="flex gap-2">
                                            <Input
                                                id="check-amount"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                inputMode="decimal"
                                                placeholder="ex. 140000"
                                                value={amount}
                                                onChange={(event) =>
                                                    setAmount(
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                            <Select
                                                value={currency}
                                                onValueChange={setCurrency}
                                            >
                                                <SelectTrigger
                                                    className="w-24"
                                                    aria-label="Monedă"
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {[
                                                        ...new Set([
                                                            ...CURRENCIES,
                                                            currency,
                                                        ]),
                                                    ].map((code) => (
                                                        <SelectItem
                                                            key={code}
                                                            value={code}
                                                        >
                                                            {code}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                    </div>
                                    <Button
                                        type="submit"
                                        disabled={
                                            checking || connection === null
                                        }
                                    >
                                        <Search />
                                        Verifică
                                    </Button>
                                </form>
                                {checkError && (
                                    <p className="mt-3 rounded-md bg-destructive/10 p-3 text-sm text-destructive">
                                        {checkError}
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                    )}

                    {checking && !check && (
                        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                            {[0, 1, 2, 3].map((index) => (
                                <Skeleton
                                    key={index}
                                    className="h-24 animate-pulse rounded-xl"
                                />
                            ))}
                        </div>
                    )}

                    {check && (
                        <>
                            <div className="-mb-1 flex justify-end">
                                <Button
                                    variant="link"
                                    size="sm"
                                    className="h-auto p-0"
                                    onClick={() => {
                                        changeView('invoices');
                                        window.scrollTo({
                                            top: 0,
                                            behavior: 'smooth',
                                        });
                                    }}
                                >
                                    Facturile acestui furnizor față de servicii
                                    →
                                </Button>
                            </div>
                            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                <Tile
                                    label="Valoare eTrip"
                                    value={
                                        check.totals.length > 0
                                            ? check.totals
                                                  .map(
                                                      (total) =>
                                                          `${fmt(total.cost)} ${total.currency}`,
                                                  )
                                                  .join(' + ')
                                            : '0'
                                    }
                                    detail={`${check.items} servicii${check.bookings ? ` · ${check.bookings} dosare` : ''} · check-in ${dmy(check.from)} – ${dmy(check.to)} · ${supplier?.name ?? check.supplier.name}`}
                                />
                                {check.requested ? (
                                    <>
                                        <Tile
                                            label="Suma cerută"
                                            value={`${fmt(check.requested.amount)} ${check.requested.currency}`}
                                            detail={
                                                check.requested.rate
                                                    ? `convertită la ${fmt(check.requested.compared_amount ?? 0, 2)} ${check.requested.compared_currency} · curs BNR ${dmy(check.requested.rate.date)}: 1 ${check.requested.rate.from} = ${check.requested.rate.value} ${check.requested.rate.to}`
                                                    : `comparată cu costul eTrip în ${check.requested.compared_currency}`
                                            }
                                        />
                                        <Tile
                                            label="Diferența (cerut − eTrip)"
                                            level={check.requested.level}
                                            value={
                                                check.requested.diff === null
                                                    ? '–'
                                                    : `${check.requested.diff > 0 ? '+' : ''}${fmt(check.requested.diff)} ${check.requested.compared_currency}`
                                            }
                                            detail={
                                                <span className="flex items-start gap-1.5">
                                                    <VerdictIcon
                                                        level={
                                                            check.requested
                                                                .level
                                                        }
                                                    />
                                                    <span>
                                                        {check.requested
                                                            .diff_pct !==
                                                            null &&
                                                            `${check.requested.diff_pct > 0 ? '+' : ''}${fmt(check.requested.diff_pct, 2)}% · `}
                                                        {
                                                            check.requested
                                                                .message
                                                        }
                                                    </span>
                                                </span>
                                            }
                                        />
                                    </>
                                ) : (
                                    <Tile
                                        label="Suma cerută"
                                        value={
                                            <span className="text-muted-foreground">
                                                –
                                            </span>
                                        }
                                        detail="Introdu suma din cerere pentru a vedea diferența."
                                    />
                                )}
                                <Tile
                                    label="Compoziție"
                                    value={
                                        <span className="text-base leading-snug font-semibold">
                                            {composition.length > 0
                                                ? composition.map((row) => (
                                                      <span
                                                          key={`${row.product_type}-${row.currency}`}
                                                          className="block"
                                                      >
                                                          {row.label}{' '}
                                                          {fmt(row.cost)}{' '}
                                                          {row.currency}
                                                      </span>
                                                  ))
                                                : '–'}
                                        </span>
                                    }
                                    detail={
                                        check.by_product.length > 3
                                            ? `+${check.by_product.length - 3} alte tipuri`
                                            : 'tipuri de produs în interval'
                                    }
                                />
                            </div>

                            <Card>
                                <CardHeader>
                                    <CardTitle>Ce intră în sumă</CardTitle>
                                    <CardDescription>
                                        Cost pe hotel / serviciu, pe zi de
                                        check-in sau pe tip de produs, cu
                                        numărul de dosare.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    <Tabs
                                        value={mode}
                                        onValueChange={(value) =>
                                            setMode(value as BreakdownMode)
                                        }
                                    >
                                        <TabsList>
                                            <TabsTrigger value="hotel">
                                                Pe hotel / serviciu
                                            </TabsTrigger>
                                            <TabsTrigger value="day">
                                                Pe zi de check-in
                                            </TabsTrigger>
                                            <TabsTrigger value="product">
                                                Pe tip de produs
                                            </TabsTrigger>
                                        </TabsList>
                                    </Tabs>
                                    <Bars
                                        mode={mode}
                                        rows={
                                            mode === 'hotel'
                                                ? check.by_hotel
                                                : mode === 'day'
                                                  ? check.by_day
                                                  : check.by_product
                                        }
                                    />
                                </CardContent>
                            </Card>

                            {check.requested && partnersCompanyId !== null && (
                                <SavePaymentRequest
                                    title="Salvează verificarea în registru"
                                    description="Cererea rămâne în registru cu cifrele eTrip din acest moment, ca dovadă pentru aprobare."
                                    payload={{
                                        company_id: partnersCompanyId,
                                        kind: 'checkin',
                                        supplier_name:
                                            supplier?.name ??
                                            check.supplier.name,
                                        etrip_supplier_id: check.supplier.id,
                                        partner_id:
                                            supplier?.partner_id ?? null,
                                        requested_amount:
                                            check.requested.amount,
                                        requested_currency:
                                            check.requested.currency,
                                        checkin_from: check.from,
                                        checkin_to: check.to,
                                        category: check.category,
                                        expected_amount: check.requested.etrip,
                                        expected_currency:
                                            check.requested.compared_currency,
                                        difference: check.requested.diff,
                                        difference_pct:
                                            check.requested.diff_pct,
                                        level: check.requested.level,
                                        verdict:
                                            check.requested.diff === null
                                                ? 'unconverted'
                                                : check.requested.level,
                                        snapshot: {
                                            totals: check.totals,
                                            items: check.items,
                                            bookings: check.bookings,
                                            compared_amount:
                                                check.requested.compared_amount,
                                            rate: check.requested.rate,
                                            message: check.requested.message,
                                        },
                                    }}
                                />
                            )}

                            <LinesTable check={check} />
                        </>
                    )}

                    {bases.length > 0 && (
                        <Card>
                            <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3 space-y-0">
                                <div>
                                    <CardTitle>Cereri de așteptat</CardTitle>
                                    <CardDescription>
                                        Furnizorii cu cele mai mari costuri de
                                        check-in în următoarele zile (toate
                                        serviciile, moneda furnizorului).
                                        {expected?.cached_at &&
                                            ` Calculat la ${new Date(expected.cached_at).toLocaleString('ro-RO')}.`}
                                    </CardDescription>
                                </div>
                                <div className="flex items-center gap-2">
                                    <Tabs
                                        value={String(expectedDays)}
                                        onValueChange={(value) =>
                                            setExpectedDays(Number(value))
                                        }
                                    >
                                        <TabsList>
                                            {windows.map((days) => (
                                                <TabsTrigger
                                                    key={days}
                                                    value={String(days)}
                                                >
                                                    {days === 2
                                                        ? 'Azi + mâine'
                                                        : `${days} zile`}
                                                </TabsTrigger>
                                            ))}
                                        </TabsList>
                                    </Tabs>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={refreshExpected}
                                        disabled={expectedLoading}
                                        title="Recalculează din eTrip"
                                    >
                                        <RefreshCw
                                            className={
                                                expectedLoading
                                                    ? 'animate-spin'
                                                    : ''
                                            }
                                        />
                                    </Button>
                                </div>
                            </CardHeader>
                            <CardContent>
                                {expectedError && (
                                    <p className="mb-3 rounded-md bg-destructive/10 p-3 text-sm text-destructive">
                                        {expectedError}
                                    </p>
                                )}
                                {expectedLoading && !expected ? (
                                    <Skeleton className="h-32 animate-pulse rounded-xl" />
                                ) : (
                                    <div className="max-h-[420px] overflow-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                                        <table className="w-full text-sm">
                                            <thead className="sticky top-0 bg-muted/50 text-left text-xs text-muted-foreground uppercase backdrop-blur">
                                                <tr>
                                                    <th className="px-3 py-2">
                                                        Furnizor
                                                    </th>
                                                    <th className="px-3 py-2 text-right">
                                                        Dosare
                                                    </th>
                                                    <th className="px-3 py-2 text-right">
                                                        Cost
                                                    </th>
                                                    <th className="px-3 py-2 text-right">
                                                        Acțiuni
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                                                {(expected?.suppliers ?? [])
                                                    .length === 0 && (
                                                    <tr>
                                                        <td
                                                            className="px-3 py-6 text-center text-muted-foreground"
                                                            colSpan={4}
                                                        >
                                                            Nimic în interval.
                                                        </td>
                                                    </tr>
                                                )}
                                                {(
                                                    expected?.suppliers ?? []
                                                ).map((row) => (
                                                    <tr
                                                        key={`${row.connection}-${row.supplier_code}-${row.currency}`}
                                                    >
                                                        <td className="px-3 py-2">
                                                            {row.supplier_name ??
                                                                row.supplier_code}
                                                            <span className="ml-1 font-mono text-xs text-muted-foreground">
                                                                {
                                                                    row.supplier_code
                                                                }
                                                            </span>
                                                            {bases.length > 1 &&
                                                                row.base && (
                                                                    <Badge
                                                                        variant="outline"
                                                                        className="ml-2"
                                                                    >
                                                                        {
                                                                            row.base
                                                                        }
                                                                    </Badge>
                                                                )}
                                                        </td>
                                                        <td className="px-3 py-2 text-right tabular-nums">
                                                            {row.bookings}
                                                        </td>
                                                        <td className="px-3 py-2 text-right whitespace-nowrap tabular-nums">
                                                            {fmt(row.cost)}{' '}
                                                            {row.currency ?? ''}
                                                        </td>
                                                        <td className="px-3 py-2">
                                                            <div className="flex items-center justify-end gap-1">
                                                                {row.partner_id && (
                                                                    <Button
                                                                        asChild
                                                                        size="sm"
                                                                        variant="ghost"
                                                                        title="Deschide furnizorul"
                                                                    >
                                                                        <Link
                                                                            href={partnerShow(
                                                                                row.partner_id,
                                                                            )}
                                                                        >
                                                                            <ExternalLink />
                                                                        </Link>
                                                                    </Button>
                                                                )}
                                                                <Button
                                                                    size="sm"
                                                                    variant="outline"
                                                                    onClick={() =>
                                                                        pickExpected(
                                                                            row,
                                                                        )
                                                                    }
                                                                >
                                                                    verifică
                                                                </Button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    )}

                    <Card>
                        <CardHeader>
                            <CardTitle>Cum se citește</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2 text-sm text-muted-foreground">
                            <p>
                                <b className="text-foreground">Sursa:</b> eTrip,
                                live la fiecare verificare — dosare cu status
                                confirmat, servicii confirmate la client și
                                neanulate la furnizor; cost = brut + taxe −
                                comision furnizor, în moneda furnizorului,
                                grupat pe data de check-in. Serviciile din
                                pachete sunt luate pe componente, deci un dosar
                                poate apărea cu mai multe rânduri.
                            </p>
                            <p>
                                <b className="text-foreground">Limite:</b> eTrip
                                nu înregistrează plățile către furnizori, iar
                                facturile furnizorilor din grup merg în altă
                                bază OMC, așa că „deja plătit” se verifică pe
                                pagina furnizorului, din facturile sincronizate.
                            </p>
                            <p>
                                <b className="text-foreground">
                                    Diferențe tipice:
                                </b>{' '}
                                dosare create sau modificate după cererea
                                furnizorului, penalizări de anulare, early
                                check-in / late check-out, taxe locale facturate
                                separat, curs de schimb când furnizorul
                                facturează în altă monedă.
                            </p>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

PaymentChecksIndex.layout = (page: React.ReactNode) => (
    <AppLayout
        breadcrumbs={[
            { title: 'Verificare plăți', href: paymentChecksIndex() },
        ]}
    >
        {page}
    </AppLayout>
);
