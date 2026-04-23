import { Badge } from '@/components/ui/badge';

export type ApprovalStage = 'ok' | 'supervisors_ok' | 'pending' | 'na';

const labels: Record<ApprovalStage, string> = {
    ok: 'Bun de plată',
    supervisors_ok: 'Așteaptă master',
    pending: 'Așteaptă supervizor',
    na: '—',
};

const classes: Record<ApprovalStage, string> = {
    ok: 'border-green-600/40 bg-green-50 text-green-700 dark:bg-green-500/10 dark:text-green-300',
    supervisors_ok: 'border-sky-600/40 bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
    pending: 'border-amber-600/40 bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
    na: 'border-sidebar-border/70 text-muted-foreground',
};

export default function ApprovalStatusBadge({ stage }: { stage: ApprovalStage }) {
    return (
        <Badge variant="outline" className={classes[stage]}>
            {labels[stage]}
        </Badge>
    );
}
