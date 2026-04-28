import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import type { PaginatedLink } from '@/types/pagination';

export default function Pagination({ links }: { links: PaginatedLink[] }) {
    if (links.length <= 3) {
        return null;
    }

    return (
        <nav className="flex flex-wrap items-center gap-1">
            {links.map((link, i) => (
                <Link
                    key={`${link.label}-${i}`}
                    href={link.url ?? ''}
                    preserveScroll
                    preserveState
                    className={cn(
                        'rounded-md border border-sidebar-border/70 px-3 py-1.5 text-sm dark:border-sidebar-border',
                        link.active &&
                            'border-primary bg-primary text-primary-foreground',
                        !link.url && 'pointer-events-none opacity-40',
                    )}
                    dangerouslySetInnerHTML={{ __html: link.label }}
                />
            ))}
        </nav>
    );
}
