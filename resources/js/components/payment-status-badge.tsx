import { Badge } from '@/components/ui/badge';

export type PaymentStatus = 'paid' | 'partial' | 'unpaid';

const labels: Record<PaymentStatus, string> = {
    paid: 'Plătită',
    partial: 'Parțial',
    unpaid: 'Neplătită',
};

const classes: Record<PaymentStatus, string> = {
    paid: 'border-green-600/40 bg-green-50 text-green-700 dark:bg-green-500/10 dark:text-green-300',
    partial:
        'border-amber-600/40 bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
    unpaid: 'border-red-600/40 bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300',
};

export default function PaymentStatusBadge({
    status,
}: {
    status: PaymentStatus;
}) {
    return (
        <Badge variant="outline" className={classes[status]}>
            {labels[status]}
        </Badge>
    );
}
