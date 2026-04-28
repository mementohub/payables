export type CompanyListItem = {
    id: number;
    name: string;
    cui: string | null;
    db_host: string;
    db_port: string;
    db_database: string;
    partners_count: number;
    invoices_count: number;
    last_synced_at: string | null;
};

export type CompanyEditPayload = {
    id: number;
    name: string;
    cui: string | null;
    db_driver: string;
    db_host: string;
    db_port: string;
    db_database: string;
    db_username: string;
};
