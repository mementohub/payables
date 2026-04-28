import { Link, usePage } from '@inertiajs/react';
import {
    Building2,
    Contact,
    FileCheck2,
    FileInput,
    FileOutput,
    FileText,
    Landmark,
    LayoutGrid,
    ShieldCheck,
    Sparkles,
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
import { dashboard } from '@/routes';
import { index as aiChatIndex } from '@/routes/ai-chat';
import { index as bankStatementsIndex } from '@/routes/bank-statements';
import { index as companiesIndex } from '@/routes/companies';
import { index as departmentsIndex } from '@/routes/departments';
import { index as eInvoicesIndex } from '@/routes/e-invoices';
import {
    emise as facturiEmise,
    primite as facturiPrimite,
} from '@/routes/invoices';
import { clienti, furnizori } from '@/routes/partners';
import { index as usersIndex } from '@/routes/users';
import type { NavItem, NavItemOrGroup } from '@/types/navigation';
import { isNavGroup } from '@/types/navigation';

function buildMainNavItems(isMaster: boolean): NavItemOrGroup[] {
    const facturiChildren: NavItem[] = [
        ...(isMaster
            ? [
                  {
                      title: 'Emise',
                      href: facturiEmise(),
                      icon: FileOutput,
                  },
              ]
            : []),
        {
            title: 'Primite',
            href: facturiPrimite(),
            icon: FileInput,
        },
        {
            title: 'eFacturi',
            href: eInvoicesIndex(),
            icon: FileCheck2,
        },
    ];

    const parteneriChildren: NavItem[] = [
        {
            title: 'Furnizori',
            href: furnizori(),
            icon: Truck,
        },
        ...(isMaster
            ? [
                  {
                      title: 'Clienți',
                      href: clienti(),
                      icon: Users,
                  },
              ]
            : []),
    ];

    const items: NavItemOrGroup[] = [
        {
            title: 'Panou principal',
            href: dashboard(),
            icon: LayoutGrid,
        },
        {
            title: 'Facturi',
            icon: FileText,
            children: facturiChildren,
        },
        {
            title: 'Parteneri',
            icon: Contact,
            children: parteneriChildren,
        },
        {
            title: 'Extrase bancare',
            href: bankStatementsIndex(),
            icon: Landmark,
        },
    ];

    if (isMaster) {
        items.push({
            title: 'Asistent AI',
            href: aiChatIndex(),
            icon: Sparkles,
        });
    }

    return items.filter(
        (item) => !isNavGroup(item) || item.children.length > 0,
    );
}

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
    {
        title: 'Departamente',
        href: departmentsIndex(),
        icon: ShieldCheck,
    },
];

export function AppSidebar() {
    const { auth } = usePage<{
        auth: { user: { is_master?: boolean } | null };
    }>().props;
    const isMaster = Boolean(auth?.user?.is_master);
    const mainNavItems = buildMainNavItems(isMaster);

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
                <NavMain
                    items={settingsNavItems}
                    label="Setări"
                    className="mt-auto"
                />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
