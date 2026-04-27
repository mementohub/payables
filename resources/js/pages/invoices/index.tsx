import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { Check, Clock, ShieldCheck } from 'lucide-react';
import { useMemo, useState } from 'react';
import InvoiceController from '@/actions/App/Http/Controllers/InvoiceController';
import ApprovalStatusBadge from '@/components/approval-status-badge';
import type {ApprovalStage} from '@/components/approval-status-badge';
import DateRangePicker from '@/components/date-range-picker';
import type {DateRangeValue} from '@/components/date-range-picker';
import Pagination from '@/components/pagination';
import PaymentStatusBadge from '@/components/payment-status-badge';
import type {PaymentStatus} from '@/components/payment-status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import { emise as facturiEmise, primite as facturiPrimite, show as invoicesShow } from '@/routes/invoices';
import type { Paginated } from '@/types/pagination';

type SupervisorStep = {
    department_id: number;
    department_name: string;
    approved: boolean;
    approved_by: { id: number; name: string } | null;
    approved_at: string | null;
};

type MasterApproval = {
    department_id: number;
    department_name: string | null;
    approved_by: { id: number; name: string } | null;
    approved_at: string | null;
} | null;

type Approval = {
    needs_approval: boolean;
    stage: ApprovalStage;
    supervisors_approved_at: string | null;
    is_fully_approved: boolean;
    fully_approved_at: string | null;
    supervisor_steps: SupervisorStep[];
    master: MasterApproval;
};

type InvoiceRow = {
    id: number;
    data_doc: string;
    data_scadenta: string | null;
    nr_doc: string;
    partener_type: 'furnizor' | 'client' | null;
    partner: { id: number; name: string; cui: string | null } | null;
    company: { id: number; name: string };
    moneda: string | null;
    val_mon: number;
    val_mon_tva: number;
    val_mon_paid: number;
    payment_status: PaymentStatus;
    approval?: Approval;
};

type Scope = 'emise' | 'primite';

type Filters = {
    search: string | null;
    company_id: number | null;
    payment: string | null;
    data_doc_from: string | null;
    data_doc_to: string | null;
    data_scadenta_from: string | null;
    data_scadenta_to: string | null;
    approval: string | null;
    responsible_id: number | null;
};

type CurrentUser = {
    id: number | null;
    supervisor_department_ids: number[];
    master_department_ids: number[];
};

type Props = {
    invoices: Paginated<InvoiceRow>;
    scope: Scope;
    filters: Filters;
    companies: { id: number; name: string }[];
    currentUser: CurrentUser;
    availableResponsibles: { id: number; name: string }[];
};

function formatAmount(value: number, currency: string | null) {
    return `${new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)} ${currency ?? ''}`.trim();
}

function formatDateTime(iso: string | null) {
    if (! iso) {
return '';
}

    const d = new Date(iso);

    return d.toLocaleString('ro-RO', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export default function InvoicesIndex({
    invoices,
    scope,
    filters,
    companies,
    currentUser,
    availableResponsibles,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const label = scope === 'emise' ? 'Facturi emise' : 'Facturi primite';
    const baseUrl = scope === 'emise' ? facturiEmise().url : facturiPrimite().url;
    const description =
        scope === 'emise'
            ? 'Facturi emise către clienți (FactCI / FactCE / FactINT) sincronizate din BD-urile companiilor.'
            : 'Facturi primite de la furnizori (FactFI / FactFE) sincronizate din BD-urile companiilor.';

    const applyFilter = (next: Partial<Filters>) => {
        const merged = { ...filters, ...next };
        router.get(
            baseUrl,
            {
                search: merged.search ?? undefined,
                company_id: merged.company_id ?? undefined,
                payment: merged.payment ?? undefined,
                data_doc_from: merged.data_doc_from ?? undefined,
                data_doc_to: merged.data_doc_to ?? undefined,
                data_scadenta_from: merged.data_scadenta_from ?? undefined,
                data_scadenta_to: merged.data_scadenta_to ?? undefined,
                approval: merged.approval ?? undefined,
                responsible_id: merged.responsible_id ?? undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const docRange: DateRangeValue = { from: filters.data_doc_from, to: filters.data_doc_to };
    const scadentaRange: DateRangeValue = { from: filters.data_scadenta_from, to: filters.data_scadenta_to };
    const isPrimite = scope === 'primite';
    const columnCount = isPrimite ? 9 : 7;

    return (
        <>
            <Head title={label} />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">{label}</h1>
                    <p className="text-sm text-muted-foreground">{description}</p>
                </div>

                <form
                    aria-label={`Filtre ${label.toLowerCase()}`}
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
                            placeholder={
                                scope === 'emise'
                                    ? 'Număr factură sau client…'
                                    : 'Număr factură sau furnizor…'
                            }
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
                        <Label className="text-xs">Data factură</Label>
                        <DateRangePicker
                            className="w-full sm:w-[230px]"
                            value={docRange}
                            onChange={(v) => applyFilter({ data_doc_from: v.from, data_doc_to: v.to })}
                            placeholder="Perioadă data"
                        />
                    </div>
                    <div className="grid w-full gap-1 sm:w-auto">
                        <Label className="text-xs">Data scadență</Label>
                        <DateRangePicker
                            className="w-full sm:w-[230px]"
                            value={scadentaRange}
                            onChange={(v) =>
                                applyFilter({ data_scadenta_from: v.from, data_scadenta_to: v.to })
                            }
                            placeholder="Perioadă scadență"
                        />
                    </div>
                    <div className="grid w-full gap-1 sm:w-auto">
                        <Label className="text-xs">Plată</Label>
                        <Select
                            value={filters.payment ?? 'all'}
                            onValueChange={(v) => applyFilter({ payment: v === 'all' ? null : v })}
                        >
                            <SelectTrigger className="min-h-11 w-full sm:w-[160px]">
                                <SelectValue placeholder="Plată" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Toate plățile</SelectItem>
                                <SelectItem value="paid">Plătite</SelectItem>
                                <SelectItem value="partial">Parțial</SelectItem>
                                <SelectItem value="unpaid">Neplătite</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    {isPrimite && (
                        <>
                            <div className="grid w-full gap-1 sm:w-auto">
                                <Label className="text-xs">Bun de plată</Label>
                                <Select
                                    value={filters.approval ?? 'all'}
                                    onValueChange={(v) => applyFilter({ approval: v === 'all' ? null : v })}
                                >
                                    <SelectTrigger className="min-h-11 w-full sm:w-[200px]">
                                        <SelectValue placeholder="Bun de plată" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">Toate</SelectItem>
                                        <SelectItem value="needs_approval">Necesită aprobare</SelectItem>
                                        <SelectItem value="pending">Așteaptă supervizor</SelectItem>
                                        <SelectItem value="supervisors_ok">Așteaptă master</SelectItem>
                                        <SelectItem value="ok">Bun de plată</SelectItem>
                                        <SelectItem value="na">Fără departament</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="grid w-full gap-1 sm:w-auto">
                                <Label className="text-xs">Responsabil</Label>
                                <Select
                                    value={filters.responsible_id ? String(filters.responsible_id) : 'all'}
                                    onValueChange={(v) =>
                                        applyFilter({ responsible_id: v === 'all' ? null : Number(v) })
                                    }
                                >
                                    <SelectTrigger className="min-h-11 w-full sm:w-[200px]">
                                        <SelectValue placeholder="Responsabil" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">Toți responsabilii</SelectItem>
                                        {availableResponsibles.map((u) => (
                                            <SelectItem key={u.id} value={String(u.id)}>
                                                {u.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </>
                    )}
                    <Button type="submit" variant="secondary" className="min-h-11 w-full sm:w-auto">
                        Caută
                    </Button>
                </form>

                <div className="hidden overflow-x-auto rounded-xl border border-sidebar-border/70 md:block dark:border-sidebar-border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left text-xs uppercase text-muted-foreground">
                            <tr>
                                <th className="px-4 py-3">Dată</th>
                                <th className="px-4 py-3">Scadență</th>
                                <th className="px-4 py-3">Număr</th>
                                <th className="px-4 py-3">{scope === 'emise' ? 'Client' : 'Furnizor'}</th>
                                <th className="px-4 py-3">Companie</th>
                                <th className="px-4 py-3 text-right">Total</th>
                                <th className="px-4 py-3">Plată</th>
                                {isPrimite && <th className="px-4 py-3">Bun de plată</th>}
                                {isPrimite && <th className="px-4 py-3">Acțiune</th>}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                            {invoices.data.length === 0 && (
                                <tr>
                                    <td className="px-4 py-6 text-center text-muted-foreground" colSpan={columnCount}>
                                        Nicio factură încă. Pornește o sincronizare din pagina Companii.
                                    </td>
                                </tr>
                            )}
                            {invoices.data.map((invoice) => (
                                <tr key={invoice.id} className="hover:bg-muted/30">
                                    <td className="px-4 py-3">{invoice.data_doc}</td>
                                    <td className="px-4 py-3 text-muted-foreground">
                                        {invoice.data_scadenta ?? '—'}
                                    </td>
                                    <td className="px-4 py-3 font-medium">
                                        <Link className="hover:underline" href={invoicesShow(invoice.id)}>
                                            {invoice.nr_doc}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3">
                                        {invoice.partner ? (
                                            <div>
                                                <div>{invoice.partner.name}</div>
                                                {invoice.partner.cui && (
                                                    <div className="text-xs text-muted-foreground">
                                                        CUI: {invoice.partner.cui}
                                                    </div>
                                                )}
                                            </div>
                                        ) : (
                                            <span className="text-muted-foreground">—</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-muted-foreground">{invoice.company.name}</td>
                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {formatAmount(invoice.val_mon, invoice.moneda)}
                                    </td>
                                    <td className="px-4 py-3">
                                        <PaymentStatusBadge status={invoice.payment_status} />
                                    </td>
                                    {isPrimite && (
                                        <td className="px-4 py-3">
                                            <ApprovalCell approval={invoice.approval} />
                                        </td>
                                    )}
                                    {isPrimite && (
                                        <td className="px-4 py-3">
                                            <ApproveActions
                                                invoice={invoice}
                                                currentUser={currentUser}
                                            />
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="space-y-3 md:hidden">
                    {invoices.data.length === 0 && (
                        <div className="rounded-xl border border-sidebar-border/70 bg-background p-6 text-center text-sm text-muted-foreground dark:border-sidebar-border">
                            Nicio factură încă. Pornește o sincronizare din pagina Companii.
                        </div>
                    )}
                    {invoices.data.map((invoice) => (
                        <InvoiceMobileCard
                            key={invoice.id}
                            invoice={invoice}
                            scope={scope}
                            isPrimite={isPrimite}
                            currentUser={currentUser}
                        />
                    ))}
                </div>

                <div className="flex items-center justify-between">
                    <span className="text-xs text-muted-foreground">
                        {invoices.from ?? 0}–{invoices.to ?? 0} din {invoices.total}
                    </span>
                    <Pagination links={invoices.links} />
                </div>
            </div>
        </>
    );
}

function ApprovalCell({ approval }: { approval?: Approval }) {
    if (! approval || ! approval.needs_approval) {
        return <ApprovalStatusBadge stage="na" />;
    }

    const supervisorsDone = approval.supervisor_steps.filter((s) => s.approved).length;
    const supervisorsTotal = approval.supervisor_steps.length;
    const masterDone = approval.master !== null ? 1 : 0;
    const totalDone = supervisorsDone + masterDone;
    const totalSteps = supervisorsTotal + 1;

    return (
        <div className="flex min-w-[180px] flex-col gap-1.5">
            <div className="flex items-center gap-2">
                <ApprovalStatusBadge stage={approval.stage} />
                <span className="text-[11px] font-medium tabular-nums text-muted-foreground">
                    {totalDone}/{totalSteps}
                </span>
            </div>
            <div className="flex flex-wrap items-center gap-1">
                {approval.supervisor_steps.map((step, index) => (
                    <div key={step.department_id} className="flex items-center gap-1">
                        <SupervisorPill step={step} />
                        {index < approval.supervisor_steps.length - 1 && (
                            <span aria-hidden className="text-muted-foreground/40">·</span>
                        )}
                    </div>
                ))}
                {supervisorsTotal > 0 && (
                    <span aria-hidden className="text-muted-foreground/40">·</span>
                )}
                <MasterPill master={approval.master} />
            </div>
        </div>
    );
}

function SupervisorPill({ step }: { step: SupervisorStep }) {
    const cls = step.approved
        ? 'border-green-600/40 bg-green-50 text-green-700 dark:bg-green-500/10 dark:text-green-300'
        : 'border-amber-600/40 bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300';

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span
                    className={`inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-medium leading-none ${cls}`}
                >
                    {step.approved ? <Check className="size-3" /> : <Clock className="size-3" />}
                    {step.department_name}
                </span>
            </TooltipTrigger>
            <TooltipContent>
                {step.approved && step.approved_by
                    ? `${step.approved_by.name} · ${formatDateTime(step.approved_at)}`
                    : 'În așteptare supervizor'}
            </TooltipContent>
        </Tooltip>
    );
}

function MasterPill({ master }: { master: MasterApproval }) {
    const approved = master !== null;
    const cls = approved
        ? 'border-green-600/40 bg-green-50 text-green-700 dark:bg-green-500/10 dark:text-green-300'
        : 'border-sky-600/40 bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300';

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span
                    className={`inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-medium leading-none ${cls}`}
                >
                    {approved ? <Check className="size-3" /> : <ShieldCheck className="size-3" />}
                    Master
                </span>
            </TooltipTrigger>
            <TooltipContent>
                {approved
                    ? `${master.approved_by?.name ?? 'Master'}${master.approved_at ? ` · ${formatDateTime(master.approved_at)}` : ''}`
                    : 'În așteptare master'}
            </TooltipContent>
        </Tooltip>
    );
}

function InvoiceMobileCard({
    invoice,
    scope,
    isPrimite,
    currentUser,
}: {
    invoice: InvoiceRow;
    scope: Scope;
    isPrimite: boolean;
    currentUser: CurrentUser;
}) {
    return (
        <div className="rounded-xl border border-sidebar-border/70 bg-background p-4 shadow-sm dark:border-sidebar-border">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <Link
                        href={invoicesShow(invoice.id)}
                        className="text-base font-semibold hover:underline"
                    >
                        {invoice.nr_doc}
                    </Link>
                    <div className="mt-0.5 text-xs text-muted-foreground">
                        {invoice.data_doc}
                        {invoice.data_scadenta ? ` · scadență ${invoice.data_scadenta}` : ''}
                    </div>
                </div>
                <PaymentStatusBadge status={invoice.payment_status} />
            </div>

            <div className="mt-3 text-sm">
                {invoice.partner ? (
                    <>
                        <div className="font-medium">{invoice.partner.name}</div>
                        {invoice.partner.cui && (
                            <div className="text-xs text-muted-foreground">
                                CUI: {invoice.partner.cui}
                            </div>
                        )}
                    </>
                ) : (
                    <span className="text-muted-foreground">
                        Fără {scope === 'emise' ? 'client' : 'furnizor'}
                    </span>
                )}
                <div className="mt-0.5 text-xs text-muted-foreground">{invoice.company.name}</div>
            </div>

            <div className="mt-3 flex items-end justify-between gap-3 border-t border-sidebar-border/70 pt-3 dark:border-sidebar-border">
                <div className="text-xs text-muted-foreground">Total</div>
                <div className="text-base font-semibold tabular-nums">
                    {formatAmount(invoice.val_mon, invoice.moneda)}
                </div>
            </div>

            {isPrimite && (
                <div className="mt-3 space-y-3 border-t border-sidebar-border/70 pt-3 dark:border-sidebar-border">
                    <div>
                        <div className="mb-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                            Bun de plată
                        </div>
                        <ApprovalCell approval={invoice.approval} />
                    </div>
                    <ApproveActions invoice={invoice} currentUser={currentUser} />
                </div>
            )}
        </div>
    );
}

function ApproveActions({
    invoice,
    currentUser,
}: {
    invoice: InvoiceRow;
    currentUser: CurrentUser;
}) {
    const approval = invoice.approval;
    const action = useMemo(() => {
        if (! approval || ! approval.needs_approval || approval.is_fully_approved || ! currentUser.id) {
            return null;
        }

        const pendingStep = approval.supervisor_steps.find(
            (s) => ! s.approved && currentUser.supervisor_department_ids.includes(s.department_id),
        );

        if (pendingStep) {
            return {
                departmentId: pendingStep.department_id,
                label: `OK supervizor (${pendingStep.department_name})`,
            };
        }

        if (
            approval.supervisors_approved_at !== null &&
            currentUser.master_department_ids.length > 0
        ) {
            return {
                departmentId: currentUser.master_department_ids[0],
                label: 'OK master',
            };
        }

        return null;
    }, [approval, currentUser]);

    if (! action) {
        return <span className="text-xs text-muted-foreground">—</span>;
    }

    return (
        <Form
            {...InvoiceController.approve.form(invoice.id)}
            options={{ preserveScroll: true }}
        >
            {({ processing }) => (
                <>
                    <input type="hidden" name="department_id" value={action.departmentId} />
                    <Button type="submit" size="sm" disabled={processing} className="w-full md:w-auto">
                        {action.label}
                    </Button>
                </>
            )}
        </Form>
    );
}

function InvoicesLayout({ children }: { children: React.ReactNode }) {
    const { scope } = usePage<Props>().props;
    const label = scope === 'emise' ? 'Facturi emise' : 'Facturi primite';
    const href = scope === 'emise' ? facturiEmise() : facturiPrimite();

    return <AppLayout breadcrumbs={[{ title: label, href }]}>{children}</AppLayout>;
}

InvoicesIndex.layout = (page: React.ReactNode) => (
    <InvoicesLayout>{page}</InvoicesLayout>
);
