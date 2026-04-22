import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import DateRangePicker, { type DateRangeValue } from '@/components/date-range-picker';
import Pagination from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
    data_doc_from: string | null;
    data_doc_to: string | null;
    data_scadenta_from: string | null;
    data_scadenta_to: string | null;
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
                data_doc_from: next.data_doc_from ?? filters.data_doc_from ?? undefined,
                data_doc_to: next.data_doc_to ?? filters.data_doc_to ?? undefined,
                data_scadenta_from: next.data_scadenta_from ?? filters.data_scadenta_from ?? undefined,
                data_scadenta_to: next.data_scadenta_to ?? filters.data_scadenta_to ?? undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const docRange: DateRangeValue = { from: filters.data_doc_from, to: filters.data_doc_to };
    const scadentaRange: DateRangeValue = { from: filters.data_scadenta_from, to: filters.data_scadenta_to };

    return (
        <>
            <Head title={label} />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">{label}</h1>
                    <p className="text-sm text-muted-foreground">{description}</p>
                </div>

                <form
                    aria-label={`Filtre ${label.toLowerCase()}`}
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        applyFilter({ search });
                    }}
                >
                    <div className="grid gap-1">
                        <Label className="text-xs">Caută</Label>
                        <Input
                            className="min-h-11 w-[260px]"
                            placeholder={
                                scope === 'emise'
                                    ? 'Număr factură sau client…'
                                    : 'Număr factură sau furnizor…'
                            }
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label className="text-xs">Companie</Label>
                        <Select
                            value={filters.company_id ? String(filters.company_id) : 'all'}
                            onValueChange={(v) => applyFilter({ company_id: v === 'all' ? null : Number(v) })}
                        >
                            <SelectTrigger className="min-h-11 w-[200px]">
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
                    </div>
                    <div className="grid gap-1">
                        <Label className="text-xs">Data factură</Label>
                        <DateRangePicker
                            className="w-[230px]"
                            value={docRange}
                            onChange={(v) => applyFilter({ data_doc_from: v.from, data_doc_to: v.to })}
                            placeholder="Perioadă data"
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label className="text-xs">Data scadență</Label>
                        <DateRangePicker
                            className="w-[230px]"
                            value={scadentaRange}
                            onChange={(v) =>
                                applyFilter({ data_scadenta_from: v.from, data_scadenta_to: v.to })
                            }
                            placeholder="Perioadă scadență"
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label className="text-xs">Plată</Label>
                        <Select
                            value={filters.payment ?? 'all'}
                            onValueChange={(v) => applyFilter({ payment: v === 'all' ? null : v })}
                        >
                            <SelectTrigger className="min-h-11 w-[160px]">
                                <SelectValue placeholder="Plată" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Toate plățile</SelectItem>
                                <SelectItem value="paid">Plătite</SelectItem>
                                <SelectItem value="partial">Parțial</SelectItem>
                                <SelectItem value="unpaid">Neplătite</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <Button type="submit" variant="secondary" className="min-h-11">
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
                                <th className="px-4 py-3 text-right">Total</th>
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
