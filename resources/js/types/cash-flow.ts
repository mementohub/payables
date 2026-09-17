export type LineKind =
    | 'value'
    | 'total'
    | 'balance'
    | 'threshold'
    | 'text'
    | 'reference';

export type ReportLine = {
    code: string;
    key: string | null;
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
};

export type OpeningRow = {
    key: string;
    label: string;
    values: Record<string, number>;
};

export type OpeningDetail = {
    mode: 'auto' | 'manual';
    date: string | null;
    as_of: string;
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

export type CharterSummary = {
    id: number;
    name: string;
    season: string;
    status: string;
    operator: string | null;
    currency: string;
    flights: number;
    total_net: number;
    in_horizon: number;
    taxes: number;
    deposit: number;
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
    kpis: ReportKpis;
    opening: OpeningDetail;
    structure: {
        receivables: ReceivableStructureRow[];
        payables: PayableStructureRow[];
        new_sales: PayableStructureRow[];
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
    opening: {
        mode: 'auto' | 'manual';
        date: string | null;
        bank: Record<string, number>;
        cash: Record<string, number>;
        deposits: Record<string, number>;
    };
    opex: Record<string, number | null>;
};

export type Contract = {
    id: number;
    name: string;
    season: string;
    status: 'signed' | 'draft';
    operator: string | null;
    currency: string;
    days_before_flight: number;
    deposit_percent: number | null;
    deposit_amount: number | null;
    deposit_due_date: string | null;
    deposit_paid: boolean;
    contract_value: number | null;
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

export type CashFlowPageProps = {
    snapshot: Snapshot | null;
    run: RunStatus;
    lastRun: { at: string; status: string; id: number } | null;
    parameters: Parameters;
    opex: OpexCategory[];
    connections: { key: string; label: string }[];
    contracts: Contract[];
    flights: Flight[];
    schedule: { nightly: string; timezone: string; weeks: number };
};
