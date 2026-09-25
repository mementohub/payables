import { useEffect, useState } from 'react';
import { details as detailsRoute } from '@/routes/reports/pnl';

export type CostItem = {
    key: string;
    account: string;
    sediu: string;
    partner: string;
    documents: number;
    lei: number;
};

export type CostDocument = {
    data_doc: string;
    tip_doc: string;
    nr_doc: string;
    account: string;
    sediu: string;
    partner: string;
    note: string;
    month: number;
    /** Nota contabilă care a dus cheltuiala în luna asta. */
    booked: string;
    /** Contul din care a venit: 401 direct de la furnizor, 471 dintr-un avans. */
    counterpart: string;
    /** Factura întreagă, din registrul OMC. */
    currency: string;
    rate: number;
    doc_total: number;
    doc_vat: number;
    due: string | null;
    issued: string | null;
    issuer: string;
    observation: string;
    partner_office: string;
    partner_account: string;
    journal: string;
    /** Cât din ea a căzut pe linia (și coloana) deschisă. */
    lei: number;
    /** Ce știe aplicația despre aceeași factură, dacă o are. */
    invoice: {
        id: number;
        approval_status: string | null;
        approval_track: string | null;
        department: string | null;
        payment_status: string;
        paid: number;
        closed_at: string | null;
        due: string | null;
        internal: string | null;
    } | null;
};

export type CostDetails = {
    saf: string;
    label: string;
    group: string;
    period: string;
    /** Coloana pentru care s-a cerut detaliul, sau null pentru toată linia. */
    column: string | null;
    total: number;
    by_month: Record<string, number>;
    items: CostItem[];
    documents: CostDocument[];
    documents_total: number;
};

/**
 * Ce s-a adunat într-o linie de cheltuială, citit la cerere.
 *
 * Rezultatul se ține împreună cu linia pentru care a fost cerut, ca să nu se
 * vadă o clipă detaliul liniei precedente și ca efectul să nu scrie starea
 * sincron.
 */
export function useCostDetails(
    companyId: number | null,
    year: number,
    period: string,
    saf: string | null,
    column: string | null = null,
    axis: 'channel' | 'product' | 'branch' = 'channel',
) {
    const [loaded, setLoaded] = useState<{
        saf: string;
        period: string;
        column: string | null;
        data: CostDetails;
    } | null>(null);

    useEffect(() => {
        if (!saf || !companyId) {
            return;
        }

        const controller = new AbortController();

        fetch(
            `${detailsRoute.url({ company: companyId })}?${new URLSearchParams({
                year: String(year),
                saf,
                period,
                ...(column ? { column, axis } : {}),
            })}`,
            {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            },
        )
            .then((response) => response.json())
            .then((data: CostDetails) =>
                setLoaded({ saf, period, column, data }),
            )
            .catch(() => undefined);

        return () => controller.abort();
    }, [companyId, year, period, saf, column, axis]);

    // Rezultatul se ține împreună cu ce s-a cerut: altfel, apăsând altă
    // coloană, s-ar vedea o clipă detaliul celei dinainte, cu alte cifre.
    const details =
        loaded?.saf === saf &&
        loaded.period === period &&
        loaded.column === column
            ? loaded.data
            : null;

    return { details, loading: saf !== null && details === null };
}
