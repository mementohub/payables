import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Check, X } from 'lucide-react';
import { useState } from 'react';
import PartnerController from '@/actions/App/Http/Controllers/PartnerController';
import CompanyBadge from '@/components/company-badge';
import DatePicker from '@/components/date-picker';
import Pagination from '@/components/pagination';
import PaymentStatusBadge from '@/components/payment-status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
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
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import { show as invoiceShow } from '@/routes/invoices';
import {
    clienti as clientiRoute,
    furnizori as furnizoriRoute,
    show as partnerShow,
} from '@/routes/partners';
import type { InvoiceFilters, ShowProps as Props } from './types';

function formatAmount(value: number, currency: string | null) {
    return `${new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)} ${currency ?? ''}`.trim();
}

export default function PartnerShow({
    partner,
    invoices,
    invoiceFilters,
    availableTipDocs,
    availableDepartments,
    stats,
}: Props) {
    const [selectedDepartmentId, setSelectedDepartmentId] =
        useState<string>('');
    const [search, setSearch] = useState(invoiceFilters.search ?? '');
    const [from, setFrom] = useState(invoiceFilters.from ?? '');
    const [to, setTo] = useState(invoiceFilters.to ?? '');

    const applyInvoiceFilter = (next: Partial<InvoiceFilters>) => {
        router.get(
            partnerShow(partner.id).url,
            {
                invoice_search:
                    next.search ?? invoiceFilters.search ?? undefined,
                invoice_tip_doc:
                    next.tip_doc ?? invoiceFilters.tip_doc ?? undefined,
                invoice_from: next.from ?? invoiceFilters.from ?? undefined,
                invoice_to: next.to ?? invoiceFilters.to ?? undefined,
                invoice_payment:
                    next.payment ?? invoiceFilters.payment ?? undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const hasFilters = Boolean(
        invoiceFilters.search ||
        invoiceFilters.tip_doc ||
        invoiceFilters.from ||
        invoiceFilters.to ||
        invoiceFilters.payment,
    );

    const activeBankAccounts = partner.bank_accounts.filter(
        (a) => !a.is_discontinued,
    );

    return (
        <>
            <Head title={partner.name} />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div>
                    <Button asChild variant="ghost" size="sm">
                        <Link
                            href={
                                partner.is_furnizor
                                    ? furnizoriRoute()
                                    : clientiRoute()
                            }
                        >
                            <ArrowLeft />
                            Înapoi la{' '}
                            {partner.is_furnizor ? 'furnizori' : 'clienți'}
                        </Link>
                    </Button>
                </div>

                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2">
                            <h1 className="text-2xl font-semibold">
                                {partner.name}
                            </h1>
                            {partner.is_furnizor && (
                                <Badge variant="secondary">Furnizor</Badge>
                            )}
                            {partner.is_client && (
                                <Badge variant="outline">Client</Badge>
                            )}
                        </div>
                        <div className="mt-1 flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                            {partner.cui && <span>CUI {partner.cui}</span>}
                            {partner.reg_com && (
                                <span>· J{partner.reg_com}</span>
                            )}
                            {partner.company && (
                                <CompanyBadge
                                    id={partner.company.id}
                                    name={partner.company.name}
                                />
                            )}
                        </div>
                    </div>
                </div>

                <StatsCards stats={stats} />

                {activeBankAccounts.length > 0 && (
                    <div className="flex flex-wrap gap-2">
                        {activeBankAccounts.map((account) => (
                            <Tooltip key={account.id}>
                                <TooltipTrigger asChild>
                                    <div
                                        className={
                                            'flex items-center gap-2 rounded-md border px-2.5 py-1.5 text-xs ' +
                                            (account.is_default
                                                ? 'border-green-600/40 bg-green-50 dark:bg-green-500/10'
                                                : 'border-sidebar-border/70 dark:border-sidebar-border')
                                        }
                                    >
                                        <Badge
                                            variant="secondary"
                                            className="px-1.5 py-0 text-[10px]"
                                        >
                                            {account.currency}
                                        </Badge>
                                        <span className="font-mono">
                                            {account.iban}
                                        </span>
                                        {account.is_default && (
                                            <Check className="size-3 text-green-700 dark:text-green-400" />
                                        )}
                                    </div>
                                </TooltipTrigger>
                                <TooltipContent>
                                    {account.bank ?? 'Bancă necunoscută'}
                                    {account.is_default ? ' · implicit' : ''}
                                </TooltipContent>
                            </Tooltip>
                        ))}
                        {partner.bank_accounts.length >
                            activeBankAccounts.length && (
                            <span className="self-center text-xs text-muted-foreground">
                                +
                                {partner.bank_accounts.length -
                                    activeBankAccounts.length}{' '}
                                inactive
                            </span>
                        )}
                    </div>
                )}

                <div className="grid gap-4 md:grid-cols-2">
                    <Card className="gap-2 py-3">
                        <CardHeader className="px-4 pb-0">
                            <CardTitle className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                Contact
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-0.5 px-4 pb-1 text-sm">
                            {partner.address && <div>{partner.address}</div>}
                            <div className="text-muted-foreground">
                                {[partner.city, partner.country]
                                    .filter(Boolean)
                                    .join(', ') || '—'}
                            </div>
                            {partner.phone && (
                                <div className="text-muted-foreground">
                                    {partner.phone}
                                </div>
                            )}
                            {partner.email && (
                                <div className="text-muted-foreground">
                                    {partner.email}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card className="gap-2 py-3">
                        <CardHeader className="px-4 pb-0">
                            <CardTitle className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                Departamente responsabili
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2 px-4 pb-2">
                            {partner.responsabil_departments.length === 0 ? (
                                <p className="text-xs text-muted-foreground">
                                    Niciun departament atribuit.
                                </p>
                            ) : (
                                <ul className="flex flex-wrap gap-1.5">
                                    {partner.responsabil_departments.map(
                                        (dept) => (
                                            <li
                                                key={dept.id}
                                                className="flex items-center gap-1.5 rounded-full border border-sidebar-border/70 px-2 py-0.5 text-xs dark:border-sidebar-border"
                                            >
                                                <span>{dept.name}</span>
                                                <Form
                                                    {...PartnerController.detachResponsabilDepartment.form(
                                                        [partner.id, dept.id],
                                                    )}
                                                    options={{
                                                        preserveScroll: true,
                                                    }}
                                                    className="flex"
                                                >
                                                    {({ processing }) => (
                                                        <button
                                                            type="submit"
                                                            className="rounded-full p-0.5 text-muted-foreground hover:bg-muted disabled:opacity-50"
                                                            disabled={
                                                                processing
                                                            }
                                                            aria-label={`Elimină ${dept.name}`}
                                                        >
                                                            <X className="size-3" />
                                                        </button>
                                                    )}
                                                </Form>
                                            </li>
                                        ),
                                    )}
                                </ul>
                            )}

                            {availableDepartments.length > 0 && (
                                <Form
                                    {...PartnerController.attachResponsabilDepartment.form(
                                        partner.id,
                                    )}
                                    options={{ preserveScroll: true }}
                                    onSuccess={() =>
                                        setSelectedDepartmentId('')
                                    }
                                    className="flex items-center gap-2"
                                >
                                    {({ processing }) => (
                                        <>
                                            <Select
                                                value={selectedDepartmentId}
                                                onValueChange={
                                                    setSelectedDepartmentId
                                                }
                                            >
                                                <SelectTrigger
                                                    size="sm"
                                                    className="w-full"
                                                >
                                                    <SelectValue placeholder="Atribuie departament…" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {availableDepartments.map(
                                                        (dept) => (
                                                            <SelectItem
                                                                key={dept.id}
                                                                value={String(
                                                                    dept.id,
                                                                )}
                                                            >
                                                                {dept.name}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                            <input
                                                type="hidden"
                                                name="department_id"
                                                value={selectedDepartmentId}
                                            />
                                            <Button
                                                size="sm"
                                                type="submit"
                                                disabled={
                                                    processing ||
                                                    !selectedDepartmentId
                                                }
                                            >
                                                Atribuie
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle className="text-base">Facturi</CardTitle>
                        <span className="text-xs text-muted-foreground">
                            {invoices.from ?? 0}–{invoices.to ?? 0} din{' '}
                            {invoices.total}
                        </span>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <form
                            className="flex flex-wrap items-center gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                applyInvoiceFilter({ search, from, to });
                            }}
                        >
                            <Input
                                className="max-w-xs"
                                placeholder="Caută număr factură…"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                            <Select
                                value={invoiceFilters.tip_doc ?? 'all'}
                                onValueChange={(v) =>
                                    applyInvoiceFilter({
                                        tip_doc: v === 'all' ? null : v,
                                    })
                                }
                            >
                                <SelectTrigger className="w-[140px]">
                                    <SelectValue placeholder="Tip" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        Toate tipurile
                                    </SelectItem>
                                    {availableTipDocs.map((type) => (
                                        <SelectItem key={type} value={type}>
                                            {type}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <DatePicker
                                className="w-[180px]"
                                value={from}
                                onChange={setFrom}
                                placeholder="De la"
                            />
                            <DatePicker
                                className="w-[180px]"
                                value={to}
                                onChange={setTo}
                                placeholder="Până la"
                            />
                            <Select
                                value={invoiceFilters.payment ?? 'all'}
                                onValueChange={(v) =>
                                    applyInvoiceFilter({
                                        payment: v === 'all' ? null : v,
                                    })
                                }
                            >
                                <SelectTrigger className="w-[140px]">
                                    <SelectValue placeholder="Plată" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        Toate plățile
                                    </SelectItem>
                                    <SelectItem value="paid">
                                        Plătite
                                    </SelectItem>
                                    <SelectItem value="partial">
                                        Parțial
                                    </SelectItem>
                                    <SelectItem value="unpaid">
                                        Neplătite
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <Button type="submit" variant="secondary" size="sm">
                                Caută
                            </Button>
                            {hasFilters && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => {
                                        setSearch('');
                                        setFrom('');
                                        setTo('');
                                        router.get(
                                            partnerShow(partner.id).url,
                                            {},
                                            {
                                                preserveState: true,
                                                preserveScroll: true,
                                                replace: true,
                                            },
                                        );
                                    }}
                                >
                                    Resetează
                                </Button>
                            )}
                        </form>

                        <div className="overflow-hidden rounded-md border border-sidebar-border/70 dark:border-sidebar-border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-28">
                                            Dată
                                        </TableHead>
                                        <TableHead className="w-24">
                                            Tip
                                        </TableHead>
                                        <TableHead>Număr</TableHead>
                                        <TableHead className="w-28">
                                            Scadență
                                        </TableHead>
                                        <TableHead className="w-32 text-right">
                                            Net
                                        </TableHead>
                                        <TableHead className="w-32 text-right">
                                            TVA
                                        </TableHead>
                                        <TableHead className="w-32 text-right">
                                            Total
                                        </TableHead>
                                        <TableHead className="w-28">
                                            Plată
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {invoices.data.length === 0 && (
                                        <TableRow>
                                            <TableCell
                                                colSpan={8}
                                                className="py-6 text-center text-muted-foreground"
                                            >
                                                {hasFilters
                                                    ? 'Nicio factură pe filtrele curente.'
                                                    : 'Nicio factură pentru acest furnizor.'}
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {invoices.data.map((invoice) => (
                                        <TableRow key={invoice.id}>
                                            <TableCell>
                                                {invoice.data_doc}
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant="secondary">
                                                    {invoice.tip_doc}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="font-medium">
                                                <Link
                                                    className="hover:underline"
                                                    href={invoiceShow(
                                                        invoice.id,
                                                    )}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                >
                                                    {invoice.nr_doc}
                                                </Link>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {invoice.data_scadenta ?? '—'}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatAmount(
                                                    invoice.val_mon,
                                                    invoice.moneda,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatAmount(
                                                    invoice.val_mon_tva,
                                                    invoice.moneda,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right font-medium tabular-nums">
                                                {formatAmount(
                                                    invoice.val_mon +
                                                        invoice.val_mon_tva,
                                                    invoice.moneda,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <PaymentStatusBadge
                                                    status={
                                                        invoice.payment_status
                                                    }
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>

                        <div className="flex justify-end">
                            <Pagination links={invoices.links} />
                        </div>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

function PartnerShowLayout({ children }: { children: React.ReactNode }) {
    const { partner } = usePage<Props>().props;
    const isFurnizor = partner.is_furnizor;

    return (
        <AppLayout
            breadcrumbs={[
                {
                    title: isFurnizor ? 'Furnizori' : 'Clienți',
                    href: isFurnizor ? furnizoriRoute() : clientiRoute(),
                },
                { title: partner.name, href: partnerShow(partner.id) },
            ]}
        >
            {children}
        </AppLayout>
    );
}

function StatsCards({
    stats,
}: {
    stats: import('./types').PartnerStats;
}) {
    const { totals, counts, oldest_unpaid, last_invoice_date } = stats;

    return (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Card className="gap-1 py-3">
                <CardHeader className="px-4 pb-0">
                    <CardTitle className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                        Total facturi
                    </CardTitle>
                </CardHeader>
                <CardContent className="px-4 pb-2">
                    <div className="text-2xl font-semibold tabular-nums">
                        {counts.total}
                    </div>
                    <div className="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-muted-foreground">
                        <span>
                            <span className="text-green-700 dark:text-green-400">
                                {counts.paid}
                            </span>{' '}
                            plătite
                        </span>
                        {counts.partial > 0 && (
                            <span>
                                <span className="text-amber-700 dark:text-amber-400">
                                    {counts.partial}
                                </span>{' '}
                                parțial
                            </span>
                        )}
                        {counts.unpaid > 0 && (
                            <span>
                                <span className="text-red-700 dark:text-red-400">
                                    {counts.unpaid}
                                </span>{' '}
                                neplătite
                            </span>
                        )}
                    </div>
                    {last_invoice_date && (
                        <div className="mt-1 text-xs text-muted-foreground">
                            Ultima: {last_invoice_date}
                        </div>
                    )}
                </CardContent>
            </Card>

            <Card className="gap-1 py-3">
                <CardHeader className="px-4 pb-0">
                    <CardTitle className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                        Total facturat
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-0.5 px-4 pb-2">
                    {totals.length === 0 ? (
                        <div className="text-sm text-muted-foreground">—</div>
                    ) : (
                        totals.map((t) => (
                            <div
                                key={t.moneda ?? '—'}
                                className="flex items-baseline justify-between gap-2 text-sm tabular-nums"
                            >
                                <span className="font-semibold">
                                    {formatAmount(t.val_mon, t.moneda)}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {t.count}
                                </span>
                            </div>
                        ))
                    )}
                </CardContent>
            </Card>

            <Card className="gap-1 py-3">
                <CardHeader className="px-4 pb-0">
                    <CardTitle className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                        Achitat
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-0.5 px-4 pb-2">
                    {totals.length === 0 ? (
                        <div className="text-sm text-muted-foreground">—</div>
                    ) : (
                        totals.map((t) => {
                            const pct =
                                t.val_mon > 0
                                    ? Math.round(
                                          (t.val_mon_paid / t.val_mon) * 100,
                                      )
                                    : 0;
                            return (
                                <div
                                    key={t.moneda ?? '—'}
                                    className="flex items-baseline justify-between gap-2 text-sm tabular-nums"
                                >
                                    <span className="font-semibold text-green-700 dark:text-green-400">
                                        {formatAmount(t.val_mon_paid, t.moneda)}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {pct}%
                                    </span>
                                </div>
                            );
                        })
                    )}
                </CardContent>
            </Card>

            <Card
                className={
                    'gap-1 py-3 ' +
                    (totals.some((t) => t.sold > 0.01)
                        ? 'border-amber-500/40'
                        : '')
                }
            >
                <CardHeader className="px-4 pb-0">
                    <CardTitle className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                        Sold rămas
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-0.5 px-4 pb-2">
                    {totals.length === 0 ? (
                        <div className="text-sm text-muted-foreground">—</div>
                    ) : (
                        totals.map((t) => (
                            <div
                                key={t.moneda ?? '—'}
                                className={
                                    'text-sm font-semibold tabular-nums ' +
                                    (t.sold > 0.01
                                        ? 'text-amber-700 dark:text-amber-400'
                                        : 'text-muted-foreground')
                                }
                            >
                                {formatAmount(t.sold, t.moneda)}
                            </div>
                        ))
                    )}
                    {oldest_unpaid && (
                        <Link
                            href={invoiceShow(oldest_unpaid.id)}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="mt-1 block border-t pt-1 text-xs text-muted-foreground hover:underline"
                        >
                            Cea mai veche neplătită:{' '}
                            <span className="text-foreground">
                                {oldest_unpaid.nr_doc}
                            </span>{' '}
                            ·{' '}
                            <span className="text-foreground">
                                {formatAmount(
                                    oldest_unpaid.val_mon,
                                    oldest_unpaid.moneda,
                                )}
                            </span>
                            {oldest_unpaid.days_overdue !== null &&
                                oldest_unpaid.days_overdue > 0 && (
                                    <span className="text-red-700 dark:text-red-400">
                                        {' '}
                                        · {oldest_unpaid.days_overdue}z
                                        întârziere
                                    </span>
                                )}
                        </Link>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}

PartnerShow.layout = (page: React.ReactNode) => (
    <PartnerShowLayout>{page}</PartnerShowLayout>
);
