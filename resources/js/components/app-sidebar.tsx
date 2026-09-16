import { Link } from '@inertiajs/react';
import {
    Building2,
    CalendarCheck,
    CalendarRange,
    ChartColumn,
    ClipboardCheck,
    Contact,
    DatabaseZap,
    FileCheck2,
    FileInput,
    FileOutput,
    FileSearch,
    FileText,
    Landmark,
    LayoutGrid,
    ListChecks,
    Receipt,
    ShieldCheck,
    Sparkles,
    Truck,
    UserCog,
    Users,
    Wrench,
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
import { index as databaseStatusIndex } from '@/routes/database-status';
import { index as departmentsIndex } from '@/routes/departments';
import { index as eInvoicesIndex } from '@/routes/e-invoices';
import {
    emise as facturiEmise,
    primite as facturiPrimite,
} from '@/routes/invoices';
import { index as maintenanceIndex } from '@/routes/maintenance';
import { clienti, furnizori } from '@/routes/partners';
import { index as paymentChecksIndex } from '@/routes/payment-checks';
import { index as invoiceChecksIndex } from '@/routes/payment-checks/invoices';
import { index as paymentRequestsIndex } from '@/routes/payment-requests';
import { index as cashFlowIndex } from '@/routes/reports/cash-flow';
import { index as opexIndex } from '@/routes/reports/opex';
import { index as usersIndex } from '@/routes/users';
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
            {
                title: 'eFacturi',
                href: eInvoicesIndex(),
                icon: FileCheck2,
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
    {
        title: 'Verificare plăți',
        icon: ClipboardCheck,
        children: [
            {
                title: 'Check-in (eTrip)',
                href: paymentChecksIndex(),
                icon: CalendarCheck,
            },
            {
                title: 'Facturi (OMC)',
                href: invoiceChecksIndex(),
                icon: FileSearch,
            },
            {
                title: 'Registru cereri',
                href: paymentRequestsIndex(),
                icon: ListChecks,
            },
        ],
    },
    {
        title: 'Extrase bancare',
        href: bankStatementsIndex(),
        icon: Landmark,
    },
    {
        title: 'Rapoarte',
        icon: ChartColumn,
        children: [
            {
                title: 'OpEx',
                href: opexIndex(),
                icon: Receipt,
            },
            {
                title: 'WCFR 52 Weeks',
                href: cashFlowIndex(),
                icon: CalendarRange,
            },
        ],
    },
    {
        title: 'Asistent AI',
        href: aiChatIndex(),
        icon: Sparkles,
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
    {
        title: 'Departamente',
        href: departmentsIndex(),
        icon: ShieldCheck,
    },
    {
        title: 'Stare baze de date',
        href: databaseStatusIndex(),
        icon: DatabaseZap,
    },
    {
        title: 'Întreținere',
        href: maintenanceIndex(),
        icon: Wrench,
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
