import { X } from 'lucide-react';
import type { ReactNode } from 'react';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

export function FilterField({
    label,
    active,
    onClear,
    children,
    className,
}: {
    label: string;
    active: boolean;
    onClear?: () => void;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('grid w-full gap-1 sm:w-auto', className)}>
            <Label className="text-xs">{label}</Label>
            <div
                data-active={active ? 'true' : 'false'}
                className="filter-field relative inline-flex w-full sm:w-auto data-[active=true]:[&_[data-slot=select-trigger]>svg:last-of-type]:hidden"
            >
                {children}
                {active && onClear && (
                    <button
                        type="button"
                        aria-label={`Șterge filtrul ${label}`}
                        onMouseDown={(e) => {
                            e.preventDefault();
                            e.stopPropagation();
                        }}
                        onPointerDown={(e) => {
                            e.preventDefault();
                            e.stopPropagation();
                        }}
                        onClick={(e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            onClear();
                        }}
                        className="absolute top-1/2 right-2 inline-flex size-6 -translate-y-1/2 cursor-pointer items-center justify-center rounded text-destructive transition-transform hover:scale-110 hover:bg-destructive/10 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <X className="size-3.5" />
                    </button>
                )}
            </div>
        </div>
    );
}

export const filterTriggerClass = (active: boolean) =>
    cn(
        'transition-colors',
        active ? 'border-solid border-primary/50' : 'border-dashed',
    );

export const filterInputClass = (active: boolean) =>
    cn(
        'transition-colors',
        active ? 'border-solid border-primary/50 pr-9' : 'border-dashed',
    );
