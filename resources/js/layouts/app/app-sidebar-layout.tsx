import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            {/*
                `min-w-0`: fără el, zona de conținut e un element flex cu
                lățime minimă „auto”, adică nu coboară sub lățimea proprie a
                conținutului. Un tabel lat (P&L, WCFR) lățea toată pagina și
                împingea meniul în afara ecranului, iar `overflow-x-hidden`
                doar tăia tabelul în loc să-l lase să defileze singur.
            */}
            <AppContent variant="sidebar" className="min-w-0 overflow-x-hidden">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {children}
            </AppContent>
        </AppShell>
    );
}
