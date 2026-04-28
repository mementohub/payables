import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { PaginatedLink } from '@/types/pagination';

export default function Pagination({ links }: { links: PaginatedLink[] }) {
    if (links.length <= 3) {
        return null;
    }

    const prev = links[0];
    const next = links[links.length - 1];
    const pageLinks = links.slice(1, -1);
    const active = pageLinks.find((l) => l.active);

    const baseLink =
        'inline-flex h-9 min-w-9 items-center justify-center rounded-md border border-sidebar-border/70 px-3 text-sm transition-colors dark:border-sidebar-border';
    const disabledLink = 'pointer-events-none opacity-40';
    const activeLink = 'border-primary bg-primary text-primary-foreground';

    return (
        <nav className="flex w-full items-center justify-center gap-1 sm:w-auto">
            <Link
                href={prev.url ?? ''}
                preserveScroll
                preserveState
                aria-label="Pagina anterioară"
                className={cn(baseLink, !prev.url && disabledLink)}
            >
                <ChevronLeft className="size-4" />
            </Link>

            <div className="flex items-center gap-1 sm:hidden">
                <span className={cn(baseLink, activeLink)}>
                    {active?.label ?? '—'}
                </span>
            </div>

            <div className="hidden flex-wrap items-center gap-1 sm:flex">
                {pageLinks.map((link, i) => (
                    <Link
                        key={`${link.label}-${i}`}
                        href={link.url ?? ''}
                        preserveScroll
                        preserveState
                        className={cn(
                            baseLink,
                            link.active && activeLink,
                            !link.url && disabledLink,
                        )}
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                ))}
            </div>

            <Link
                href={next.url ?? ''}
                preserveScroll
                preserveState
                aria-label="Pagina următoare"
                className={cn(baseLink, !next.url && disabledLink)}
            >
                <ChevronRight className="size-4" />
            </Link>
        </nav>
    );
}
