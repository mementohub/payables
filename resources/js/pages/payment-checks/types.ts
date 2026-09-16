export type EtripCompany = {
    id: number;
    name: string;
    etrip: string | null;
    suppliers_synced_at: string | null;
};

export type EtripSupplierOption = {
    id: number;
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
    supplier: { code: string; name: string; currency: string | null };
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
    company_id: number | null;
    supplier: string | null;
    from: string;
    to: string;
    category: CheckinCategory;
    amount: string | null;
    currency: string | null;
};

export type Props = {
    companies: EtripCompany[];
    categories: Record<CheckinCategory, string>;
    windows: number[];
    filters: Filters;
};
