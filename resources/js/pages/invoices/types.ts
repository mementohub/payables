import type { PaymentStatus } from '@/components/payment-status-badge';
import type {
    DepartmentRef,
    DepartmentShare,
    WorkflowStatus,
} from '@/types/approvals';
import type { Paginated } from '@/types/pagination';

export type TimelineEvent = {
    id: number;
    type: string;
    body: string | null;
    payload: Record<string, unknown> | null;
    created_at: string;
    user: { id: number; name: string } | null;
    department: { id: number; name: string } | null;
};

/** Where the invoice stands in the approval flow (InvoicePresenter::workflowPayload). */
export type Workflow = {
    approval_status: WorkflowStatus | null;
    approval_track: 'run' | 'invoice' | null;
    postponed_until: string | null;
    assignment_state: 'assigned' | 'partial' | 'unassigned' | null;
    department: DepartmentRef | null;
    departments: DepartmentShare[];
    final: { by: string | null; at: string; comment: string | null } | null;
};

/** One invoice line with the department it was routed to, and why. */
export type RoutedLine = {
    scv: number;
    account: string | null;
    loc: string | null;
    com_int: string | null;
    amount: number;
    department: string | null;
    channel: string | null;
    rule: string | null;
    detail: string | null;
    manual_by: string | null;
};

export type SourceInvoiceRef = {
    id: number;
    data_doc: string | null;
    tip_doc: string;
    nr_doc: string;
    company: { id: number; name: string } | null;
    real_supplier: { id: number; name: string; cui: string | null } | null;
};

export type ComIntMatch = {
    id: number;
    data_doc: string | null;
    tip_doc: string;
    nr_doc: string;
    moneda: string | null;
    val_mon: number;
    val_mon_tva: number;
    val_mon_paid: number;
    payment_status: PaymentStatus;
    data_inchidere: string | null;
    partner: { id: number; name: string; cui: string | null } | null;
};

export type BazaRef = {
    data_doc: string | null;
    tip_doc: string | null;
    nr_doc: string | null;
    invoice: {
        id: number;
        real_supplier: { id: number; name: string; cui: string | null } | null;
    } | null;
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
    val_mon_storno: number;
    /** What the ERP settled decides, unless a manual override is in force. */
    payment_status: PaymentStatus;
    payment_status_manual: PaymentStatus | null;
    comments_count: number;
    has_com_int_counterpart: boolean;
    source_invoice: SourceInvoiceRef | null;
    baza: BazaRef | null;
    workflow?: Workflow;
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
    department_id: number | null;
    partner_id: number | null;
};

export type CurrentUser = {
    id: number | null;
    name?: string | null;
    /** Departments the user approves for (all of them for an admin). */
    department_ids: number[];
    roles: string[];
};

export type IndexProps = {
    invoices: Paginated<InvoiceRow>;
    scope: Scope;
    filters: IndexFilters;
    companies: { id: number; name: string; last_synced_at?: string | null }[];
    syncRunning: boolean;
    currentUser: CurrentUser;
    departments: DepartmentRef[];
    selectedPartner: { id: number; name: string; cui: string | null } | null;
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

export type InvoicePaymentRequestRef = {
    id: number;
    kind: 'checkin' | 'invoice';
    status: string;
    status_label: string;
    level: 'ok' | 'warn' | 'crit' | null;
    requested_amount: number;
    requested_currency: string;
    difference_pct: number | null;
    checkin_from: string | null;
    checkin_to: string | null;
    created_by: string | null;
    created_at: string | null;
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
    val_mon_storno: number;
    /** What the ERP settled decides, unless a manual override is in force. */
    payment_status: PaymentStatus;
    /** The status the settled amounts alone give. */
    payment_status_erp: PaymentStatus;
    /** Set by the payments department for a payment the ERP does not have yet. */
    payment_status_manual: PaymentStatus | null;
    payment_status_updated_at: string | null;
    data_scadenta: string | null;
    data_inchidere: string | null;
    emitent: string | null;
    com_int: string | null;
    com_int_matches: ComIntMatch[];
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
    source_invoice: SourceInvoiceRef | null;
    baza: BazaRef | null;
    payment_requests: InvoicePaymentRequestRef[];
    workflow: Workflow;
    routing: RoutedLine[];
    timeline: TimelineEvent[];
};

export type ShowProps = {
    invoice: Invoice;
    activeCompany: { id: number; name: string };
    currentUser: CurrentUser;
    departments: DepartmentRef[];
};
