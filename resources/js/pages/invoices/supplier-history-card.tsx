import { Link } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import PaymentStatusBadge from '@/components/payment-status-badge';
import WorkflowStatusBadge from '@/components/workflow-status-badge';
import { formatDate, formatMoney } from '@/lib/money';
import { show as invoiceShow } from '@/routes/invoices';
import type { SupplierHistory } from './types';

/**
 * Ce a mai trimis furnizorul până acum, lângă factura din față.
 *
 * Fișa completă a furnizorului e a Financiarului, iar omul care aprobă n-are
 * acces la ea — dar are nevoie să vadă dacă factura asta e una obișnuită
 * pentru furnizorul ăsta sau o excepție.
 */
export function SupplierHistoryCard({
    supplier,
    currentInvoiceId,
}: {
    supplier: SupplierHistory;
    currentInvoiceId: number;
}) {
    const { totals } = supplier;

    return (
        <div className="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
            <div className="flex flex-wrap items-center justify-between gap-2 bg-muted/50 px-4 py-2">
                <span className="text-sm font-semibold">
                    Istoricul furnizorului
                </span>
                {supplier.url && (
                    <Link
                        href={supplier.url}
                        className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:underline"
                    >
                        Fișa furnizorului
                        <ExternalLink className="size-3" />
                    </Link>
                )}
            </div>

            <div className="space-y-3 p-4 text-sm">
                <div className="flex flex-wrap gap-x-6 gap-y-1 text-xs text-muted-foreground">
                    <span>
                        <span className="font-medium text-foreground tabular-nums">
                            {totals.invoices}
                        </span>{' '}
                        {totals.invoices === 1 ? 'factură' : 'facturi'} primite
                    </span>
                    <span>
                        facturat{' '}
                        <span className="font-medium text-foreground tabular-nums">
                            {formatMoney(totals.billed_lei, 'RON')}
                        </span>
                    </span>
                    <span>
                        neplătit{' '}
                        <span className="font-medium text-foreground tabular-nums">
                            {formatMoney(totals.unpaid_lei, 'RON')}
                        </span>
                    </span>
                    {totals.first_doc && (
                        <span>
                            din {formatDate(totals.first_doc)} până la{' '}
                            {formatDate(totals.last_doc)}
                        </span>
                    )}
                </div>

                {supplier.invoices.length === 0 ? (
                    <p className="text-xs text-muted-foreground">
                        Prima factură de la acest furnizor.
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[640px] text-sm">
                            <thead className="text-left text-xs text-muted-foreground uppercase">
                                <tr>
                                    <th className="py-1 pr-3">Factură</th>
                                    <th className="py-1 pr-3">
                                        Data / Scadență
                                    </th>
                                    <th className="py-1 pr-3 text-right">
                                        Valoare
                                    </th>
                                    <th className="py-1 pr-3 text-right">
                                        Rest
                                    </th>
                                    <th className="py-1 pr-3">Plată</th>
                                    <th className="py-1">Aprobare</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                                {supplier.invoices.map((row) => (
                                    <tr
                                        key={row.id}
                                        className={
                                            row.id === currentInvoiceId
                                                ? 'bg-muted/40'
                                                : undefined
                                        }
                                    >
                                        <td className="py-1.5 pr-3">
                                            <Link
                                                href={invoiceShow(row.id)}
                                                className="font-medium hover:underline"
                                            >
                                                {row.nr_doc}
                                            </Link>
                                            <span className="ml-1 text-xs text-muted-foreground">
                                                {row.tip_doc}
                                            </span>
                                        </td>
                                        <td className="py-1.5 pr-3 text-xs whitespace-nowrap tabular-nums">
                                            {formatDate(row.data_doc)}
                                            <span className="text-muted-foreground">
                                                {row.data_scadenta
                                                    ? ` · ${formatDate(row.data_scadenta)}`
                                                    : ''}
                                            </span>
                                        </td>
                                        <td className="py-1.5 pr-3 text-right whitespace-nowrap tabular-nums">
                                            {formatMoney(
                                                row.val_mon,
                                                row.moneda,
                                            )}
                                        </td>
                                        <td className="py-1.5 pr-3 text-right whitespace-nowrap tabular-nums">
                                            {row.outstanding > 0.01
                                                ? formatMoney(
                                                      row.outstanding,
                                                      row.moneda,
                                                  )
                                                : '—'}
                                        </td>
                                        <td className="py-1.5 pr-3">
                                            <PaymentStatusBadge
                                                status={row.payment_status}
                                            />
                                        </td>
                                        <td className="py-1.5">
                                            {row.approval_status ? (
                                                <WorkflowStatusBadge
                                                    status={row.approval_status}
                                                />
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
                )}
            </div>
        </div>
    );
}
