import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
};

export type NavGroup = {
    title: string;
    icon?: LucideIcon | null;
    children: NavItem[];
};

export type NavItemOrGroup = NavItem | NavGroup;

export function isNavGroup(item: NavItemOrGroup): item is NavGroup {
    return 'children' in item && Array.isArray((item as NavGroup).children);
}
