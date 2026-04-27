import { Head, Link, router } from '@inertiajs/react';
import { Info, Link as LinkIcon, Search, Unlink } from 'lucide-react';
import { useEffect, useState } from 'react';
import EFactStatusBadge from '@/components/efact-status-badge';
import type { EFactStatus } from '@/components/efact-status-badge';
import DateRangePicker from '@/components/date-range-picker';
import type { DateRangeValue } from '@/components/date-range-picker';
import Pagination from '@/components/pagination';
import { Button } from '@/components/ui/button';
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
import { candidates as candidatesRoute, detail as detailRoute, index as eInvoicesIndex, match as matchRoute } from '@/routes/e-invoices';
import { show as invoicesShow } from '@/routes/invoices';
import type { Paginated } from '@/types/pagination';

type Invoice = {
    id: number;
    data_doc: string | null;
    tip_doc: string;
    nr_doc: string;
};

type Partner = {
    id: number;
    name: string;
    cui: string | null;
};

type EInvoiceRow = {
    id: number;
    msg_id: string;
    msg_index_incarcare: string | null;
    msg_data_creare_d: string | null;
    data_doc_xml: string | null;
    tip_doc_xml: string | null;
    nr_doc_xml: string | null;
    partener_xml: string | null;
    cod_cci_xml: string | null;
    data_ins_omc: string | null;
    err_ins_omc: string | null;
    status: EFactStatus;
    company: { id: number; name: string };
    partner: Partner | null;
    invoice: Invoice | null;
};

type Filters = {
    search: string | null;
    company_id: number | null;
    status: string | null;
    matched: string | null;
    from: string | null;
    to: string | null;
};

type Props = {
    eInvoices: Paginated<EInvoiceRow>;
    filters: Filters;
    companies: { id: number; name: string }[];
};

type DetailPayload = EInvoiceRow & {
    msg_detalii: string | null;
    msg_xml: string | null;
};

type Candidate = {
    id: number;
    data_doc: string | null;
    tip_doc: string;
    nr_doc: string;
    val_mon: number;
    moneda: string | null;
    partner: Partner | null;
};

function formatDateTime(iso: string | null) {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleString('ro-RO', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export default function EInvoicesIndex({ eInvoices, filters, companies }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [openInfo, setOpenInfo] = useState<EInvoiceRow | null>(null);

    const applyFilter = (next: Partial<Filters>) => {
        const merged = { ...filters, ...next };
        router.get(
            eInvoicesIndex().url,
            {
                search: merged.search ?? undefined,
                company_id: merged.company_id ?? undefined,
                status: merged.status ?? undefined,
                matched: merged.matched ?? undefined,
                from: merged.from ?? undefined,
                to: merged.to ?? undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const range: DateRangeValue = { from: filters.from, to: filters.to };

    return (
        <>
            <Head title="eFacturi" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">eFacturi</h1>
                    <p className="text-sm text-muted-foreground">
                        Mesaje e-factura primite de la ANAF (FACTURA PRIMITA), sincronizate din BD-urile companiilor.
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
                    <div className="grid w-full gap-1 sm:w-auto">
                        <Label className="text-xs">Caută</Label>
                        <Input
                            className="min-h-11 w-full sm:w-[260px]"
                            placeholder="Număr factură, partener, CIF, msg_id…"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                    </div>
                    <div className="grid w-full gap-1 sm:w-auto">
                        <Label className="text-xs">Companie</Label>
                        <Select
                            value={filters.company_id ? String(filters.company_id) : 'all'}
                            onValueChange={(v) => applyFilter({ company_id: v === 'all' ? null : Number(v) })}
                        >
                            <SelectTrigger className="min-h-11 w-full sm:w-[200px]">
                                <SelectValue placeholder="Companie" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Toate companiile</SelectItem>
                                {companies.map((c) => (
                                    <SelectItem key={c.id} value={String(c.id)}>
                                        {c.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="grid w-full gap-1 sm:w-auto">
                        <Label className="text-xs">Status</Label>
                        <Select
                            value={filters.status ?? 'all'}
                            onValueChange={(v) => applyFilter({ status: v === 'all' ? null : v })}
                        >
                            <SelectTrigger className="min-h-11 w-full sm:w-[180px]">
                                <SelectValue placeholder="Status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Toate</SelectItem>
                                <SelectItem value="pending">Neprocesate</SelectItem>
                                <SelectItem value="error">Cu erori</SelectItem>
                                <SelectItem value="processed">Procesate</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="grid w-full gap-1 sm:w-auto">
                        <Label className="text-xs">Asociere factură</Label>
                        <Select
                            value={filters.matched ?? 'all'}
                            onValueChange={(v) => applyFilter({ matched: v === 'all' ? null : v })}
                        >
                            <SelectTrigger className="min-h-11 w-full sm:w-[170px]">
                                <SelectValue placeholder="Asociere" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Toate</SelectItem>
                                <SelectItem value="yes">Asociate</SelectItem>
                                <SelectItem value="no">Neasociate</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="grid w-full gap-1 sm:w-auto">
                        <Label className="text-xs">Data primire</Label>
                        <DateRangePicker
                            className="w-full sm:w-[230px]"
                            value={range}
                            onChange={(v) => applyFilter({ from: v.from, to: v.to })}
                            placeholder="Perioadă"
                        />
                    </div>
                    <Button type="submit" variant="secondary" className="min-h-11 w-full sm:w-auto">
                        Caută
                    </Button>
                </form>

                <div className="hidden overflow-x-auto rounded-xl border border-sidebar-border/70 md:block dark:border-sidebar-border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left text-xs uppercase text-muted-foreground">
                            <tr>
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
                                    <td className="px-4 py-6 text-center text-muted-foreground" colSpan={8}>
                                        Nicio eFactură. Pornește o sincronizare din pagina Companii.
                                    </td>
                                </tr>
                            )}
                            {eInvoices.data.map((row) => (
                                <tr key={row.id} className="hover:bg-muted/30">
                                    <td className="px-4 py-3 whitespace-nowrap">
                                        {formatDateTime(row.msg_data_creare_d)}
                                    </td>
                                    <td className="px-4 py-3 whitespace-nowrap text-muted-foreground">
                                        {row.data_doc_xml ?? '—'}
                                    </td>
                                    <td className="px-4 py-3 font-medium">{row.nr_doc_xml ?? '—'}</td>
                                    <td className="px-4 py-3">
                                        {row.partener_xml ? (
                                            <div>
                                                <div>{row.partener_xml}</div>
                                                {row.cod_cci_xml && (
                                                    <div className="text-xs text-muted-foreground">
                                                        CIF: {row.cod_cci_xml}
                                                    </div>
                                                )}
                                            </div>
                                        ) : (
                                            <span className="text-muted-foreground">—</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-muted-foreground">{row.company.name}</td>
                                    <td className="px-4 py-3">
                                        <EFactStatusBadge status={row.status} />
                                    </td>
                                    <td className="px-4 py-3">
                                        {row.invoice ? (
                                            <Link
                                                className="text-primary hover:underline"
                                                href={invoicesShow(row.invoice.id)}
                                            >
                                                {row.invoice.nr_doc}
                                            </Link>
                                        ) : (
                                            <span className="text-xs text-muted-foreground">Neasociată</span>
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
                            Nicio eFactură. Pornește o sincronizare din pagina Companii.
                        </div>
                    )}
                    {eInvoices.data.map((row) => (
                        <div
                            key={row.id}
                            className="rounded-xl border border-sidebar-border/70 bg-background p-4 shadow-sm dark:border-sidebar-border"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <div className="font-medium">{row.nr_doc_xml ?? '—'}</div>
                                    <div className="truncate text-xs text-muted-foreground">
                                        {row.partener_xml ?? '—'}
                                    </div>
                                </div>
                                <EFactStatusBadge status={row.status} />
                            </div>
                            <div className="mt-2 grid grid-cols-2 gap-2 text-xs text-muted-foreground">
                                <div>Primită: {formatDateTime(row.msg_data_creare_d)}</div>
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
                                    <span className="text-xs text-muted-foreground">Neasociată</span>
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

                <div className="flex items-center justify-between">
                    <span className="text-xs text-muted-foreground">
                        {eInvoices.from ?? 0}–{eInvoices.to ?? 0} din {eInvoices.total}
                    </span>
                    <Pagination links={eInvoices.links} />
                </div>
            </div>

            <InfoDialog
                row={openInfo}
                onClose={() => setOpenInfo(null)}
            />
        </>
    );
}

EInvoicesIndex.layout = (page: React.ReactNode) => (
    <AppLayout breadcrumbs={[{ title: 'eFacturi', href: eInvoicesIndex() }]}>{page}</AppLayout>
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
    const [candidates, setCandidates] = useState<Candidate[]>([]);
    const [searchTerm, setSearchTerm] = useState('');
    const [searching, setSearching] = useState(false);

    useEffect(() => {
        setDetail(null);
        setCandidates([]);
        setSearchTerm('');
        if (!row) {
            return;
        }

        setLoading(true);
        fetch(detailRoute(row.id).url, { headers: { Accept: 'application/json' } })
            .then(async (res) => {
                if (!res.ok) throw new Error('Eroare la încărcare');
                const data = await res.json();
                setDetail((data?.eInvoice as DetailPayload) ?? null);
            })
            .catch(() => setDetail(null))
            .finally(() => setLoading(false));
    }, [row]);

    const fetchCandidates = (q: string) => {
        if (!row) return;
        setSearching(true);
        const url = candidatesRoute(row.id).url + (q ? `?q=${encodeURIComponent(q)}` : '');
        fetch(url, { headers: { Accept: 'application/json' } })
            .then(async (res) => {
                if (!res.ok) throw new Error('Eroare la căutare');
                const data = await res.json();
                setCandidates(data.candidates ?? []);
            })
            .catch(() => setCandidates([]))
            .finally(() => setSearching(false));
    };

    const linkInvoice = (invoiceId: number | null) => {
        if (!row) return;
        router.post(
            matchRoute(row.id).url,
            invoiceId ? { invoice_id: invoiceId } : {},
            {
                preserveScroll: true,
                onSuccess: () => onClose(),
            },
        );
    };

    return (
        <Dialog open={!!row} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Detalii eFactură</DialogTitle>
                    <DialogDescription>
                        {row?.nr_doc_xml ? `${row.nr_doc_xml} · ` : ''}
                        {row?.partener_xml ?? ''}
                    </DialogDescription>
                </DialogHeader>

                {row && (
                    <div className="grid gap-4 text-sm">
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="msg_id">{row.msg_id}</Field>
                            <Field label="Index încărcare">{row.msg_index_incarcare ?? '—'}</Field>
                            <Field label="Data primire">{formatDateTime(row.msg_data_creare_d)}</Field>
                            <Field label="Data factură">{row.data_doc_xml ?? '—'}</Field>
                            <Field label="Tip doc XML">{row.tip_doc_xml ?? '—'}</Field>
                            <Field label="CIF furnizor">{row.cod_cci_xml ?? '—'}</Field>
                            <Field label="Data ins. OMC">{formatDateTime(row.data_ins_omc)}</Field>
                            <Field label="Status">
                                <EFactStatusBadge status={row.status} />
                            </Field>
                        </div>

                        {row.err_ins_omc && (
                            <div className="rounded-md border border-red-600/40 bg-red-50 p-3 text-xs text-red-800 dark:bg-red-500/10 dark:text-red-200">
                                <div className="mb-1 font-semibold">Eroare la inserare</div>
                                <pre className="whitespace-pre-wrap">{row.err_ins_omc}</pre>
                            </div>
                        )}

                        <div>
                            <Label className="text-xs">Detalii mesaj</Label>
                            <div className="mt-1 rounded-md border border-sidebar-border/70 bg-muted/40 p-3 text-xs dark:border-sidebar-border">
                                {loading && <span className="text-muted-foreground">Se încarcă…</span>}
                                {!loading && (
                                    <pre className="whitespace-pre-wrap break-words">{detail?.msg_detalii ?? row.partener_xml ?? '—'}</pre>
                                )}
                            </div>
                        </div>

                        <details className="rounded-md border border-sidebar-border/70 dark:border-sidebar-border">
                            <summary className="cursor-pointer select-none px-3 py-2 text-xs font-medium">
                                XML brut
                            </summary>
                            <pre className="max-h-[280px] overflow-auto bg-muted/40 p-3 text-[11px]">
                                {loading ? 'Se încarcă…' : (detail?.msg_xml ?? '—')}
                            </pre>
                        </details>

                        <div className="rounded-md border border-sidebar-border/70 p-3 dark:border-sidebar-border">
                            <div className="mb-2 flex items-center justify-between">
                                <Label className="text-xs">Factură asociată</Label>
                                {row.invoice && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => linkInvoice(null)}
                                    >
                                        <Unlink className="size-4" /> Elimină asocierea
                                    </Button>
                                )}
                            </div>

                            {row.invoice ? (
                                <Link
                                    className="text-sm text-primary hover:underline"
                                    href={invoicesShow(row.invoice.id)}
                                >
                                    {row.invoice.tip_doc} {row.invoice.nr_doc} · {row.invoice.data_doc}
                                </Link>
                            ) : (
                                <p className="text-xs text-muted-foreground">
                                    Nicio factură asociată automat. Caută una mai jos.
                                </p>
                            )}

                            <form
                                className="mt-3 flex gap-2"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    fetchCandidates(searchTerm);
                                }}
                            >
                                <Input
                                    placeholder={`Caută după număr (default: ${row.nr_doc_xml ?? ''}) sau partener…`}
                                    value={searchTerm}
                                    onChange={(e) => setSearchTerm(e.target.value)}
                                />
                                <Button type="submit" variant="secondary" size="sm">
                                    <Search className="size-4" /> Caută
                                </Button>
                            </form>

                            {searching && <p className="mt-2 text-xs text-muted-foreground">Se caută…</p>}

                            {candidates.length > 0 && (
                                <ul className="mt-2 divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                                    {candidates.map((c) => (
                                        <li key={c.id} className="flex items-center justify-between py-2 text-xs">
                                            <div>
                                                <div className="font-medium">
                                                    {c.tip_doc} {c.nr_doc}
                                                </div>
                                                <div className="text-muted-foreground">
                                                    {c.data_doc ?? '—'} · {c.partner?.name ?? '—'} · {c.val_mon.toFixed(2)}{' '}
                                                    {c.moneda ?? ''}
                                                </div>
                                            </div>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() => linkInvoice(c.id)}
                                            >
                                                <LinkIcon className="size-4" /> Asociază
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            )}

                            {!searching && candidates.length === 0 && (
                                <p className="mt-2 text-xs text-muted-foreground">
                                    Apasă <strong>Caută</strong> pentru sugestii (default după numărul facturii).
                                </p>
                            )}
                        </div>
                    </div>
                )}

                <DialogFooter>
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Închide
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="grid gap-0.5">
            <Label className="text-[11px] uppercase text-muted-foreground">{label}</Label>
            <div className="text-sm">{children}</div>
        </div>
    );
}
