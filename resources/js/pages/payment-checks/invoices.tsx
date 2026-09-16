import { Head, Link, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import PaymentCheckResult, {
    DuePill,
    FALLBACK_CURRENCIES,
    PaymentCheckSkeleton,
    fetchCheck,
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
import AppLayout from '@/layouts/app-layout';
import { index as companiesIndex } from '@/routes/companies';
import { show as partnerShow } from '@/routes/partners';
import { index as paymentChecksIndex } from '@/routes/payment-checks';
import { index as invoiceChecksIndex } from '@/routes/payment-checks/invoices';
import type { PaymentCheck } from '@/types/payment-check';

type CompanyOption = {
    id: number;
    name: string;
    synced_at: string | null;
};

type OpenSupplier = {
    partner_id: number;
    name: string;
    cui: string | null;
    invoices: number;
    first_due: string | null;
    overdue: boolean;
    rest: { moneda: string; rest: number }[];
    rest_lei: number;
};

type Props = {
    companies: CompanyOption[];
    filters: {
        company_id: number | null;
        partner: OmcSupplierOption | null;
        amount: string | null;
        currency: string | null;
    };
    openSuppliers: OpenSupplier[];
};

function daysUntil(date: string): number {
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    return Math.round(
        (new Date(`${date}T00:00:00`).getTime() - today.getTime()) / 86_400_000,
    );
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
                Nicio factură de furnizor neachitată în ERP pentru această
                companie.
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
                            key={supplier.partner_id}
                            className={
                                supplier.overdue
                                    ? 'bg-destructive/5'
                                    : undefined
                            }
                        >
                            <td className="px-3 py-2 font-medium">
                                <Link
                                    href={partnerShow(supplier.partner_id)}
                                    className="hover:underline"
                                >
                                    {supplier.name}
                                </Link>
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
    companies,
    filters,
    openSuppliers,
}: Props) {
    const [companyId, setCompanyId] = useState<number | null>(
        filters.company_id,
    );
    const [partner, setPartner] = useState<OmcSupplierOption | null>(
        filters.partner,
    );
    const [amount, setAmount] = useState(filters.amount ?? '');
    const [currency, setCurrency] = useState(filters.currency ?? '');
    const [check, setCheck] = useState<PaymentCheck | null>(null);
    const [loading, setLoading] = useState(filters.partner !== null);
    const [error, setError] = useState<string | null>(null);

    const company = companies.find((item) => item.id === companyId) ?? null;

    const currencies = useMemo(() => {
        const known = check?.currencies ?? [];

        return known.length > 0 ? known : FALLBACK_CURRENCIES;
    }, [check]);

    const selectedCurrency = currency || currencies[0];

    useEffect(() => {
        if (!filters.partner) {
            return;
        }

        let cancelled = false;
        const query: Record<string, string> = {};

        if (filters.amount) {
            query.amount = filters.amount;

            if (filters.currency) {
                query.currency = filters.currency;
            }
        }

        fetchCheck(filters.partner.id, query)
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
    }, [filters.partner, filters.amount, filters.currency]);

    function run(
        target: OmcSupplierOption,
        requested: string,
        requestedCurrency: string,
    ) {
        const query: Record<string, string> = {};

        if (requested.trim()) {
            query.amount = requested.trim();
            query.currency = requestedCurrency;
        }

        setLoading(true);
        setError(null);

        fetchCheck(target.id, query)
            .then(setCheck)
            .catch((err: Error) => setError(err.message))
            .finally(() => setLoading(false));
    }

    function choose(option: OmcSupplierOption | null) {
        setPartner(option);
        setCheck(null);
        setError(null);

        if (option) {
            run(option, amount, selectedCurrency);
        }
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (partner) {
            run(partner, amount, selectedCurrency);
        }
    }

    function changeCompany(value: string) {
        const id = Number(value);

        setCompanyId(id);
        setPartner(null);
        setCheck(null);
        setError(null);

        router.get(
            invoiceChecksIndex().url,
            { company_id: id },
            { preserveScroll: true },
        );
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
                    {company && (
                        <p className="text-xs text-muted-foreground">
                            Date OMC sincronizate:{' '}
                            {company.synced_at
                                ? new Date(company.synced_at).toLocaleString(
                                      'ro-RO',
                                  )
                                : 'niciodată'}
                            {' · '}
                            <Link
                                href={companiesIndex()}
                                className="underline underline-offset-2"
                            >
                                Sincronizează
                            </Link>
                        </p>
                    )}
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Factura de plată</CardTitle>
                        <CardDescription>
                            Alege furnizorul din cerere; facturile lui
                            neachitate apar imediat, iar cu suma cerută vezi
                            dacă ea corespunde unei facturi înregistrate.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <form
                            onSubmit={submit}
                            className="grid gap-4 md:grid-cols-2 xl:grid-cols-6 xl:items-end"
                        >
                            <div className="grid min-w-0 gap-1.5 xl:col-span-2">
                                <Label htmlFor="invoice-check-company">
                                    Companie
                                </Label>
                                <Select
                                    value={
                                        companyId !== null
                                            ? String(companyId)
                                            : ''
                                    }
                                    onValueChange={changeCompany}
                                >
                                    <SelectTrigger
                                        id="invoice-check-company"
                                        className="w-full"
                                    >
                                        <SelectValue placeholder="Alege compania" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {companies.map((item) => (
                                            <SelectItem
                                                key={item.id}
                                                value={String(item.id)}
                                            >
                                                {item.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="grid min-w-0 gap-1.5 xl:col-span-4">
                                <Label htmlFor="invoice-check-supplier">
                                    Furnizor (OMC)
                                </Label>
                                <OmcSupplierPicker
                                    id="invoice-check-supplier"
                                    companyId={companyId}
                                    value={partner}
                                    onChange={choose}
                                />
                            </div>
                            <div className="grid min-w-0 gap-1.5 xl:col-span-2">
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
                                    disabled={loading || partner === null}
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

                        {partner === null && !loading && (
                            <p className="rounded-md bg-muted/50 p-3 text-sm text-muted-foreground">
                                Alege un furnizor ca să vezi facturile lui
                                neachitate din OMC, sau apasă „Verifică” pe un
                                rând din lista de mai jos.
                            </p>
                        )}

                        {loading && !check ? <PaymentCheckSkeleton /> : null}

                        {check && partner && company && (
                            <PaymentCheckResult
                                key={partner.id}
                                check={check}
                                companyId={company.id}
                                partnerId={partner.id}
                                partnerName={partner.name}
                            />
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Furnizori cu facturi neachitate</CardTitle>
                        <CardDescription>
                            Toate facturile FactFI cu rest de plată în OMC,
                            grupate pe furnizor, în ordinea scadenței celei mai
                            apropiate. Echivalentul în lei folosește cursul
                            facturii.
                            {openSuppliers.length > 0 &&
                                ` ${openSuppliers.length} furnizori.`}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <OpenSuppliersTable
                            suppliers={openSuppliers}
                            onPick={(supplier) => {
                                choose({
                                    id: supplier.partner_id,
                                    name: supplier.name,
                                    cui: supplier.cui,
                                    city: null,
                                });
                                window.scrollTo({ top: 0, behavior: 'smooth' });
                            }}
                        />
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
