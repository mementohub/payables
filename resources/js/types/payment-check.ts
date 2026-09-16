export type PaymentCheckLevel = 'ok' | 'warn' | 'crit';

export type PaymentCheckInvoice = {
    id: number;
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

export type PaymentCheck = {
    open: PaymentCheckInvoice[];
    open_totals: { moneda: string; count: number; rest: number }[];
    first_due: { date: string; days: number; overdue: boolean } | null;
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
};
