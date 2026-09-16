import { Link } from '@inertiajs/react';
import { CircleAlert, CircleCheck, CircleX, Search } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    ReferenceLine,
    XAxis,
    YAxis,
} from 'recharts';
import PartnerController from '@/actions/App/Http/Controllers/PartnerController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
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
import { show as invoiceShow } from '@/routes/invoices';
import type {
    PaymentCheck,
    PaymentCheckInvoice,
    PaymentCheckLevel,
} from './types';

const FALLBACK_CURRENCIES = ['RON', 'EUR', 'USD', 'GBP'];

const LEVEL_BORDER: Record<PaymentCheckLevel, string> = {
    ok: 'border-t-emerald-600',
    warn: 'border-t-amber-500',
    crit: 'border-t-destructive',
};

const LEVEL_TEXT: Record<PaymentCheckLevel, string> = {
    ok: 'text-emerald-700 dark:text-emerald-500',
    warn: 'text-amber-600 dark:text-amber-400',
    crit: 'text-destructive',
};

const chartConfig = {
    total_lei: { label: 'Facturi (lei)', color: 'var(--chart-2)' },
} satisfies ChartConfig;

function formatAmount(value: number, currency?: string | null): string {
    const formatted = new Intl.NumberFormat('ro-RO', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(value);

    return currency ? `${formatted} ${currency}` : formatted;
}

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const [year, month, day] = value.split('-');

    return `${day}.${month}.${year}`;
}

function DuePill({ days }: { days: number | null }) {
    if (days === null) {
        return null;
    }

    if (days < 0) {
        return (
            <Badge variant="destructive">
                {Math.abs(days)} {Math.abs(days) === 1 ? 'zi' : 'zile'}{' '}
                întârziere
            </Badge>
        );
    }

    if (days === 0) {
        return <Badge variant="secondary">azi</Badge>;
    }

    return (
        <Badge
            variant={days <= 7 ? 'secondary' : 'outline'}
            className={days <= 7 ? LEVEL_TEXT.warn : undefined}
        >
            în {days} {days === 1 ? 'zi' : 'zile'}
        </Badge>
    );
}

function VerdictIcon({ level }: { level: PaymentCheckLevel }) {
    const className = `size-4 shrink-0 ${LEVEL_TEXT[level]}`;

    if (level === 'ok') {
        return <CircleCheck className={className} />;
    }

    if (level === 'warn') {
        return <CircleAlert className={className} />;
    }

    return <CircleX className={className} />;
}

function Tile({
    label,
    value,
    detail,
    level,
}: {
    label: string;
    value: React.ReactNode;
    detail?: React.ReactNode;
    level?: PaymentCheckLevel | null;
}) {
    return (
        <div
            className={`flex flex-col gap-1 rounded-xl border border-t-[3px] border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border ${
                level ? LEVEL_BORDER[level] : 'border-t-foreground'
            }`}
        >
            <span className="text-xs tracking-wide text-muted-foreground uppercase">
                {label}
            </span>
            <span
                className={`font-heading text-2xl leading-tight font-bold ${
                    level ? LEVEL_TEXT[level] : ''
                }`}
            >
                {value}
            </span>
            {detail && (
                <span className="text-sm text-muted-foreground">{detail}</span>
            )}
        </div>
    );
}

function OpenInvoicesTable({
    invoices,
    totals,
}: {
    invoices: PaymentCheckInvoice[];
    totals: PaymentCheck['open_totals'];
}) {
    if (invoices.length === 0) {
        return (
            <p className="rounded-md bg-muted/50 p-3 text-sm text-muted-foreground">
                Nicio factură neachitată în ERP pentru acest furnizor. Dacă
                există o cerere de plată, factura nu a fost încă înregistrată
                sau a fost deja plătită.
            </p>
        );
    }

    return (
        <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-left text-xs text-muted-foreground uppercase">
                    <tr>
                        <th className="px-3 py-2">Data</th>
                        <th className="px-3 py-2">Număr</th>
                        <th className="px-3 py-2">Scadență</th>
                        <th className="px-3 py-2 text-right">Valoare</th>
                        <th className="px-3 py-2 text-right">Plătit</th>
                        <th className="px-3 py-2 text-right">Rest</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                    {invoices.map((invoice) => (
                        <tr
                            key={invoice.id}
                            className={
                                invoice.days_to_due !== null &&
                                invoice.days_to_due < 0
                                    ? 'bg-destructive/5'
                                    : undefined
                            }
                        >
                            <td className="px-3 py-2 whitespace-nowrap text-muted-foreground">
                                {formatDate(invoice.data_doc)}
                            </td>
                            <td className="px-3 py-2 font-medium">
                                <Link
                                    href={invoiceShow(invoice.id)}
                                    className="hover:underline"
                                >
                                    {invoice.nr_doc}
                                </Link>
                            </td>
                            <td className="px-3 py-2 whitespace-nowrap">
                                <span className="mr-2 text-muted-foreground">
                                    {formatDate(invoice.data_scadenta)}
                                </span>
                                <DuePill days={invoice.days_to_due} />
                            </td>
                            <td className="px-3 py-2 text-right whitespace-nowrap">
                                {formatAmount(invoice.val_mon, invoice.moneda)}
                            </td>
                            <td className="px-3 py-2 text-right whitespace-nowrap text-muted-foreground">
                                {invoice.val_mon_paid || invoice.val_mon_storno
                                    ? formatAmount(
                                          invoice.val_mon_paid +
                                              invoice.val_mon_storno,
                                          invoice.moneda,
                                      )
                                    : '—'}
                            </td>
                            <td className="px-3 py-2 text-right font-semibold whitespace-nowrap">
                                {formatAmount(invoice.rest, invoice.moneda)}
                            </td>
                        </tr>
                    ))}
                    <tr className="bg-muted/50 font-semibold">
                        <td className="px-3 py-2" colSpan={5}>
                            Total rest
                        </td>
                        <td className="px-3 py-2 text-right whitespace-nowrap">
                            {totals
                                .map((total) =>
                                    formatAmount(total.rest, total.moneda),
                                )
                                .join(' + ')}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    );
}

function PatternChart({ check }: { check: PaymentCheck }) {
    const hasData = check.pattern.some((month) => month.count > 0);

    if (!hasData) {
        return (
            <p className="rounded-md bg-muted/50 p-3 text-sm text-muted-foreground">
                Nicio factură în ultimele 24 de luni.
            </p>
        );
    }

    return (
        <ChartContainer config={chartConfig} className="h-44 w-full">
            <BarChart data={check.pattern} margin={{ left: 8, right: 8 }}>
                <CartesianGrid vertical={false} strokeDasharray="3 3" />
                <XAxis
                    dataKey="label"
                    tickLine={false}
                    axisLine={false}
                    interval={3}
                    fontSize={11}
                />
                <YAxis
                    tickLine={false}
                    axisLine={false}
                    width={56}
                    fontSize={11}
                    tickFormatter={(value: number) =>
                        new Intl.NumberFormat('ro-RO', {
                            notation: 'compact',
                        }).format(value)
                    }
                />
                <ChartTooltip
                    content={
                        <ChartTooltipContent
                            formatter={(value) => [
                                formatAmount(Number(value), 'lei'),
                                ' facturi',
                            ]}
                        />
                    }
                />
                <ReferenceLine
                    y={check.average_month_lei}
                    stroke="var(--chart-1)"
                    strokeDasharray="4 4"
                />
                <Bar
                    dataKey="total_lei"
                    fill="var(--color-total_lei)"
                    radius={[3, 3, 0, 0]}
                />
            </BarChart>
        </ChartContainer>
    );
}

function fetchCheck(
    partnerId: number,
    query: Record<string, string>,
): Promise<PaymentCheck> {
    return fetch(PartnerController.paymentCheck(partnerId, { query }).url, {
        headers: { Accept: 'application/json' },
    }).then(async (res) => {
        if (!res.ok) {
            throw new Error('Verificarea nu a putut fi încărcată.');
        }

        return (await res.json()) as PaymentCheck;
    });
}

export default function PaymentCheckPanel({
    partnerId,
}: {
    partnerId: number;
}) {
    const [amount, setAmount] = useState('');
    const [currency, setCurrency] = useState('');
    const [check, setCheck] = useState<PaymentCheck | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    const currencies = useMemo(() => {
        const known = check?.currencies ?? [];

        return known.length > 0 ? known : FALLBACK_CURRENCIES;
    }, [check]);

    const selectedCurrency = currency || currencies[0];

    useEffect(() => {
        let cancelled = false;

        fetchCheck(partnerId, {})
            .then((data) => {
                if (!cancelled) {
                    setCheck(data);
                }
            })
            .catch((err: Error) => {
                if (!cancelled) {
                    setError(err.message);
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [partnerId]);

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        const requested = amount.trim();

        if (!requested) {
            return;
        }

        setLoading(true);
        setError(null);

        fetchCheck(partnerId, { amount: requested, currency: selectedCurrency })
            .then(setCheck)
            .catch((err: Error) => setError(err.message))
            .finally(() => setLoading(false));
    }

    const openLevel: PaymentCheckLevel =
        !check || check.open.length === 0
            ? 'ok'
            : check.first_due?.overdue
              ? 'crit'
              : 'warn';

    return (
        <Card>
            <CardHeader>
                <CardTitle>Verificare cerere de plată</CardTitle>
                <CardDescription>
                    Facturile neachitate din ERP, ultimele 24 de luni și tiparul
                    lunar, ca să vezi dacă suma cerută este o factură
                    înregistrată și dacă se încadrează în tipar.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                <form
                    onSubmit={submit}
                    className="flex flex-wrap items-end gap-3"
                >
                    <div className="grid gap-1.5">
                        <Label htmlFor="payment-check-amount">
                            Suma cerută
                        </Label>
                        <Input
                            id="payment-check-amount"
                            type="number"
                            step="0.01"
                            min="0"
                            inputMode="decimal"
                            placeholder="ex. 20431.86"
                            value={amount}
                            onChange={(event) => setAmount(event.target.value)}
                            className="w-44"
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="payment-check-currency">Monedă</Label>
                        <Select
                            value={selectedCurrency}
                            onValueChange={setCurrency}
                        >
                            <SelectTrigger
                                id="payment-check-currency"
                                className="w-28"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {currencies.map((code) => (
                                    <SelectItem key={code} value={code}>
                                        {code}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <Button type="submit" disabled={loading || !amount}>
                        <Search />
                        Verifică
                    </Button>
                </form>

                {error && (
                    <p className="rounded-md bg-destructive/10 p-3 text-sm text-destructive">
                        {error}
                    </p>
                )}

                {loading && !check ? (
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        {[0, 1, 2, 3].map((index) => (
                            <Skeleton
                                key={index}
                                className="h-24 animate-pulse rounded-xl"
                            />
                        ))}
                    </div>
                ) : null}

                {check && (
                    <>
                        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                            <Tile
                                label="Rest de plată în ERP"
                                level={openLevel}
                                value={
                                    check.open_totals.length > 0
                                        ? check.open_totals
                                              .map((total) =>
                                                  formatAmount(
                                                      total.rest,
                                                      total.moneda,
                                                  ),
                                              )
                                              .join(' + ')
                                        : '0'
                                }
                                detail={
                                    <>
                                        {check.open.length} facturi neachitate
                                        {check.first_due &&
                                            ` · prima scadență ${formatDate(check.first_due.date)}${
                                                check.first_due.overdue
                                                    ? ' (depășită)'
                                                    : ''
                                            }`}
                                    </>
                                }
                            />
                            {check.requested ? (
                                <Tile
                                    label="Suma cerută"
                                    level={check.requested.level}
                                    value={formatAmount(
                                        check.requested.amount,
                                        check.requested.currency,
                                    )}
                                    detail={
                                        <span className="flex items-start gap-1.5">
                                            <VerdictIcon
                                                level={check.requested.level}
                                            />
                                            <span>
                                                {check.requested.message}
                                                {check.requested.invoice && (
                                                    <>
                                                        {' '}
                                                        <Link
                                                            href={invoiceShow(
                                                                check.requested
                                                                    .invoice.id,
                                                            )}
                                                            className="underline underline-offset-2"
                                                        >
                                                            Deschide factura
                                                        </Link>
                                                    </>
                                                )}
                                            </span>
                                        </span>
                                    }
                                />
                            ) : (
                                <Tile
                                    label="Suma cerută"
                                    value={
                                        <span className="text-muted-foreground">
                                            –
                                        </span>
                                    }
                                    detail="Introdu suma din cerere pentru a vedea dacă se regăsește în ERP."
                                />
                            )}
                            <Tile
                                label="Ultima factură"
                                level={check.last_invoice?.level ?? null}
                                value={
                                    check.last_invoice
                                        ? formatAmount(
                                              check.last_invoice.val_mon,
                                              check.last_invoice.moneda,
                                          )
                                        : '–'
                                }
                                detail={
                                    check.last_invoice
                                        ? `${formatDate(check.last_invoice.data_doc)} · nr. ${check.last_invoice.nr_doc}${
                                              check.last_invoice
                                                  .deviation_pct !== null
                                                  ? ` · ${check.last_invoice.deviation_pct > 0 ? '+' : ''}${check.last_invoice.deviation_pct}% față de factura medie (${formatAmount(check.average_invoice_lei, 'lei')})`
                                                  : ''
                                          }`
                                        : 'Nicio factură în ultimele 24 de luni.'
                                }
                            />
                            <Tile
                                label="Media lunară (12 luni)"
                                value={formatAmount(
                                    check.average_month_lei,
                                    'lei',
                                )}
                                detail={`${check.invoices_12m} facturi în 12 luni`}
                            />
                        </div>

                        <div className="grid gap-4 lg:grid-cols-5">
                            <div className="space-y-2 lg:col-span-3">
                                <h3 className="text-sm font-semibold">
                                    Facturi neachitate
                                </h3>
                                <OpenInvoicesTable
                                    invoices={check.open}
                                    totals={check.open_totals}
                                />
                            </div>
                            <div className="space-y-2 lg:col-span-2">
                                <h3 className="text-sm font-semibold">
                                    Tipar lunar
                                </h3>
                                <p className="text-xs text-muted-foreground">
                                    Valoarea facturilor pe lună, în lei, cu
                                    media ultimelor 12 luni.
                                </p>
                                <PatternChart check={check} />
                            </div>
                        </div>
                    </>
                )}
            </CardContent>
        </Card>
    );
}
