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
    FileSignature,
    FileSearch,
    Landmark,
    LayoutGrid,
    ListChecks,
    Receipt,
    Route,
    ShieldCheck,
    Sparkles,
    Truck,
    Users,
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
import { index as maintenanceIndex } from '@/routes/maintenance';
import { furnizori } from '@/routes/partners';
import { index as paymentChecksIndex } from '@/routes/payment-checks';
import { index as invoiceChecksIndex } from '@/routes/payment-checks/invoices';
import { index as paymentRequestsIndex } from '@/routes/payment-requests';
import { index as paymentRunsIndex } from '@/routes/payment-runs';
import { index as cashFlowIndex } from '@/routes/reports/cash-flow';
import { index as dueIndex } from '@/routes/reports/due';
import { index as opexIndex } from '@/routes/reports/opex';
import { index as pnlIndex } from '@/routes/reports/pnl';
import { index as contractsIndex } from '@/routes/contracts';
import { index as routingIndex } from '@/routes/routing';
import { index as teamIndex } from '@/routes/team';
import { index as usersIndex } from '@/routes/users';
import type { Auth, Capabilities } from '@/types/auth';
import type { NavItemOrGroup } from '@/types/navigation';

/**
 * Meniul se desenează după ce are voie omul să vadă — aceleași reguli după
 * care rutele refuză, trimise din server. Ascunderea e pentru ordine, nu în
 * loc de autorizare.
 */
const mainNavItems = (
    pending: number,
    can: Partial<Capabilities>,
): NavItemOrGroup[] => [
    ...(can.dashboard
        ? [
              {
                  title: 'Panou principal',
                  href: dashboard(),
                  icon: LayoutGrid,
              },
          ]
        : []),
    ...(can.approvals
        ? [
              {
                  title: 'Aprobări',
                  href: approvalsIndex(),
                  icon: CheckCheck,
                  badge: pending,
              },
          ]
        : []),
    ...(can.payments
        ? [
              {
                  title: 'Rulaje de plată',
                  href: paymentRunsIndex(),
                  icon: Banknote,
              },
          ]
        : []),
    // Facturile se decid într-un singur loc, în Aprobări. „Primite” dubla
    // lista și acțiunile ei, așa că a ieșit din meniu; pagina rămâne, ca
    // arhivă căutabilă, legată din factura deschisă și din e-Facturi.
    ...(can.payments
        ? [
              {
                  title: 'e-Facturi (ANAF)',
                  href: eInvoicesIndex(),
                  icon: FileCheck2,
              },
          ]
        : []),
    ...(can.invoices
        ? [
              {
                  title: 'Furnizori',
                  href: furnizori(),
                  icon: Truck,
              },
          ]
        : []),
    ...(can.payments
        ? [
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
          ]
        : []),
    ...(can.payments
        ? [
              {
                  title: 'Extrase bancare',
                  href: bankStatementsIndex(),
                  icon: Landmark,
              },
          ]
        : []),
    ...(can.team
        ? [
              {
                  title: 'Echipa mea',
                  href: teamIndex(),
                  icon: Users,
              },
          ]
        : []),
    // Rapoartele arată cifrele companiei întregi, deci sunt ale Top
    // Management-ului. Rutele verifică același rol: ascunderea meniului e
    // pentru ordine, nu în loc de autorizare.
    ...(can.reports
        ? [
              {
                  title: 'Rapoarte',
                  icon: ChartColumn,
                  children: [
                      {
                          title: 'P&L',
                          href: pnlIndex(),
                          icon: ChartColumn,
                      },
                      {
                          title: 'Facturi pe categorii',
                          href: opexIndex(),
                          icon: Receipt,
                      },
                      {
                          title: 'WCFR 52 Weeks',
                          href: cashFlowIndex(),
                          icon: CalendarRange,
                      },
                      {
                          title: 'Scadențar',
                          href: dueIndex(),
                          icon: CalendarCheck,
                      },
                  ],
              },
          ]
        : []),
    // Contractele stau la rolul lor: cine nu-l are nici nu vede tabul.
    ...(can.contracts
        ? [
              {
                  title: 'Contracte',
                  href: contractsIndex(),
                  icon: FileSignature,
              },
          ]
        : []),
    // Asistentul răspunde cu cifrele companiei, deci stă sub aceeași regulă
    // ca Rapoartele.
    ...(can.reports
        ? [
              {
                  title: 'Asistent AI',
                  href: aiChatIndex(),
                  icon: Sparkles,
              },
          ]
        : []),
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
                <NavMain
                    items={mainNavItems(auth.pending ?? 0, auth.can ?? {})}
                />
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
