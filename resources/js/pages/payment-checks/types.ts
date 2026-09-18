import type { WorkflowStatus } from '@/types/approvals';

export type EtripBase = {
    key: string;
    label: string;
    suppliers_synced_at: string | null;
};

export type EtripSupplierOption = {
    code: string;
    name: string;
    currency: string | null;
    partner_id: number | null;
};

export type CheckinCategory = 'hotel' | 'all' | 'transfer' | 'other';

export type CheckinLevel = 'ok' | 'warn' | 'crit';

export type CheckinTotal = {
    currency: string;
    cost: number;
    items: number;
    bookings: number;
};

export type CheckinBreakdownRow = {
    name?: string;
    date?: string;
    product_type?: number;
    label?: string;
    currency: string;
    cost: number;
    bookings: number;
};

export type CheckinLine = {
    booking: number;
    item: number;
    lead: string | null;
    product_type: number;
    product_label: string;
    category: CheckinCategory;
    start_date: string;
    nights: number | null;
    hotel: string | null;
    room: string | null;
    meal: string | null;
    transfer: string | null;
    service: string;
    pax: number;
    currency: string;
    cost: number;
};

export type CheckinRequested = {
    amount: number;
    currency: string;
    compared_amount: number | null;
    compared_currency: string;
    rate: { from: string; to: string; value: number; date: string } | null;
    etrip: number;
    diff: number | null;
    diff_pct: number | null;
    level: CheckinLevel;
    message: string;
};

export type CheckinCheck = {
    supplier: {
        id: number;
        code: string;
        name: string;
        currency: string | null;
    };
    from: string;
    to: string;
    category: CheckinCategory;
    totals: CheckinTotal[];
    items: number;
    bookings: number;
    by_hotel: CheckinBreakdownRow[];
    by_day: CheckinBreakdownRow[];
    by_product: CheckinBreakdownRow[];
    lines: CheckinLine[];
    lines_total: number;
    requested: CheckinRequested | null;
};

export type ExpectedSupplier = {
    supplier_code: string;
    supplier_name: string | null;
    currency: string | null;
    cost: number;
    bookings: number;
    items: number;
    partner_id: number | null;
};

export type ExpectedPayload = {
    days: number;
    from: string;
    to: string;
    cached_at: string;
    suppliers: ExpectedSupplier[];
};

export type Filters = {
    view: CheckView;
    connection: string | null;
    supplier: string | null;
    from: string;
    to: string;
    category: CheckinCategory;
    amount: string | null;
    currency: string | null;
    period_from: string | null;
    period_to: string | null;
};

export type Props = {
    bases: EtripBase[];
    company_id: number | null;
    categories: Record<CheckinCategory, string>;
    windows: number[];
    filters: Filters;
};

export type CheckView = 'checkin' | 'invoices';

export type ReconciliationSupplier = {
    id: number;
    code: string;
    name: string;
    country: string | null;
    currency: string | null;
    active: boolean;
    balance_due: string | null;
    balance_due_days: number | null;
    self_billing: boolean;
    vat_no: string | null;
    web_access: boolean;
    secondary_omc: boolean;
    created_at: string | null;
    partner_id: number | null;
    sources: { source: string | null; services: number }[];
    manual: boolean;
};

export type ReconciliationKpi = {
    currency: string;
    services: number;
    cost: number;
    billed: number;
    diff: number;
    diff_pct: number | null;
    unbilled_cost: number;
    invoiced: number;
    invoices: number;
    open: number;
    open_unknown: number;
    overdue: number;
    future_cost: number;
    future_services: number;
};

export type ReconciliationMonth = {
    month: string;
    currency: string;
    services: number;
    cost: number;
    billed: number;
    diff: number;
    diff_pct: number | null;
    unbilled_services: number;
    unbilled_cost: number;
    status: 'closed' | 'billing';
    alert: boolean;
};

export type ReconciliationType = {
    product_type: number;
    label: string;
    currency: string;
    services: number;
    cost: number;
    billed: number;
    diff: number;
    billed_pct: number | null;
    unbilled_services: number;
    unbilled_cost: number;
    window_cost: number;
    window_billed_pct: number | null;
    alert: boolean;
};

export type ReconciliationFuture = {
    month: string;
    currency: string;
    services: number;
    cost: number;
    billed: number;
};

export type ReconciliationInvoice = {
    id: number;
    number: string;
    date: string;
    currency: string;
    amount: number;
    paid: number | null;
    open: number | null;
    due_date: string | null;
    due: string | null;
    due_source: 'invoice' | 'term' | 'omc' | null;
    days_overdue: number;
    finalized: boolean;
    good_for_payment: boolean;
    lines: number;
    bookings: number;
    checkin_from: string | null;
    checkin_to: string | null;
    etrip_cost: number | null;
    correction: boolean;
    invalid_due: boolean;
    omc: {
        id: number;
        nr_doc: string;
        amount: number;
        approval_status: WorkflowStatus | null;
    } | null;
};

export type ReconciliationAlertKind =
    | 'types'
    | 'months'
    | 'overdue'
    | 'draft'
    | 'invalid_due'
    | 'omc_missing'
    | 'unlinked';

export type Reconciliation = {
    supplier: ReconciliationSupplier;
    from: string;
    to: string;
    today: string;
    kpis: ReconciliationKpi[];
    months: ReconciliationMonth[];
    types: ReconciliationType[];
    future: ReconciliationFuture[];
    invoices: ReconciliationInvoice[];
    invoices_total: number;
    invoice_totals: {
        currency: string;
        invoices: number;
        amount: number;
        paid: number;
        open: number;
        unpaid: number;
        unknown: number;
        etrip_cost: number;
    }[];
    payments_source: 'etrip' | 'omc';
    payments: {
        currency: string;
        payments: number;
        paid: number;
        first: string | null;
        last: string | null;
        allocated: number;
        difference: number;
    }[];
    omc: {
        partner_id: number | null;
        totals: {
            currency: string;
            invoices: number;
            amount: number;
            open: number;
        }[];
    } | null;
    alerts: {
        kind: ReconciliationAlertKind;
        level: CheckinLevel;
        count: number;
        detail: string;
    }[];
    thresholds: {
        month_pct: number;
        type_min_billed_pct: number;
        type_window_months: number;
    };
};
