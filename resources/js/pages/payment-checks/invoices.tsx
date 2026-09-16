import { Head, Link } from '@inertiajs/react';
import { RefreshCw, Search } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import InvoiceCheckController from '@/actions/App/Http/Controllers/InvoiceCheckController';
import PaymentCheckResult, {
    DuePill,
    FALLBACK_CURRENCIES,
    PaymentCheckSkeleton,
    fetchJson,
    formatAmount,
    formatDate,
} from '@/components/invoice-payment-check';
import OmcSupplierPicker from '@/components/omc-supplier-picker';
import type { OmcSupplierOption } from '@/components/omc-supplier-picker';
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
import AppLayout from '@/layouts/app-layout';
import { index as databaseStatusIndex } from '@/routes/database-status';
import { index as paymentChecksIndex } from '@/routes/payment-checks';
import { index as invoiceChecksIndex } from '@/routes/payment-checks/invoices';
import type { PaymentCheck } from '@/types/payment-check';

type OpenSupplier = {
    name: string;
    cui: string | null;
    invoices: number;
    first_due: string | null;
    overdue: boolean;
    rest: { moneda: string; rest: number }[];
    rest_lei: number;
};

type OpenPayload = {
    since: string;
    suppliers: OpenSupplier[];
};

type Props = {
    company: { id: number; name: string } | null;
    database: string;
    filters: {
        supplier: string | null;
        amount: string | null;
        currency: string | null;
    };
};

function daysUntil(date: string): number {
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    return Math.round(
        (new Date(`${date}T00:00:00`).getTime() - today.getTime()) / 86_400_000,
    );
}

function optionFor(name: string): OmcSupplierOption {
    return {
        name,
        cui: null,
        country: null,
        city: null,
        invoices: null,
        last_invoice: null,
    };
}

function fetchCheckByName(
    query: Record<string, string>,
): Promise<PaymentCheck> {
    return fetchJson<PaymentCheck>(InvoiceCheckController.check({ query }).url);
}

function OpenSuppliersTable({
    suppliers,
    onPick,
}: {
    suppliers: OpenSupplier[];
    onPick: (supplier: OpenSupplier) => void;
}) {
    if (suppliers.length === 0) {
        return (
            <p className="rounded-md bg-muted/50 p-3 text-sm text-muted-foreground">
                Nicio factură de furnizor neachitată în OMC.
            </p>
        );
    }

    return (
        <div className="max-h-[32rem] overflow-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
            <table className="w-full text-sm">
                <thead className="sticky top-0 bg-muted text-left text-xs text-muted-foreground uppercase">
                    <tr>
                        <th className="px-3 py-2">Furnizor</th>
                        <th className="px-3 py-2 text-right">Facturi</th>
                        <th className="px-3 py-2">Prima scadență</th>
                        <th className="px-3 py-2 text-right">Rest de plată</th>
                        <th className="px-3 py-2 text-right">Echiv. lei</th>
                        <th className="px-3 py-2" />
                    </tr>
                </thead>
                <tbody className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                    {suppliers.map((supplier) => (
                        <tr
                            key={supplier.name}
                            className={
                                supplier.overdue
                                    ? 'bg-destructive/5'
                                    : undefined
                            }
                        >
                            <td className="px-3 py-2 font-medium">
                                {supplier.name}
                                {supplier.cui && (
                                    <span className="ml-2 font-mono text-xs font-normal text-muted-foreground">
                                        {supplier.cui}
                                    </span>
                                )}
                            </td>
                            <td className="px-3 py-2 text-right">
                                {supplier.invoices}
                            </td>
                            <td className="px-3 py-2 whitespace-nowrap">
                                <span className="mr-2 text-muted-foreground">
                                    {formatDate(supplier.first_due)}
                                </span>
                                {supplier.first_due && (
                                    <DuePill
                                        days={daysUntil(supplier.first_due)}
                                    />
                                )}
                            </td>
                            <td className="px-3 py-2 text-right font-semibold whitespace-nowrap">
                                {supplier.rest
                                    .map((total) =>
                                        formatAmount(total.rest, total.moneda),
                                    )
                                    .join(' + ')}
                            </td>
                            <td className="px-3 py-2 text-right whitespace-nowrap text-muted-foreground">
                                {formatAmount(supplier.rest_lei)}
                            </td>
                            <td className="px-3 py-2 text-right">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => onPick(supplier)}
                                >
                                    Verifică
                                </Button>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export default function InvoiceChecksIndex({
    company,
    database,
    filters,
}: Props) {
    const [supplier, setSupplier] = useState<OmcSupplierOption | null>(
        filters.supplier ? optionFor(filters.supplier) : null,
    );
    const [amount, setAmount] = useState(filters.amount ?? '');
    const [currency, setCurrency] = useState(filters.currency ?? '');
    const [check, setCheck] = useState<PaymentCheck | null>(null);
    const [loading, setLoading] = useState(filters.supplier !== null);
    const [error, setError] = useState<string | null>(null);

    const [open, setOpen] = useState<OpenPayload | null>(null);
    const [openKey, setOpenKey] = useState(0);
    const [openError, setOpenError] = useState<string | null>(null);

    const currencies = useMemo(() => {
        const known = check?.currencies ?? [];

        return known.length > 0 ? known : FALLBACK_CURRENCIES;
    }, [check]);

    const selectedCurrency = currency || currencies[0];

    useEffect(() => {
        if (!filters.supplier) {
            return;
        }

        let cancelled = false;
        const query: Record<string, string> = { supplier: filters.supplier };

        if (filters.amount) {
            query.amount = filters.amount;

            if (filters.currency) {
                query.currency = filters.currency;
            }
        }

        fetchCheckByName(query)
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
    }, [filters.supplier, filters.amount, filters.currency]);

    useEffect(() => {
        let cancelled = false;

        fetchJson<OpenPayload>(InvoiceCheckController.open().url)
            .then((data) => {
                if (!cancelled) {
                    setOpen(data);
                    setOpenError(null);
                }
            })
            .catch((err: Error) => {
                if (!cancelled) {
                    setOpenError(err.message);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [openKey]);

    function run(
        target: OmcSupplierOption,
        requested: string,
        requestedCurrency: string,
    ) {
        const query: Record<string, string> = { supplier: target.name };

        if (requested.trim()) {
            query.amount = requested.trim();
            query.currency = requestedCurrency;
        }

        setLoading(true);
        setError(null);

        fetchCheckByName(query)
            .then(setCheck)
            .catch((err: Error) => setError(err.message))
            .finally(() => setLoading(false));
    }

    function choose(option: OmcSupplierOption | null) {
        setSupplier(option);
        setCheck(null);
        setError(null);

        if (option) {
            run(option, amount, selectedCurrency);
        }
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (supplier) {
            run(supplier, amount, selectedCurrency);
        }
    }

    return (
        <>
            <Head title="Verificare facturi furnizori (OMC)" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold">
                            Verificare facturi furnizori (OMC)
                        </h1>
                        <p className="max-w-3xl text-sm text-muted-foreground">
                            Pentru furnizorii care nu depind de check-in
                            (marketing, telecom, chirii, servicii), dar și
                            pentru orice alt furnizor cu facturi în OMC:
                            facturile neachitate, ultimele 24 de luni și tiparul
                            lunar, ca să vezi dacă suma cerută este o factură
                            înregistrată și dacă se încadrează în tipar.
                        </p>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Date live din OMC ·{' '}
                        <Link
                            href={databaseStatusIndex()}
                            className="underline underline-offset-2"
                            title="Stare baze de date"
                        >
                            {database}
                        </Link>
                    </p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Factura de plată</CardTitle>
                        <CardDescription>
                            Scrie furnizorul din cerere; facturile lui
                            neachitate apar imediat, iar cu suma cerută vezi
                            dacă ea corespunde unei facturi înregistrate.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <form
                            onSubmit={submit}
                            className="grid gap-4 md:grid-cols-2 xl:grid-cols-6 xl:items-end"
                        >
                            <div className="grid min-w-0 gap-1.5 md:col-span-2 xl:col-span-3">
                                <Label htmlFor="invoice-check-supplier">
                                    Furnizor (OMC)
                                </Label>
                                <OmcSupplierPicker
                                    id="invoice-check-supplier"
                                    value={supplier}
                                    onChange={choose}
                                />
                            </div>
                            <div className="grid min-w-0 gap-1.5 xl:col-span-1">
                                <Label htmlFor="invoice-check-amount">
                                    Suma cerută (opțional)
                                </Label>
                                <Input
                                    id="invoice-check-amount"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    inputMode="decimal"
                                    placeholder="ex. 20431.86"
                                    value={amount}
                                    onChange={(event) =>
                                        setAmount(event.target.value)
                                    }
                                    className="w-full"
                                />
                            </div>
                            <div className="grid min-w-0 gap-1.5 xl:col-span-1">
                                <Label htmlFor="invoice-check-currency">
                                    Monedă
                                </Label>
                                <Select
                                    value={selectedCurrency}
                                    onValueChange={setCurrency}
                                >
                                    <SelectTrigger
                                        id="invoice-check-currency"
                                        className="w-full"
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
                            <div className="xl:col-span-1">
                                <Button
                                    type="submit"
                                    className="w-full"
                                    disabled={loading || supplier === null}
                                >
                                    <Search />
                                    Verifică
                                </Button>
                            </div>
                        </form>

                        {error && (
                            <p className="rounded-md bg-destructive/10 p-3 text-sm text-destructive">
                                {error}
                            </p>
                        )}

                        {supplier === null && !loading && (
                            <p className="rounded-md bg-muted/50 p-3 text-sm text-muted-foreground">
                                Alege un furnizor ca să vezi facturile lui
                                neachitate din OMC, sau apasă „Verifică” pe un
                                rând din lista de mai jos.
                            </p>
                        )}

                        {loading && !check ? <PaymentCheckSkeleton /> : null}

                        {check && supplier && (
                            <PaymentCheckResult
                                key={supplier.name}
                                check={check}
                                companyId={company?.id ?? null}
                                partnerId={check.supplier.partner_id}
                                partnerName={check.supplier.name}
                            />
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-wrap items-start justify-between gap-3">
                        <div className="space-y-1.5">
                            <CardTitle>
                                Furnizori cu facturi neachitate
                            </CardTitle>
                            <CardDescription>
                                Facturile FactFI cu rest de plată în OMC
                                {open
                                    ? ` (emise de la ${formatDate(open.since)})`
                                    : ''}
                                , grupate pe furnizor, în ordinea scadenței
                                celei mai apropiate. Echivalentul în lei
                                folosește cursul facturii.
                                {open && open.suppliers.length > 0
                                    ? ` ${open.suppliers.length} furnizori.`
                                    : ''}
                            </CardDescription>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => {
                                setOpen(null);
                                setOpenKey((key) => key + 1);
                            }}
                        >
                            <RefreshCw />
                            Reîncarcă
                        </Button>
                    </CardHeader>
                    <CardContent>
                        {openError ? (
                            <p className="rounded-md bg-destructive/10 p-3 text-sm text-destructive">
                                {openError}
                            </p>
                        ) : open === null ? (
                            <div className="space-y-2">
                                {[0, 1, 2, 3, 4].map((index) => (
                                    <Skeleton
                                        key={index}
                                        className="h-9 animate-pulse rounded-md"
                                    />
                                ))}
                            </div>
                        ) : (
                            <OpenSuppliersTable
                                suppliers={open.suppliers}
                                onPick={(row) => {
                                    choose({
                                        ...optionFor(row.name),
                                        cui: row.cui,
                                        invoices: row.invoices,
                                    });
                                    window.scrollTo({
                                        top: 0,
                                        behavior: 'smooth',
                                    });
                                }}
                            />
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

InvoiceChecksIndex.layout = (page: React.ReactNode) => (
    <AppLayout
        breadcrumbs={[
            { title: 'Verificare plăți', href: paymentChecksIndex() },
            {
                title: 'Facturi furnizori (OMC)',
                href: invoiceChecksIndex(),
            },
        ]}
    >
        {page}
    </AppLayout>
);
