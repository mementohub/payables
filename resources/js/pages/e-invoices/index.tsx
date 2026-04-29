import { Head, Link, router } from '@inertiajs/react';
import { FileText, Info, X } from 'lucide-react';
import { useEffect, useState } from 'react';
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
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import type {
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

export default function EInvoicesIndex({
    eInvoices,
    filters,
    companies,
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
        });
        setTimeout(() => setExporting(false), 1500);
    };

    const applyFilter = (next: Partial<Filters>) => {
        const merged = { ...filters, ...next };
        router.get(
            eInvoicesIndex().url,
            {
                search: merged.search ?? undefined,
                company_id: merged.company_id ?? undefined,
                status: merged.status ?? 'all',
                matched: merged.matched ?? undefined,
                from: merged.from ?? undefined,
                to: merged.to ?? undefined,
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
        !!filters.to;

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
                                <th className="px-4 py-3">Companie</th>
                                <th className="px-4 py-3">Status</th>
                                <th className="px-4 py-3">Factură asociată</th>
                                <th className="px-4 py-3">Acțiuni</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                            {eInvoices.data.length === 0 && (
                                <tr>
                                    <td
                                        className="px-4 py-6 text-center text-muted-foreground"
                                        colSpan={9}
                                    >
                                        Nicio eFactură. Pornește o sincronizare
                                        din pagina Companii.
                                    </td>
                                </tr>
                            )}
                            {eInvoices.data.map((row) => (
                                <tr key={row.id} className="hover:bg-muted/30">
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
                                        {row.nr_doc_xml ?? '—'}
                                    </td>
                                    <td className="max-w-65 px-4 py-3">
                                        {row.partener_xml ? (
                                            <div className="min-w-0">
                                                <div
                                                    className="truncate"
                                                    title={row.partener_xml}
                                                >
                                                    {row.partener_xml}
                                                </div>
                                                {row.msg_cif && (
                                                    <div className="truncate text-xs text-muted-foreground">
                                                        CUI: {row.msg_cif}
                                                    </div>
                                                )}
                                            </div>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                —
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-muted-foreground">
                                        {row.company.name}
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
                                            >
                                                {row.invoice.nr_doc}
                                            </Link>
                                        ) : (
                                            <span className="text-xs text-muted-foreground">
                                                Neasociată
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => setOpenInfo(row)}
                                        >
                                            <Info className="size-4" />
                                            Info
                                        </Button>
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
                            className="rounded-xl border border-sidebar-border/70 bg-background p-4 shadow-sm dark:border-sidebar-border"
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
                                        <div className="font-medium">
                                            {row.nr_doc_xml ?? '—'}
                                        </div>
                                        <div className="truncate text-xs text-muted-foreground">
                                            {row.partener_xml ?? '—'}
                                        </div>
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
                            </div>
                            <div className="mt-2 flex items-center justify-between gap-2">
                                {row.invoice ? (
                                    <Link
                                        className="text-xs text-primary hover:underline"
                                        href={invoicesShow(row.invoice.id)}
                                    >
                                        Factură {row.invoice.nr_doc}
                                    </Link>
                                ) : (
                                    <span className="text-xs text-muted-foreground">
                                        Neasociată
                                    </span>
                                )}
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setOpenInfo(row)}
                                >
                                    <Info className="size-4" /> Info
                                </Button>
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
    const [loading, setLoading] = useState(false);
    const [parsedOpen, setParsedOpen] = useState(false);
    const [lastRowId, setLastRowId] = useState<number | null>(row?.id ?? null);

    if ((row?.id ?? null) !== lastRowId) {
        setLastRowId(row?.id ?? null);
        setDetail(null);
        setParsedOpen(false);
        setLoading(!!row);
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
            .finally(() => setLoading(false));
    }, [row]);

    return (
        <>
            <Dialog
                open={!!row && !parsedOpen}
                onOpenChange={(open) => !open && onClose()}
            >
                <DialogContent className="flex max-h-[90vh] w-[95vw] max-w-3xl flex-col overflow-hidden p-0 sm:w-full">
                    <DialogHeader className="border-b px-4 py-3 sm:px-6">
                        <DialogTitle>Detalii eFactură</DialogTitle>
                        <DialogDescription>
                            {row?.nr_doc_xml ? `${row.nr_doc_xml} · ` : ''}
                            {row?.partener_xml ?? ''}
                        </DialogDescription>
                    </DialogHeader>

                    {row && (
                        <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-4 py-4 text-sm sm:px-6">
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
                                    {row.msg_cif ?? '—'}
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
                            </div>

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
                                    {loading && (
                                        <span className="text-muted-foreground">
                                            Se încarcă…
                                        </span>
                                    )}
                                    {!loading && (
                                        <pre className="break-words whitespace-pre-wrap">
                                            {detail?.msg_detalii ??
                                                row.partener_xml ??
                                                '—'}
                                        </pre>
                                    )}
                                </div>
                            </div>

                            <details className="rounded-md border border-sidebar-border/70 dark:border-sidebar-border">
                                <summary className="flex cursor-pointer flex-wrap items-center justify-between gap-2 px-3 py-2 text-xs font-medium select-none">
                                    <span>XML brut</span>
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        size="sm"
                                        onClick={(e) => {
                                            e.preventDefault();
                                            e.stopPropagation();
                                            setParsedOpen(true);
                                        }}
                                        disabled={!detail?.msg_xml}
                                    >
                                        <FileText className="size-4" /> Vezi
                                        date eFactură
                                    </Button>
                                </summary>
                                <pre className="max-h-[280px] overflow-auto bg-muted/40 p-3 text-[11px] break-all whitespace-pre-wrap">
                                    {loading
                                        ? 'Se încarcă…'
                                        : (detail?.msg_xml ?? '—')}
                                </pre>
                            </details>

                            {row.invoice && (
                                <div className="rounded-md border border-sidebar-border/70 p-3 dark:border-sidebar-border">
                                    <Label className="text-xs">
                                        Factură asociată
                                    </Label>
                                    <Link
                                        className="mt-1 block text-sm text-primary hover:underline"
                                        href={invoicesShow(row.invoice.id)}
                                    >
                                        {row.invoice.tip_doc}{' '}
                                        {row.invoice.nr_doc} ·{' '}
                                        {row.invoice.data_doc}
                                    </Link>
                                </div>
                            )}
                        </div>
                    )}

                    <DialogFooter className="border-t px-4 py-3 sm:px-6">
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={onClose}
                        >
                            Închide
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <ParsedXmlDialog
                row={parsedOpen ? row : null}
                onClose={() => setParsedOpen(false)}
            />
        </>
    );
}

function ParsedXmlDialog({
    row,
    onClose,
}: {
    row: EInvoiceRow | null;
    onClose: () => void;
}) {
    const [payload, setPayload] = useState<ParsedPayload | null>(null);
    const [loading, setLoading] = useState(false);
    const [lastRowId, setLastRowId] = useState<number | null>(row?.id ?? null);

    if ((row?.id ?? null) !== lastRowId) {
        setLastRowId(row?.id ?? null);
        setPayload(null);
        setLoading(!!row);
    }

    useEffect(() => {
        if (!row) {
            return;
        }

        fetch(parsedRoute(row.id).url, {
            headers: { Accept: 'application/json' },
        })
            .then(async (res) => {
                if (!res.ok) {
                    throw new Error('Eroare la încărcare');
                }

                const data = (await res.json()) as ParsedPayload;
                setPayload(data);
            })
            .catch((e: Error) => setPayload({ parsed: null, error: e.message }))
            .finally(() => setLoading(false));
    }, [row]);

    const parsed = payload?.parsed ?? null;

    return (
        <Dialog open={!!row} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="flex h-[95vh] w-[95vw] max-w-6xl flex-col overflow-hidden p-0 sm:h-[90vh] sm:w-full">
                <DialogHeader className="border-b px-4 py-3 sm:px-6">
                    <DialogTitle>Date eFactură (XML)</DialogTitle>
                    <DialogDescription>
                        Informații extrase din XML conform standardului UBL / EN
                        16931.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-4 py-4 sm:px-6">
                    {loading && (
                        <p className="text-sm text-muted-foreground">
                            Se încarcă…
                        </p>
                    )}

                    {!loading && payload?.error && (
                        <div className="rounded-md border border-red-600/40 bg-red-50 p-3 text-xs text-red-800 dark:bg-red-500/10 dark:text-red-200">
                            {payload.error}
                        </div>
                    )}

                    {!loading && parsed && (
                        <div className="grid gap-4 text-sm">
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-3">
                                <Field label="Număr">
                                    {parsed.number ?? '—'}
                                </Field>
                                <Field label="Data emitere">
                                    {parsed.issue_date ?? '—'}
                                </Field>
                                <Field label="Scadență">
                                    {parsed.due_date ?? '—'}
                                </Field>
                                <Field label="Monedă">
                                    {parsed.currency || '—'}
                                </Field>
                                <Field label="Ref. cumpărător">
                                    {parsed.buyer_reference ?? '—'}
                                </Field>
                                <Field label="Ref. comandă">
                                    {parsed.purchase_order_reference ?? '—'}
                                </Field>
                            </div>

                            <div className="grid gap-3 md:grid-cols-2">
                                <PartyCard
                                    title="Furnizor (Seller)"
                                    party={parsed.seller}
                                />
                                <PartyCard
                                    title="Cumpărător (Buyer)"
                                    party={parsed.buyer}
                                />
                            </div>

                            {parsed.payee && (
                                <PartyCard
                                    title="Beneficiar plată (Payee)"
                                    party={parsed.payee}
                                />
                            )}

                            <div className="rounded-md border border-sidebar-border/70 dark:border-sidebar-border">
                                <div className="border-b px-3 py-2 text-xs font-semibold">
                                    Totaluri (
                                    {parsed.totals.currency ?? parsed.currency})
                                </div>
                                <div className="grid grid-cols-2 gap-3 p-3 sm:grid-cols-3">
                                    <Field label="Net">
                                        {parsed.totals.net_amount.toFixed(2)}
                                    </Field>
                                    <Field label="Reduceri">
                                        {parsed.totals.allowances_amount.toFixed(
                                            2,
                                        )}
                                    </Field>
                                    <Field label="Suplimente">
                                        {parsed.totals.charges_amount.toFixed(
                                            2,
                                        )}
                                    </Field>
                                    <Field label="Bază TVA">
                                        {parsed.totals.tax_exclusive_amount.toFixed(
                                            2,
                                        )}
                                    </Field>
                                    <Field label="TVA">
                                        {parsed.totals.vat_amount.toFixed(2)}
                                    </Field>
                                    <Field label="Total cu TVA">
                                        {parsed.totals.tax_inclusive_amount.toFixed(
                                            2,
                                        )}
                                    </Field>
                                    <Field label="Plătit">
                                        {parsed.totals.paid_amount.toFixed(2)}
                                    </Field>
                                    <Field label="Rotunjire">
                                        {parsed.totals.rounding_amount.toFixed(
                                            2,
                                        )}
                                    </Field>
                                    <Field label="De plată">
                                        <strong>
                                            {parsed.totals.payable_amount.toFixed(
                                                2,
                                            )}
                                        </strong>
                                    </Field>
                                </div>
                            </div>

                            {parsed.lines.length > 0 && (
                                <div className="rounded-md border border-sidebar-border/70 dark:border-sidebar-border">
                                    <div className="border-b px-3 py-2 text-xs font-semibold">
                                        Linii ({parsed.lines.length})
                                    </div>
                                    <div className="hidden overflow-x-auto sm:block">
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
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                                                {parsed.lines.map((line, i) => (
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
                                                            {line.quantity}
                                                        </td>
                                                        <td className="px-3 py-2 whitespace-nowrap">
                                                            {line.unit}
                                                        </td>
                                                        <td className="px-3 py-2 text-right whitespace-nowrap">
                                                            {line.price?.toFixed(
                                                                2,
                                                            ) ?? '—'}
                                                        </td>
                                                        <td className="px-3 py-2 text-right whitespace-nowrap">
                                                            {line.net_amount?.toFixed(
                                                                2,
                                                            ) ?? '—'}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                    <ul className="divide-y divide-sidebar-border/70 sm:hidden dark:divide-sidebar-border">
                                        {parsed.lines.map((line, i) => (
                                            <li
                                                key={i}
                                                className="space-y-1 px-3 py-2 text-xs"
                                            >
                                                <div className="font-medium">
                                                    {line.name ?? '—'}
                                                </div>
                                                {line.description && (
                                                    <div className="text-muted-foreground">
                                                        {line.description}
                                                    </div>
                                                )}
                                                <div className="grid grid-cols-2 gap-2 pt-1 text-muted-foreground">
                                                    <span>
                                                        Cant.: {line.quantity}{' '}
                                                        {line.unit}
                                                    </span>
                                                    <span className="text-right">
                                                        Preț:{' '}
                                                        {line.price?.toFixed(
                                                            2,
                                                        ) ?? '—'}
                                                    </span>
                                                    <span className="col-span-2 text-right font-medium text-foreground">
                                                        Net:{' '}
                                                        {line.net_amount?.toFixed(
                                                            2,
                                                        ) ?? '—'}
                                                    </span>
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}

                            {parsed.notes.length > 0 && (
                                <div className="rounded-md border border-sidebar-border/70 p-3 dark:border-sidebar-border">
                                    <Label className="text-xs">Note</Label>
                                    <ul className="mt-1 list-inside list-disc space-y-1 text-xs">
                                        {parsed.notes.map((n, i) => (
                                            <li key={i}>{n}</li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                        </div>
                    )}
                </div>

                <DialogFooter className="border-t px-4 py-3 sm:px-6">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Înapoi
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
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
        ...party.address,
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
