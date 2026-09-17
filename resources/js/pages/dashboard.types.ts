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
    kind: 'actual' | 'forecast';
    incoming: number;
    outgoing: number;
    balance: number | null;
};

export type CashflowSeries = {
    built_at: string | null;
    points: CashflowPoint[];
};

export type Filters = {
    from: string | null;
    to: string | null;
};

export type Props = {
    filters: Filters;
    paymentBreakdown?: PaymentState[];
    agingBuckets?: AgingBucket[];
    topOverdueSuppliers?: TopSupplier[];
    cashflow?: CashflowSeries;
};
