import { Link } from '@inertiajs/react';
import {
    Building2,
    Contact,
    FileInput,
    FileOutput,
    FileText,
    LayoutGrid,
    Truck,
    UserCog,
    Users,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { index as companiesIndex } from '@/routes/companies';
import { emise as facturiEmise, primite as facturiPrimite } from '@/routes/invoices';
import { clienti, furnizori } from '@/routes/partners';
import { index as usersIndex } from '@/routes/users';
import { dashboard } from '@/routes';
import type { NavItemOrGroup } from '@/types/navigation';

const mainNavItems: NavItemOrGroup[] = [
    {
        title: 'Panou principal',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Facturi',
        icon: FileText,
        children: [
            {
                title: 'Emise',
                href: facturiEmise(),
                icon: FileOutput,
            },
            {
                title: 'Primite',
                href: facturiPrimite(),
                icon: FileInput,
            },
        ],
    },
    {
        title: 'Parteneri',
        icon: Contact,
        children: [
            {
                title: 'Furnizori',
                href: furnizori(),
                icon: Truck,
            },
            {
                title: 'Clienți',
                href: clienti(),
                icon: Users,
            },
        ],
    },
];

const settingsNavItems: NavItemOrGroup[] = [
    {
        title: 'Companii',
        href: companiesIndex(),
        icon: Building2,
    },
    {
        title: 'Utilizatori',
        href: usersIndex(),
        icon: UserCog,
    },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
                <NavMain items={settingsNavItems} label="Setări" className="mt-auto" />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
