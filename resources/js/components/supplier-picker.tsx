import { useEffect, useState } from 'react';
import PartnerController from '@/actions/App/Http/Controllers/PartnerController';
import {
    Combobox,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxInput,
    ComboboxItem,
    ComboboxList,
} from '@/components/ui/combobox';
import { cn } from '@/lib/utils';

export type SupplierOption = {
    id: number;
    name: string;
    cui: string | null;
    last_invoice?: string | null;
};

/**
 * Searchable list of the suppliers the app knows, searched on the server as
 * the user types; without a term the most recently invoiced come first.
 */
export default function SupplierPicker({
    value,
    onChange,
    className,
}: {
    value: SupplierOption | null;
    onChange: (supplier: SupplierOption | null) => void;
    className?: string;
}) {
    const [query, setQuery] = useState('');
    const [options, setOptions] = useState<SupplierOption[]>([]);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        let cancelled = false;
        const term = query.trim();
        const timer = window.setTimeout(
            () => {
                setLoading(true);
                fetch(
                    PartnerController.search({
                        query: term ? { q: term } : {},
                    }).url,
                    { headers: { Accept: 'application/json' } },
                )
                    .then(
                        (res) =>
                            res.json() as Promise<{
                                suppliers: SupplierOption[];
                            }>,
                    )
                    .then((data) => !cancelled && setOptions(data.suppliers))
                    .catch(() => !cancelled && setOptions([]))
                    .finally(() => !cancelled && setLoading(false));
            },
            term ? 250 : 0,
        );

        return () => {
            cancelled = true;
            window.clearTimeout(timer);
        };
    }, [query]);

    return (
        <Combobox
            items={
                value && !options.some((o) => o.id === value.id)
                    ? [value, ...options]
                    : options
            }
            filter={null}
            value={value}
            onValueChange={(item) => onChange(item)}
            onInputValueChange={(text, details) => {
                if (details.reason !== 'item-press') {
                    setQuery(text);
                }
            }}
            itemToStringLabel={(supplier: SupplierOption) => supplier.name}
            isItemEqualToValue={(a, b) => a?.id === b?.id}
        >
            <ComboboxInput
                placeholder="Furnizor (nume sau CUI)"
                showClear
                className={cn('w-full', className)}
            />
            <ComboboxContent>
                <ComboboxEmpty>
                    {loading
                        ? 'Se caută…'
                        : 'Niciun furnizor nu se potrivește.'}
                </ComboboxEmpty>
                <ComboboxList>
                    {(option: SupplierOption) => (
                        <ComboboxItem key={option.id} value={option}>
                            <span className="flex-1 truncate">
                                {option.name}
                            </span>
                            <span className="font-mono text-xs text-muted-foreground">
                                {option.cui ?? ''}
                            </span>
                        </ComboboxItem>
                    )}
                </ComboboxList>
            </ComboboxContent>
        </Combobox>
    );
}
