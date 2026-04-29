import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { Check, ShieldCheck } from 'lucide-react';
import { useMemo, useState } from 'react';
import InvoiceController from '@/actions/App/Http/Controllers/InvoiceController';
import ApprovalStatusBadge from '@/components/approval-status-badge';
import DateRangePicker from '@/components/date-range-picker';
import type { DateRangeValue } from '@/components/date-range-picker';
import {
    FilterField,
    filterInputClass,
    filterTriggerClass,
} from '@/components/filter-field';
import Pagination from '@/components/pagination';
import PaymentStatusBadge from '@/components/payment-status-badge';
import {
    SelectionBar,
    downloadXlsxFromForm,
    useTableSelection,
} from '@/components/table-selection';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { cn } from '@/lib/utils';
import {
    emise as facturiEmise,
    primite as facturiPrimite,
    show as invoicesShow,
} from '@/routes/invoices';
import { exportMethod as exportEmise } from '@/routes/invoices/emise';
import { exportMethod as exportPrimite } from '@/routes/invoices/primite';
import type {
    Approval,
    CurrentUser,
    IndexFilters as Filters,
    IndexProps as Props,
    InvoiceRow,
    Scope,
} from './types';

function formatAmount(value: number, currency: string | null) {
    return `${new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)} ${currency ?? ''}`.trim();
}

function formatDateTime(iso: string | null) {
    if (!iso) {
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
    const baseUrl =
        scope === 'emise' ? facturiEmise().url : facturiPrimite().url;
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

    const docRange: DateRangeValue = {
        from: filters.data_doc_from,
        to: filters.data_doc_to,
    };
    const scadentaRange: DateRangeValue = {
        from: filters.data_scadenta_from,
        to: filters.data_scadenta_to,
    };
    const isPrimite = scope === 'primite';
    const columnCount = (isPrimite ? 9 : 7) + 1;
    const [exporting, setExporting] = useState(false);
    const selection = useTableSelection(invoices.data, invoices.total);

    const handleExport = () => {
        setExporting(true);
        const url = (scope === 'emise' ? exportEmise() : exportPrimite()).url;
        downloadXlsxFromForm(url, selection.payload(), {
            search: filters.search,
            company_id: filters.company_id,
            payment: filters.payment,
            data_doc_from: filters.data_doc_from,
            data_doc_to: filters.data_doc_to,
            data_scadenta_from: filters.data_scadenta_from,
            data_scadenta_to: filters.data_scadenta_to,
            approval: filters.approval,
            responsible_id: filters.responsible_id,
        });
        setTimeout(() => setExporting(false), 1500);
    };

    return (
        <>
            <Head title={label} />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">{label}</h1>
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                </div>

                <form
                    aria-label={`Filtre ${label.toLowerCase()}`}
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
                                'min-h-11 w-full sm:w-[260px]',
                                filterInputClass(!!search),
                            )}
                            placeholder={
                                scope === 'emise'
                                    ? 'Număr factură sau client…'
                                    : 'Număr factură sau furnizor…'
                            }
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
                                    'min-h-11 w-full sm:w-[200px]',
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
                    <div className="grid w-full gap-1 sm:w-auto">
                        <Label className="text-xs">Data factură</Label>
                        <DateRangePicker
                            className="w-full sm:w-[230px]"
                            value={docRange}
                            onChange={(v) =>
                                applyFilter({
                                    data_doc_from: v.from,
                                    data_doc_to: v.to,
                                })
                            }
                            placeholder="Perioadă data"
                        />
                    </div>
                    <div className="grid w-full gap-1 sm:w-auto">
                        <Label className="text-xs">Data scadență</Label>
                        <DateRangePicker
                            className="w-full sm:w-[230px]"
                            value={scadentaRange}
                            onChange={(v) =>
                                applyFilter({
                                    data_scadenta_from: v.from,
                                    data_scadenta_to: v.to,
                                })
                            }
                            placeholder="Perioadă scadență"
                        />
                    </div>
                    <FilterField
                        label="Plată"
                        active={!!filters.payment}
                        onClear={() => applyFilter({ payment: null })}
                    >
                        <Select
                            value={filters.payment ?? 'all'}
                            onValueChange={(v) =>
                                applyFilter({ payment: v === 'all' ? null : v })
                            }
                        >
                            <SelectTrigger
                                className={cn(
                                    'min-h-11 w-full sm:w-[160px]',
                                    filterTriggerClass(!!filters.payment),
                                )}
                            >
                                <SelectValue placeholder="Plată" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    Toate plățile
                                </SelectItem>
                                <SelectItem value="paid">Plătite</SelectItem>
                                <SelectItem value="partial">Parțial</SelectItem>
                                <SelectItem value="unpaid">
                                    Neplătite
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </FilterField>
                    {isPrimite && (
                        <>
                            <FilterField
                                label="Bun de plată"
                                active={!!filters.approval}
                                onClear={() => applyFilter({ approval: null })}
                            >
                                <Select
                                    value={filters.approval ?? 'all'}
                                    onValueChange={(v) =>
                                        applyFilter({
                                            approval: v === 'all' ? null : v,
                                        })
                                    }
                                >
                                    <SelectTrigger
                                        className={cn(
                                            'min-h-11 w-full sm:w-[200px]',
                                            filterTriggerClass(
                                                !!filters.approval,
                                            ),
                                        )}
                                    >
                                        <SelectValue placeholder="Bun de plată" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">
                                            Toate
                                        </SelectItem>
                                        <SelectItem value="needs_approval">
                                            Necesită aprobare
                                        </SelectItem>
                                        <SelectItem value="pending">
                                            Așteaptă supervizor
                                        </SelectItem>
                                        <SelectItem value="supervisors_ok">
                                            Așteaptă master
                                        </SelectItem>
                                        <SelectItem value="ok">
                                            Bun de plată
                                        </SelectItem>
                                        <SelectItem value="na">
                                            Fără departament
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </FilterField>
                            <FilterField
                                label="Responsabil"
                                active={!!filters.responsible_id}
                                onClear={() =>
                                    applyFilter({ responsible_id: null })
                                }
                            >
                                <Select
                                    value={
                                        filters.responsible_id
                                            ? String(filters.responsible_id)
                                            : 'all'
                                    }
                                    onValueChange={(v) =>
                                        applyFilter({
                                            responsible_id:
                                                v === 'all' ? null : Number(v),
                                        })
                                    }
                                >
                                    <SelectTrigger
                                        className={cn(
                                            'min-h-11 w-full sm:w-[200px]',
                                            filterTriggerClass(
                                                !!filters.responsible_id,
                                            ),
                                        )}
                                    >
                                        <SelectValue placeholder="Responsabil" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">
                                            Toți responsabilii
                                        </SelectItem>
                                        {availableResponsibles.map((u) => (
                                            <SelectItem
                                                key={u.id}
                                                value={String(u.id)}
                                            >
                                                {u.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </FilterField>
                        </>
                    )}
                    <Button
                        type="submit"
                        variant="secondary"
                        className="min-h-11 w-full sm:w-auto"
                    >
                        Caută
                    </Button>
                </form>

                <SelectionBar
                    state={selection}
                    total={invoices.total}
                    pageCount={invoices.data.length}
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
                                <th className="px-4 py-3">Dată</th>
                                <th className="px-4 py-3">Scadență</th>
                                <th className="px-4 py-3">Număr</th>
                                <th className="px-4 py-3">
                                    {scope === 'emise' ? 'Client' : 'Furnizor'}
                                </th>
                                <th className="px-4 py-3">Companie</th>
                                <th className="px-4 py-3 text-right">Total</th>
                                <th className="px-4 py-3">Plată</th>
                                {isPrimite && (
                                    <th className="px-4 py-3">Bun de plată</th>
                                )}
                                {isPrimite && (
                                    <th className="px-4 py-3">Acțiune</th>
                                )}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                            {invoices.data.length === 0 && (
                                <tr>
                                    <td
                                        className="px-4 py-6 text-center text-muted-foreground"
                                        colSpan={columnCount}
                                    >
                                        Nicio factură încă. Pornește o
                                        sincronizare din pagina Companii.
                                    </td>
                                </tr>
                            )}
                            {invoices.data.map((invoice) => (
                                <tr
                                    key={invoice.id}
                                    className="hover:bg-muted/30"
                                >
                                    <td className="px-4 py-3">
                                        <Checkbox
                                            aria-label={`Selectează ${invoice.nr_doc}`}
                                            checked={selection.isSelected(
                                                invoice.id,
                                            )}
                                            onCheckedChange={() =>
                                                selection.toggle(invoice.id)
                                            }
                                        />
                                    </td>
                                    <td className="px-4 py-3">
                                        {invoice.data_doc}
                                    </td>
                                    <td className="px-4 py-3 text-muted-foreground">
                                        {invoice.data_scadenta ?? '—'}
                                    </td>
                                    <td className="px-4 py-3 font-medium">
                                        <Link
                                            className="hover:underline"
                                            href={invoicesShow(invoice.id)}
                                        >
                                            {invoice.nr_doc}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3">
                                        {invoice.partner ? (
                                            <div>
                                                <div>
                                                    {invoice.partner.name}
                                                </div>
                                                {invoice.partner.cui && (
                                                    <div className="text-xs text-muted-foreground">
                                                        CUI:{' '}
                                                        {invoice.partner.cui}
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
                                        {invoice.company.name}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {formatAmount(
                                            invoice.val_mon,
                                            invoice.moneda,
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        <PaymentStatusBadge
                                            status={invoice.payment_status}
                                        />
                                    </td>
                                    {isPrimite && (
                                        <td className="px-4 py-3">
                                            <ApprovalCell
                                                approval={invoice.approval}
                                            />
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
                            Nicio factură încă. Pornește o sincronizare din
                            pagina Companii.
                        </div>
                    )}
                    {invoices.data.map((invoice) => (
                        <InvoiceMobileCard
                            key={invoice.id}
                            invoice={invoice}
                            scope={scope}
                            isPrimite={isPrimite}
                            currentUser={currentUser}
                            selected={selection.isSelected(invoice.id)}
                            onToggle={() => selection.toggle(invoice.id)}
                        />
                    ))}
                </div>

                <div className="flex flex-col items-center gap-3 sm:flex-row sm:justify-between">
                    <span className="text-xs text-muted-foreground">
                        {invoices.from ?? 0}–{invoices.to ?? 0} din{' '}
                        {invoices.total}
                    </span>
                    <Pagination links={invoices.links} />
                </div>
            </div>
        </>
    );
}

function ApprovalCell({ approval }: { approval?: Approval }) {
    if (!approval || !approval.needs_approval) {
        return <ApprovalStatusBadge stage="na" />;
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span className="inline-flex cursor-help items-center">
                    <ApprovalStatusBadge stage={approval.stage} />
                </span>
            </TooltipTrigger>
            <TooltipContent className="flex max-w-sm flex-col items-stretch gap-1.5 text-left">
                {approval.supervisor_steps.map((step) => (
                    <div
                        key={step.department_id}
                        className="flex flex-col gap-0.5"
                    >
                        <div className="flex items-center gap-1.5 font-medium">
                            {step.approved ? (
                                <Check className="size-3 text-green-500" />
                            ) : (
                                <span className="inline-block size-1.5 rounded-full bg-amber-400" />
                            )}
                            {step.department_name}
                        </div>
                        <div className="pl-4.5 text-muted-foreground">
                            {step.approved && step.approved_by
                                ? `${step.approved_by.name} · ${formatDateTime(step.approved_at)}`
                                : 'în așteptare'}
                        </div>
                    </div>
                ))}
                <div className="flex flex-col gap-0.5">
                    <div className="flex items-center gap-1.5 font-medium">
                        {approval.master ? (
                            <Check className="size-3 text-green-500" />
                        ) : (
                            <ShieldCheck className="size-3 text-sky-500" />
                        )}
                        Master
                    </div>
                    <div className="pl-4.5 text-muted-foreground">
                        {approval.master
                            ? `${approval.master.approved_by?.name ?? 'Master'}${approval.master.approved_at ? ` · ${formatDateTime(approval.master.approved_at)}` : ''}`
                            : 'în așteptare'}
                    </div>
                </div>
            </TooltipContent>
        </Tooltip>
    );
}

function InvoiceMobileCard({
    invoice,
    scope,
    isPrimite,
    currentUser,
    selected,
    onToggle,
}: {
    invoice: InvoiceRow;
    scope: Scope;
    isPrimite: boolean;
    currentUser: CurrentUser;
    selected: boolean;
    onToggle: () => void;
}) {
    return (
        <div className="rounded-xl border border-sidebar-border/70 bg-background p-4 shadow-sm dark:border-sidebar-border">
            <div className="flex items-start justify-between gap-3">
                <div className="flex min-w-0 items-start gap-3">
                    <Checkbox
                        aria-label={`Selectează ${invoice.nr_doc}`}
                        checked={selected}
                        onCheckedChange={onToggle}
                        className="mt-1"
                    />
                    <div className="min-w-0">
                        <Link
                            href={invoicesShow(invoice.id)}
                            className="text-base font-semibold hover:underline"
                        >
                            {invoice.nr_doc}
                        </Link>
                        <div className="mt-0.5 text-xs text-muted-foreground">
                            {invoice.data_doc}
                            {invoice.data_scadenta
                                ? ` · scadență ${invoice.data_scadenta}`
                                : ''}
                        </div>
                    </div>
                </div>
                <PaymentStatusBadge status={invoice.payment_status} />
            </div>

            <div className="mt-3 text-sm">
                {invoice.partner ? (
                    <>
                        <div className="font-medium">
                            {invoice.partner.name}
                        </div>
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
                <div className="mt-0.5 text-xs text-muted-foreground">
                    {invoice.company.name}
                </div>
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
                        <div className="mb-1 text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
                            Bun de plată
                        </div>
                        <ApprovalCell approval={invoice.approval} />
                    </div>
                    <ApproveActions
                        invoice={invoice}
                        currentUser={currentUser}
                        fullWidth
                    />
                </div>
            )}
        </div>
    );
}

function ApproveActions({
    invoice,
    currentUser,
    fullWidth = false,
}: {
    invoice: InvoiceRow;
    currentUser: CurrentUser;
    fullWidth?: boolean;
}) {
    const approval = invoice.approval;
    const action = useMemo(() => {
        if (
            !approval ||
            !approval.needs_approval ||
            approval.is_fully_approved ||
            !currentUser.id
        ) {
            return null;
        }

        const pendingStep = approval.supervisor_steps.find(
            (s) =>
                !s.approved &&
                currentUser.supervisor_department_ids.includes(s.department_id),
        );

        if (pendingStep) {
            return {
                departmentId: pendingStep.department_id,
                kind: 'supervisor' as const,
                label: 'Aprobă',
                hint: pendingStep.department_name,
            };
        }

        if (
            approval.supervisors_approved_at !== null &&
            currentUser.master_department_ids.length > 0
        ) {
            return {
                departmentId: currentUser.master_department_ids[0],
                kind: 'master' as const,
                label: 'Aprobă',
                hint: 'final',
            };
        }

        return null;
    }, [approval, currentUser]);

    if (!action) {
        return <span className="text-xs text-muted-foreground">—</span>;
    }

    const isSupervisor = action.kind === 'supervisor';
    const Icon = isSupervisor ? Check : ShieldCheck;
    const colorClass = isSupervisor
        ? 'border-green-600/40 bg-green-50 text-green-700 hover:bg-green-100 dark:bg-green-500/10 dark:text-green-300 dark:hover:bg-green-500/20'
        : 'border-sky-600/40 bg-sky-50 text-sky-700 hover:bg-sky-100 dark:bg-sky-500/10 dark:text-sky-300 dark:hover:bg-sky-500/20';

    return (
        <Form
            {...InvoiceController.approve.form(invoice.id)}
            options={{ preserveScroll: true }}
        >
            {({ processing }) => (
                <>
                    <input
                        type="hidden"
                        name="department_id"
                        value={action.departmentId}
                    />
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <Button
                                type="submit"
                                size="sm"
                                variant="outline"
                                disabled={processing}
                                className={cn(
                                    colorClass,
                                    fullWidth && 'w-full',
                                )}
                            >
                                <Icon />
                                {action.label}
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>
                            {isSupervisor
                                ? `Aprobă ca supervizor (${action.hint})`
                                : 'Aprobă ca master (semnătura finală)'}
                        </TooltipContent>
                    </Tooltip>
                </>
            )}
        </Form>
    );
}

function InvoicesLayout({ children }: { children: React.ReactNode }) {
    const { scope } = usePage<Props>().props;
    const label = scope === 'emise' ? 'Facturi emise' : 'Facturi primite';
    const href = scope === 'emise' ? facturiEmise() : facturiPrimite();

    return (
        <AppLayout breadcrumbs={[{ title: label, href }]}>{children}</AppLayout>
    );
}

InvoicesIndex.layout = (page: React.ReactNode) => (
    <InvoicesLayout>{page}</InvoicesLayout>
);
