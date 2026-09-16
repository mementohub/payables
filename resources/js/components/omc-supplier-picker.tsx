import { useEffect, useState } from 'react';
import InvoiceCheckController from '@/actions/App/Http/Controllers/InvoiceCheckController';
import {
    Combobox,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxInput,
    ComboboxItem,
    ComboboxList,
} from '@/components/ui/combobox';

export type OmcSupplierOption = {
    /** The `partener` name, which is the ERP key of the supplier. */
    name: string;
    cui: string | null;
    country: string | null;
    city: string | null;
    invoices: number | null;
    last_invoice: string | null;
};

export function omcSupplierLabel(supplier: OmcSupplierOption): string {
    return supplier.name;
}

function shortDate(value: string | null): string {
    if (!value) {
        return '';
    }

    const [year, month, day] = value.split('-');

    return `${day}.${month}.${year}`;
}

/**
 * Searchable list of the suppliers invoiced in OMC. The ERP has hundreds of
 * thousands of partners, so the list is searched live on the server as the
 * user types; without a term the most recently invoiced suppliers are shown.
 */
export default function OmcSupplierPicker({
    id,
    value,
    onChange,
    disabled = false,
}: {
    id?: string;
    value: OmcSupplierOption | null;
    onChange: (supplier: OmcSupplierOption | null) => void;
    disabled?: boolean;
}) {
    const [query, setQuery] = useState('');
    const [options, setOptions] = useState<OmcSupplierOption[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        let cancelled = false;
        const term = query.trim();
        const timer = window.setTimeout(
            () => {
                setLoading(true);

                fetch(
                    InvoiceCheckController.suppliers({
                        query: term ? { q: term } : {},
                    }).url,
                    { headers: { Accept: 'application/json' } },
                )
                    .then(async (res) => {
                        if (!res.ok) {
                            const body = (await res
                                .json()
                                .catch(() => ({}))) as { message?: string };

                            throw new Error(
                                body.message ??
                                    'Lista furnizorilor nu a putut fi încărcată.',
                            );
                        }

                        return (await res.json()) as {
                            suppliers: OmcSupplierOption[];
                        };
                    })
                    .then((data) => {
                        if (!cancelled) {
                            setOptions(data.suppliers);
                            setError(null);
                        }
                    })
                    .catch((err: Error) => {
                        if (!cancelled) {
                            setError(err.message);
                        }
                    })
                    .finally(() => {
                        if (!cancelled) {
                            setLoading(false);
                        }
                    });
            },
            term ? 250 : 0,
        );

        return () => {
            cancelled = true;
            window.clearTimeout(timer);
        };
    }, [query]);

    return (
        <div className="grid gap-1">
            <Combobox
                items={options}
                filter={null}
                value={value}
                onValueChange={(item) => onChange(item)}
                onInputValueChange={(text, details) => {
                    if (details.reason !== 'item-press') {
                        setQuery(text);
                    }
                }}
                itemToStringLabel={omcSupplierLabel}
                isItemEqualToValue={(a, b) => a?.name === b?.name}
                disabled={disabled}
            >
                <ComboboxInput
                    id={id}
                    placeholder="scrie numele sau CUI-ul (ex. Vodafone)"
                    showClear
                    className="w-full"
                />
                <ComboboxContent>
                    <ComboboxEmpty>
                        {loading
                            ? 'Se caută în OMC…'
                            : error
                              ? error
                              : query.trim()
                                ? 'Niciun furnizor cu facturi nu se potrivește.'
                                : 'Niciun furnizor cu facturi în OMC.'}
                    </ComboboxEmpty>
                    <ComboboxList>
                        {(option: OmcSupplierOption) => (
                            <ComboboxItem key={option.name} value={option}>
                                <span className="flex-1 truncate">
                                    {option.name}
                                </span>
                                <span className="font-mono text-xs text-muted-foreground">
                                    {option.cui ?? ''}
                                    {option.invoices !== null
                                        ? ` · ${option.invoices} fact.`
                                        : ''}
                                    {option.last_invoice
                                        ? ` · ${shortDate(option.last_invoice)}`
                                        : ''}
                                </span>
                            </ComboboxItem>
                        )}
                    </ComboboxList>
                </ComboboxContent>
            </Combobox>
            {error && <span className="text-xs text-destructive">{error}</span>}
        </div>
    );
}
