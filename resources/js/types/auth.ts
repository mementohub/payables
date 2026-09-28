export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    is_ordonator: boolean;
    roles: string[];
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

/** Ce are voie omul să vadă; meniul se desenează după asta. */
export type Capabilities = {
    dashboard: boolean;
    approvals: boolean;
    payments: boolean;
    invoices: boolean;
    routing: boolean;
    reports: boolean;
    team: boolean;
    admin: boolean;
};

export type Auth = {
    user: User;
    /** Invoices waiting for the user's decision. */
    pending?: number;
    can?: Partial<Capabilities>;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
