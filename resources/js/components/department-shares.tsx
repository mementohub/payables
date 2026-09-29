import { DecisionBadge } from '@/components/workflow-status-badge';
import { formatDate, formatDateTime, formatMoney } from '@/lib/money';
import type { DepartmentShare } from '@/types/approvals';

/**
 * The departments that own an invoice, each with its share and decision;
 * the reason of a dispute or postponement shows on hover.
 */
export default function DepartmentShares({
    shares,
    currency,
}: {
    shares: DepartmentShare[];
    currency: string | null;
}) {
    if (shares.length === 0) {
        return (
            <span className="text-xs text-muted-foreground">
                Fără departament
            </span>
        );
    }

    return (
        <div className="flex flex-col gap-1">
            {shares.map((share) => (
                <div
                    key={share.id}
                    className="flex flex-wrap items-center gap-1.5 text-xs"
                    title={
                        [
                            share.comment,
                            share.postponed_until
                                ? `până la ${formatDate(share.postponed_until)}`
                                : null,
                            share.by ? `— ${share.by}` : null,
                        ]
                            .filter(Boolean)
                            .join(' ') || undefined
                    }
                >
                    <span className="font-medium">{share.name ?? '—'}</span>
                    {shares.length > 1 && (
                        <span className="text-muted-foreground tabular-nums">
                            {formatMoney(share.amount, currency)}
                        </span>
                    )}
                    <DecisionBadge
                        decision={share.status}
                        className="px-1.5 py-0 text-[10px]"
                    />
                    {/* Aprobare pe o parte din sumă: se spune cât, altfel
                        „aprobată” ar părea aprobată pe tot. */}
                    {share.approved_amount !== null &&
                        share.status === 'approved' && (
                            <span className="font-medium text-amber-700 tabular-nums dark:text-amber-300">
                                doar {formatMoney(share.approved_amount, currency)}
                            </span>
                        )}
                    {/* Cine a decis și când: pe hârtie, o aprobare fără nume
                        și fără oră nu e o aprobare. */}
                    {share.by && share.at && (
                        <span className="text-muted-foreground">
                            {share.by} · {formatDateTime(share.at)}
                        </span>
                    )}
                </div>
            ))}
        </div>
    );
}
