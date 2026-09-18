import { Head, Link, router } from '@inertiajs/react';
import {
    Ban,
    CalendarClock,
    Check,
    CheckCheck,
    CircleAlert,
    Forward,
    MessageSquare,
    MoreHorizontal,
    RotateCcw,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import ApprovalController from '@/actions/App/Http/Controllers/Approvals/ApprovalController';
import DepartmentSelect from '@/components/department-select';
import DepartmentShares from '@/components/department-shares';
import {
    FilterField,
    filterInputClass,
    filterTriggerClass,
} from '@/components/filter-field';
import InputError from '@/components/input-error';
import Pagination from '@/components/pagination';
import { SelectionBar, useTableSelection } from '@/components/table-selection';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import WorkflowStatusBadge from '@/components/workflow-status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import { index as approvalsIndex } from '@/routes/approvals';
import { show as invoicesShow } from '@/routes/invoices';
import type {
    ApprovalsPageProps,
    DepartmentRef,
    WorkflowInvoice,
} from '@/types/approvals';

type Tab = ApprovalsPageProps['tab'];
type Filters = ApprovalsPageProps['filters'];
type Department = ApprovalsPageProps['departments'][number];
/** `redirected`: the department hands its share to another department. */
type Decision = 'approved' | 'disputed' | 'postponed' | 'redirected';
type Errors = Record<string, string>;

type Query = Filters & { tab: Tab };

/** What the decision dialog is about to send. */
type DecisionRequest =
    | {
          kind: 'department';
          decision: Decision;
          invoices: WorkflowInvoice[];
          departmentOptions: Department[];
          departmentId: number | null;
      }
    | { kind: 'final'; decision: Decision; invoices: WorkflowInvoice[] }
    | { kind: 'reopen'; invoice: WorkflowInvoice };

type DecisionValues = {
    comment: string;
    until: string;
    departmentId: number | null;
    toDepartmentId: number | null;
};

/** YYYY-MM-DD in the browser's time zone, `offsetDays` from today. */
function localIsoDate(offsetDays = 0): string {
    const date = new Date();
    date.setDate(date.getDate() + offsetDays);

    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

function hasPendingShare(invoice: WorkflowInvoice, departmentId: number) {
    return invoice.departments.some(
        (share) => share.id === departmentId && share.status === 'pending',
    );
}

/** The invoices a department still has to decide on. */
function idsPendingAt(invoices: WorkflowInvoice[], departmentId: number) {
    return invoices
        .filter((invoice) => hasPendingShare(invoice, departmentId))
        .map((invoice) => invoice.id);
}

function describeInvoices(invoices: WorkflowInvoice[]): string {
    if (invoices.length === 1) {
        const [invoice] = invoices;

        return `factura ${invoice.nr_doc}${invoice.partner ? ` (${invoice.partner.name})` : ''}`;
    }

    return `cele ${invoices.length} facturi selectate`;
}

function dialogCopy(request: DecisionRequest): {
    title: string;
    description: string;
    submit: string;
} {
    if (request.kind === 'reopen') {
        return {
            title: 'Redeschide factura',
            description: `Factura ${request.invoice.nr_doc} se întoarce la departamente pentru o nouă decizie.`,
            submit: 'Redeschide',
        };
    }

    const subject = describeInvoices(request.invoices);
    const isFinal = request.kind === 'final';

    switch (request.decision) {
        case 'approved':
            return {
                title: isFinal ? 'Aprobare finală' : 'Aprobare',
                description: isFinal
                    ? `Aprobați final ${subject}; după aprobare devine bună de plată.`
                    : `Aprobați ${subject} pentru departamentul ales.`,
                submit: isFinal ? 'Aprobă final' : 'Aprobă',
            };
        case 'disputed':
            return {
                title: 'Contestare',
                description: `Spuneți de ce contestați ${subject}.`,
                submit: 'Contestă',
            };
        case 'postponed':
            return {
                title: 'Amânare plată',
                description: `Alegeți data până la care se amână plata pentru ${subject}.`,
                submit: 'Amână',
            };
        case 'redirected':
            return {
                title: 'Redirecționează la alt departament',
                description: `Partea departamentului dumneavoastră din ${subject} merge la departamentul ales, care o va aproba. Spuneți de ce nu vă aparține.`,
                submit: 'Redirecționează',
            };
    }
}

export default function ApprovalsIndex({
    tab,
    rows,
    departments,
    all_departments: allDepartments,
    counts,
    filters,
    can,
}: ApprovalsPageProps) {
    const [search, setSearch] = useState(filters.search);
    const [request, setRequest] = useState<DecisionRequest | null>(null);
    const [dialogKey, setDialogKey] = useState(0);
    const [errors, setErrors] = useState<Errors>({});
    const [processing, setProcessing] = useState(false);
    const selection = useTableSelection(rows.data, rows.total);

    const activeDepartment =
        departments.find((d) => d.id === filters.department) ?? null;
    const canDecideMine = tab === 'mine' && departments.length > 0;
    const canDecideFinal = tab === 'final' && can.final;
    const canReopen = tab === 'blocked' && can.reopen;
    const selectable = canDecideMine || canDecideFinal;
    const showActions = selectable || canReopen;
    const columnCount = 5 + (selectable ? 1 : 0) + (showActions ? 1 : 0);
    const today = localIsoDate();

    const selectedInvoices = useMemo(
        () => rows.data.filter((invoice) => selection.selected.has(invoice.id)),
        [rows.data, selection.selected],
    );

    const tabs: { value: Tab; label: string; count: number }[] = [
        { value: 'mine', label: 'De aprobat', count: counts.mine },
        ...(can.final
            ? [
                  {
                      value: 'final' as const,
                      label: 'Aprobare finală',
                      count: counts.final,
                  },
              ]
            : []),
        {
            value: 'blocked',
            label: 'Contestate / amânate',
            count: counts.blocked,
        },
    ];

    const applyFilter = (next: Partial<Query>) => {
        const merged: Query = { ...filters, tab, ...next };

        router.get(
            approvalsIndex().url,
            {
                tab: merged.tab,
                search: merged.search || undefined,
                due_until: merged.due_until ?? undefined,
                department:
                    merged.tab === 'mine'
                        ? (merged.department ?? undefined)
                        : undefined,
                with_runs:
                    merged.tab === 'final' && merged.with_runs ? 1 : undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const post = (
        url: string,
        data: Record<string, string | number | number[] | null>,
    ) => {
        router.post(url, data, {
            preserveScroll: true,
            onStart: () => {
                setProcessing(true);
                setErrors({});
            },
            onFinish: () => setProcessing(false),
            onError: (errs) => setErrors(errs),
            onSuccess: () => {
                selection.clear();
                setRequest(null);
            },
        });
    };

    const openDialog = (next: DecisionRequest) => {
        setErrors({});
        setDialogKey((key) => key + 1);
        setRequest(next);
    };

    /** The user's departments that still have to decide on an invoice. */
    const pendingDepartmentsOf = (invoice: WorkflowInvoice): Department[] => {
        if (activeDepartment && hasPendingShare(invoice, activeDepartment.id)) {
            return [activeDepartment];
        }

        return departments.filter((d) => hasPendingShare(invoice, d.id));
    };

    /** The departments a decision on several invoices can be given for. */
    const bulkDepartmentOptions = (invoices: WorkflowInvoice[]) => {
        if (activeDepartment) {
            return [activeDepartment];
        }

        if (departments.length === 1) {
            return departments;
        }

        const relevant = departments.filter((d) =>
            invoices.some((invoice) => hasPendingShare(invoice, d.id)),
        );

        return relevant.length > 0 ? relevant : departments;
    };

    const decideForDepartment = (
        invoices: WorkflowInvoice[],
        departmentId: number,
        decision: Decision,
        comment: string | null = null,
        until: string | null = null,
    ) => {
        const pendingIds = idsPendingAt(invoices, departmentId);

        post(ApprovalController.decide().url, {
            invoice_ids:
                pendingIds.length > 0
                    ? pendingIds
                    : invoices.map((invoice) => invoice.id),
            department_id: departmentId,
            decision,
            comment,
            until,
        });
    };

    const decideFinal = (
        invoices: WorkflowInvoice[],
        decision: Decision,
        comment: string | null = null,
        until: string | null = null,
    ) => {
        post(ApprovalController.decideFinal().url, {
            invoice_ids: invoices.map((invoice) => invoice.id),
            decision,
            comment,
            until,
        });
    };

    const openRowDialog = (invoice: WorkflowInvoice, decision: Decision) => {
        if (tab === 'final') {
            openDialog({ kind: 'final', decision, invoices: [invoice] });

            return;
        }

        const options = pendingDepartmentsOf(invoice);

        openDialog({
            kind: 'department',
            decision,
            invoices: [invoice],
            departmentOptions: options,
            departmentId: options[0]?.id ?? null,
        });
    };

    const bulkDecide = (decision: Decision) => {
        if (selectedInvoices.length === 0) {
            return;
        }

        if (tab === 'final') {
            if (decision === 'approved') {
                decideFinal(selectedInvoices, decision);
            } else {
                openDialog({
                    kind: 'final',
                    decision,
                    invoices: selectedInvoices,
                });
            }

            return;
        }

        const options = bulkDepartmentOptions(selectedInvoices);

        if (decision === 'approved' && options.length === 1) {
            decideForDepartment(selectedInvoices, options[0].id, decision);

            return;
        }

        const coveringAll = options.filter(
            (d) =>
                idsPendingAt(selectedInvoices, d.id).length ===
                selectedInvoices.length,
        );

        openDialog({
            kind: 'department',
            decision,
            invoices: selectedInvoices,
            departmentOptions: options,
            departmentId:
                options.length === 1
                    ? options[0].id
                    : coveringAll.length === 1
                      ? coveringAll[0].id
                      : null,
        });
    };

    const submitDialog = (values: DecisionValues) => {
        if (request === null) {
            return;
        }

        const comment = values.comment !== '' ? values.comment : null;

        if (request.kind === 'reopen') {
            post(ApprovalController.reopen(request.invoice.id).url, {
                comment,
            });

            return;
        }

        const until = request.decision === 'postponed' ? values.until : null;

        if (request.kind === 'final') {
            decideFinal(request.invoices, request.decision, comment, until);

            return;
        }

        if (
            request.decision === 'redirected' &&
            values.departmentId !== null &&
            values.toDepartmentId !== null
        ) {
            const pendingIds = idsPendingAt(
                request.invoices,
                values.departmentId,
            );

            post(ApprovalController.redirect().url, {
                invoice_ids:
                    pendingIds.length > 0
                        ? pendingIds
                        : request.invoices.map((invoice) => invoice.id),
                department_id: values.departmentId,
                to_department_id: values.toDepartmentId,
                comment,
            });

            return;
        }

        if (values.departmentId !== null) {
            decideForDepartment(
                request.invoices,
                values.departmentId,
                request.decision,
                comment,
                until,
            );
        }
    };

    const pageErrors =
        request === null ? [...new Set(Object.values(errors))] : [];
    const hasFilters =
        filters.search !== '' ||
        filters.due_until !== null ||
        (tab === 'mine' && filters.department !== null);

    const emptyMessage = (() => {
        if (tab === 'mine' && departments.length === 0) {
            return 'Nu faceți parte din niciun departament, așa că nu aveți facturi de aprobat. Cereți unui administrator să vă adauge într-un departament.';
        }

        if (hasFilters) {
            return 'Nicio factură nu corespunde filtrelor.';
        }

        switch (tab) {
            case 'mine':
                return 'Nicio factură nu așteaptă aprobarea departamentelor dumneavoastră.';
            case 'final':
                return filters.with_runs
                    ? 'Nicio factură nu așteaptă aprobarea finală.'
                    : 'Nicio factură nu așteaptă aprobarea finală. Facturile din rulajele de plată apar dacă includeți rulajele.';
            case 'blocked':
                return 'Nicio factură contestată sau amânată.';
        }
    })();

    const bulkActions =
        tab === 'final' ? (
            <>
                <Button
                    type="button"
                    size="sm"
                    disabled={processing}
                    onClick={() => bulkDecide('approved')}
                >
                    <CheckCheck className="size-4" /> Aprobă final
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={processing}
                    onClick={() => bulkDecide('disputed')}
                >
                    <Ban className="size-4" /> Contestă
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={processing}
                    onClick={() => bulkDecide('postponed')}
                >
                    <CalendarClock className="size-4" /> Amână
                </Button>
            </>
        ) : (
            <>
                <Button
                    type="button"
                    size="sm"
                    disabled={processing}
                    onClick={() => bulkDecide('approved')}
                >
                    <Check className="size-4" /> Aprobă selecția
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={processing}
                    onClick={() => bulkDecide('disputed')}
                >
                    <Ban className="size-4" /> Contestă
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={processing}
                    onClick={() => bulkDecide('postponed')}
                >
                    <CalendarClock className="size-4" /> Amână
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={processing}
                    onClick={() => bulkDecide('redirected')}
                >
                    <Forward className="size-4" /> Redirecționează
                </Button>
            </>
        );

    const renderActions = (invoice: WorkflowInvoice) => {
        if (tab === 'blocked') {
            return (
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={processing}
                    onClick={() => openDialog({ kind: 'reopen', invoice })}
                >
                    <RotateCcw className="size-4" /> Redeschide
                </Button>
            );
        }

        const rowDepartment =
            tab === 'mine' ? (pendingDepartmentsOf(invoice)[0] ?? null) : null;
        const blocked = tab === 'mine' && rowDepartment === null;

        return (
            <div className="flex items-center justify-end gap-1">
                <Button
                    type="button"
                    size="sm"
                    disabled={processing || blocked}
                    title={
                        rowDepartment
                            ? `Aprobă pentru ${rowDepartment.name}`
                            : undefined
                    }
                    onClick={() => {
                        if (tab === 'final') {
                            decideFinal([invoice], 'approved');
                        } else if (rowDepartment) {
                            decideForDepartment(
                                [invoice],
                                rowDepartment.id,
                                'approved',
                            );
                        }
                    }}
                >
                    {tab === 'final' ? (
                        <>
                            <CheckCheck className="size-4" /> Aprobă final
                        </>
                    ) : (
                        <>
                            <Check className="size-4" /> Aprobă
                        </>
                    )}
                </Button>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            className="size-8"
                            disabled={processing || blocked}
                            aria-label={`Alte decizii pentru ${invoice.nr_doc}`}
                        >
                            <MoreHorizontal className="size-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem
                            onSelect={() => openRowDialog(invoice, 'approved')}
                        >
                            <MessageSquare /> Aprobă cu comentariu
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            variant="destructive"
                            onSelect={() => openRowDialog(invoice, 'disputed')}
                        >
                            <Ban /> Contestă
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            onSelect={() => openRowDialog(invoice, 'postponed')}
                        >
                            <CalendarClock /> Amână
                        </DropdownMenuItem>
                        {tab === 'mine' && (
                            <>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    onSelect={() =>
                                        openRowDialog(invoice, 'redirected')
                                    }
                                >
                                    <Forward /> Nu e al nostru: redirecționează
                                </DropdownMenuItem>
                            </>
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        );
    };

    return (
        <>
            <Head title="Aprobări" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">Aprobări</h1>
                    <p className="text-sm text-muted-foreground">
                        Facturile de furnizor care așteaptă decizia
                        departamentului dumneavoastră sau a Top Management.
                    </p>
                </div>

                <Tabs
                    value={tab}
                    onValueChange={(value) => {
                        const next = tabs.find((t) => t.value === value);

                        if (next) {
                            applyFilter({ tab: next.value });
                        }
                    }}
                >
                    <TabsList className="h-auto flex-wrap">
                        {tabs.map((t) => (
                            <TabsTrigger key={t.value} value={t.value}>
                                {t.label}
                                {t.count > 0 && (
                                    <span className="rounded-full bg-primary/10 px-1.5 py-0.5 text-[11px] font-semibold text-primary tabular-nums">
                                        {t.count}
                                    </span>
                                )}
                            </TabsTrigger>
                        ))}
                    </TabsList>
                </Tabs>

                <form
                    aria-label="Filtre aprobări"
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        applyFilter({ search: search.trim() });
                    }}
                >
                    <FilterField
                        label="Caută"
                        active={filters.search !== ''}
                        onClear={() => {
                            setSearch('');
                            applyFilter({ search: '' });
                        }}
                    >
                        <Input
                            className={cn(
                                'min-h-11 w-full sm:w-[260px]',
                                filterInputClass(filters.search !== ''),
                            )}
                            placeholder="Număr factură sau furnizor…"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                    </FilterField>

                    <FilterField
                        label="Scadență până la"
                        active={filters.due_until !== null}
                        onClear={() => applyFilter({ due_until: null })}
                    >
                        <Input
                            type="date"
                            className={cn(
                                'min-h-11 w-full sm:w-[190px]',
                                filterInputClass(filters.due_until !== null),
                            )}
                            value={filters.due_until ?? ''}
                            onChange={(e) =>
                                applyFilter({
                                    due_until: e.target.value || null,
                                })
                            }
                        />
                    </FilterField>

                    {tab === 'mine' && departments.length > 1 && (
                        <FilterField
                            label="Departament"
                            active={activeDepartment !== null}
                            onClear={() => applyFilter({ department: null })}
                        >
                            <Select
                                value={
                                    activeDepartment
                                        ? String(activeDepartment.id)
                                        : 'all'
                                }
                                onValueChange={(value) =>
                                    applyFilter({
                                        department:
                                            value === 'all'
                                                ? null
                                                : Number(value),
                                    })
                                }
                            >
                                <SelectTrigger
                                    className={cn(
                                        'min-h-11 w-full sm:w-[240px]',
                                        filterTriggerClass(
                                            activeDepartment !== null,
                                        ),
                                    )}
                                >
                                    <SelectValue placeholder="Departament" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        Toate departamentele mele
                                    </SelectItem>
                                    {departments.map((department) => (
                                        <SelectItem
                                            key={department.id}
                                            value={String(department.id)}
                                        >
                                            {department.name}
                                            <span className="text-muted-foreground tabular-nums">
                                                ({department.pending})
                                            </span>
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </FilterField>
                    )}

                    {tab === 'final' && (
                        <div className="flex min-h-11 items-center gap-2">
                            <Switch
                                id="with-runs"
                                checked={filters.with_runs}
                                onCheckedChange={(checked) =>
                                    applyFilter({ with_runs: checked })
                                }
                            />
                            <Label htmlFor="with-runs" className="text-sm">
                                Include facturile din rulajele de plată
                            </Label>
                        </div>
                    )}
                </form>

                {pageErrors.length > 0 && (
                    <Alert variant="destructive">
                        <CircleAlert />
                        <AlertTitle>Decizia nu a fost salvată</AlertTitle>
                        <AlertDescription>
                            {pageErrors.map((message) => (
                                <p key={message}>{message}</p>
                            ))}
                        </AlertDescription>
                    </Alert>
                )}

                {selectable && (
                    <SelectionBar
                        state={selection}
                        total={rows.data.length}
                        pageCount={rows.data.length}
                        actions={bulkActions}
                    />
                )}

                <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <Table>
                        <TableHeader className="bg-muted/50">
                            <TableRow>
                                {selectable && (
                                    <TableHead className="w-10">
                                        <Checkbox
                                            aria-label="Selectează pagina"
                                            checked={selection.pageCheckedValue}
                                            onCheckedChange={() =>
                                                selection.togglePage()
                                            }
                                        />
                                    </TableHead>
                                )}
                                <TableHead>Furnizor / factură</TableHead>
                                <TableHead>Data / Scadență</TableHead>
                                <TableHead className="text-right">
                                    De plată
                                </TableHead>
                                <TableHead>Departamente</TableHead>
                                <TableHead>Stare</TableHead>
                                {showActions && (
                                    <TableHead className="text-right">
                                        Acțiuni
                                    </TableHead>
                                )}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={columnCount}
                                        className="py-10 text-center whitespace-normal text-muted-foreground"
                                    >
                                        {emptyMessage}
                                    </TableCell>
                                </TableRow>
                            )}
                            {rows.data.map((invoice) => {
                                const overdue =
                                    invoice.data_scadenta !== null &&
                                    invoice.data_scadenta.slice(0, 10) < today;

                                return (
                                    <TableRow
                                        key={invoice.id}
                                        className="align-top"
                                        data-state={
                                            selection.isSelected(invoice.id)
                                                ? 'selected'
                                                : undefined
                                        }
                                    >
                                        {selectable && (
                                            <TableCell>
                                                <Checkbox
                                                    aria-label={`Selectează ${invoice.nr_doc}`}
                                                    checked={selection.isSelected(
                                                        invoice.id,
                                                    )}
                                                    onCheckedChange={() =>
                                                        selection.toggle(
                                                            invoice.id,
                                                        )
                                                    }
                                                />
                                            </TableCell>
                                        )}
                                        <TableCell className="max-w-[280px] whitespace-normal">
                                            <div className="font-medium">
                                                {invoice.partner?.name ?? '—'}
                                            </div>
                                            <Link
                                                href={invoicesShow(invoice.id)}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="text-xs text-muted-foreground hover:text-foreground hover:underline"
                                            >
                                                {invoice.nr_doc}
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            <div>
                                                {formatDate(invoice.data_doc)}
                                            </div>
                                            <div
                                                className={cn(
                                                    'text-xs',
                                                    overdue
                                                        ? 'font-medium text-red-600 dark:text-red-400'
                                                        : 'text-muted-foreground',
                                                )}
                                                title={
                                                    overdue
                                                        ? 'Scadența a trecut'
                                                        : undefined
                                                }
                                            >
                                                {formatDate(
                                                    invoice.data_scadenta,
                                                )}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            <div className="font-medium">
                                                {formatMoney(
                                                    invoice.outstanding,
                                                    invoice.moneda,
                                                )}
                                            </div>
                                            {Math.abs(
                                                invoice.val_mon -
                                                    invoice.outstanding,
                                            ) >= 0.01 && (
                                                <div className="text-xs text-muted-foreground">
                                                    din{' '}
                                                    {formatMoney(
                                                        invoice.val_mon,
                                                        invoice.moneda,
                                                    )}
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <DepartmentShares
                                                shares={invoice.departments}
                                                currency={invoice.moneda}
                                            />
                                        </TableCell>
                                        <TableCell className="whitespace-normal">
                                            <WorkflowStatusBadge
                                                status={invoice.approval_status}
                                            />
                                            {invoice.approval_status ===
                                                'postponed' &&
                                                invoice.postponed_until && (
                                                    <div className="mt-1 text-xs text-muted-foreground">
                                                        până la{' '}
                                                        {formatDate(
                                                            invoice.postponed_until,
                                                        )}
                                                    </div>
                                                )}
                                            {invoice.approval_track ===
                                                'run' && (
                                                <div className="mt-1 text-xs text-muted-foreground">
                                                    În rulaj de plată
                                                </div>
                                            )}
                                            {invoice.final?.comment && (
                                                <div
                                                    className="mt-1 line-clamp-2 max-w-[220px] text-xs text-muted-foreground italic"
                                                    title={
                                                        invoice.final.by
                                                            ? `${invoice.final.comment} — ${invoice.final.by}`
                                                            : invoice.final
                                                                  .comment
                                                    }
                                                >
                                                    „{invoice.final.comment}”
                                                </div>
                                            )}
                                        </TableCell>
                                        {showActions && (
                                            <TableCell className="text-right">
                                                {renderActions(invoice)}
                                            </TableCell>
                                        )}
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </div>

                <div className="flex flex-col items-center gap-3 sm:flex-row sm:justify-between">
                    <span className="text-xs text-muted-foreground">
                        {rows.from ?? 0}–{rows.to ?? 0} din {rows.total}
                    </span>
                    <Pagination links={rows.links} />
                </div>
            </div>

            <Dialog
                open={request !== null}
                onOpenChange={(open) => {
                    if (!open && !processing) {
                        setRequest(null);
                    }
                }}
            >
                <DialogContent className="sm:max-w-lg">
                    {request !== null && (
                        <DecisionForm
                            key={dialogKey}
                            request={request}
                            allDepartments={allDepartments}
                            processing={processing}
                            errors={errors}
                            onCancel={() => setRequest(null)}
                            onSubmit={submitDialog}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

function DecisionForm({
    request,
    allDepartments,
    processing,
    errors,
    onCancel,
    onSubmit,
}: {
    request: DecisionRequest;
    allDepartments: DepartmentRef[];
    processing: boolean;
    errors: Errors;
    onCancel: () => void;
    onSubmit: (values: DecisionValues) => void;
}) {
    const [comment, setComment] = useState('');
    const [until, setUntil] = useState('');
    const [departmentId, setDepartmentId] = useState<number | null>(
        request.kind === 'department' ? request.departmentId : null,
    );
    const [toDepartmentId, setToDepartmentId] = useState<number | null>(null);

    const copy = dialogCopy(request);
    const decision = request.kind === 'reopen' ? null : request.decision;
    const tomorrow = localIsoDate(1);
    const redirecting = decision === 'redirected';
    const needsComment = decision === 'disputed' || redirecting;
    const needsDate = decision === 'postponed';
    const invoices = request.kind === 'reopen' ? [] : request.invoices;
    const departmentOptions =
        request.kind === 'department' ? request.departmentOptions : [];
    const selectedDepartment =
        departmentOptions.find((d) => d.id === departmentId) ?? null;
    const coveredCount =
        departmentId !== null ? idsPendingAt(invoices, departmentId).length : 0;

    const otherErrors = [
        ...new Set(
            Object.entries(errors)
                .filter(
                    ([key]) =>
                        key !== 'comment' &&
                        key !== 'until' &&
                        key !== 'to_department_id',
                )
                .map(([, message]) => message),
        ),
    ];

    const canSubmit =
        !processing &&
        (!needsComment || comment.trim() !== '') &&
        (!needsDate || until >= tomorrow) &&
        (request.kind !== 'department' || departmentId !== null) &&
        (!redirecting || toDepartmentId !== null);

    const handleSubmit = (e: FormEvent<HTMLFormElement>) => {
        e.preventDefault();

        if (canSubmit) {
            onSubmit({
                comment: comment.trim(),
                until,
                departmentId,
                toDepartmentId,
            });
        }
    };

    return (
        <form onSubmit={handleSubmit} className="space-y-4">
            <DialogHeader>
                <DialogTitle>{copy.title}</DialogTitle>
                <DialogDescription>{copy.description}</DialogDescription>
            </DialogHeader>

            {request.kind === 'department' && (
                <div className="grid gap-2">
                    <Label htmlFor="decision-department">Departament</Label>
                    {departmentOptions.length > 1 ? (
                        <Select
                            value={
                                departmentId !== null
                                    ? String(departmentId)
                                    : ''
                            }
                            onValueChange={(value) =>
                                setDepartmentId(Number(value))
                            }
                        >
                            <SelectTrigger
                                id="decision-department"
                                className="w-full"
                            >
                                <SelectValue placeholder="Alegeți departamentul" />
                            </SelectTrigger>
                            <SelectContent>
                                {departmentOptions.map((department) => (
                                    <SelectItem
                                        key={department.id}
                                        value={String(department.id)}
                                    >
                                        {department.name}
                                        {invoices.length > 1 && (
                                            <span className="text-muted-foreground tabular-nums">
                                                (
                                                {
                                                    idsPendingAt(
                                                        invoices,
                                                        department.id,
                                                    ).length
                                                }
                                                /{invoices.length})
                                            </span>
                                        )}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    ) : (
                        <p id="decision-department" className="text-sm">
                            {selectedDepartment?.name ?? '—'}
                        </p>
                    )}
                    {invoices.length > 1 &&
                        departmentId !== null &&
                        coveredCount > 0 &&
                        coveredCount < invoices.length && (
                            <p className="text-xs text-amber-700 dark:text-amber-300">
                                Decizia se aplică celor {coveredCount} din{' '}
                                {invoices.length} facturi care așteaptă acest
                                departament.
                            </p>
                        )}
                </div>
            )}

            {redirecting && (
                <div className="grid gap-2">
                    <Label>Către departamentul</Label>
                    <DepartmentSelect
                        departments={allDepartments}
                        exclude={departmentId !== null ? [departmentId] : []}
                        value={
                            toDepartmentId !== null
                                ? String(toDepartmentId)
                                : ''
                        }
                        onChange={(value) => setToDepartmentId(Number(value))}
                    />
                    <InputError message={errors.to_department_id} />
                </div>
            )}

            {needsDate && (
                <div className="grid gap-2">
                    <Label htmlFor="decision-until">Amână până la</Label>
                    <Input
                        id="decision-until"
                        type="date"
                        min={tomorrow}
                        value={until}
                        onChange={(e) => setUntil(e.target.value)}
                        required
                        className="w-full sm:w-[200px]"
                    />
                    <InputError message={errors.until} />
                </div>
            )}

            <div className="grid gap-2">
                <Label htmlFor="decision-comment">
                    {redirecting
                        ? 'De ce nu este a departamentului dumneavoastră'
                        : needsComment
                          ? 'Motivul contestării'
                          : 'Comentariu (opțional)'}
                </Label>
                <Textarea
                    id="decision-comment"
                    value={comment}
                    onChange={(e) => setComment(e.target.value)}
                    maxLength={2000}
                    rows={4}
                    required={needsComment}
                    placeholder={
                        needsComment
                            ? 'Ce nu este în regulă cu factura?'
                            : 'Un comentariu pentru colegi…'
                    }
                />
                <InputError message={errors.comment} />
            </div>

            {otherErrors.length > 0 && (
                <div className="space-y-1">
                    {otherErrors.map((message) => (
                        <InputError key={message} message={message} />
                    ))}
                </div>
            )}

            <DialogFooter>
                <Button
                    type="button"
                    variant="outline"
                    onClick={onCancel}
                    disabled={processing}
                >
                    <X className="size-4" /> Anulează
                </Button>
                <Button
                    type="submit"
                    variant={
                        decision === 'disputed' ? 'destructive' : 'default'
                    }
                    disabled={!canSubmit}
                >
                    {processing ? 'Se salvează…' : copy.submit}
                </Button>
            </DialogFooter>
        </form>
    );
}

ApprovalsIndex.layout = (page: React.ReactNode) => (
    <AppLayout breadcrumbs={[{ title: 'Aprobări', href: approvalsIndex() }]}>
        {page}
    </AppLayout>
);
