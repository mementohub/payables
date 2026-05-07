import { companyColor } from '@/lib/company-color';
import { cn } from '@/lib/utils';

type Props = {
    id: number | null | undefined;
    name: string;
    className?: string;
    size?: 'sm' | 'md';
};

export default function CompanyBadge({
    id,
    name,
    className,
    size = 'sm',
}: Props) {
    const color = companyColor(id);

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border font-medium whitespace-nowrap',
                color.bg,
                color.text,
                color.border,
                size === 'sm' ? 'px-2 py-0.5 text-xs' : 'px-2.5 py-1 text-sm',
                className,
            )}
        >
            <span
                className={cn(
                    'shrink-0 rounded-full',
                    color.dot,
                    size === 'sm' ? 'size-1.5' : 'size-2',
                )}
            />
            <span className="truncate">{name}</span>
        </span>
    );
}
