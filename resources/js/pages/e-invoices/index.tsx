import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, Check, ChevronsUpDown, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import CompanyBadge from '@/components/company-badge';
import DateRangePicker from '@/components/date-range-picker';
import type { DateRangeValue } from '@/components/date-range-picker';
import EFactStatusBadge from '@/components/efact-status-badge';
import {
    FilterField,
    filterInputClass,
    filterTriggerClass,
} from '@/components/filter-field';
import Pagination from '@/components/pagination';
import {
    SelectionBar,
    downloadXlsxFromForm,
    useTableSelection,
} from '@/components/table-selection';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import {
    detail as detailRoute,
    exportMethod as eInvoicesExport,
    index as eInvoicesIndex,
    parsed as parsedRoute,
} from '@/routes/e-invoices';
import { show as invoicesShow } from '@/routes/invoices';
import { show as partnersShow } from '@/routes/partners';
import type {
    DepartmentRef,
    DetailPayload,
    EInvoiceRow,
    Filters,
    ParsedParty,
    ParsedPayload,
    Props,
} from './types';

function formatDateTime(value: string | null) {
    return value ?? '—';
}

function formatAmount(value: number | null, currency: string | null = null) {
    if (value === null || value === undefined) {
        return '—';
    }

    const formatted = new Intl.NumberFormat('ro-RO', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(value);

    return currency ? `${formatted} ${currency}` : formatted;
}

function DepartmentMultiSelect({
    options,
    selected,
    onChange,
}: {
    options: DepartmentRef[];
    selected: number[];
    onChange: (next: number[]) => void;
}) {
    const [open, setOpen] = useState(false);

    const toggle = (id: number) => {
        onChange(
            selected.includes(id)
                ? selected.filter((s) => s !== id)
                : [...selected, id],
        );
    };

    const label =
        selected.length === 0
            ? 'Toate'
            : selected.length === 1
              ? (options.find((o) => o.id === selected[0])?.name ??
                '1 selectat')
              : `${selected.length} selectate`;

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    className={cn(
                        'min-h-11 w-full justify-between font-normal sm:w-50',
                        filterTriggerClass(selected.length > 0),
                    )}
                >
                    <span className="truncate">{label}</span>
                    <ChevronsUpDown className="ml-2 size-4 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-[260px] p-0" align="start">
                <Command>
                    <CommandInput placeholder="Caută departament…" />
                    <CommandList>
                        <CommandEmpty>Niciun departament.</CommandEmpty>
                        <CommandGroup>
                            {options.map((dept) => {
                                const isSelected = selected.includes(dept.id);

                                return (
                                    <CommandItem
                                        key={dept.id}
                                        value={dept.name}
                                        onSelect={() => toggle(dept.id)}
                                    >
                                        <Check
                                            className={cn(
                                                'mr-2 size-4',
                                                isSelected
                                                    ? 'opacity-100'
                                                    : 'opacity-0',
                                            )}
                                        />
                                        {dept.name}
                                    </CommandItem>
                                );
                            })}
                        </CommandGroup>
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}

export default function EInvoicesIndex({
    eInvoices,
    filters,
    companies,
    availableDepartments,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [openInfo, setOpenInfo] = useState<EInvoiceRow | null>(null);
    const [exporting, setExporting] = useState(false);
    const selection = useTableSelection(eInvoices.data, eInvoices.total);

    const handleExport = () => {
        setExporting(true);
        downloadXlsxFromForm(eInvoicesExport().url, selection.payload(), {
            search: filters.search,
            company_id: filters.company_id,
            status: filters.status ?? 'all',
            matched: filters.matched,
            from: filters.from,
            to: filters.to,
            department_ids: filters.department_ids,
        });
        setTimeout(() => setExporting(false), 1500);
    };

    const applyFilter = (next: Partial<Filters>) => {
        const merged = { ...filters, ...next };
        const departmentIds = merged.department_ids ?? [];

        router.get(
            eInvoicesIndex().url,
            {
                search: merged.search ?? undefined,
                company_id: merged.company_id ?? undefined,
                status: merged.status ?? 'all',
                matched: merged.matched ?? undefined,
                from: merged.from ?? undefined,
                to: merged.to ?? undefined,
                department_ids:
                    departmentIds.length > 0 ? departmentIds : undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const resetFilters = () => {
        setSearch('');
        router.get(
            eInvoicesIndex().url,
            { status: 'all' },
            { preserveScroll: true, replace: true },
        );
    };

    const hasActiveFilters =
        !!filters.search ||
        !!filters.company_id ||
        (filters.status !== null && filters.status !== 'all') ||
        !!filters.matched ||
        !!filters.from ||
        !!filters.to ||
        filters.department_ids.length > 0;

    const range: DateRangeValue = { from: filters.from, to: filters.to };

    return (
        <>
            <Head title="eFacturi" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">eFacturi</h1>
                    <p className="text-sm text-muted-foreground">
                        Mesaje e-factura primite de la ANAF (FACTURA PRIMITA),
                        sincronizate din BD-urile companiilor.
                    </p>
                </div>

                <form
                    aria-label="Filtre eFacturi"
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        applyFilter({ search });
                    }}
                >
                    <FilterField
                        label="Caută"
                        active={!!search}
                        onClear={() => {
                            setSearch('');
                            applyFilter({ search: null });
                        }}
                    >
                        <Input
                            className={cn(
                                'min-h-11 w-full sm:w-65',
                                filterInputClass(!!search),
                            )}
                            placeholder="Număr factură, partener, CIF, msg_id…"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                    </FilterField>
                    <FilterField
                        label="Companie"
                        active={!!filters.company_id}
                        onClear={() => applyFilter({ company_id: null })}
                    >
                        <Select
                            value={
                                filters.company_id
                                    ? String(filters.company_id)
                                    : 'all'
                            }
                            onValueChange={(v) =>
                                applyFilter({
                                    company_id: v === 'all' ? null : Number(v),
                                })
                            }
                        >
                            <SelectTrigger
                                className={cn(
                                    'min-h-11 w-full sm:w-50',
                                    filterTriggerClass(!!filters.company_id),
                                )}
                            >
                                <SelectValue placeholder="Companie" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    Toate companiile
                                </SelectItem>
                                {companies.map((c) => (
                                    <SelectItem key={c.id} value={String(c.id)}>
                                        {c.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </FilterField>
                    <FilterField
                        label="Status"
                        active={!!filters.status && filters.status !== 'all'}
                        onClear={() => applyFilter({ status: 'all' })}
                    >
                        <Select
                            value={filters.status ?? 'all'}
                            onValueChange={(v) => applyFilter({ status: v })}
                        >
                            <SelectTrigger
                                className={cn(
                                    'min-h-11 w-full sm:w-45',
                                    filterTriggerClass(
                                        !!filters.status &&
                                            filters.status !== 'all',
                                    ),
                                )}
                            >
                                <SelectValue placeholder="Status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Toate</SelectItem>
                                <SelectItem value="pending">
                                    Neprocesate
                                </SelectItem>
                                <SelectItem value="error">Cu erori</SelectItem>
                                <SelectItem value="processed">
                                    Procesate
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </FilterField>
                    <FilterField
                        label="Asociere factură"
                        active={!!filters.matched}
                        onClear={() => applyFilter({ matched: null })}
                    >
                        <Select
                            value={filters.matched ?? 'all'}
                            onValueChange={(v) =>
                                applyFilter({ matched: v === 'all' ? null : v })
                            }
                        >
                            <SelectTrigger
                                className={cn(
                                    'min-h-11 w-full sm:w-42.5',
                                    filterTriggerClass(!!filters.matched),
                                )}
                            >
                                <SelectValue placeholder="Asociere" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Toate</SelectItem>
                                <SelectItem value="yes">Asociate</SelectItem>
                                <SelectItem value="no">Neasociate</SelectItem>
                            </SelectContent>
                        </Select>
                    </FilterField>
                    {availableDepartments.length > 0 && (
                        <FilterField
                            label="Departamente"
                            active={filters.department_ids.length > 0}
                            onClear={() => applyFilter({ department_ids: [] })}
                        >
                            <DepartmentMultiSelect
                                options={availableDepartments}
                                selected={filters.department_ids}
                                onChange={(ids) =>
                                    applyFilter({ department_ids: ids })
                                }
                            />
                        </FilterField>
                    )}
                    <div className="grid w-full gap-1 sm:w-auto">
                        <Label className="text-xs">Data primire</Label>
                        <DateRangePicker
                            className="w-full sm:w-[230px]"
                            value={range}
                            onChange={(v) =>
                                applyFilter({ from: v.from, to: v.to })
                            }
                            placeholder="Perioadă"
                        />
                    </div>
                    <Button
                        type="submit"
                        variant="secondary"
                        className="min-h-11 w-full sm:w-auto"
                    >
                        Caută
                    </Button>
                    {hasActiveFilters && (
                        <Button
                            type="button"
                            variant="ghost"
                            className="min-h-11 w-full sm:w-auto"
                            onClick={resetFilters}
                        >
                            <X className="size-4" /> Resetează
                        </Button>
                    )}
                </form>

                <SelectionBar
                    state={selection}
                    total={eInvoices.total}
                    pageCount={eInvoices.data.length}
                    onExport={handleExport}
                    exporting={exporting}
                />

                <div className="hidden overflow-x-auto rounded-xl border border-sidebar-border/70 md:block dark:border-sidebar-border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left text-xs text-muted-foreground uppercase">
                            <tr>
                                <th className="w-10 px-4 py-3">
                                    <Checkbox
                                        aria-label="Selectează tot"
                                        checked={selection.pageCheckedValue}
                                        onCheckedChange={() =>
                                            selection.togglePage()
                                        }
                                    />
                                </th>
                                <th className="px-4 py-3">Data primire</th>
                                <th className="px-4 py-3">Data factură</th>
                                <th className="px-4 py-3">Număr</th>
                                <th className="px-4 py-3">Furnizor</th>
                                <th className="px-4 py-3">Departamente</th>
                                <th className="px-4 py-3 text-right">Total</th>
                                <th className="px-4 py-3 text-right">TVA</th>
                                <th className="px-4 py-3">Companie</th>
                                <th className="px-4 py-3">Status</th>
                                <th className="px-4 py-3">Factură asociată</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                            {eInvoices.data.length === 0 && (
                                <tr>
                                    <td
                                        className="px-4 py-6 text-center text-muted-foreground"
                                        colSpan={11}
                                    >
                                        Nicio eFactură. Pornește o sincronizare
                                        din pagina Companii.
                                    </td>
                                </tr>
                            )}
                            {eInvoices.data.map((row) => (
                                <tr
                                    key={row.id}
                                    className={cn(
                                        'hover:bg-muted/30',
                                        row.mismatch.any &&
                                            'bg-amber-50/60 dark:bg-amber-500/10',
                                    )}
                                >
                                    <td className="px-4 py-3">
                                        <Checkbox
                                            aria-label={`Selectează ${row.nr_doc_xml ?? row.msg_id}`}
                                            checked={selection.isSelected(
                                                row.id,
                                            )}
                                            onCheckedChange={() =>
                                                selection.toggle(row.id)
                                            }
                                        />
                                    </td>
                                    <td className="px-4 py-3 whitespace-nowrap">
                                        {formatDateTime(row.msg_data_creare_d)}
                                    </td>
                                    <td className="px-4 py-3 whitespace-nowrap text-muted-foreground">
                                        {row.data_doc_xml ?? '—'}
                                    </td>
                                    <td className="px-4 py-3 font-medium">
                                        <button
                                            type="button"
                                            onClick={() => setOpenInfo(row)}
                                            className="inline-flex items-center gap-1.5 text-primary hover:underline"
                                        >
                                            {row.nr_doc_xml ?? '—'}
                                            {row.mismatch.any && (
                                                <AlertTriangle
                                                    className="size-4 text-amber-600 dark:text-amber-400"
                                                    aria-label="Diferențe între eFactură și factură"
                                                />
                                            )}
                                        </button>
                                    </td>
                                    <td className="max-w-65 px-4 py-3">
                                        {row.partener_xml ? (
                                            <div className="min-w-0">
                                                {row.partner ? (
                                                    <Link
                                                        href={partnersShow(
                                                            row.partner.id,
                                                        )}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="block truncate hover:underline"
                                                        title={row.partener_xml}
                                                    >
                                                        {row.partener_xml}
                                                    </Link>
                                                ) : (
                                                    <div
                                                        className="truncate"
                                                        title={row.partener_xml}
                                                    >
                                                        {row.partener_xml}
                                                    </div>
                                                )}
                                                {row.supplier_cui && (
                                                    <div className="truncate text-xs text-muted-foreground">
                                                        CUI: {row.supplier_cui}
                                                    </div>
                                                )}
                                            </div>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                —
                                            </span>
                                        )}
                                    </td>
                                    <td className="max-w-55 px-4 py-3">
                                        {row.responsabil_departments.length ===
                                        0 ? (
                                            <span className="text-xs text-muted-foreground">
                                                —
                                            </span>
                                        ) : (
                                            <div className="flex flex-wrap gap-1">
                                                {row.responsabil_departments.map(
                                                    (d) => (
                                                        <Badge
                                                            key={d.id}
                                                            variant="outline"
                                                            className="text-[10px]"
                                                        >
                                                            {d.name}
                                                        </Badge>
                                                    ),
                                                )}
                                            </div>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {formatAmount(row.total_amount)}
                                    </td>
                                    <td className="px-4 py-3 text-right text-muted-foreground tabular-nums">
                                        {formatAmount(row.total_vat)}
                                    </td>
                                    <td className="px-4 py-3">
                                        <CompanyBadge
                                            id={row.company.id}
                                            name={row.company.name}
                                        />
                                    </td>
                                    <td className="px-4 py-3">
                                        <EFactStatusBadge status={row.status} />
                                    </td>
                                    <td className="px-4 py-3">
                                        {row.invoice ? (
                                            <Link
                                                className="text-primary hover:underline"
                                                href={invoicesShow(
                                                    row.invoice.id,
                                                )}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                {row.invoice.nr_doc}
                                            </Link>
                                        ) : (
                                            <span className="text-xs text-muted-foreground">
                                                Neasociată
                                            </span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="space-y-3 md:hidden">
                    {eInvoices.data.length === 0 && (
                        <div className="rounded-xl border border-sidebar-border/70 bg-background p-6 text-center text-sm text-muted-foreground dark:border-sidebar-border">
                            Nicio eFactură. Pornește o sincronizare din pagina
                            Companii.
                        </div>
                    )}
                    {eInvoices.data.map((row) => (
                        <div
                            key={row.id}
                            className={cn(
                                'rounded-xl border border-sidebar-border/70 bg-background p-4 shadow-sm dark:border-sidebar-border',
                                row.mismatch.any &&
                                    'border-amber-500/50 bg-amber-50/60 dark:bg-amber-500/10',
                            )}
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div className="flex min-w-0 items-start gap-3">
                                    <Checkbox
                                        aria-label={`Selectează ${row.nr_doc_xml ?? row.msg_id}`}
                                        checked={selection.isSelected(row.id)}
                                        onCheckedChange={() =>
                                            selection.toggle(row.id)
                                        }
                                        className="mt-1"
                                    />
                                    <div className="min-w-0">
                                        <button
                                            type="button"
                                            onClick={() => setOpenInfo(row)}
                                            className="inline-flex items-center gap-1.5 font-medium text-primary hover:underline"
                                        >
                                            {row.nr_doc_xml ?? '—'}
                                            {row.mismatch.any && (
                                                <AlertTriangle className="size-4 text-amber-600 dark:text-amber-400" />
                                            )}
                                        </button>
                                        {row.partner ? (
                                            <Link
                                                href={partnersShow(
                                                    row.partner.id,
                                                )}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="block truncate text-xs text-muted-foreground hover:underline"
                                            >
                                                {row.partener_xml ?? '—'}
                                            </Link>
                                        ) : (
                                            <div className="truncate text-xs text-muted-foreground">
                                                {row.partener_xml ?? '—'}
                                            </div>
                                        )}
                                    </div>
                                </div>
                                <EFactStatusBadge status={row.status} />
                            </div>
                            <div className="mt-2 grid grid-cols-2 gap-2 text-xs text-muted-foreground">
                                <div>
                                    Primită:{' '}
                                    {formatDateTime(row.msg_data_creare_d)}
                                </div>
                                <div>Data: {row.data_doc_xml ?? '—'}</div>
                                <div>
                                    Total: {formatAmount(row.total_amount)}
                                </div>
                                <div>TVA: {formatAmount(row.total_vat)}</div>
                            </div>
                            <div className="mt-2 flex items-center justify-between gap-2">
                                {row.invoice ? (
                                    <Link
                                        className="text-xs text-primary hover:underline"
                                        href={invoicesShow(row.invoice.id)}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        Factură {row.invoice.nr_doc}
                                    </Link>
                                ) : (
                                    <span className="text-xs text-muted-foreground">
                                        Neasociată
                                    </span>
                                )}
                            </div>
                        </div>
                    ))}
                </div>

                <div className="flex flex-col items-center gap-3 sm:flex-row sm:justify-between">
                    <span className="text-xs text-muted-foreground">
                        {eInvoices.from ?? 0}–{eInvoices.to ?? 0} din{' '}
                        {eInvoices.total}
                    </span>
                    <Pagination links={eInvoices.links} />
                </div>
            </div>

            <InfoDialog row={openInfo} onClose={() => setOpenInfo(null)} />
        </>
    );
}

EInvoicesIndex.layout = (page: React.ReactNode) => (
    <AppLayout breadcrumbs={[{ title: 'eFacturi', href: eInvoicesIndex() }]}>
        {page}
    </AppLayout>
);

function InfoDialog({
    row,
    onClose,
}: {
    row: EInvoiceRow | null;
    onClose: () => void;
}) {
    const [detail, setDetail] = useState<DetailPayload | null>(null);
    const [parsed, setParsed] = useState<ParsedPayload | null>(null);
    const [loadingDetail, setLoadingDetail] = useState(false);
    const [loadingParsed, setLoadingParsed] = useState(false);
    const [lastRowId, setLastRowId] = useState<number | null>(row?.id ?? null);

    if ((row?.id ?? null) !== lastRowId) {
        setLastRowId(row?.id ?? null);
        setDetail(null);
        setParsed(null);
        setLoadingDetail(!!row);
        setLoadingParsed(!!row);
    }

    useEffect(() => {
        if (!row) {
            return;
        }

        fetch(detailRoute(row.id).url, {
            headers: { Accept: 'application/json' },
        })
            .then(async (res) => {
                if (!res.ok) {
                    throw new Error('Eroare la încărcare');
                }

                const data = await res.json();
                setDetail((data?.eInvoice as DetailPayload) ?? null);
            })
            .catch(() => setDetail(null))
            .finally(() => setLoadingDetail(false));

        fetch(parsedRoute(row.id).url, {
            headers: { Accept: 'application/json' },
        })
            .then(async (res) => {
                if (!res.ok) {
                    throw new Error('Eroare la încărcare');
                }

                const data = (await res.json()) as ParsedPayload;
                setParsed(data);
            })
            .catch((e: Error) => setParsed({ parsed: null, error: e.message }))
            .finally(() => setLoadingParsed(false));
    }, [row]);

    const parsedInvoice = parsed?.parsed ?? null;

    return (
        <Dialog open={!!row} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="flex h-[calc(100vh-1.5rem)] max-h-[calc(100vh-1.5rem)] w-[calc(100vw-1.5rem)] max-w-[calc(100vw-1.5rem)] flex-col gap-0 overflow-hidden rounded-lg p-0 sm:max-w-[calc(100vw-1.5rem)]">
                <DialogHeader className="border-b px-6 py-3">
                    <DialogTitle className="flex items-center gap-2">
                        Detalii eFactură
                        {row?.mismatch.any && (
                            <Badge
                                variant="outline"
                                className="border-amber-600/50 bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300"
                            >
                                <AlertTriangle className="mr-1 size-3" />
                                Diferențe față de factură
                            </Badge>
                        )}
                    </DialogTitle>
                    <DialogDescription>
                        {row?.nr_doc_xml ? `${row.nr_doc_xml} · ` : ''}
                        {row?.partener_xml ?? ''}
                    </DialogDescription>
                </DialogHeader>

                {row && (
                    <div className="grid min-h-0 flex-1 grid-cols-1 gap-0 overflow-hidden lg:grid-cols-2">
                        <div className="flex min-h-0 flex-col gap-4 overflow-y-auto border-b px-6 py-4 text-sm lg:border-r lg:border-b-0">
                            <h3 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                Mesaj eFactură
                            </h3>

                            <div className="grid grid-cols-2 gap-3">
                                <Field label="msg_id">{row.msg_id}</Field>
                                <Field label="Index încărcare">
                                    {row.msg_index_incarcare ?? '—'}
                                </Field>
                                <Field label="Data primire">
                                    {formatDateTime(row.msg_data_creare_d)}
                                </Field>
                                <Field label="Data factură">
                                    {row.data_doc_xml ?? '—'}
                                </Field>
                                <Field label="Tip doc XML">
                                    {row.tip_doc_xml ?? '—'}
                                </Field>
                                <Field label="CIF furnizor">
                                    {row.supplier_cui ?? '—'}
                                </Field>
                                <Field label="Reg. com.">
                                    {row.cod_cci_xml ?? '—'}
                                </Field>
                                <Field label="Data ins. OMC">
                                    {formatDateTime(row.data_ins_omc)}
                                </Field>
                                <Field label="Status">
                                    <EFactStatusBadge status={row.status} />
                                </Field>
                                <Field label="Departamente">
                                    {row.responsabil_departments.length === 0
                                        ? '—'
                                        : row.responsabil_departments
                                              .map((d) => d.name)
                                              .join(', ')}
                                </Field>
                            </div>

                            <ComparisonCard row={row} />

                            {row.err_ins_omc && (
                                <div className="rounded-md border border-red-600/40 bg-red-50 p-3 text-xs text-red-800 dark:bg-red-500/10 dark:text-red-200">
                                    <div className="mb-1 font-semibold">
                                        Eroare la inserare
                                    </div>
                                    <pre className="whitespace-pre-wrap">
                                        {row.err_ins_omc}
                                    </pre>
                                </div>
                            )}

                            <div>
                                <Label className="text-xs">Detalii mesaj</Label>
                                <div className="mt-1 rounded-md border border-sidebar-border/70 bg-muted/40 p-3 text-xs dark:border-sidebar-border">
                                    {loadingDetail && (
                                        <span className="text-muted-foreground">
                                            Se încarcă…
                                        </span>
                                    )}
                                    {!loadingDetail && (
                                        <pre className="break-words whitespace-pre-wrap">
                                            {detail?.msg_detalii ??
                                                row.partener_xml ??
                                                '—'}
                                        </pre>
                                    )}
                                </div>
                            </div>

                            <details className="rounded-md border border-sidebar-border/70 dark:border-sidebar-border">
                                <summary className="cursor-pointer px-3 py-2 text-xs font-medium select-none">
                                    XML brut
                                </summary>
                                <pre className="max-h-[300px] overflow-auto bg-muted/40 p-3 text-[11px] break-all whitespace-pre-wrap">
                                    {loadingDetail
                                        ? 'Se încarcă…'
                                        : (detail?.msg_xml ?? '—')}
                                </pre>
                            </details>
                        </div>

                        <div className="flex min-h-0 flex-col gap-4 overflow-y-auto px-6 py-4 text-sm">
                            <h3 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                Date eFactură (XML parsat)
                            </h3>

                            {loadingParsed && (
                                <p className="text-muted-foreground">
                                    Se încarcă…
                                </p>
                            )}

                            {!loadingParsed && parsed?.error && (
                                <div className="rounded-md border border-red-600/40 bg-red-50 p-3 text-xs text-red-800 dark:bg-red-500/10 dark:text-red-200">
                                    {parsed.error}
                                </div>
                            )}

                            {!loadingParsed && parsedInvoice && (
                                <div className="grid gap-4">
                                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                                        <Field label="Număr">
                                            {parsedInvoice.number ?? '—'}
                                        </Field>
                                        <Field label="Emitere">
                                            {parsedInvoice.issue_date ?? '—'}
                                        </Field>
                                        <Field label="Scadență">
                                            {parsedInvoice.due_date ?? '—'}
                                        </Field>
                                        <Field label="Monedă">
                                            {parsedInvoice.currency || '—'}
                                        </Field>
                                        <Field label="Ref. cumpărător">
                                            {parsedInvoice.buyer_reference ??
                                                '—'}
                                        </Field>
                                        <Field label="Ref. comandă">
                                            {parsedInvoice.purchase_order_reference ??
                                                '—'}
                                        </Field>
                                    </div>

                                    <div className="grid gap-3 md:grid-cols-2">
                                        <PartyCard
                                            title="Furnizor"
                                            party={parsedInvoice.seller}
                                        />
                                        <PartyCard
                                            title="Cumpărător"
                                            party={parsedInvoice.buyer}
                                        />
                                    </div>

                                    {parsedInvoice.payee && (
                                        <PartyCard
                                            title="Beneficiar plată"
                                            party={parsedInvoice.payee}
                                        />
                                    )}

                                    <div className="rounded-md border border-sidebar-border/70 dark:border-sidebar-border">
                                        <div className="border-b px-3 py-2 text-xs font-semibold">
                                            Totaluri (
                                            {parsedInvoice.totals.currency ??
                                                parsedInvoice.currency}
                                            )
                                        </div>
                                        <div className="grid grid-cols-2 gap-3 p-3 sm:grid-cols-3">
                                            <Field label="Net">
                                                {parsedInvoice.totals.net_amount.toFixed(
                                                    2,
                                                )}
                                            </Field>
                                            <Field label="Reduceri">
                                                {parsedInvoice.totals.allowances_amount.toFixed(
                                                    2,
                                                )}
                                            </Field>
                                            <Field label="Suplimente">
                                                {parsedInvoice.totals.charges_amount.toFixed(
                                                    2,
                                                )}
                                            </Field>
                                            <Field label="Bază TVA">
                                                {parsedInvoice.totals.tax_exclusive_amount.toFixed(
                                                    2,
                                                )}
                                            </Field>
                                            <Field label="TVA">
                                                {parsedInvoice.totals.vat_amount.toFixed(
                                                    2,
                                                )}
                                            </Field>
                                            <Field label="Total cu TVA">
                                                {parsedInvoice.totals.tax_inclusive_amount.toFixed(
                                                    2,
                                                )}
                                            </Field>
                                            <Field label="Plătit">
                                                {parsedInvoice.totals.paid_amount.toFixed(
                                                    2,
                                                )}
                                            </Field>
                                            <Field label="Rotunjire">
                                                {parsedInvoice.totals.rounding_amount.toFixed(
                                                    2,
                                                )}
                                            </Field>
                                            <Field label="De plată">
                                                <strong>
                                                    {parsedInvoice.totals.payable_amount.toFixed(
                                                        2,
                                                    )}
                                                </strong>
                                            </Field>
                                        </div>
                                    </div>

                                    {parsedInvoice.lines.length > 0 && (
                                        <div className="rounded-md border border-sidebar-border/70 dark:border-sidebar-border">
                                            <div className="border-b px-3 py-2 text-xs font-semibold">
                                                Linii (
                                                {parsedInvoice.lines.length})
                                            </div>
                                            <div className="overflow-x-auto">
                                                <table className="w-full text-xs">
                                                    <thead className="bg-muted/50 text-left text-muted-foreground">
                                                        <tr>
                                                            <th className="px-3 py-2">
                                                                Articol
                                                            </th>
                                                            <th className="px-3 py-2 text-right">
                                                                Cant.
                                                            </th>
                                                            <th className="px-3 py-2">
                                                                UM
                                                            </th>
                                                            <th className="px-3 py-2 text-right">
                                                                Preț
                                                            </th>
                                                            <th className="px-3 py-2 text-right">
                                                                Net
                                                            </th>
                                                            <th className="px-3 py-2 text-right">
                                                                TVA %
                                                            </th>
                                                            <th className="px-3 py-2 text-right">
                                                                TVA
                                                            </th>
                                                            <th className="px-3 py-2 text-right">
                                                                Total
                                                            </th>
                                                        </tr>
                                                    </thead>
                                                    <tbody className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                                                        {parsedInvoice.lines.map(
                                                            (line, i) => {
                                                                const lineTotal =
                                                                    (line.net_amount ??
                                                                        0) +
                                                                    (line.vat_amount ??
                                                                        0);

                                                                return (
                                                                    <tr key={i}>
                                                                        <td className="px-3 py-2">
                                                                            <div className="font-medium">
                                                                                {line.name ??
                                                                                    '—'}
                                                                            </div>
                                                                            {line.description && (
                                                                                <div className="text-muted-foreground">
                                                                                    {
                                                                                        line.description
                                                                                    }
                                                                                </div>
                                                                            )}
                                                                        </td>
                                                                        <td className="px-3 py-2 text-right whitespace-nowrap">
                                                                            {
                                                                                line.quantity
                                                                            }
                                                                        </td>
                                                                        <td className="px-3 py-2 whitespace-nowrap">
                                                                            {
                                                                                line.unit
                                                                            }
                                                                        </td>
                                                                        <td className="px-3 py-2 text-right whitespace-nowrap">
                                                                            {line.price?.toFixed(
                                                                                2,
                                                                            ) ??
                                                                                '—'}
                                                                        </td>
                                                                        <td className="px-3 py-2 text-right whitespace-nowrap">
                                                                            {line.net_amount?.toFixed(
                                                                                2,
                                                                            ) ??
                                                                                '—'}
                                                                        </td>
                                                                        <td className="px-3 py-2 text-right whitespace-nowrap text-muted-foreground">
                                                                            {line.vat_rate !==
                                                                            null
                                                                                ? `${line.vat_rate}%`
                                                                                : '—'}
                                                                        </td>
                                                                        <td className="px-3 py-2 text-right whitespace-nowrap">
                                                                            {line.vat_amount?.toFixed(
                                                                                2,
                                                                            ) ??
                                                                                '—'}
                                                                        </td>
                                                                        <td className="px-3 py-2 text-right font-medium whitespace-nowrap">
                                                                            {lineTotal.toFixed(
                                                                                2,
                                                                            )}
                                                                        </td>
                                                                    </tr>
                                                                );
                                                            },
                                                        )}
                                                    </tbody>
                                                    <tfoot className="border-t bg-muted/30">
                                                        <tr>
                                                            <td
                                                                colSpan={4}
                                                                className="px-3 py-2 text-right text-muted-foreground"
                                                            >
                                                                Total fără TVA
                                                            </td>
                                                            <td className="px-3 py-2 text-right font-medium whitespace-nowrap">
                                                                {parsedInvoice.totals.tax_exclusive_amount.toFixed(
                                                                    2,
                                                                )}
                                                            </td>
                                                            <td className="px-3 py-2 text-right text-muted-foreground">
                                                                Total TVA
                                                            </td>
                                                            <td
                                                                colSpan={2}
                                                                className="px-3 py-2 text-right font-medium whitespace-nowrap"
                                                            >
                                                                {parsedInvoice.totals.vat_amount.toFixed(
                                                                    2,
                                                                )}
                                                            </td>
                                                        </tr>
                                                        <tr className="border-t">
                                                            <td
                                                                colSpan={6}
                                                                className="px-3 py-2 text-right font-semibold"
                                                            >
                                                                Total factură
                                                            </td>
                                                            <td
                                                                colSpan={2}
                                                                className="px-3 py-2 text-right font-semibold whitespace-nowrap"
                                                            >
                                                                {(
                                                                    parsedInvoice
                                                                        .totals
                                                                        .tax_exclusive_amount +
                                                                    parsedInvoice
                                                                        .totals
                                                                        .vat_amount
                                                                ).toFixed(2)}
                                                            </td>
                                                        </tr>
                                                    </tfoot>
                                                </table>
                                            </div>
                                        </div>
                                    )}

                                    {parsedInvoice.notes.length > 0 && (
                                        <div className="rounded-md border border-sidebar-border/70 p-3 dark:border-sidebar-border">
                                            <Label className="text-xs">
                                                Note
                                            </Label>
                                            <ul className="mt-1 list-inside list-disc space-y-1 text-xs">
                                                {parsedInvoice.notes.map(
                                                    (n, i) => (
                                                        <li key={i}>{n}</li>
                                                    ),
                                                )}
                                            </ul>
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>
                    </div>
                )}

                <DialogFooter className="border-t px-6 py-3">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Închide
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function ComparisonCard({ row }: { row: EInvoiceRow }) {
    if (!row.invoice) {
        return null;
    }

    const totalDiff =
        row.total_amount !== null && row.invoice.val_mon !== null
            ? row.total_amount - row.invoice.val_mon
            : null;
    const vatDiff =
        row.total_vat !== null && row.invoice.val_mon_tva !== null
            ? row.total_vat - row.invoice.val_mon_tva
            : null;

    return (
        <div
            className={cn(
                'rounded-md border p-3',
                row.mismatch.any
                    ? 'border-amber-500/50 bg-amber-50/50 dark:bg-amber-500/10'
                    : 'border-sidebar-border/70 dark:border-sidebar-border',
            )}
        >
            <div className="mb-2 flex items-center justify-between">
                <Label className="text-xs">Factură asociată</Label>
                <Link
                    className="text-xs text-primary hover:underline"
                    href={invoicesShow(row.invoice.id)}
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    {row.invoice.tip_doc} {row.invoice.nr_doc} ·{' '}
                    {row.invoice.data_doc}
                </Link>
            </div>
            <div className="grid grid-cols-3 gap-3 text-xs">
                <div className="text-muted-foreground" />
                <div className="text-center font-semibold text-muted-foreground">
                    eFactură
                </div>
                <div className="text-center font-semibold text-muted-foreground">
                    Factură
                </div>

                <div className="text-muted-foreground">Total</div>
                <div
                    className={cn(
                        'text-center tabular-nums',
                        row.mismatch.total &&
                            'font-semibold text-amber-700 dark:text-amber-300',
                    )}
                >
                    {formatAmount(row.total_amount, row.currency)}
                </div>
                <div className="text-center tabular-nums">
                    {formatAmount(row.invoice.val_mon, row.invoice.moneda)}
                </div>

                <div className="text-muted-foreground">TVA</div>
                <div
                    className={cn(
                        'text-center tabular-nums',
                        row.mismatch.vat &&
                            'font-semibold text-amber-700 dark:text-amber-300',
                    )}
                >
                    {formatAmount(row.total_vat, row.currency)}
                </div>
                <div className="text-center tabular-nums">
                    {formatAmount(row.invoice.val_mon_tva, row.invoice.moneda)}
                </div>

                <div className="text-muted-foreground">Monedă</div>
                <div
                    className={cn(
                        'text-center',
                        row.mismatch.currency &&
                            'font-semibold text-amber-700 dark:text-amber-300',
                    )}
                >
                    {row.currency ?? '—'}
                </div>
                <div className="text-center">{row.invoice.moneda ?? '—'}</div>

                {row.mismatch.any && (
                    <div className="col-span-3 mt-1 rounded border border-amber-500/40 bg-amber-50 p-2 text-[11px] text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
                        {row.mismatch.currency && (
                            <div>
                                Monedă diferită: {row.currency ?? '—'} vs{' '}
                                {row.invoice.moneda ?? '—'}
                            </div>
                        )}
                        {totalDiff !== null && row.mismatch.total && (
                            <div>
                                Diferență total: {totalDiff > 0 ? '+' : ''}
                                {totalDiff.toFixed(2)}
                            </div>
                        )}
                        {vatDiff !== null && row.mismatch.vat && (
                            <div>
                                Diferență TVA: {vatDiff > 0 ? '+' : ''}
                                {vatDiff.toFixed(2)}
                            </div>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}

function PartyCard({
    title,
    party,
}: {
    title: string;
    party: ParsedParty | null;
}) {
    if (!party) {
        return (
            <div className="rounded-md border border-sidebar-border/70 p-3 dark:border-sidebar-border">
                <div className="text-xs font-semibold">{title}</div>
                <p className="mt-1 text-xs text-muted-foreground">—</p>
            </div>
        );
    }

    const addressLine = [
        ...(Array.isArray(party.address) ? party.address : []),
        party.postal_code,
        party.city,
        party.country,
    ]
        .filter(Boolean)
        .join(', ');

    return (
        <div className="rounded-md border border-sidebar-border/70 p-3 dark:border-sidebar-border">
            <div className="text-xs font-semibold">{title}</div>
            <div className="mt-1 space-y-0.5 text-sm">
                <div className="font-medium">{party.name ?? '—'}</div>
                {party.trading_name && party.trading_name !== party.name && (
                    <div className="text-xs text-muted-foreground">
                        {party.trading_name}
                    </div>
                )}
                {party.vat_number && (
                    <div className="text-xs">CUI: {party.vat_number}</div>
                )}
                {addressLine && (
                    <div className="text-xs text-muted-foreground">
                        {addressLine}
                    </div>
                )}
                {party.contact_email && (
                    <div className="text-xs text-muted-foreground">
                        {party.contact_email}
                    </div>
                )}
                {party.contact_phone && (
                    <div className="text-xs text-muted-foreground">
                        {party.contact_phone}
                    </div>
                )}
            </div>
        </div>
    );
}

function Field({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid gap-0.5">
            <Label className="text-[11px] text-muted-foreground uppercase">
                {label}
            </Label>
            <div className="text-sm">{children}</div>
        </div>
    );
}
