import type { Paginated } from '@/types/pagination';

export type Statement = {
    id: number;
    data_extras: string;
    banca: string | null;
    iban: string;
    operator: string | null;
    moneda: string | null;
    lines_count: number;
    unallocated_count: number;
    total_incoming: number;
    total_outgoing: number;
    total_unallocated: number;
    company: { id: number; name: string };
};

export type IndexFilters = {
    company_id: number | null;
    from: string | null;
    to: string | null;
    only_unallocated: boolean;
};

export type IndexProps = {
    statements: Paginated<Statement>;
    filters: IndexFilters;
    companies: { id: number; name: string }[];
};

export type InvoiceRef = {
    id: number;
    nr_doc: string;
    tip_doc: string;
    data_doc: string;
    moneda: string | null;
    val_mon: number;
    val_mon_tva: number;
    val_mon_paid: number;
    partner: { id: number; name: string } | null;
};

export type Allocation = {
    id: number;
    data_doc_com: string;
    tip_doc_com: string;
    nr_doc_com: string;
    val_fin: number;
    val_com: number;
    invoice: InvoiceRef | null;
};

export type Line = {
    id: number;
    data_doc: string;
    tip_doc: string;
    nr_doc: string;
    direction: 'incoming' | 'outgoing';
    partener_name: string | null;
    partner: { id: number; name: string } | null;
    emitent: string | null;
    cine_preda: string | null;
    cine_primeste: string | null;
    obs_txt: string | null;
    moneda: string | null;
    val_mon: number;
    val_allocated: number;
    unallocated: number;
    is_unallocated: boolean;
    allocations: Allocation[];
};

export type ShowFilters = {
    only_unallocated: boolean;
    direction: string | null;
};

export type ShowProps = {
    statement: Statement;
    lines: Line[];
    filters: ShowFilters;
};
