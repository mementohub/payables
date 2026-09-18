import type { Paginated } from '@/types/pagination';

/** Where an open supplier invoice stands in the approval flow. */
export type WorkflowStatus =
    | 'routing'
    | 'department'
    | 'final'
    | 'approved'
    | 'disputed'
    | 'postponed';

export type DepartmentDecision =
    | 'pending'
    | 'approved'
    | 'disputed'
    | 'postponed';

export type DepartmentGroup = 'product' | 'channel' | 'support';

export type DepartmentRef = {
    id: number;
    name: string;
    group?: DepartmentGroup | null;
    parent_id?: number | null;
};

/** One department's share of an invoice and its decision on it. */
export type DepartmentShare = {
    id: number;
    name: string | null;
    amount: number;
    status: DepartmentDecision;
    comment: string | null;
    postponed_until: string | null;
    by: string | null;
    at: string | null;
};

/** An invoice as the approval pages show it (ApprovalPresenter::invoice). */
export type WorkflowInvoice = {
    id: number;
    nr_doc: string;
    data_doc: string | null;
    data_scadenta: string | null;
    partner: { id: number; name: string } | null;
    moneda: string | null;
    val_mon: number;
    outstanding: number;
    payment_status: 'paid' | 'partial' | 'unpaid';
    department: DepartmentRef | null;
    assignment_state: 'assigned' | 'partial' | 'unassigned' | null;
    approval_track: 'run' | 'invoice' | null;
    approval_status: WorkflowStatus | null;
    postponed_until: string | null;
    final: { by: string | null; at: string; comment: string | null } | null;
    departments: DepartmentShare[];
};

export type ApprovalsPageProps = {
    tab: 'mine' | 'final' | 'blocked';
    rows: Paginated<WorkflowInvoice>;
    departments: { id: number; name: string; pending: number }[];
    /** Every active department: where a share can be redirected. */
    all_departments: DepartmentRef[];
    counts: { mine: number; final: number; blocked: number };
    filters: {
        department: number | null;
        search: string;
        due_until: string | null;
        with_runs: boolean;
    };
    can: { final: boolean; reopen: boolean };
};

export type PaymentRunStatus =
    | 'review'
    | 'final'
    | 'approved'
    | 'exported'
    | 'closed'
    | 'cancelled';

export type RunTotals = { count: number; by_currency: Record<string, number> };

export type PaymentRunSummary = {
    id: number;
    reference: string;
    pay_date: string;
    due_until: string;
    status: PaymentRunStatus;
    included_count: number;
    totals: RunTotals;
    created_by: string | null;
    approved_by: string | null;
    approved_at: string | null;
    exported_by: string | null;
    exported_at: string | null;
};

export type PaymentRunsPageProps = {
    runs: Paginated<PaymentRunSummary>;
    defaults: { pay_date: string; due_until: string };
    can: { create: boolean };
};

export type CashPosition = {
    total_lei: number;
    by_currency: Record<string, number>;
    /** Monday of the WCFR week the payment day falls in. */
    week: string | null;
    /** Treasury balance the WCFR forecast expects at the end of that week. */
    closing: number | null;
    minimum: number | null;
    margin: number | null;
    built_at: string | null;
};

export type PaymentRunItem = {
    id: number;
    status: 'included' | 'excluded';
    amount: number;
    currency: string | null;
    comment: string | null;
    department: { id: number; name: string } | null;
    invoice: WorkflowInvoice | null;
};

export type PaymentRunPageProps = {
    run: Omit<PaymentRunSummary, 'included_count'> & { note: string | null };
    cash: CashPosition;
    items: PaymentRunItem[];
    /** Department ids the user approves for; null for an admin (all). */
    my_departments: number[] | null;
    can: {
        approve: boolean;
        final: boolean;
        edit: boolean;
        export: boolean;
        close: boolean;
    };
    payable_invoice_ids: number[];
};

export type RoutingRule = {
    id: number;
    kind: 'loc' | 'partner' | 'account' | 'office';
    pattern: string;
    department_id: number;
    department: string | null;
    note: string | null;
    created_by: string | null;
};

export type RoutingAccuracy = {
    since: string;
    by_rule: { rule: string; lines: number; amount: number }[];
    tagged_lines: number;
    agree: number;
    silent: number;
    disagreements: { tagged: string; predicted: string; lines: number }[];
};

export type RoutingPageProps = {
    tab: 'rules' | 'accuracy';
    departments: DepartmentRef[];
    rules: RoutingRule[] | null;
    accuracy: RoutingAccuracy | null;
    run: { running: boolean; started_at: string | null; log: string };
    can: { edit: boolean };
};
