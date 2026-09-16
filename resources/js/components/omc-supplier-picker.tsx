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
    id: number;
    name: string;
    cui: string | null;
    city: string | null;
};

export function omcSupplierLabel(supplier: OmcSupplierOption): string {
    return supplier.name;
}

/**
 * Searchable list of a company's ERP suppliers. The ERP has thousands of
 * partners, so the list is searched on the server as the user types.
 */
export default function OmcSupplierPicker({
    id,
    companyId,
    value,
    onChange,
    disabled = false,
}: {
    id?: string;
    companyId: number | null;
    value: OmcSupplierOption | null;
    onChange: (supplier: OmcSupplierOption | null) => void;
    disabled?: boolean;
}) {
    const [query, setQuery] = useState('');
    const [options, setOptions] = useState<OmcSupplierOption[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (companyId === null) {
            return;
        }

        let cancelled = false;
        const term = query.trim();
        const timer = window.setTimeout(
            () => {
                setLoading(true);

                fetch(
                    InvoiceCheckController.suppliers(companyId, {
                        query: term ? { q: term } : {},
                    }).url,
                    { headers: { Accept: 'application/json' } },
                )
                    .then(async (res) => {
                        if (!res.ok) {
                            throw new Error(
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
            term ? 200 : 0,
        );

        return () => {
            cancelled = true;
            window.clearTimeout(timer);
        };
    }, [companyId, query]);

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
                isItemEqualToValue={(a, b) => a?.id === b?.id}
                disabled={disabled || companyId === null}
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
                            ? 'Se caută…'
                            : query.trim()
                              ? 'Niciun furnizor nu se potrivește.'
                              : 'Niciun furnizor sincronizat pentru această companie.'}
                    </ComboboxEmpty>
                    <ComboboxList>
                        {(option: OmcSupplierOption) => (
                            <ComboboxItem key={option.id} value={option}>
                                <span className="flex-1 truncate">
                                    {option.name}
                                </span>
                                <span className="font-mono text-xs text-muted-foreground">
                                    {option.cui ?? ''}
                                    {option.city ? ` · ${option.city}` : ''}
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
