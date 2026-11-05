/** Registrul deschis: deciziile sau procedurile. */
export type DocumentSlug = 'decizii' | 'proceduri';

export type DocumentStatus = 'draft' | 'active' | 'superseded' | 'revoked';

/** Cum se cheamă registrul, la singular și la plural. */
export type DocumentLabels = {
    one: string;
    many: string;
    article: string;
};

export type DocumentRow = {
    id: number;
    type: 'decizie' | 'procedura';
    number: string;
    title: string;
    issuer: string | null;
    department: string | null;
    department_id: number | null;
    owner: string | null;
    owner_id: number | null;
    issued_at: string | null;
    effective_at: string | null;
    expires_at: string | null;
    status: DocumentStatus;
    archived: boolean;
    added_at: string | null;
    files_count: number;
    supersedes_id: number | null;
    /** Când a plecat spre companie; null cât timp n-a plecat. */
    published_at: string | null;
    sent_to: string | null;
    consent_due_at: string | null;
    /** Procedură trimisă: cere „am luat la cunoștință”. */
    needs_consent: boolean;
    consents_count: number;
    /** A apăsat chiar omul care se uită acum? */
    i_consented: boolean;
};

/** Ce a citit mașina dintr-un câmp și cu câtă încredere. */
export type OcrField = {
    confidence: number;
    source: string | null;
    value: unknown;
};

export type DocumentFile = {
    id: number;
    version: number;
    kind: 'document' | 'annex';
    kind_label: string;
    title: string;
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

export type DocumentConsentRow = {
    id: number;
    name: string;
    email: string;
    at: string | null;
    source: 'link' | 'account';
    /** Adresa e a cuiva cu cont în aplicație. */
    matched: boolean;
    /** Altă adresă, același nume: poate fi același om, numărat de două ori. */
    twin: string | null;
};

export type CompanyDocument = DocumentRow & {
    object: string | null;
    notes: string | null;
    tags: string[];
    ocr_fields: Record<string, OcrField>;
    created_by: string | null;
    supersedes: { id: number; number: string; title: string } | null;
    /** Legătura pe care o deschid cei din mail; null cât timp n-a plecat. */
    token: string | null;
    read_url: string | null;
    files: DocumentFile[];
    consents: DocumentConsentRow[];
    events: {
        id: number;
        type: string;
        body: string | null;
        user: string | null;
        at: string | null;
    }[];
};

export const STATUS_LABELS: Record<DocumentStatus, string> = {
    draft: 'În lucru',
    active: 'În vigoare',
    superseded: 'Înlocuită',
    revoked: 'Abrogată',
};
