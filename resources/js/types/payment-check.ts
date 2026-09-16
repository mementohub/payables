export type PaymentCheckLevel = 'ok' | 'warn' | 'crit';

export type PaymentCheckSource = 'omc' | 'local';

export type PaymentCheckInvoice = {
    /** Id of the locally synced copy, when there is one. */
    id: number | null;
    /** ERP document key (date | type | number), unique for the supplier. */
    key: string;
    tip_doc: string;
    nr_doc: string;
    data_doc: string;
    data_scadenta: string | null;
    days_to_due: number | null;
    moneda: string | null;
    val_mon: number;
    val_mon_paid: number;
    val_mon_storno: number;
    rest: number;
    payment_status: string;
    description: string | null;
    paid_at: string | null;
    accounts: string | null;
};

export type PaymentCheckLastInvoice = PaymentCheckInvoice & {
    total_lei: number;
    deviation_pct: number | null;
    level: PaymentCheckLevel | null;
};

export type PaymentCheckRequested = {
    amount: number;
    currency: string;
    open_sum: number;
    open_count: number;
    invoice: PaymentCheckInvoice | null;
    verdict: 'exact' | 'sum' | 'paid' | 'near' | 'missing';
    level: PaymentCheckLevel;
    message: string;
};

export type PaymentCheckSupplier = {
    name: string;
    cui: string | null;
    country: string | null;
    city: string | null;
    partner_id: number | null;
    accounts: string | null;
};

export type PaymentCheck = {
    open: PaymentCheckInvoice[];
    open_totals: { moneda: string; count: number; rest: number }[];
    first_due: { date: string; days: number; overdue: boolean } | null;
    recent: PaymentCheckInvoice[];
    pattern: {
        month: string;
        label: string;
        total_lei: number;
        count: number;
    }[];
    average_month_lei: number;
    average_invoice_lei: number;
    invoices_12m: number;
    last_invoice: PaymentCheckLastInvoice | null;
    currencies: string[];
    requested: PaymentCheckRequested | null;
    source: PaymentCheckSource;
    supplier: PaymentCheckSupplier;
};
