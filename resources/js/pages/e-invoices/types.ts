import type { EFactStatus } from '@/components/efact-status-badge';
import type { Paginated } from '@/types/pagination';

export type Invoice = {
    id: number;
    data_doc: string | null;
    tip_doc: string;
    nr_doc: string;
};

export type Partner = {
    id: number;
    name: string;
    cui: string | null;
};

export type EInvoiceRow = {
    id: number;
    msg_id: string;
    msg_cif: string | null;
    msg_index_incarcare: string | null;
    msg_data_creare_d: string | null;
    data_doc_xml: string | null;
    tip_doc_xml: string | null;
    nr_doc_xml: string | null;
    partener_xml: string | null;
    cod_cci_xml: string | null;
    data_ins_omc: string | null;
    err_ins_omc: string | null;
    status: EFactStatus;
    company: { id: number; name: string };
    partner: Partner | null;
    invoice: Invoice | null;
};

export type Filters = {
    search: string | null;
    company_id: number | null;
    status: string | null;
    matched: string | null;
    from: string | null;
    to: string | null;
};

export type Props = {
    eInvoices: Paginated<EInvoiceRow>;
    filters: Filters;
    companies: { id: number; name: string }[];
};

export type DetailPayload = EInvoiceRow & {
    msg_detalii: string | null;
    msg_xml: string | null;
};

export type ParsedParty = {
    name: string | null;
    trading_name: string | null;
    vat_number: string | null;
    company_id: string | null;
    address: string[];
    city: string | null;
    postal_code: string | null;
    country: string | null;
    contact_name: string | null;
    contact_phone: string | null;
    contact_email: string | null;
};

export type ParsedTotals = {
    currency: string | null;
    net_amount: number;
    allowances_amount: number;
    charges_amount: number;
    tax_exclusive_amount: number;
    vat_amount: number;
    tax_inclusive_amount: number;
    paid_amount: number;
    rounding_amount: number;
    payable_amount: number;
};

export type ParsedLine = {
    name: string | null;
    description: string | null;
    quantity: number;
    unit: string;
    price: number | null;
    net_amount: number | null;
};

export type ParsedInvoice = {
    number: string | null;
    issue_date: string | null;
    due_date: string | null;
    tax_point_date: string | null;
    currency: string;
    notes: string[];
    buyer_reference: string | null;
    purchase_order_reference: string | null;
    contract_reference: string | null;
    paid_amount: number;
    rounding_amount: number;
    seller: ParsedParty | null;
    buyer: ParsedParty | null;
    payee: ParsedParty | null;
    totals: ParsedTotals;
    lines: ParsedLine[];
};

export type ParsedPayload = {
    parsed: ParsedInvoice | null;
    error: string | null;
};
