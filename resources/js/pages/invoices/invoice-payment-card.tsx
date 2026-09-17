import { Form } from '@inertiajs/react';
import {
    CircleAlert,
    CircleCheck,
    CircleDashed,
    RotateCcw,
} from 'lucide-react';
import PaymentStatusBadge from '@/components/payment-status-badge';
import type { PaymentStatus } from '@/components/payment-status-badge';
import { Button } from '@/components/ui/button';
import { update as updatePaymentStatusRoute } from '@/routes/invoices/payment-status';
import type { CurrentUser } from './types';

const order: PaymentStatus[] = ['unpaid', 'partial', 'paid'];

const labels: Record<PaymentStatus, string> = {
    paid: 'Marchează plătită',
    partial: 'Marchează parțial',
    unpaid: 'Marchează neplătită',
};

const erpLabels: Record<PaymentStatus, string> = {
    paid: 'achitată integral',
    partial: 'achitată parțial',
    unpaid: 'neachitată',
};

const Icons: Record<PaymentStatus, typeof CircleCheck> = {
    paid: CircleCheck,
    partial: CircleAlert,
    unpaid: CircleDashed,
};

const colorClasses: Record<PaymentStatus, string> = {
    paid: 'border-green-600/40 bg-green-50 text-green-700 hover:bg-green-100 dark:bg-green-500/10 dark:text-green-300 dark:hover:bg-green-500/20',
    partial:
        'border-amber-600/40 bg-amber-50 text-amber-700 hover:bg-amber-100 dark:bg-amber-500/10 dark:text-amber-300 dark:hover:bg-amber-500/20',
    unpaid: 'border-red-600/40 bg-red-50 text-red-700 hover:bg-red-100 dark:bg-red-500/10 dark:text-red-300 dark:hover:bg-red-500/20',
};

/**
 * The payment status of a received invoice. It follows the amounts the ERP
 * settled; the payments department can override it while the ERP has not
 * recorded a payment yet, and the override drops away once the ERP settles
 * the invoice.
 */
export function InvoicePaymentCard({
    invoiceId,
    status,
    erpStatus,
    manualStatus,
    updatedAt,
    currentUser,
}: {
    invoiceId: number;
    status: PaymentStatus;
    erpStatus: PaymentStatus;
    manualStatus: PaymentStatus | null;
    updatedAt: string | null;
    currentUser: CurrentUser;
}) {
    const canEdit = currentUser.plati_department_ids.length > 0;
    const overridden = status !== erpStatus;

    const updatedLabel = updatedAt
        ? new Intl.DateTimeFormat('ro-RO', {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(updatedAt))
        : null;

    return (
        <div className="space-y-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-sm font-semibold text-muted-foreground uppercase">
                    Status plată
                </h2>
                <PaymentStatusBadge status={status} />
            </div>

            <p className="text-xs text-muted-foreground">
                În ERP: {erpLabels[erpStatus]}.{' '}
                {overridden
                    ? `Marcat manual ${erpLabels[status]}, pentru o plată pe care ERP-ul nu o are încă.`
                    : 'Statusul urmează sumele decontate în ERP.'}
                {updatedLabel && manualStatus
                    ? ` Marcat la ${updatedLabel}.`
                    : ''}
            </p>

            {!canEdit ? (
                <p className="text-xs text-muted-foreground">
                    Doar membrii departamentului de plăți pot modifica statusul.
                </p>
            ) : (
                <div className="flex flex-wrap gap-2">
                    {order
                        .filter((s) => s !== manualStatus)
                        .map((target) => {
                            const Icon = Icons[target];

                            return (
                                <Form
                                    key={target}
                                    {...updatePaymentStatusRoute.form(
                                        invoiceId,
                                    )}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing }) => (
                                        <>
                                            <input
                                                type="hidden"
                                                name="status"
                                                value={target}
                                            />
                                            <Button
                                                type="submit"
                                                size="sm"
                                                variant="outline"
                                                disabled={processing}
                                                className={`h-8 gap-1.5 ${colorClasses[target]}`}
                                            >
                                                <Icon className="size-3.5" />
                                                {labels[target]}
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            );
                        })}

                    {manualStatus && (
                        <Form
                            {...updatePaymentStatusRoute.form(invoiceId)}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="status"
                                        value="auto"
                                    />
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="ghost"
                                        disabled={processing}
                                        className="h-8 gap-1.5"
                                    >
                                        <RotateCcw className="size-3.5" />
                                        Urmează ERP-ul
                                    </Button>
                                </>
                            )}
                        </Form>
                    )}
                </div>
            )}
        </div>
    );
}
