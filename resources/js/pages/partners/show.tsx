import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Check, X } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import PartnerController from '@/actions/App/Http/Controllers/PartnerController';
import Pagination from '@/components/pagination';
import PaymentStatusBadge, { type PaymentStatus } from '@/components/payment-status-badge';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
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
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { show as invoiceShow } from '@/routes/invoices';
import { furnizori as furnizoriRoute, show as partnerShow } from '@/routes/partners';
import type { Paginated } from '@/types/pagination';

type BankAccount = {
    id: number;
    bank: string | null;
    iban: string;
    currency: string;
    is_default: boolean;
    is_discontinued: boolean;
};

type Responsible = {
    id: number;
    name: string;
    email: string;
    initials: string;
};

type Partner = {
    id: number;
    name: string;
    cui: string | null;
    reg_com: string | null;
    country: string | null;
    city: string | null;
    address: string | null;
    phone: string | null;
    email: string | null;
    is_furnizor: boolean;
    is_client: boolean;
    company: { id: number; name: string };
    bank_accounts: BankAccount[];
    responsibles: Responsible[];
};

type InvoiceRow = {
    id: number;
    data_doc: string;
    tip_doc: string;
    nr_doc: string;
    moneda: string | null;
    val_mon: number;
    val_mon_tva: number;
    val_mon_paid: number;
    payment_status: PaymentStatus;
    data_scadenta: string | null;
    data_inchidere: string | null;
};

type InvoiceFilters = {
    search: string | null;
    tip_doc: string | null;
    from: string | null;
    to: string | null;
    payment: string | null;
};

type AvailableUser = { id: number; name: string; email: string };

type Props = {
    partner: Partner;
    invoices: Paginated<InvoiceRow>;
    invoiceFilters: InvoiceFilters;
    availableTipDocs: string[];
    availableUsers: AvailableUser[];
};

function formatAmount(value: number, currency: string | null) {
    return `${new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)} ${currency ?? ''}`.trim();
}

export default function PartnerShow({
    partner,
    invoices,
    invoiceFilters,
    availableTipDocs,
    availableUsers,
}: Props) {
    const [selectedUserId, setSelectedUserId] = useState<string>('');
    const [search, setSearch] = useState(invoiceFilters.search ?? '');
    const [from, setFrom] = useState(invoiceFilters.from ?? '');
    const [to, setTo] = useState(invoiceFilters.to ?? '');

    const applyInvoiceFilter = (next: Partial<InvoiceFilters>) => {
        router.get(
            partnerShow(partner.id).url,
            {
                invoice_search: next.search ?? invoiceFilters.search ?? undefined,
                invoice_tip_doc: next.tip_doc ?? invoiceFilters.tip_doc ?? undefined,
                invoice_from: next.from ?? invoiceFilters.from ?? undefined,
                invoice_to: next.to ?? invoiceFilters.to ?? undefined,
                invoice_payment: next.payment ?? invoiceFilters.payment ?? undefined,
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

    const activeBankAccounts = partner.bank_accounts.filter((a) => !a.is_discontinued);

    return (
        <>
            <Head title={partner.name} />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div>
                    <Button asChild variant="ghost" size="sm">
                        <Link href={furnizoriRoute()}>
                            <ArrowLeft />
                            Înapoi la furnizori
                        </Link>
                    </Button>
                </div>

                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2">
                            <h1 className="text-2xl font-semibold">{partner.name}</h1>
                            {partner.is_furnizor && <Badge variant="secondary">Furnizor</Badge>}
                            {partner.is_client && <Badge variant="outline">Client</Badge>}
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {partner.cui && <>CUI {partner.cui}</>}
                            {partner.reg_com && <> · J{partner.reg_com}</>}
                            {partner.company && <> · {partner.company.name}</>}
                        </p>
                    </div>
                </div>

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
                                        <Badge variant="secondary" className="px-1.5 py-0 text-[10px]">
                                            {account.currency}
                                        </Badge>
                                        <span className="font-mono">{account.iban}</span>
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
                        {partner.bank_accounts.length > activeBankAccounts.length && (
                            <span className="self-center text-xs text-muted-foreground">
                                +{partner.bank_accounts.length - activeBankAccounts.length} inactive
                            </span>
                        )}
                    </div>
                )}

                <div className="grid gap-4 md:grid-cols-2">
                    <Card className="gap-2 py-3">
                        <CardHeader className="px-4 pb-0">
                            <CardTitle className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                Contact
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-0.5 px-4 pb-1 text-sm">
                            {partner.address && <div>{partner.address}</div>}
                            <div className="text-muted-foreground">
                                {[partner.city, partner.country].filter(Boolean).join(', ') || '—'}
                            </div>
                            {partner.phone && <div className="text-muted-foreground">{partner.phone}</div>}
                            {partner.email && <div className="text-muted-foreground">{partner.email}</div>}
                        </CardContent>
                    </Card>

                    <Card className="gap-2 py-3">
                        <CardHeader className="px-4 pb-0">
                            <CardTitle className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                Responsabili
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2 px-4 pb-2">
                            {partner.responsibles.length === 0 ? (
                                <p className="text-xs text-muted-foreground">Niciun utilizator atribuit.</p>
                            ) : (
                                <ul className="flex flex-wrap gap-1.5">
                                    {partner.responsibles.map((user) => (
                                        <li
                                            key={user.id}
                                            className="flex items-center gap-1.5 rounded-full border border-sidebar-border/70 py-0.5 pr-1 pl-0.5 text-xs dark:border-sidebar-border"
                                        >
                                            <Tooltip>
                                                <TooltipTrigger asChild>
                                                    <Avatar size="sm">
                                                        <AvatarFallback>{user.initials}</AvatarFallback>
                                                    </Avatar>
                                                </TooltipTrigger>
                                                <TooltipContent>{user.email}</TooltipContent>
                                            </Tooltip>
                                            <span>{user.name}</span>
                                            <Form
                                                {...PartnerController.detachResponsible.form([partner.id, user.id])}
                                                options={{ preserveScroll: true }}
                                                className="flex"
                                            >
                                                {({ processing }) => (
                                                    <button
                                                        type="submit"
                                                        className="rounded-full p-0.5 text-muted-foreground hover:bg-muted disabled:opacity-50"
                                                        disabled={processing}
                                                        aria-label={`Elimină ${user.name}`}
                                                    >
                                                        <X className="size-3" />
                                                    </button>
                                                )}
                                            </Form>
                                        </li>
                                    ))}
                                </ul>
                            )}

                            {availableUsers.length > 0 && (
                                <Form
                                    {...PartnerController.attachResponsible.form(partner.id)}
                                    options={{ preserveScroll: true }}
                                    onSuccess={() => setSelectedUserId('')}
                                    className="flex items-center gap-2"
                                >
                                    {({ processing }) => (
                                        <>
                                            <Select value={selectedUserId} onValueChange={setSelectedUserId}>
                                                <SelectTrigger size="sm" className="w-full">
                                                    <SelectValue placeholder="Atribuie utilizator…" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {availableUsers.map((user) => (
                                                        <SelectItem key={user.id} value={String(user.id)}>
                                                            {user.name}
                                                            <span className="ml-2 text-xs text-muted-foreground">
                                                                {user.email}
                                                            </span>
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            <input type="hidden" name="user_id" value={selectedUserId} />
                                            <Button
                                                size="sm"
                                                type="submit"
                                                disabled={processing || !selectedUserId}
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
                            {invoices.from ?? 0}–{invoices.to ?? 0} din {invoices.total}
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
                                onValueChange={(v) => applyInvoiceFilter({ tip_doc: v === 'all' ? null : v })}
                            >
                                <SelectTrigger className="w-[140px]">
                                    <SelectValue placeholder="Tip" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Toate tipurile</SelectItem>
                                    {availableTipDocs.map((type) => (
                                        <SelectItem key={type} value={type}>
                                            {type}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Input
                                type="date"
                                className="w-[160px]"
                                value={from}
                                onChange={(e) => setFrom(e.target.value)}
                                aria-label="De la"
                            />
                            <Input
                                type="date"
                                className="w-[160px]"
                                value={to}
                                onChange={(e) => setTo(e.target.value)}
                                aria-label="Până la"
                            />
                            <Select
                                value={invoiceFilters.payment ?? 'all'}
                                onValueChange={(v) => applyInvoiceFilter({ payment: v === 'all' ? null : v })}
                            >
                                <SelectTrigger className="w-[140px]">
                                    <SelectValue placeholder="Plată" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Toate plățile</SelectItem>
                                    <SelectItem value="paid">Plătite</SelectItem>
                                    <SelectItem value="partial">Parțial</SelectItem>
                                    <SelectItem value="unpaid">Neplătite</SelectItem>
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
                                            { preserveState: true, preserveScroll: true, replace: true },
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
                                        <TableHead className="w-28">Dată</TableHead>
                                        <TableHead className="w-24">Tip</TableHead>
                                        <TableHead>Număr</TableHead>
                                        <TableHead className="w-28">Scadență</TableHead>
                                        <TableHead className="w-32 text-right">Net</TableHead>
                                        <TableHead className="w-32 text-right">TVA</TableHead>
                                        <TableHead className="w-32 text-right">Total</TableHead>
                                        <TableHead className="w-28">Plată</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {invoices.data.length === 0 && (
                                        <TableRow>
                                            <TableCell
                                                colSpan={8}
                                                className="py-6 text-center text-muted-foreground"
                                            >
                                                {hasFilters ? 'Nicio factură pe filtrele curente.' : 'Nicio factură pentru acest furnizor.'}
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {invoices.data.map((invoice) => (
                                        <TableRow key={invoice.id}>
                                            <TableCell>{invoice.data_doc}</TableCell>
                                            <TableCell>
                                                <Badge variant="secondary">{invoice.tip_doc}</Badge>
                                            </TableCell>
                                            <TableCell className="font-medium">
                                                <Link className="hover:underline" href={invoiceShow(invoice.id)}>
                                                    {invoice.nr_doc}
                                                </Link>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {invoice.data_scadenta ?? '—'}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatAmount(invoice.val_mon, invoice.moneda)}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatAmount(invoice.val_mon_tva, invoice.moneda)}
                                            </TableCell>
                                            <TableCell className="text-right font-medium tabular-nums">
                                                {formatAmount(
                                                    invoice.val_mon + invoice.val_mon_tva,
                                                    invoice.moneda,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <PaymentStatusBadge status={invoice.payment_status} />
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
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Furnizori', href: furnizoriRoute() },
                { title: partner.name, href: partnerShow(partner.id) },
            ]}
        >
            {children}
        </AppLayout>
    );
}

PartnerShow.layout = (page: React.ReactNode) => <PartnerShowLayout>{page}</PartnerShowLayout>;
