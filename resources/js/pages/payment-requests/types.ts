export type RequestKind = 'checkin' | 'invoice';

export type RequestLevel = 'ok' | 'warn' | 'crit';

export type RequestStatus = 'pending' | 'payable' | 'disputed' | 'paid';

export type PaymentRequestRow = {
    id: number;
    created_at: string | null;
    kind: RequestKind;
    supplier_name: string;
    company: { id: number; name: string } | null;
    partner: { id: number; name: string } | null;
    reference: string | null;
    checkin_from: string | null;
    checkin_to: string | null;
    category: string | null;
    requested_amount: number;
    requested_currency: string;
    expected_amount: number | null;
    expected_currency: string | null;
    difference: number | null;
    difference_pct: number | null;
    level: RequestLevel | null;
    verdict: string | null;
    status: RequestStatus;
    status_label: string;
    note: string | null;
    created_by: string | null;
    status_updated_at: string | null;
    status_updated_by: string | null;
    invoices_count: number;
};

export type LinkedInvoice = {
    id: number;
    tip_doc: string;
    nr_doc: string;
    data_doc: string | null;
    data_scadenta: string | null;
    moneda: string | null;
    val_mon: number;
    rest: number;
    payment_status: string;
    is_fully_approved: boolean;
    responsabili_approved: boolean;
};

export type RequestEvent = {
    id: number;
    type:
        | 'created'
        | 'status_changed'
        | 'commented'
        | 'invoice_linked'
        | 'invoice_unlinked';
    body: string | null;
    payload: Record<string, unknown> | null;
    user: string | null;
    created_at: string | null;
};

export type PaymentRequestDetail = PaymentRequestRow & {
    etrip_supplier: {
        id: number;
        code: string;
        name: string;
        currency: string | null;
    } | null;
    snapshot: Record<string, unknown> | null;
    invoices: LinkedInvoice[];
    events: RequestEvent[];
};

export type Paginated<T> = {
    data: T[];
    from: number | null;
    to: number | null;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
};

export type IndexProps = {
    requests: Paginated<PaymentRequestRow>;
    filters: {
        status: RequestStatus | null;
        kind: RequestKind | null;
        search: string | null;
    };
    statuses: Record<RequestStatus, string>;
    counts: Record<RequestStatus, number>;
};

export type ShowProps = {
    request: PaymentRequestDetail;
    candidateInvoices: LinkedInvoice[];
    statuses: Record<RequestStatus, string>;
    categories: Record<string, string>;
    currentUser: { id: number | null; is_plati: boolean };
};
