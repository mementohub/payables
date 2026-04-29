import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, ExternalLink, Landmark } from 'lucide-react';
import PaymentStatusBadge from '@/components/payment-status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { show as bankStatementShow } from '@/routes/bank-statements';
import {
    emise as facturiEmise,
    primite as facturiPrimite,
} from '@/routes/invoices';
import { show as partnerShow } from '@/routes/partners';
import type { Invoice } from './types';

function formatAmount(value: number, currency: string | null) {
    return `${new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)} ${currency ?? ''}`.trim();
}

export default function InvoiceShow({ invoice }: { invoice: Invoice }) {
    return (
        <>
            <Head title={`Factura ${invoice.nr_doc}`} />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div>
                    <Button asChild variant="ghost" size="sm">
                        <Link
                            href={
                                invoice.partener_type === 'furnizor'
                                    ? facturiPrimite()
                                    : facturiEmise()
                            }
                        >
                            <ArrowLeft />
                            Înapoi la{' '}
                            {invoice.partener_type === 'furnizor'
                                ? 'facturi primite'
                                : 'facturi emise'}
                        </Link>
                    </Button>
                </div>

                <div className="flex flex-col items-start justify-between gap-4 sm:flex-row sm:flex-wrap">
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-2xl font-semibold break-all sm:break-normal">
                                {invoice.nr_doc}
                            </h1>
                            <Badge
                                variant={
                                    invoice.partener_type === 'furnizor'
                                        ? 'secondary'
                                        : 'outline'
                                }
                            >
                                {invoice.tip_doc}
                            </Badge>
                            <PaymentStatusBadge
                                status={invoice.payment_status}
                            />
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {invoice.data_doc}
                            {invoice.data_scadenta
                                ? ` · scadență ${invoice.data_scadenta}`
                                : ''}
                            {invoice.data_inchidere
                                ? ` · închisă ${invoice.data_inchidere}`
                                : ''}
                        </p>
                    </div>
                    <div className="w-full rounded-xl border border-sidebar-border/70 bg-muted/30 p-3 text-left sm:w-auto sm:border-0 sm:bg-transparent sm:p-0 sm:text-right dark:border-sidebar-border">
                        <div className="text-xs text-muted-foreground">
                            Total
                        </div>
                        <div className="text-xl font-semibold tabular-nums">
                            {formatAmount(invoice.val_mon, invoice.moneda)}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            net{' '}
                            {formatAmount(
                                invoice.val_mon - invoice.val_mon_tva,
                                invoice.moneda,
                            )}{' '}
                            · TVA{' '}
                            {formatAmount(invoice.val_mon_tva, invoice.moneda)}
                        </div>
                        <div className="mt-1 text-xs">
                            <span className="text-muted-foreground">
                                {invoice.partener_type === 'furnizor'
                                    ? 'plătit'
                                    : 'încasat'}
                                :
                            </span>{' '}
                            <span className="font-medium tabular-nums">
                                {formatAmount(
                                    invoice.val_mon_paid,
                                    invoice.moneda,
                                )}
                            </span>
                            {invoice.payment_status === 'partial' && (
                                <>
                                    {' '}
                                    ·{' '}
                                    <span className="text-muted-foreground">
                                        rămas:
                                    </span>{' '}
                                    <span className="font-medium tabular-nums">
                                        {formatAmount(
                                            invoice.val_mon -
                                                invoice.val_mon_paid,
                                            invoice.moneda,
                                        )}
                                    </span>
                                </>
                            )}
                        </div>
                    </div>
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    <div className="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                        <h2 className="text-sm font-semibold text-muted-foreground uppercase">
                            Partener
                        </h2>
                        {invoice.partner ? (
                            <div className="mt-2 space-y-1 text-sm">
                                <div className="flex items-center gap-2">
                                    <span className="font-medium">
                                        {invoice.partner.name}
                                    </span>
                                    {invoice.partner.is_furnizor && (
                                        <Link
                                            href={partnerShow(
                                                invoice.partner.id,
                                            )}
                                            className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:underline"
                                        >
                                            <ExternalLink className="size-3" />
                                            deschide furnizor
                                        </Link>
                                    )}
                                </div>
                                {invoice.partner.cui && (
                                    <div>CUI: {invoice.partner.cui}</div>
                                )}
                                {(invoice.partner.city ||
                                    invoice.partner.country) && (
                                    <div className="text-muted-foreground">
                                        {[
                                            invoice.partner.city,
                                            invoice.partner.country,
                                        ]
                                            .filter(Boolean)
                                            .join(', ')}
                                    </div>
                                )}
                                {invoice.partner.address && (
                                    <div className="text-muted-foreground">
                                        {invoice.partner.address}
                                    </div>
                                )}
                            </div>
                        ) : (
                            <p className="mt-2 text-sm text-muted-foreground">
                                Niciun partener asociat.
                            </p>
                        )}
                    </div>
                    <div className="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                        <h2 className="text-sm font-semibold text-muted-foreground uppercase">
                            Companie
                        </h2>
                        <div className="mt-2 space-y-1 text-sm">
                            <div className="font-medium">
                                {invoice.company.name}
                            </div>
                            {invoice.emitent && (
                                <div className="text-muted-foreground">
                                    Emitent: {invoice.emitent}
                                </div>
                            )}
                            {invoice.moneda && invoice.curs > 0 && (
                                <div className="text-muted-foreground">
                                    Monedă: {invoice.moneda} (curs{' '}
                                    {invoice.curs})
                                </div>
                            )}
                        </div>
                    </div>
                </div>

                <div className="overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <div className="bg-muted/50 px-4 py-2 text-sm font-semibold">
                        Detalii
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[640px] text-sm">
                            <thead className="bg-muted/30 text-left text-xs text-muted-foreground uppercase">
                                <tr>
                                    <th className="w-12 px-4 py-2">#</th>
                                    <th className="px-4 py-2">Articol</th>
                                    <th className="px-4 py-2 text-right">
                                        Cantitate
                                    </th>
                                    <th className="px-4 py-2">UM</th>
                                    <th className="px-4 py-2 text-right">
                                        Preț
                                    </th>
                                    <th className="px-4 py-2 text-right">
                                        TVA %
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                                {invoice.details.length === 0 && (
                                    <tr>
                                        <td
                                            className="px-4 py-6 text-center text-muted-foreground"
                                            colSpan={6}
                                        >
                                            Fără detalii.
                                        </td>
                                    </tr>
                                )}
                                {invoice.details.map((row) => (
                                    <tr key={row.id}>
                                        <td className="px-4 py-2 text-muted-foreground">
                                            {row.scv}
                                        </td>
                                        <td className="px-4 py-2">
                                            <div className="font-medium">
                                                {row.articol}
                                            </div>
                                            {row.detaliu_articol && (
                                                <div className="text-xs text-muted-foreground">
                                                    {row.detaliu_articol}
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-4 py-2 text-right tabular-nums">
                                            {row.cant}
                                        </td>
                                        <td className="px-4 py-2">
                                            {row.um ?? '—'}
                                        </td>
                                        <td className="px-4 py-2 text-right tabular-nums">
                                            {formatAmount(
                                                row.pret,
                                                invoice.moneda,
                                            )}
                                        </td>
                                        <td className="px-4 py-2 text-right tabular-nums">
                                            {row.proc_tva ?? 0}%
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                <div className="overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <div className="flex items-center justify-between bg-muted/50 px-4 py-2 text-sm font-semibold">
                        <span>
                            {invoice.partener_type === 'furnizor'
                                ? 'Plăți efectuate'
                                : 'Încasări'}
                        </span>
                        <span className="text-xs font-normal text-muted-foreground">
                            {invoice.payments.length}{' '}
                            {invoice.payments.length === 1
                                ? 'tranzacție'
                                : 'tranzacții'}
                        </span>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[720px] text-sm">
                            <thead className="bg-muted/30 text-left text-xs text-muted-foreground uppercase">
                                <tr>
                                    <th className="w-32 px-4 py-2">
                                        Repartizare
                                    </th>
                                    <th className="w-28 px-4 py-2">
                                        Dată doc.
                                    </th>
                                    <th className="w-28 px-4 py-2">Tip</th>
                                    <th className="px-4 py-2">Număr</th>
                                    <th className="px-4 py-2 text-right">
                                        Alocat (monedă factură)
                                    </th>
                                    <th className="px-4 py-2 text-right">
                                        Plătit (monedă plată)
                                    </th>
                                    <th className="px-4 py-2">Extras</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                                {invoice.payments.length === 0 && (
                                    <tr>
                                        <td
                                            className="px-4 py-6 text-center text-muted-foreground"
                                            colSpan={7}
                                        >
                                            {invoice.partener_type ===
                                            'furnizor'
                                                ? 'Nicio plată înregistrată.'
                                                : 'Nicio încasare înregistrată.'}
                                        </td>
                                    </tr>
                                )}
                                {invoice.payments.map((payment) => (
                                    <tr key={payment.id}>
                                        <td className="px-4 py-2">
                                            {payment.data_repartizare ?? '—'}
                                        </td>
                                        <td className="px-4 py-2 text-muted-foreground">
                                            {payment.data_doc}
                                        </td>
                                        <td className="px-4 py-2">
                                            <Badge variant="secondary">
                                                {payment.tip_doc}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-2 font-medium">
                                            {payment.nr_doc}
                                        </td>
                                        <td className="px-4 py-2 text-right tabular-nums">
                                            {formatAmount(
                                                payment.val_com,
                                                invoice.moneda,
                                            )}
                                        </td>
                                        <td className="px-4 py-2 text-right tabular-nums">
                                            {formatAmount(
                                                payment.val_fin,
                                                payment.moneda,
                                            )}
                                        </td>
                                        <td className="px-4 py-2">
                                            {payment.bank_statement ? (
                                                <Link
                                                    href={`${bankStatementShow(payment.bank_statement.id).url}#line-${payment.bank_statement.line_id}`}
                                                    className="inline-flex items-center gap-1.5 text-primary hover:underline"
                                                >
                                                    <Landmark className="size-3.5" />
                                                    <span>
                                                        {
                                                            payment
                                                                .bank_statement
                                                                .data_extras
                                                        }
                                                    </span>
                                                    <span className="text-xs text-muted-foreground">
                                                        {payment.bank_statement
                                                            .banca ??
                                                            payment
                                                                .bank_statement
                                                                .iban}
                                                    </span>
                                                </Link>
                                            ) : (
                                                <span className="text-xs text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </>
    );
}

function InvoiceShowLayout({ children }: { children: React.ReactNode }) {
    const { invoice } = usePage<{ invoice: Invoice }>().props;
    const isFurnizor = invoice.partener_type === 'furnizor';

    return (
        <AppLayout
            breadcrumbs={[
                {
                    title: isFurnizor ? 'Facturi primite' : 'Facturi emise',
                    href: isFurnizor ? facturiPrimite() : facturiEmise(),
                },
            ]}
        >
            {children}
        </AppLayout>
    );
}

InvoiceShow.layout = (page: React.ReactNode) => (
    <InvoiceShowLayout>{page}</InvoiceShowLayout>
);
