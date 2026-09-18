/** 1.234,56 LEI — amounts as the rest of the app prints them. */
export function formatMoney(
    value: number | null | undefined,
    currency?: string | null,
): string {
    if (value === null || value === undefined || Number.isNaN(value)) {
        return '—';
    }

    const amount = new Intl.NumberFormat('ro-RO', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(value);

    return `${amount} ${currency ?? ''}`.trim();
}

/** Totals kept per currency, printed one after the other. */
export function formatByCurrency(byCurrency: Record<string, number>): string {
    const parts = Object.entries(byCurrency)
        .filter(([, amount]) => Math.abs(amount) >= 0.005)
        .map(([currency, amount]) => formatMoney(amount, currency));

    return parts.length > 0 ? parts.join(' + ') : '—';
}

export function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const [year, month, day] = value.slice(0, 10).split('-');

    return `${day}.${month}.${year}`;
}
