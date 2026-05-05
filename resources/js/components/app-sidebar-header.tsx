import { usePage } from '@inertiajs/react';
import ActiveCompanyBadge from '@/components/active-company-badge';
import { AppearanceToggle } from '@/components/appearance-toggle';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { companyColor } from '@/lib/company-color';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

type SharedProps = {
    activeCompany?: { id: number; name: string } | null;
};

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { activeCompany = null } = usePage<SharedProps>().props;
    const stripeColor = companyColor(activeCompany?.id ?? null);

    return (
        <div className="relative">
            <header className="flex h-16 shrink-0 items-center gap-3 border-b border-sidebar-border/50 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
                <div className="flex flex-1 flex-wrap items-center gap-2">
                    <SidebarTrigger className="-ml-1" />
                    <ActiveCompanyBadge />
                    <Breadcrumbs breadcrumbs={breadcrumbs} />
                </div>
                <AppearanceToggle />
            </header>
            <div
                className={cn(
                    'pointer-events-none absolute inset-x-0 top-full h-[3px]',
                    stripeColor.stripe,
                )}
                aria-hidden="true"
            />
        </div>
    );
}
