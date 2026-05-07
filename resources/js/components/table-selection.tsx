import { Download, X } from 'lucide-react';
import {
    
    useCallback,
    useEffect,
    useMemo,
    useState
} from 'react';
import type {ReactNode} from 'react';
import { Button } from '@/components/ui/button';

type Identifiable = { id: number };

export type SelectionState = {
    selected: Set<number>;
    selectAllAcrossPages: boolean;
    pageState: 'none' | 'partial' | 'all';
    pageCheckedValue: boolean | 'indeterminate';
    isSelected: (id: number) => boolean;
    toggle: (id: number) => void;
    togglePage: () => void;
    selectAcrossPages: () => void;
    clear: () => void;
    selectionCount: number;
    payload: () => { ids?: number[]; select_all?: boolean };
};

export function useTableSelection<T extends Identifiable>(
    pageItems: T[],
    total: number,
): SelectionState {
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [selectAllAcrossPages, setSelectAll] = useState(false);

    const pageIds = useMemo(() => pageItems.map((i) => i.id), [pageItems]);

    useEffect(() => {
        if (selectAllAcrossPages) {
            return;
        }

        // eslint-disable-next-line react-hooks/set-state-in-effect
        setSelected((prev) => {
            const next = new Set<number>();

            for (const id of prev) {
                if (pageIds.includes(id)) {
                    next.add(id);
                }
            }

            return next.size === prev.size ? prev : next;
        });
    }, [pageIds, selectAllAcrossPages]);

    const pageState = useMemo<'none' | 'partial' | 'all'>(() => {
        if (selectAllAcrossPages) {
            return 'all';
        }

        if (pageIds.length === 0) {
            return 'none';
        }

        const onPage = pageIds.filter((id) => selected.has(id)).length;

        if (onPage === 0) {
            return 'none';
        }

        if (onPage === pageIds.length) {
            return 'all';
        }

        return 'partial';
    }, [selected, pageIds, selectAllAcrossPages]);

    const togglePage = useCallback(() => {
        if (selectAllAcrossPages) {
            setSelectAll(false);
            setSelected(new Set());

            return;
        }

        setSelected((prev) => {
            const next = new Set(prev);
            const allOnPage = pageIds.every((id) => next.has(id));

            if (allOnPage) {
                pageIds.forEach((id) => next.delete(id));
            } else {
                pageIds.forEach((id) => next.add(id));
            }

            return next;
        });
    }, [pageIds, selectAllAcrossPages]);

    const toggle = useCallback(
        (id: number) => {
            if (selectAllAcrossPages) {
                setSelectAll(false);
            }

            setSelected((prev) => {
                const next = new Set(prev);

                if (next.has(id)) {
                    next.delete(id);
                } else {
                    next.add(id);
                }

                return next;
            });
        },
        [selectAllAcrossPages],
    );

    const selectAcrossPages = useCallback(() => {
        setSelectAll(true);
        setSelected(new Set());
    }, []);

    const clear = useCallback(() => {
        setSelectAll(false);
        setSelected(new Set());
    }, []);

    const selectionCount = selectAllAcrossPages ? total : selected.size;

    const payload = useCallback(
        () =>
            selectAllAcrossPages
                ? { select_all: true }
                : { ids: Array.from(selected) },
        [selectAllAcrossPages, selected],
    );

    return {
        selected,
        selectAllAcrossPages,
        pageState,
        pageCheckedValue:
            pageState === 'all'
                ? true
                : pageState === 'partial'
                  ? 'indeterminate'
                  : false,
        isSelected: (id: number) => selectAllAcrossPages || selected.has(id),
        toggle,
        togglePage,
        selectAcrossPages,
        clear,
        selectionCount,
        payload,
    };
}

export function SelectionBar({
    state,
    total,
    pageCount,
    onExport,
    exporting,
    actions,
}: {
    state: SelectionState;
    total: number;
    pageCount: number;
    onExport?: () => void;
    exporting?: boolean;
    actions?: ReactNode;
}) {
    if (state.selectionCount === 0) {
        return null;
    }

    const showSelectAcrossPages =
        !state.selectAllAcrossPages &&
        state.pageState === 'all' &&
        total > pageCount;

    return (
        <div className="flex flex-wrap items-center gap-3 rounded-md border border-primary/30 bg-primary/5 px-3 py-2 text-sm">
            <span className="font-medium">
                {state.selectAllAcrossPages
                    ? `Toate cele ${total} rezultate sunt selectate.`
                    : `${state.selectionCount} ${state.selectionCount === 1 ? 'rând selectat' : 'rânduri selectate'}.`}
            </span>

            {showSelectAcrossPages && (
                <Button
                    type="button"
                    size="sm"
                    variant="link"
                    className="h-auto p-0"
                    onClick={state.selectAcrossPages}
                >
                    Selectează toate cele {total} rezultate filtrate
                </Button>
            )}

            <div className="ml-auto flex items-center gap-2">
                {actions ??
                    (onExport && (
                        <Button
                            type="button"
                            size="sm"
                            onClick={onExport}
                            disabled={exporting}
                        >
                            <Download className="size-4" />{' '}
                            {exporting ? 'Export…' : 'Export XLSX'}
                        </Button>
                    ))}
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={state.clear}
                >
                    <X className="size-4" /> Deselectează
                </Button>
            </div>
        </div>
    );
}

export function downloadXlsxFromForm(
    url: string,
    payload: { ids?: number[]; select_all?: boolean },
    queryParams: Record<string, string | number | null | undefined>,
) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = url;
    form.target = '_self';

    const csrf = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;

    if (csrf) {
        appendInput(form, '_token', csrf);
    }

    if (payload.select_all) {
        appendInput(form, 'select_all', '1');
    }

    for (const id of payload.ids ?? []) {
        appendInput(form, 'ids[]', String(id));
    }

    for (const [key, value] of Object.entries(queryParams)) {
        if (value !== null && value !== undefined && value !== '') {
            appendInput(form, key, String(value));
        }
    }

    document.body.appendChild(form);
    form.submit();
    form.remove();
}

function appendInput(form: HTMLFormElement, name: string, value: string) {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value;
    form.appendChild(input);
}
