import { CircleAlert, CircleCheck, CircleX } from 'lucide-react';
import type { CheckinLevel } from '@/pages/payment-checks/types';

export const LEVEL_BORDER: Record<CheckinLevel, string> = {
    ok: 'border-t-emerald-600',
    warn: 'border-t-amber-500',
    crit: 'border-t-destructive',
};

export const LEVEL_TEXT: Record<CheckinLevel, string> = {
    ok: 'text-emerald-700 dark:text-emerald-500',
    warn: 'text-amber-600 dark:text-amber-400',
    crit: 'text-destructive',
};

export function fmt(value: number, decimals = 0): string {
    return new Intl.NumberFormat('ro-RO', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }).format(value);
}

export function dmy(value: string | null): string {
    if (!value) {
        return '—';
    }

    const [year, month, day] = value.split('-');

    return `${day}.${month}.${year}`;
}

export function VerdictIcon({ level }: { level: CheckinLevel }) {
    const className = `size-4 shrink-0 ${LEVEL_TEXT[level]}`;

    if (level === 'ok') {
        return <CircleCheck className={className} />;
    }

    if (level === 'warn') {
        return <CircleAlert className={className} />;
    }

    return <CircleX className={className} />;
}

export function Tile({
    label,
    value,
    detail,
    level,
}: {
    label: string;
    value: React.ReactNode;
    detail?: React.ReactNode;
    level?: CheckinLevel | null;
}) {
    return (
        <div
            className={`flex flex-col gap-1 rounded-xl border border-t-[3px] border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border ${
                level ? LEVEL_BORDER[level] : 'border-t-foreground'
            }`}
        >
            <span className="text-xs tracking-wide text-muted-foreground uppercase">
                {label}
            </span>
            <span
                className={`font-heading text-2xl leading-tight font-bold ${
                    level ? LEVEL_TEXT[level] : ''
                }`}
            >
                {value}
            </span>
            {detail && (
                <span className="text-sm text-muted-foreground">{detail}</span>
            )}
        </div>
    );
}
