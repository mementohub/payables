import { Form } from '@inertiajs/react';
import { CircleAlert, CircleCheck, CircleDashed } from 'lucide-react';
import PaymentStatusBadge from '@/components/payment-status-badge';
import type {PaymentStatus} from '@/components/payment-status-badge';
import { Button } from '@/components/ui/button';
import { update as updatePaymentStatusRoute } from '@/routes/invoices/payment-status';
import type { CurrentUser } from './types';

const order: PaymentStatus[] = ['unpaid', 'partial', 'paid'];

const labels: Record<PaymentStatus, string> = {
    paid: 'Marchează plătită',
    partial: 'Marchează parțial',
    unpaid: 'Marchează neplătită',
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

export function InvoicePaymentCard({
    invoiceId,
    status,
    updatedAt,
    currentUser,
}: {
    invoiceId: number;
    status: PaymentStatus;
    updatedAt: string | null;
    currentUser: CurrentUser;
}) {
    const canEdit = currentUser.plati_department_ids.length > 0;

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

            {updatedLabel && (
                <p className="text-xs text-muted-foreground">
                    Actualizat la {updatedLabel}
                </p>
            )}

            {!canEdit ? (
                <p className="text-xs text-muted-foreground">
                    Doar membrii departamentului de plăți pot modifica statusul.
                </p>
            ) : (
                <div className="flex flex-wrap gap-2">
                    {order
                        .filter((s) => s !== status)
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
                </div>
            )}
        </div>
    );
}
