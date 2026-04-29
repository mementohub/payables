import type { PaymentStatus } from '@/components/payment-status-badge';
import type { Paginated } from '@/types/pagination';

export type ResponsabilDepartmentRef = {
    id: number;
    name: string;
};

export type ResponsabilDepartmentDetail = ResponsabilDepartmentRef & {
    type: string;
};

export type PartnerListItem = {
    id: number;
    name: string;
    cui: string | null;
    reg_com: string | null;
    city: string | null;
    country: string | null;
    phone: string | null;
    email: string | null;
    is_furnizor: boolean;
    is_client: boolean;
    invoices_count: number;
    company: { id: number; name: string };
    responsabil_departments: ResponsabilDepartmentRef[];
};

export type IndexProps = {
    partners: Paginated<PartnerListItem>;
    scope: 'furnizori' | 'clienti';
    filters: {
        search: string | null;
        company_id: number | null;
        department_ids: number[];
    };
    companies: { id: number; name: string }[];
    availableDepartments: { id: number; name: string }[];
};

export type BankAccount = {
    id: number;
    bank: string | null;
    iban: string;
    currency: string;
    is_default: boolean;
    is_discontinued: boolean;
};

export type PartnerDetail = {
    id: number;
    name: string;
    cui: string | null;
    reg_com: string | null;
    country: string | null;
    city: string | null;
    address: string | null;
    phone: string | null;
    email: string | null;
    is_furnizor: boolean;
    is_client: boolean;
    company: { id: number; name: string };
    bank_accounts: BankAccount[];
    responsabil_departments: ResponsabilDepartmentDetail[];
};

export type InvoiceRow = {
    id: number;
    data_doc: string;
    tip_doc: string;
    nr_doc: string;
    moneda: string | null;
    val_mon: number;
    val_mon_tva: number;
    val_mon_paid: number;
    payment_status: PaymentStatus;
    data_scadenta: string | null;
    data_inchidere: string | null;
};

export type InvoiceFilters = {
    search: string | null;
    tip_doc: string | null;
    from: string | null;
    to: string | null;
    payment: string | null;
};

export type AvailableDepartment = {
    id: number;
    name: string;
    type: string;
};

export type CurrencyTotal = {
    moneda: string | null;
    count: number;
    val_mon: number;
    val_mon_paid: number;
    sold: number;
};

export type OldestUnpaid = {
    id: number;
    tip_doc: string;
    nr_doc: string;
    data_doc: string | null;
    data_scadenta: string | null;
    days_overdue: number | null;
    val_mon: number;
    moneda: string | null;
};

export type PartnerStats = {
    totals: CurrencyTotal[];
    counts: {
        paid: number;
        partial: number;
        unpaid: number;
        total: number;
    };
    oldest_unpaid: OldestUnpaid | null;
    last_invoice_date: string | null;
    first_invoice_date: string | null;
};

export type ShowProps = {
    partner: PartnerDetail;
    invoices: Paginated<InvoiceRow>;
    invoiceFilters: InvoiceFilters;
    availableTipDocs: string[];
    availableDepartments: AvailableDepartment[];
    stats: PartnerStats;
};
