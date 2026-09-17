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
    /** The eTrip base (config/etrip.php key) the supplier belongs to. */
    connection: string;
    /** Base label, shown only when more than one base is searched. */
    base: string | null;
};

export type EtripSupplierRef = { connection: string; code: string };

export type EtripBase = { key: string; label?: string | null };

export function supplierLabel(supplier: EtripSupplierOption): string {
    return `${supplier.name} [${supplier.code}]`;
}

const cache = new Map<string, EtripSupplierOption[]>();

export function loadEtripSuppliers(
    base: EtripBase,
): Promise<EtripSupplierOption[]> {
    const cached = cache.get(base.key);

    if (cached) {
        return Promise.resolve(cached);
    }

    return fetch(EtripSupplierController.search(base.key).url, {
        headers: { Accept: 'application/json' },
    }).then(async (res) => {
        if (!res.ok) {
            throw new Error(
                'Lista furnizorilor eTrip nu a putut fi încărcată.',
            );
        }

        const data = (await res.json()) as {
            suppliers: Omit<EtripSupplierOption, 'connection' | 'base'>[];
        };

        const suppliers = data.suppliers.map((supplier) => ({
            ...supplier,
            connection: base.key,
            base: base.label ?? null,
        }));

        cache.set(base.key, suppliers);

        return suppliers;
    });
}

function sameSupplier(
    a: EtripSupplierRef | null | undefined,
    b: EtripSupplierRef | null | undefined,
): boolean {
    return (
        (a ?? null) === (b ?? null) ||
        (a != null &&
            b != null &&
            a.connection === b.connection &&
            a.code === b.code)
    );
}

/**
 * Searchable list of eTrip suppliers. The active list of every base given is
 * loaded once and merged, so the user types a name without choosing a base
 * first; each option remembers the base it came from.
 */
export default function EtripSupplierPicker({
    id,
    bases,
    value,
    onChange,
    disabled = false,
}: {
    id?: string;
    bases: EtripBase[];
    value: EtripSupplierRef | null;
    onChange: (supplier: EtripSupplierOption | null) => void;
    disabled?: boolean;
}) {
    const baseKey = bases.map((base) => base.key).join(',');
    const [loaded, setLoaded] = useState<{
        key: string;
        options: EtripSupplierOption[];
        error: string | null;
    }>({ key: '', options: [], error: null });

    useEffect(() => {
        if (bases.length === 0) {
            return;
        }

        let cancelled = false;

        Promise.allSettled(bases.map(loadEtripSuppliers)).then((results) => {
            if (cancelled) {
                return;
            }

            const options = results.flatMap((result) =>
                result.status === 'fulfilled' ? result.value : [],
            );
            const failures = results.filter(
                (result) => result.status === 'rejected',
            ).length;

            options.sort((a, b) => a.name.localeCompare(b.name, 'ro'));
            setLoaded({
                key: baseKey,
                options,
                error:
                    failures === 0
                        ? null
                        : failures === results.length
                          ? 'Lista furnizorilor eTrip nu a putut fi încărcată.'
                          : 'O parte din bazele eTrip nu au răspuns; lista este incompletă.',
            });
        });

        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [baseKey]);

    const ready = bases.length > 0 && loaded.key === baseKey;
    const loading = bases.length > 0 && !ready;
    const options = useMemo(
        () => (ready ? loaded.options : []),
        [ready, loaded.options],
    );
    const error = ready ? loaded.error : null;

    const selected = useMemo(
        () =>
            options.find((option) =>
                sameSupplier(option, value ?? undefined),
            ) ?? null,
        [options, value],
    );

    const showBase = bases.length > 1;

    return (
        <div className="grid gap-1">
            <Combobox
                items={options}
                value={selected}
                onValueChange={(item) => onChange(item)}
                itemToStringLabel={supplierLabel}
                isItemEqualToValue={(a, b) => sameSupplier(a, b)}
                disabled={disabled || bases.length === 0}
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
                            ? 'Niciun furnizor eTrip sincronizat.'
                            : 'Niciun furnizor nu se potrivește.'}
                    </ComboboxEmpty>
                    <ComboboxList>
                        {(option: EtripSupplierOption) => (
                            <ComboboxItem
                                key={`${option.connection}:${option.code}`}
                                value={option}
                            >
                                <span className="flex-1 truncate">
                                    {option.name}
                                </span>
                                <span className="font-mono text-xs text-muted-foreground">
                                    {option.code}
                                    {option.currency
                                        ? ` · ${option.currency}`
                                        : ''}
                                    {showBase && option.base
                                        ? ` · ${option.base}`
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
