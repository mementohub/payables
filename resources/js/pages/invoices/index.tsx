import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import Pagination from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import PaymentStatusBadge, { type PaymentStatus } from '@/components/payment-status-badge';
import { emise as facturiEmise, primite as facturiPrimite, show as invoicesShow } from '@/routes/invoices';
import type { Paginated } from '@/types/pagination';

type InvoiceRow = {
    id: number;
    data_doc: string;
    data_scadenta: string | null;
    nr_doc: string;
    partener_type: 'furnizor' | 'client' | null;
    partner: { id: number; name: string; cui: string | null } | null;
    company: { id: number; name: string };
    moneda: string | null;
    val_mon: number;
    val_mon_tva: number;
    val_mon_paid: number;
    payment_status: PaymentStatus;
};

type Scope = 'emise' | 'primite';

type Filters = {
    search: string | null;
    company_id: number | null;
    payment: string | null;
};

type Props = {
    invoices: Paginated<InvoiceRow>;
    scope: Scope;
    filters: Filters;
    companies: { id: number; name: string }[];
};

function formatAmount(value: number, currency: string | null) {
    return `${new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)} ${currency ?? ''}`.trim();
}

export default function InvoicesIndex({ invoices, scope, filters, companies }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const label = scope === 'emise' ? 'Facturi emise' : 'Facturi primite';
    const baseUrl = scope === 'emise' ? facturiEmise().url : facturiPrimite().url;
    const description =
        scope === 'emise'
            ? 'Facturi emise către clienți (FactCI / FactCE / FactINT) sincronizate din BD-urile companiilor.'
            : 'Facturi primite de la furnizori (FactFI / FactFE) sincronizate din BD-urile companiilor.';

    const applyFilter = (next: Partial<Filters>) => {
        router.get(
            baseUrl,
            {
                search: next.search ?? filters.search ?? undefined,
                company_id: next.company_id ?? filters.company_id ?? undefined,
                payment: next.payment ?? filters.payment ?? undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <>
            <Head title={label} />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">{label}</h1>
                    <p className="text-sm text-muted-foreground">{description}</p>
                </div>

                <form
                    className="flex flex-wrap items-center gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        applyFilter({ search });
                    }}
                >
                    <Input
                        className="max-w-xs"
                        placeholder={
                            scope === 'emise'
                                ? 'Caută număr factură sau client…'
                                : 'Caută număr factură sau furnizor…'
                        }
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                    <Select
                        value={filters.company_id ? String(filters.company_id) : 'all'}
                        onValueChange={(v) => applyFilter({ company_id: v === 'all' ? null : Number(v) })}
                    >
                        <SelectTrigger className="w-[200px]">
                            <SelectValue placeholder="Companie" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Toate companiile</SelectItem>
                            {companies.map((c) => (
                                <SelectItem key={c.id} value={String(c.id)}>
                                    {c.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.payment ?? 'all'}
                        onValueChange={(v) => applyFilter({ payment: v === 'all' ? null : v })}
                    >
                        <SelectTrigger className="w-[160px]">
                            <SelectValue placeholder="Plată" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Toate plățile</SelectItem>
                            <SelectItem value="paid">Plătite</SelectItem>
                            <SelectItem value="partial">Parțial</SelectItem>
                            <SelectItem value="unpaid">Neplătite</SelectItem>
                        </SelectContent>
                    </Select>
                    <Button type="submit" variant="secondary">
                        Caută
                    </Button>
                </form>

                <div className="overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left text-xs uppercase text-muted-foreground">
                            <tr>
                                <th className="px-4 py-3">Dată</th>
                                <th className="px-4 py-3">Scadență</th>
                                <th className="px-4 py-3">Număr</th>
                                <th className="px-4 py-3">{scope === 'emise' ? 'Client' : 'Furnizor'}</th>
                                <th className="px-4 py-3">Companie</th>
                                <th className="px-4 py-3 text-right">Net</th>
                                <th className="px-4 py-3 text-right">TVA</th>
                                <th className="px-4 py-3">Plată</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                            {invoices.data.length === 0 && (
                                <tr>
                                    <td className="px-4 py-6 text-center text-muted-foreground" colSpan={8}>
                                        Nicio factură încă. Pornește o sincronizare din pagina Companii.
                                    </td>
                                </tr>
                            )}
                            {invoices.data.map((invoice) => (
                                <tr key={invoice.id} className="hover:bg-muted/30">
                                    <td className="px-4 py-3">{invoice.data_doc}</td>
                                    <td className="px-4 py-3 text-muted-foreground">
                                        {invoice.data_scadenta ?? '—'}
                                    </td>
                                    <td className="px-4 py-3 font-medium">
                                        <Link className="hover:underline" href={invoicesShow(invoice.id)}>
                                            {invoice.nr_doc}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3">
                                        {invoice.partner ? (
                                            <div>
                                                <div>{invoice.partner.name}</div>
                                                {invoice.partner.cui && (
                                                    <div className="text-xs text-muted-foreground">
                                                        CUI: {invoice.partner.cui}
                                                    </div>
                                                )}
                                            </div>
                                        ) : (
                                            <span className="text-muted-foreground">—</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-muted-foreground">{invoice.company.name}</td>
                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {formatAmount(invoice.val_mon, invoice.moneda)}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {formatAmount(invoice.val_mon_tva, invoice.moneda)}
                                    </td>
                                    <td className="px-4 py-3">
                                        <PaymentStatusBadge status={invoice.payment_status} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="flex items-center justify-between">
                    <span className="text-xs text-muted-foreground">
                        {invoices.from ?? 0}–{invoices.to ?? 0} din {invoices.total}
                    </span>
                    <Pagination links={invoices.links} />
                </div>
            </div>
        </>
    );
}

function InvoicesLayout({ children }: { children: React.ReactNode }) {
    const { scope } = usePage<Props>().props;
    const label = scope === 'emise' ? 'Facturi emise' : 'Facturi primite';
    const href = scope === 'emise' ? facturiEmise() : facturiPrimite();
    return <AppLayout breadcrumbs={[{ title: label, href }]}>{children}</AppLayout>;
}

InvoicesIndex.layout = (page: React.ReactNode) => (
    <InvoicesLayout>{page}</InvoicesLayout>
);
