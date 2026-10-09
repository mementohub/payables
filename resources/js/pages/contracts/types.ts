export type ContractStatus =
    | 'draft'
    | 'negotiation'
    | 'approval'
    | 'signing'
    | 'active'
    | 'expired'
    | 'terminated';

export type ContractKind = 'supplier' | 'client' | 'group';

export type ContractRow = {
    id: number;
    number: string;
    title: string;
    partner_name: string;
    partner_id: number | null;
    kind: ContractKind;
    department: string | null;
    department_id: number | null;
    owner: string | null;
    owner_id: number | null;
    value: number | null;
    currency: string | null;
    signed_at: string | null;
    expires_at: string | null;
    /** Câte zile mai are; negativ dacă a trecut, null dacă n-are termen. */
    days_left: number | null;
    status: ContractStatus;
    archived: boolean;
    files_count: number;
};

/** Ce a citit mașina dintr-un câmp și cu câtă încredere. */
export type OcrField = {
    confidence: number;
    source: string | null;
    value: unknown;
};

export type ContractFile = {
    id: number;
    version: number;
    /** Contractul însuși, un act adițional, o anexă sau alt document. */
    kind: 'contract' | 'addendum' | 'annex' | 'other';
    kind_label: string;
    title: string;
    signed_at: string | null;
    label: string | null;
    name: string;
    size: number;
    pages: number | null;
    ocr_status: 'pending' | 'done' | 'failed' | 'skipped';
    ocr_engine: string | null;
    ocr_error: string | null;
    has_text: boolean;
    /** Textul citit, pentru fișierele care nu se pot arăta în browser. */
    text?: string | null;
    uploaded_by: string | null;
    uploaded_at: string | null;
};

export type Contract = ContractRow & {
    object: string | null;
    notes: string | null;
    partner_tax_id: string | null;
    payment_terms: string | null;
    governing_law: string | null;
    notice_days: number | null;
    notice_on: string | null;
    auto_renew: boolean;
    starts_at: string | null;
    tags: string[];
    ocr_fields: Record<string, OcrField>;
    created_by: string | null;
    files: ContractFile[];
    shares: {
        id: number;
        email: string;
        permission: string;
        expires_at: string | null;
        opened_at: string | null;
        opens: number;
    }[];
    events: {
        id: number;
        type: string;
        body: string | null;
        user: string | null;
        at: string | null;
    }[];
};

export const STATUS_LABELS: Record<ContractStatus, string> = {
    draft: 'Draft',
    negotiation: 'În negociere',
    approval: 'La aprobare',
    signing: 'La semnat',
    active: 'Activ',
    expired: 'Expirat',
    terminated: 'Reziliat',
};

export const KIND_LABELS: Record<ContractKind, string> = {
    supplier: 'Furnizor',
    client: 'Client',
    group: 'Intragrup',
};
