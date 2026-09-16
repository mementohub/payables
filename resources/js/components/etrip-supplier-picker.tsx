import { useEffect, useMemo, useState } from 'react';
import EtripSupplierController from '@/actions/App/Http/Controllers/EtripSupplierController';
import {
    Combobox,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxInput,
    ComboboxItem,
    ComboboxList,
} from '@/components/ui/combobox';

export type EtripSupplierOption = {
    code: string;
    name: string;
    currency: string | null;
    partner_id: number | null;
};

export function supplierLabel(supplier: EtripSupplierOption): string {
    return `${supplier.name} [${supplier.code}]`;
}

const cache = new Map<number, EtripSupplierOption[]>();

export function loadEtripSuppliers(
    companyId: number,
): Promise<EtripSupplierOption[]> {
    const cached = cache.get(companyId);

    if (cached) {
        return Promise.resolve(cached);
    }

    return fetch(EtripSupplierController.search(companyId).url, {
        headers: { Accept: 'application/json' },
    }).then(async (res) => {
        if (!res.ok) {
            throw new Error(
                'Lista furnizorilor eTrip nu a putut fi încărcată.',
            );
        }

        const data = (await res.json()) as {
            suppliers: EtripSupplierOption[];
        };

        cache.set(companyId, data.suppliers);

        return data.suppliers;
    });
}

/**
 * Searchable list of a company's eTrip suppliers. The whole active list is
 * loaded once per company and filtered locally.
 */
export default function EtripSupplierPicker({
    id,
    companyId,
    value,
    onChange,
    disabled = false,
}: {
    id?: string;
    companyId: number | null;
    value: string | null;
    onChange: (supplier: EtripSupplierOption | null) => void;
    disabled?: boolean;
}) {
    const [options, setOptions] = useState<EtripSupplierOption[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (companyId === null) {
            return;
        }

        let cancelled = false;

        loadEtripSuppliers(companyId)
            .then((suppliers) => {
                if (!cancelled) {
                    setOptions(suppliers);
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

        return () => {
            cancelled = true;
        };
    }, [companyId]);

    const selected = useMemo(
        () => options.find((option) => option.code === value) ?? null,
        [options, value],
    );

    return (
        <div className="grid gap-1">
            <Combobox
                items={options}
                value={selected}
                onValueChange={(item) => onChange(item)}
                itemToStringLabel={supplierLabel}
                isItemEqualToValue={(a, b) => a?.code === b?.code}
                disabled={disabled || companyId === null}
            >
                <ComboboxInput
                    id={id}
                    placeholder={
                        loading
                            ? 'Se încarcă furnizorii…'
                            : 'scrie numele (ex. Memento Turkiye)'
                    }
                    showClear
                    className="w-full"
                />
                <ComboboxContent>
                    <ComboboxEmpty>
                        {options.length === 0
                            ? 'Niciun furnizor eTrip sincronizat pentru această companie.'
                            : 'Niciun furnizor nu se potrivește.'}
                    </ComboboxEmpty>
                    <ComboboxList>
                        {(option: EtripSupplierOption) => (
                            <ComboboxItem key={option.code} value={option}>
                                <span className="flex-1 truncate">
                                    {option.name}
                                </span>
                                <span className="font-mono text-xs text-muted-foreground">
                                    {option.code}
                                    {option.currency
                                        ? ` · ${option.currency}`
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
