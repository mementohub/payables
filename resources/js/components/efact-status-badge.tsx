import { Badge } from '@/components/ui/badge';

export type EFactStatus = 'pending' | 'processed' | 'error';

const labels: Record<EFactStatus, string> = {
    pending: 'Neprocesată',
    processed: 'Procesată',
    error: 'Eroare',
};

const classes: Record<EFactStatus, string> = {
    pending:
        'border-sky-600/40 bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
    processed:
        'border-green-600/40 bg-green-50 text-green-700 dark:bg-green-500/10 dark:text-green-300',
    error: 'border-red-600/40 bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300',
};

export default function EFactStatusBadge({ status }: { status: EFactStatus }) {
    return (
        <Badge variant="outline" className={classes[status]}>
            {labels[status]}
        </Badge>
    );
}
