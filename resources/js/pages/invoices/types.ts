import type { ApprovalStage } from '@/components/approval-status-badge';
import type { PaymentStatus } from '@/components/payment-status-badge';
import type { Paginated } from '@/types/pagination';

export type ResponsabilStep = {
    department_id: number;
    department_name: string;
    approved: boolean;
    approved_by: { id: number; name: string } | null;
    approved_at: string | null;
};

export type OrdonatorApproval = {
    department_id: number;
    department_name: string | null;
    approved_by: { id: number; name: string } | null;
    approved_at: string | null;
} | null;

export type Approval = {
    needs_approval: boolean;
    stage: ApprovalStage;
    responsabili_approved_at: string | null;
    is_fully_approved: boolean;
    fully_approved_at: string | null;
    responsabil_steps: ResponsabilStep[];
    ordonator: OrdonatorApproval;
};

export type InvoiceRow = {
    id: number;
    data_doc: string;
    data_scadenta: string | null;
    nr_doc: string;
    partener_type: 'furnizor' | 'client' | null;
    partner: { id: number; name: string; cui: string | null } | null;
    company: { id: number; name: string };
    moneda: string | null;
    val_mon: number;
    val_mon_tva: number;
    val_mon_paid: number;
    payment_status: PaymentStatus;
    approval?: Approval;
};

export type Scope = 'emise' | 'primite';

export type IndexFilters = {
    search: string | null;
    company_id: number | null;
    payment: string | null;
    data_doc_from: string | null;
    data_doc_to: string | null;
    data_scadenta_from: string | null;
    data_scadenta_to: string | null;
    approval: string | null;
    responsible_id: number | null;
};

export type CurrentUser = {
    id: number | null;
    responsabil_department_ids: number[];
    ordonator_department_ids: number[];
};

export type IndexProps = {
    invoices: Paginated<InvoiceRow>;
    scope: Scope;
    filters: IndexFilters;
    companies: { id: number; name: string }[];
    currentUser: CurrentUser;
    availableResponsibles: { id: number; name: string }[];
};

export type Detail = {
    id: number;
    scv: number;
    articol: string;
    detaliu_articol: string | null;
    cant: number;
    um: string | null;
    pret: number;
    proc_tva: number;
};

export type Payment = {
    id: number;
    data_doc: string;
    tip_doc: string;
    nr_doc: string;
    data_repartizare: string | null;
    val_fin: number;
    val_com: number;
    moneda: string | null;
    bank_statement: {
        id: number;
        line_id: number;
        data_extras: string;
        banca: string | null;
        iban: string;
    } | null;
};

export type Invoice = {
    id: number;
    data_doc: string;
    tip_doc: string;
    nr_doc: string;
    partener_type: 'furnizor' | 'client' | null;
    moneda: string | null;
    curs: number;
    val_mon: number;
    val_mon_tva: number;
    val_mon_paid: number;
    payment_status: PaymentStatus;
    data_scadenta: string | null;
    data_inchidere: string | null;
    emitent: string | null;
    payments: Payment[];
    partner: {
        id: number;
        name: string;
        cui: string | null;
        address: string | null;
        city: string | null;
        country: string | null;
        is_furnizor: boolean;
    } | null;
    company: { id: number; name: string };
    details: Detail[];
};
