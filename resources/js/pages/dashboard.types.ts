export type PaymentState = {
    state: 'paid' | 'partial' | 'unpaid' | 'overdue';
    label: string;
    count: number;
    total: number;
    outstanding: number;
};

export type AgingBucket = {
    bucket: string;
    count: number;
    outstanding: number;
};

export type TopSupplier = {
    partner_id: number | null;
    name: string;
    invoices: number;
    outstanding: number;
};

export type CashflowPoint = {
    week: string;
    week_start: string;
    incoming: number;
    outgoing: number;
};

export type Filters = {
    company_id: number | null;
    from: string | null;
    to: string | null;
    moneda: string;
};

export type Props = {
    filters: Filters;
    companies: { id: number; name: string }[];
    paymentBreakdown?: PaymentState[];
    agingBuckets?: AgingBucket[];
    topOverdueSuppliers?: TopSupplier[];
    cashflow?: CashflowPoint[];
};
