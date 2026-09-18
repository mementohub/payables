export type LineKind =
    | 'value'
    | 'subtotal'
    | 'total'
    | 'balance'
    | 'threshold'
    | 'text'
    | 'reference';

export type ReportLine = {
    code: string;
    key: string | null;
    /** The subtotal line this one belongs to (e.g. B10 for B10.1). */
    parent?: string | null;
    label: string;
    section: 'A' | 'B' | 'C' | 'D' | 'E' | 'F';
    kind: LineKind;
    scenario: boolean;
    note: string | null;
    values: (number | string)[];
    total: number | null;
};

export type LastYearRow = {
    week: string;
    ly_week: string;
    ly_in: number;
    ly_out: number;
    ly_out_partener?: number;
    ly_out_salarii?: number;
    ly_out_alte?: number;
    ly_in_alte?: number;
    ly_bal: number | null;
    ly_bal_open: number | null;
};

/** One past week as OMC recorded it, in lei; the current week is partial. */
export type HistoryRow = {
    week: string;
    partial: boolean;
    opening: number | null;
    in_partner: number;
    in_other: number;
    in: number;
    out_partner: number;
    out_salaries: number;
    out_other: number;
    out: number;
    net: number;
    adjustment: number | null;
    closing: number | null;
};

export type ReportKpis = {
    opening: number;
    closing_13: number;
    closing_52: number;
    min_closing: { value: number; week: string; index: number };
    weeks_below_minimum: number;
    weeks_below_comfort: number;
    in_13: number;
    out_13: number;
    in_52: number;
    out_52: number;
    receivables_existing: number;
    payables_existing: number;
    overdue_recent: Record<string, number>;
    overdue_old: Record<string, number>;
    beyond_horizon: Record<string, number>;
    suppliers_open: number;
    bookings: number;
    scenario_bookings: number;
    scenario_receipts?: number;
    charter_incoming?: number;
};

export type OpeningRow = {
    key: string;
    label: string;
    values: Record<string, number>;
};

export type OpeningDetail = {
    /** The day the position is stated at: the end of yesterday. */
    date: string | null;
    as_of: string;
    /** The closed balance each section is rolled forward from. */
    base?: {
        bank: string | null;
        cash: string | null;
        deposits: string | null;
    };
    /** True when OMC held no closed balance before yesterday. */
    fallback?: boolean;
    /** BNR rates OMC holds for that day. */
    rates?: Record<string, number>;
    currencies: string[];
    rows: OpeningRow[];
    by_currency: Record<string, number>;
    total: number;
};

export type ReceivableStructureRow = {
    segment: string;
    label: string;
    bucket: string;
    channel: string;
    currency: string;
    amount: number;
    lei: number;
    tranches: number;
};

export type PayableStructureRow = {
    category: string;
    label: string;
    currency: string;
    amount: number;
    lei: number;
    items: number;
};

export type NewSalesReceiptRow = {
    segment: string;
    label: string;
    currency: string;
    amount: number;
    lei: number;
    receipts: number;
};

export type OpexCategory = {
    key: string;
    label: string;
    rule: {
        type: 'monthly' | 'quarterly' | 'uniform';
        day?: number;
        months?: number[];
    };
    accounts: string[];
    monthly: number;
    override: number | null;
    computed: number | null;
    source: string;
};

export type CharterTerms = {
    rotation: string;
    taxes: string;
    deposit: string;
    settlement: string;
    invoicing: string;
    fuel: string;
    fx: string;
    penalty: string;
    cancellation: string;
    source: string;
    confidence: string;
};

/**
 * One contract as the stored report summarises it. A snapshot outlives the
 * code that wrote it, so anything added since may be missing from the one
 * being read.
 */
export type CharterSummary = {
    id: number;
    name: string;
    counterparty: string | null;
    season: string;
    status: string;
    direction: 'out' | 'in';
    in_cash_flow: boolean;
    operator: string | null;
    currency: string;
    flights: number;
    total_net: number;
    in_horizon: number;
    taxes: number;
    deposit: number;
    /** Absent in a snapshot built before the contracts carried their terms. */
    terms?: CharterTerms;
};

export type ReportPayload = {
    generated: string;
    today: string;
    week_start: string;
    weeks: string[];
    currency: string;
    fx: Record<string, number>;
    lines: ReportLine[];
    coverage: ('existing' | 'scenario')[];
    lastyear: LastYearRow[];
    /** Absent in a snapshot built before the history was kept. */
    history?: HistoryRow[];
    kpis: ReportKpis;
    opening: OpeningDetail;
    structure: {
        receivables: ReceivableStructureRow[];
        payables: PayableStructureRow[];
        new_sales_receipts?: NewSalesReceiptRow[];
        new_sales_costs?: PayableStructureRow[];
        suppliers_open: {
            total: number;
            overdue: number;
            by_currency: Record<string, number>;
            mode: string;
        };
    };
    charter: CharterSummary[];
    opex: OpexCategory[];
    params: Parameters;
};

export type SourceStatus = {
    key: string;
    label: string;
    status: 'ok' | 'error' | 'skipped';
    message: string | null;
    ms: number;
    rows: number;
};

export type Snapshot = {
    id: number;
    built_at: string;
    built_by: string | null;
    status: 'ok' | 'partial' | 'failed';
    duration_ms: number;
    error: string | null;
    sources: SourceStatus[];
    payload: ReportPayload | null;
};

export type RunStatus = {
    running: boolean;
    stale: boolean;
    mode: string | null;
    started_at: string | null;
    started_by: string | null;
    arguments: string[];
    pid: number | null;
    exit_code: number | null;
    log: string;
};

export type Parameters = {
    etrip_connections: string[];
    fx: { mode: 'auto' | 'manual'; EUR: number; USD: number };
    thresholds: { minimum: number; comfort: number };
    overdue: {
        recent_days: number;
        recent_pct: number;
        recent_weeks: number;
        old_pct: number;
    };
    payables: {
        days_before_checkin: number;
        prepaid_pct: number;
        ticket_days: number;
        supplier_balance: number | null;
        supplier_balance_weeks: number;
    };
    scenario: {
        enabled: boolean;
        factor: number;
        charter_factor: number;
        charter_base_season: string | null;
        charter_target_season: string | null;
    };
    opex: Record<string, number | null>;
};

export type PaymentBasis = 'flight' | 'week_start' | 'signing';

export type TaxesRule =
    | 'monthly_first_week'
    | 'with_rotation'
    | 'days_after_flight'
    | 'days_before_flight';

export type Contract = {
    id: number;
    name: string;
    counterparty: string | null;
    buyer: string | null;
    /** out = CHR pays, in = CHR collects. */
    direction: 'out' | 'in';
    /** false keeps the contract's terms on file without any cash effect. */
    in_cash_flow: boolean;
    contract_no: string | null;
    signed_date: string | null;
    period_from: string | null;
    period_to: string | null;
    season: string;
    status: 'signed' | 'draft';
    operator: string | null;
    currency: string;
    days_before_flight: number;
    payment_basis: PaymentBasis;
    taxes_rule: TaxesRule;
    taxes_days: number | null;
    taxes_month_day: number;
    deposit_percent: number | null;
    deposit_amount: number | null;
    deposit_due_date: string | null;
    deposit_paid: boolean;
    deposit_settlement: string | null;
    contract_value: number | null;
    contract_value_with_taxes: number | null;
    invoicing: string | null;
    fuel_rule: string | null;
    fx_markup_pct: number;
    late_penalty_pct_per_day: number | null;
    cancellation_terms: string | null;
    source: string | null;
    confidence: string | null;
    notes: string | null;
    flights_count: number;
    flights_net: number;
    flights_taxes: number;
    first_flight: string | null;
    last_flight: string | null;
};

export type Flight = {
    id: number;
    charter_contract_id: number;
    season: string;
    operator: string | null;
    route: string;
    flight_no: string | null;
    flight_date: string;
    seats: number | null;
    price_per_seat: number | null;
    net_value: number;
    taxes: number;
    pay_date: string | null;
    taxes_pay_date: string | null;
    payment_date: string;
    taxes_payment_date: string;
};

/**
 * A value set by hand on one report line for one week. `line` is the OPEX
 * category key for D lines, the line code for B and C lines.
 */
export type CashFlowOverride = {
    line: string;
    week: string;
    amount: number;
    note: string | null;
    updated_by: string | null;
    updated_at: string | null;
};

export type CashFlowPageProps = {
    /** Deferred: undefined until Inertia has loaded the report. */
    snapshot?: Snapshot | null;
    /** Manual values laid over the snapshot, from this week on. */
    overrides: CashFlowOverride[];
    run: RunStatus;
    lastRun: { at: string; status: string; id: number } | null;
    parameters: Parameters;
    opex: OpexCategory[];
    connections: { key: string; label: string }[];
    contracts: Contract[];
    /** Deferred: undefined until Inertia has loaded the programme. */
    flights?: Flight[];
    schedule: { nightly: string; timezone: string; weeks: number };
};
