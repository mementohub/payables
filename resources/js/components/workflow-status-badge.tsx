import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { DepartmentDecision, WorkflowStatus } from '@/types/approvals';

const labels: Record<WorkflowStatus, string> = {
    routing: 'De rutat',
    department: 'La departamente',
    final: 'La Top Management',
    approved: 'Bun de plată',
    disputed: 'Contestată',
    postponed: 'Amânată',
};

const decisionLabels: Record<DepartmentDecision, string> = {
    pending: 'Așteaptă',
    approved: 'Aprobat',
    disputed: 'Contestat',
    postponed: 'Amânat',
};

const tone = {
    neutral: 'border-sidebar-border/70 text-muted-foreground',
    info: 'border-sky-600/40 bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
    warn: 'border-amber-600/40 bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
    good: 'border-green-600/40 bg-green-50 text-green-700 dark:bg-green-500/10 dark:text-green-300',
    bad: 'border-red-600/40 bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300',
    violet: 'border-violet-600/40 bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300',
};

const statusTone: Record<WorkflowStatus, keyof typeof tone> = {
    routing: 'violet',
    department: 'warn',
    final: 'info',
    approved: 'good',
    disputed: 'bad',
    postponed: 'neutral',
};

const decisionTone: Record<DepartmentDecision, keyof typeof tone> = {
    pending: 'warn',
    approved: 'good',
    disputed: 'bad',
    postponed: 'neutral',
};

/** Where an invoice stands in the approval flow. */
export default function WorkflowStatusBadge({
    status,
    className,
}: {
    status: WorkflowStatus | null;
    className?: string;
}) {
    if (status === null) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <Badge
            variant="outline"
            className={cn(tone[statusTone[status]], className)}
        >
            {labels[status]}
        </Badge>
    );
}

/** One department's decision on its share of an invoice. */
export function DecisionBadge({
    decision,
    className,
}: {
    decision: DepartmentDecision;
    className?: string;
}) {
    return (
        <Badge
            variant="outline"
            className={cn(tone[decisionTone[decision]], className)}
        >
            {decisionLabels[decision]}
        </Badge>
    );
}

export const workflowStatusLabels = labels;
