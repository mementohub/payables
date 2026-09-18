import { Link, usePage } from '@inertiajs/react';
import {
    Banknote,
    Building2,
    CalendarCheck,
    CalendarRange,
    ChartColumn,
    CheckCheck,
    ClipboardCheck,
    DatabaseZap,
    FileCheck2,
    FileInput,
    FileSearch,
    FileText,
    Landmark,
    LayoutGrid,
    ListChecks,
    Receipt,
    Route,
    ShieldCheck,
    Sparkles,
    Truck,
    UserCog,
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
import { index as approvalsIndex } from '@/routes/approvals';
import { index as bankStatementsIndex } from '@/routes/bank-statements';
import { index as companiesIndex } from '@/routes/companies';
import { index as databaseStatusIndex } from '@/routes/database-status';
import { index as departmentsIndex } from '@/routes/departments';
import { index as eInvoicesIndex } from '@/routes/e-invoices';
import { primite as facturiPrimite } from '@/routes/invoices';
import { index as maintenanceIndex } from '@/routes/maintenance';
import { furnizori } from '@/routes/partners';
import { index as paymentChecksIndex } from '@/routes/payment-checks';
import { index as invoiceChecksIndex } from '@/routes/payment-checks/invoices';
import { index as paymentRequestsIndex } from '@/routes/payment-requests';
import { index as paymentRunsIndex } from '@/routes/payment-runs';
import { index as cashFlowIndex } from '@/routes/reports/cash-flow';
import { index as opexIndex } from '@/routes/reports/opex';
import { index as routingIndex } from '@/routes/routing';
import { index as usersIndex } from '@/routes/users';
import type { Auth } from '@/types/auth';
import type { NavItemOrGroup } from '@/types/navigation';

const mainNavItems = (pending: number): NavItemOrGroup[] => [
    {
        title: 'Panou principal',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Aprobări',
        href: approvalsIndex(),
        icon: CheckCheck,
        badge: pending,
    },
    {
        title: 'Rulaje de plată',
        href: paymentRunsIndex(),
        icon: Banknote,
    },
    {
        title: 'Facturi',
        icon: FileText,
        children: [
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
        title: 'Furnizori',
        href: furnizori(),
        icon: Truck,
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

/** Settings: all of them for an admin, the routing rules for Finance. */
const settingsNavItems = (roles: string[]): NavItemOrGroup[] => {
    const isAdmin = roles.includes('admin');
    const rules: NavItemOrGroup = {
        title: 'Reguli de rutare',
        href: routingIndex(),
        icon: Route,
    };

    if (isAdmin) {
        return [rules, ...adminNavItems];
    }

    return roles.includes('finance') ? [rules] : [];
};

const adminNavItems: NavItemOrGroup[] = [
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
    const { auth } = usePage<{ auth: Auth }>().props;

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
                <NavMain items={mainNavItems(auth.pending ?? 0)} />
                {settingsNavItems(auth.user?.roles ?? []).length > 0 && (
                    <NavMain
                        items={settingsNavItems(auth.user?.roles ?? [])}
                        label="Setări"
                        className="mt-auto"
                    />
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
