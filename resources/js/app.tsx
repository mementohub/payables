import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import { useFlashToast } from '@/hooks/use-flash-toast';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

function FlashToastBridge() {
    useFlashToast();

    return null;
}

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    /*
     * Fiecare pagină își pune singură `AppLayout`, ca să-i dea și firimiturile
     * de navigare. Un layout pus și de aici l-ar desena a doua oară: două bare
     * de sus, cu două butoane de pliat meniul. Paginile de setări sunt
     * excepția: ele nu se învelesc singure, așa că primesc ambele straturi
     * de aici.
     */
    layout: (name) =>
        name.startsWith('settings/') ? [AppLayout, SettingsLayout] : undefined,
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                <FlashToastBridge />
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#ff4200',
    },
});

// This will set light / dark mode on load...
initializeTheme();
