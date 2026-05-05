import type { Auth } from '@/types/auth';

export type CompanyRef = { id: number; name: string };

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            companies: CompanyRef[];
            activeCompany: CompanyRef | null;
            [key: string]: unknown;
        };
    }
}
