import { Head, Link, router } from '@inertiajs/react';
import {
    Ban,
    Check,
    CheckCheck,
    Download,
    Lock,
    MoreHorizontal,
    Undo2,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import ApprovalController from '@/actions/App/Http/Controllers/Approvals/ApprovalController';
import PaymentRunController from '@/actions/App/Http/Controllers/Approvals/PaymentRunController';
import DepartmentShares from '@/components/department-shares';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import WorkflowStatusBadge from '@/components/workflow-status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatByCurrency, formatDate, formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import { BtPaymentDialog } from '@/pages/invoices/bt-payment-dialog';
import { show as invoicesShow } from '@/routes/invoices';
import { index as paymentRunsIndex } from '@/routes/payment-runs';
import type {
    CashPosition,
    DepartmentShare,
    PaymentRunItem,
    PaymentRunPageProps,
} from '@/types/approvals';
import { RunStatusBadge } from './index';

type Decision = 'approved' | 'disputed' | 'postponed';

/** Who decides: one department on its share, or Top Management. */
type DecisionTarget =
    | { kind: 'department'; id: number; name: string }
    | { kind: 'final' };

/** A dispute or postponement waiting for its reason / date in the dialog. */
type PendingDecision = {
    invoiceIds: number[];
    target: DecisionTarget;
    decision: Exclude<Decision, 'approved'>;
    subject: string;
    onDone?: () => void;
};

type ItemGroup = {
    key: string;
    department: { id: number; name: string } | null;
    items: PaymentRunItem[];
    includedCount: number;
    totals: Record<string, number>;
};

function formatDateTime(value: string | null): string {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat('ro-RO', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
}

/** Today as YYYY-MM-DD in the user's time zone. */
function localToday(): string {
    const now = new Date();
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

function invoicesLabel(count: number): string {
    return count === 1 ? 'o factură' : `${count} facturi`;
}

function toastFirstError(errors: Record<string, string>): void {
    toast.error(
        Object.values(errors)[0] ?? 'Operația nu a putut fi efectuată.',
    );
}

/** The pending department shares of an invoice the user decides for. */
function approvableShares(
    item: PaymentRunItem,
    myDepartments: number[] | null,
): DepartmentShare[] {
    if (item.status !== 'included' || !item.invoice) {
        return [];
    }

    return item.invoice.departments.filter(
        (share) =>
            share.status === 'pending' &&
            (myDepartments === null || myDepartments.includes(share.id)),
    );
}

function groupItems(items: PaymentRunItem[]): ItemGroup[] {
    const groups = new Map<string, ItemGroup>();

    for (const item of items) {
        const key = item.department ? String(item.department.id) : 'none';
        const group = groups.get(key) ?? {
            key,
            department: item.department,
            items: [],
            includedCount: 0,
            totals: {},
        };

        group.items.push(item);

        if (item.status === 'included') {
            const currency = item.currency ?? 'RON';

            group.includedCount++;
            group.totals[currency] =
                (group.totals[currency] ?? 0) + item.amount;
        }

        groups.set(key, group);
    }

    return [...groups.values()];
}

function statusExplanation(
    status: PaymentRunPageProps['run']['status'],
    items: PaymentRunItem[],
    payableCount: number,
): string {
    const included = items.filter((item) => item.status === 'included');
    const waiting = included.filter(
        (item) =>
            item.invoice?.approval_status === 'routing' ||
            item.invoice?.approval_status === 'department',
    ).length;
    const blocked = included.filter(
        (item) =>
            item.invoice?.approval_status === 'disputed' ||
            item.invoice?.approval_status === 'postponed',
    ).length;

    switch (status) {
        case 'review': {
            const parts: string[] = [];

            if (waiting > 0) {
                parts.push(
                    `Așteaptă ca departamentele să aprobe ${invoicesLabel(waiting)}.`,
                );
            }

            if (blocked > 0) {
                parts.push(
                    `${blocked === 1 ? 'O factură este contestată sau amânată' : `${blocked} facturi sunt contestate sau amânate`}: scoateți-le din rulaj sau rezolvați-le.`,
                );
            }

            return parts.length > 0
                ? parts.join(' ')
                : 'Așteaptă deciziile departamentelor.';
        }
        case 'final':
            return 'Toate departamentele și-au aprobat facturile. Așteaptă aprobarea Top Management.';
        case 'approved':
            return `Aprobat. Trezoreria descarcă fișierul BT pentru ${invoicesLabel(payableCount)}.`;
        case 'exported':
            return 'Trimis la bancă. Rulajul se închide singur când OMC înregistrează plățile.';
        case 'closed':
            return 'Rulajul este închis.';
        case 'cancelled':
            return 'Rulajul a fost anulat.';
    }
}

export default function PaymentRunShow({
    run,
    cash,
    items,
    my_departments: myDepartments,
    can,
    payable_invoice_ids: payableInvoiceIds,
}: PaymentRunPageProps) {
    const [approveOpen, setApproveOpen] = useState(false);
    const [approveComment, setApproveComment] = useState('');
    const [btOpen, setBtOpen] = useState(false);
    const [closeMode, setCloseMode] = useState<'close' | 'cancel' | null>(null);
    const [pending, setPending] = useState<PendingDecision | null>(null);
    const [busy, setBusy] = useState(false);

    const groups = useMemo(() => groupItems(items), [items]);
    const btRequest = useMemo(
        () => ({ invoice_ids: payableInvoiceIds }),
        [payableInvoiceIds],
    );

    const requestOptions = (onSuccess?: () => void) => ({
        preserveScroll: true,
        onStart: () => setBusy(true),
        onFinish: () => setBusy(false),
        onSuccess: () => onSuccess?.(),
        onError: toastFirstError,
    });

    const decide = (
        invoiceIds: number[],
        target: DecisionTarget,
        decision: Decision,
        extra: { comment?: string; until?: string } = {},
        onSuccess?: () => void,
    ) => {
        if (target.kind === 'final') {
            router.post(
                ApprovalController.decideFinal.url(),
                { invoice_ids: invoiceIds, decision, ...extra },
                requestOptions(onSuccess),
            );

            return;
        }

        router.post(
            ApprovalController.decide.url(),
            {
                invoice_ids: invoiceIds,
                department_id: target.id,
                decision,
                ...extra,
            },
            requestOptions(onSuccess),
        );
    };

    const toggleItem = (item: PaymentRunItem) => {
        router.post(
            PaymentRunController.toggle({ run: run.id, item: item.id }).url,
            { included: item.status === 'excluded' },
            requestOptions(),
        );
    };

    const approveRun = () => {
        router.post(
            PaymentRunController.approve(run.id).url,
            { comment: approveComment.trim() || null },
            requestOptions(() => {
                setApproveOpen(false);
                setApproveComment('');
            }),
        );
    };

    const closeRun = (cancel: boolean) => {
        router.post(
            PaymentRunController.close(run.id).url,
            { cancel },
            requestOptions(() => setCloseMode(null)),
        );
    };

    const markExported = () => {
        router.post(
            PaymentRunController.exported(run.id).url,
            {},
            requestOptions(),
        );
    };

    const hasActions = can.approve || can.export || can.close;

    return (
        <>
            <Head title={run.reference} />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <RunHeader run={run} />

                <CashPositionCard cash={cash} payDate={run.pay_date} />

                <div className="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-sidebar-border">
                    <p className="text-sm text-muted-foreground">
                        {statusExplanation(
                            run.status,
                            items,
                            payableInvoiceIds.length,
                        )}
                    </p>

                    {hasActions && (
                        <div className="flex flex-wrap gap-2">
                            {can.approve && (
                                <Button
                                    onClick={() => setApproveOpen(true)}
                                    disabled={busy}
                                >
                                    <CheckCheck />
                                    Aprobă rulajul
                                </Button>
                            )}
                            {can.export && (
                                <Button
                                    onClick={() => setBtOpen(true)}
                                    disabled={
                                        busy || payableInvoiceIds.length === 0
                                    }
                                >
                                    <Download />
                                    Descarcă fișierul BT
                                </Button>
                            )}
                            {can.close && (
                                <>
                                    <Button
                                        variant="outline"
                                        onClick={() => setCloseMode('close')}
                                        disabled={busy}
                                    >
                                        <Lock />
                                        Închide rulajul
                                    </Button>
                                    <Button
                                        variant="outline"
                                        className="text-destructive"
                                        onClick={() => setCloseMode('cancel')}
                                        disabled={busy}
                                    >
                                        <Ban />
                                        Anulează
                                    </Button>
                                </>
                            )}
                        </div>
                    )}
                </div>

                {groups.length === 0 ? (
                    <div className="rounded-xl border border-dashed border-sidebar-border/70 p-10 text-center text-sm text-muted-foreground dark:border-sidebar-border">
                        Rulajul nu conține nicio factură.
                    </div>
                ) : (
                    groups.map((group) => (
                        <DepartmentGroup
                            key={group.key}
                            group={group}
                            myDepartments={myDepartments}
                            canEdit={can.edit}
                            canFinal={can.final}
                            busy={busy}
                            onDecide={decide}
                            onAskDecision={setPending}
                            onToggle={toggleItem}
                        />
                    ))
                )}
            </div>

            <Dialog open={approveOpen} onOpenChange={setApproveOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Aprobă {run.reference}</DialogTitle>
                        <DialogDescription>
                            Facturile din rulaj primesc aprobarea finală, iar
                            Trezoreria poate trimite plata la bancă. Total:{' '}
                            {formatByCurrency(run.totals.by_currency)}.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="approve-comment">
                            Comentariu (opțional)
                        </Label>
                        <Textarea
                            id="approve-comment"
                            rows={3}
                            value={approveComment}
                            onChange={(e) => setApproveComment(e.target.value)}
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            onClick={() => setApproveOpen(false)}
                        >
                            Renunță
                        </Button>
                        <Button onClick={approveRun} disabled={busy}>
                            <CheckCheck />
                            Aprobă rulajul
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={closeMode !== null}
                onOpenChange={(open) => !open && setCloseMode(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {closeMode === 'cancel'
                                ? `Anulați ${run.reference}?`
                                : `Închideți ${run.reference}?`}
                        </DialogTitle>
                        <DialogDescription>
                            {closeMode === 'cancel'
                                ? 'Rulajul se anulează și nu se mai plătește. Facturile rămân deschise pentru un rulaj următor.'
                                : 'Rulajul se închide și nu mai poate fi modificat. Închideți-l doar dacă plățile au fost făcute sau nu mai sunt necesare.'}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            onClick={() => setCloseMode(null)}
                        >
                            Renunță
                        </Button>
                        <Button
                            variant={
                                closeMode === 'cancel'
                                    ? 'destructive'
                                    : 'default'
                            }
                            onClick={() => closeRun(closeMode === 'cancel')}
                            disabled={busy}
                        >
                            {closeMode === 'cancel'
                                ? 'Anulează rulajul'
                                : 'Închide rulajul'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {pending && (
                <DecisionDialog
                    key={`${pending.decision}-${pending.invoiceIds.join(',')}`}
                    pending={pending}
                    busy={busy}
                    onClose={() => setPending(null)}
                    onSubmit={(extra) =>
                        decide(
                            pending.invoiceIds,
                            pending.target,
                            pending.decision,
                            extra,
                            () => {
                                pending.onDone?.();
                                setPending(null);
                            },
                        )
                    }
                />
            )}

            {can.export && (
                <BtPaymentDialog
                    open={btOpen}
                    onOpenChange={setBtOpen}
                    request={btRequest}
                    onDownloaded={markExported}
                />
            )}
        </>
    );
}

function RunHeader({ run }: { run: PaymentRunPageProps['run'] }) {
    return (
        <div className="flex flex-col gap-2">
            <div className="flex flex-wrap items-center gap-3">
                <h1 className="text-xl font-semibold">{run.reference}</h1>
                <RunStatusBadge status={run.status} />
            </div>
            <dl className="flex flex-wrap gap-x-6 gap-y-1 text-sm">
                <div className="flex gap-1.5">
                    <dt className="text-muted-foreground">Data plății:</dt>
                    <dd className="font-medium tabular-nums">
                        {formatDate(run.pay_date)}
                    </dd>
                </div>
                <div className="flex gap-1.5">
                    <dt className="text-muted-foreground">Scadență până la:</dt>
                    <dd className="tabular-nums">
                        {formatDate(run.due_until)}
                    </dd>
                </div>
                <div className="flex gap-1.5">
                    <dt className="text-muted-foreground">Facturi:</dt>
                    <dd className="tabular-nums">
                        {run.totals.count} ·{' '}
                        {formatByCurrency(run.totals.by_currency)}
                    </dd>
                </div>
            </dl>
            <p className="text-xs text-muted-foreground">
                {[
                    run.created_by ? `Creat de ${run.created_by}` : null,
                    run.approved_by
                        ? `Aprobat de ${run.approved_by} la ${formatDateTime(run.approved_at)}`
                        : null,
                    run.exported_by
                        ? `Trimis la bancă de ${run.exported_by} la ${formatDateTime(run.exported_at)}`
                        : null,
                ]
                    .filter(Boolean)
                    .join(' · ')}
            </p>
            {run.note && (
                <p className="max-w-3xl text-sm whitespace-pre-line">
                    {run.note}
                </p>
            )}
        </div>
    );
}

function CashPositionCard({
    cash,
    payDate,
}: {
    cash: CashPosition;
    payDate: string;
}) {
    const currencies = Object.keys(cash.by_currency);
    const showByCurrency =
        currencies.length > 1 ||
        (currencies.length === 1 && currencies[0] !== 'RON');

    return (
        <Card className="gap-4 py-4">
            <CardHeader className="px-4">
                <CardTitle>Poziția de numerar</CardTitle>
                <CardDescription>
                    {cash.closing === null
                        ? `Raportul WCFR nu are o săptămână pentru ${formatDate(payDate)}.`
                        : `Prognoza WCFR pentru săptămâna din ${formatDate(cash.week)}. Prognoza include deja facturile furnizorilor care ajung la scadență, deci și pe cele din acest rulaj.`}
                    {cash.built_at && (
                        <span className="block text-xs">
                            calculat la {formatDateTime(cash.built_at)}
                        </span>
                    )}
                </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-4 px-4 sm:grid-cols-2 lg:grid-cols-4">
                <Metric label="Total rulaj">
                    {formatMoney(cash.total_lei, 'lei')}
                    {showByCurrency && (
                        <span className="block text-xs font-normal text-muted-foreground">
                            {formatByCurrency(cash.by_currency)}
                        </span>
                    )}
                </Metric>
                <Metric label="Sold estimat la final de săptămână">
                    {cash.closing === null
                        ? '—'
                        : formatMoney(cash.closing, 'lei')}
                </Metric>
                <Metric label="Prag minim">
                    {cash.minimum === null
                        ? '—'
                        : formatMoney(cash.minimum, 'lei')}
                </Metric>
                <Metric
                    label="Marjă peste prag"
                    className={cn(
                        cash.margin !== null &&
                            (cash.margin < 0
                                ? 'text-red-600 dark:text-red-400'
                                : 'text-green-700 dark:text-green-400'),
                    )}
                >
                    {cash.margin === null
                        ? '—'
                        : formatMoney(cash.margin, 'lei')}
                </Metric>
            </CardContent>
        </Card>
    );
}

function Metric({
    label,
    className,
    children,
}: {
    label: string;
    className?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-1">
            <span className="text-xs text-muted-foreground">{label}</span>
            <span
                className={cn('text-lg font-semibold tabular-nums', className)}
            >
                {children}
            </span>
        </div>
    );
}

function DepartmentGroup({
    group,
    myDepartments,
    canEdit,
    canFinal,
    busy,
    onDecide,
    onAskDecision,
    onToggle,
}: {
    group: ItemGroup;
    myDepartments: number[] | null;
    canEdit: boolean;
    canFinal: boolean;
    busy: boolean;
    onDecide: (
        invoiceIds: number[],
        target: DecisionTarget,
        decision: Decision,
        extra?: { comment?: string; until?: string },
        onSuccess?: () => void,
    ) => void;
    onAskDecision: (pending: PendingDecision) => void;
    onToggle: (item: PaymentRunItem) => void;
}) {
    const [selected, setSelected] = useState<number[]>([]);

    const department = group.department;
    const groupTarget: DecisionTarget | null = department
        ? { kind: 'department', id: department.id, name: department.name }
        : null;

    /** Invoices this group's department still has to decide on, for the user. */
    const selectableIds = department
        ? group.items.flatMap((item) =>
              item.invoice &&
              approvableShares(item, myDepartments).some(
                  (share) => share.id === department.id,
              )
                  ? [item.invoice.id]
                  : [],
          )
        : [];
    const selectedIds = selected.filter((id) => selectableIds.includes(id));
    const allSelected =
        selectableIds.length > 0 && selectedIds.length === selectableIds.length;
    const hasSelection = selectableIds.length > 0;

    const setRowSelected = (invoiceId: number, checked: boolean) => {
        setSelected((current) =>
            checked
                ? [...current, invoiceId]
                : current.filter((id) => id !== invoiceId),
        );
    };

    const clearSelection = () => setSelected([]);

    const selectionSubject = `${invoicesLabel(selectedIds.length)} (${department?.name ?? ''})`;

    return (
        <section className="flex flex-col gap-2">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="text-base font-semibold">
                    {department?.name ?? 'Fără departament'}
                    <span className="ml-2 text-sm font-normal text-muted-foreground">
                        {group.includedCount === group.items.length
                            ? invoicesLabel(group.items.length)
                            : `${group.includedCount} din ${group.items.length} facturi`}
                    </span>
                </h2>
                <span className="text-sm font-medium tabular-nums">
                    {formatByCurrency(group.totals)}
                </span>
            </div>

            {groupTarget && selectedIds.length > 0 && (
                <div className="flex flex-wrap items-center gap-2 rounded-lg bg-muted/60 px-3 py-2 text-sm">
                    <span>{invoicesLabel(selectedIds.length)} selectate</span>
                    <Button
                        size="sm"
                        disabled={busy}
                        onClick={() =>
                            onDecide(
                                selectedIds,
                                groupTarget,
                                'approved',
                                {},
                                clearSelection,
                            )
                        }
                    >
                        <Check />
                        Aprobă selecția
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={busy}
                        onClick={() =>
                            onAskDecision({
                                invoiceIds: selectedIds,
                                target: groupTarget,
                                decision: 'disputed',
                                subject: selectionSubject,
                                onDone: clearSelection,
                            })
                        }
                    >
                        Contestă
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={busy}
                        onClick={() =>
                            onAskDecision({
                                invoiceIds: selectedIds,
                                target: groupTarget,
                                decision: 'postponed',
                                subject: selectionSubject,
                                onDone: clearSelection,
                            })
                        }
                    >
                        Amână
                    </Button>
                    <Button size="sm" variant="ghost" onClick={clearSelection}>
                        <X />
                        Deselectează
                    </Button>
                </div>
            )}

            <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            {hasSelection && (
                                <TableHead className="w-8">
                                    <Checkbox
                                        aria-label="Selectează toate"
                                        checked={
                                            allSelected
                                                ? true
                                                : selectedIds.length > 0
                                                  ? 'indeterminate'
                                                  : false
                                        }
                                        onCheckedChange={(checked) =>
                                            setSelected(
                                                checked === true
                                                    ? selectableIds
                                                    : [],
                                            )
                                        }
                                    />
                                </TableHead>
                            )}
                            <TableHead>Furnizor / Factură</TableHead>
                            <TableHead>Scadență</TableHead>
                            <TableHead className="text-right">Suma</TableHead>
                            <TableHead>Departamente</TableHead>
                            <TableHead>Stare</TableHead>
                            <TableHead className="text-right">
                                Acțiuni
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {group.items.map((item) => (
                            <RunItemRow
                                key={item.id}
                                item={item}
                                groupDepartmentId={department?.id ?? null}
                                shares={approvableShares(item, myDepartments)}
                                canEdit={canEdit}
                                canFinal={
                                    canFinal &&
                                    item.status === 'included' &&
                                    item.invoice?.approval_status === 'final'
                                }
                                busy={busy}
                                showCheckbox={hasSelection}
                                selectable={
                                    item.invoice !== null &&
                                    selectableIds.includes(item.invoice.id)
                                }
                                selected={
                                    item.invoice !== null &&
                                    selectedIds.includes(item.invoice.id)
                                }
                                onSelect={setRowSelected}
                                onDecide={onDecide}
                                onAskDecision={onAskDecision}
                                onToggle={onToggle}
                            />
                        ))}
                    </TableBody>
                </Table>
            </div>
        </section>
    );
}

function RunItemRow({
    item,
    groupDepartmentId,
    shares,
    canEdit,
    canFinal,
    busy,
    showCheckbox,
    selectable,
    selected,
    onSelect,
    onDecide,
    onAskDecision,
    onToggle,
}: {
    item: PaymentRunItem;
    groupDepartmentId: number | null;
    shares: DepartmentShare[];
    canEdit: boolean;
    canFinal: boolean;
    busy: boolean;
    showCheckbox: boolean;
    selectable: boolean;
    selected: boolean;
    onSelect: (invoiceId: number, checked: boolean) => void;
    onDecide: (
        invoiceIds: number[],
        target: DecisionTarget,
        decision: Decision,
    ) => void;
    onAskDecision: (pending: PendingDecision) => void;
    onToggle: (item: PaymentRunItem) => void;
}) {
    const invoice = item.invoice;
    const excluded = item.status === 'excluded';

    /** The group's own department first, then the others the user approves for. */
    const orderedShares = [...shares].sort(
        (a, b) =>
            Number(b.id === groupDepartmentId) -
            Number(a.id === groupDepartmentId),
    );
    const targets: DecisionTarget[] = [
        ...orderedShares.map(
            (share): DecisionTarget => ({
                kind: 'department',
                id: share.id,
                name: share.name ?? '—',
            }),
        ),
        ...(canFinal ? [{ kind: 'final' } as const] : []),
    ];
    const hasMenu = invoice !== null && (targets.length > 0 || canEdit);

    const targetLabel = (target: DecisionTarget) =>
        target.kind === 'final' ? 'Top Management' : target.name;

    const askDecision = (
        target: DecisionTarget,
        decision: PendingDecision['decision'],
    ) => {
        if (!invoice) {
            return;
        }

        onAskDecision({
            invoiceIds: [invoice.id],
            target,
            decision,
            subject: `factura ${invoice.nr_doc}${invoice.partner ? ` de la ${invoice.partner.name}` : ''} (${targetLabel(target)})`,
        });
    };

    return (
        <TableRow className={cn(excluded && 'opacity-50')}>
            {showCheckbox && (
                <TableCell>
                    {selectable && invoice && (
                        <Checkbox
                            aria-label={`Selectează ${invoice.nr_doc}`}
                            checked={selected}
                            onCheckedChange={(checked) =>
                                onSelect(invoice.id, checked === true)
                            }
                        />
                    )}
                </TableCell>
            )}
            <TableCell>
                {invoice ? (
                    <div
                        className={cn(
                            'flex flex-col',
                            excluded && 'line-through',
                        )}
                    >
                        <span className="font-medium">
                            {invoice.partner?.name ?? '—'}
                        </span>
                        <Link
                            href={invoicesShow(invoice.id)}
                            className="text-xs text-muted-foreground hover:underline"
                        >
                            {invoice.nr_doc}
                        </Link>
                    </div>
                ) : (
                    <span className="text-muted-foreground">
                        Factură indisponibilă
                    </span>
                )}
                {item.comment && (
                    <div className="text-xs text-muted-foreground italic">
                        {item.comment}
                    </div>
                )}
            </TableCell>
            <TableCell className="tabular-nums">
                {formatDate(invoice?.data_scadenta)}
            </TableCell>
            <TableCell
                className={cn(
                    'text-right whitespace-nowrap tabular-nums',
                    excluded && 'line-through',
                )}
            >
                {formatMoney(item.amount, item.currency)}
            </TableCell>
            <TableCell>
                {invoice && (
                    <DepartmentShares
                        shares={invoice.departments}
                        currency={invoice.moneda}
                    />
                )}
            </TableCell>
            <TableCell>
                {excluded ? (
                    <span className="text-xs text-muted-foreground">
                        Scoasă din rulaj
                    </span>
                ) : (
                    <WorkflowStatusBadge
                        status={invoice?.approval_status ?? null}
                    />
                )}
            </TableCell>
            <TableCell>
                <div className="flex items-center justify-end gap-1">
                    {invoice &&
                        targets.map((target) => (
                            <Button
                                key={
                                    target.kind === 'final'
                                        ? 'final'
                                        : target.id
                                }
                                size="sm"
                                variant="outline"
                                disabled={busy}
                                onClick={() =>
                                    onDecide([invoice.id], target, 'approved')
                                }
                                title={`Aprobă pentru ${targetLabel(target)}`}
                            >
                                <Check />
                                {targets.length > 1
                                    ? `Aprobă · ${targetLabel(target)}`
                                    : 'Aprobă'}
                            </Button>
                        ))}
                    {hasMenu && (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    className="size-8"
                                    aria-label="Mai multe acțiuni"
                                    disabled={busy}
                                >
                                    <MoreHorizontal />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                {targets.map((target) => (
                                    <DecisionMenuItems
                                        key={
                                            target.kind === 'final'
                                                ? 'final'
                                                : target.id
                                        }
                                        suffix={
                                            targets.length > 1
                                                ? ` · ${targetLabel(target)}`
                                                : ''
                                        }
                                        onDispute={() =>
                                            askDecision(target, 'disputed')
                                        }
                                        onPostpone={() =>
                                            askDecision(target, 'postponed')
                                        }
                                    />
                                ))}
                                {canEdit && (
                                    <DropdownMenuItem
                                        onSelect={() => onToggle(item)}
                                    >
                                        {excluded ? <Undo2 /> : <X />}
                                        {excluded
                                            ? 'Readaugă în rulaj'
                                            : 'Scoate din rulaj'}
                                    </DropdownMenuItem>
                                )}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}
                </div>
            </TableCell>
        </TableRow>
    );
}

function DecisionMenuItems({
    suffix,
    onDispute,
    onPostpone,
}: {
    suffix: string;
    onDispute: () => void;
    onPostpone: () => void;
}) {
    return (
        <>
            <DropdownMenuItem onSelect={onDispute}>
                Contestă{suffix}
            </DropdownMenuItem>
            <DropdownMenuItem onSelect={onPostpone}>
                Amână{suffix}
            </DropdownMenuItem>
        </>
    );
}

function DecisionDialog({
    pending,
    busy,
    onClose,
    onSubmit,
}: {
    pending: PendingDecision;
    busy: boolean;
    onClose: () => void;
    onSubmit: (extra: { comment?: string; until?: string }) => void;
}) {
    const [comment, setComment] = useState('');
    const [until, setUntil] = useState('');

    const isDispute = pending.decision === 'disputed';
    const today = localToday();
    const valid = isDispute ? comment.trim() !== '' : until > today;

    const submit = () => {
        if (!valid) {
            return;
        }

        onSubmit({
            comment: comment.trim() || undefined,
            until: isDispute ? undefined : until,
        });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {isDispute ? 'Contestă' : 'Amână'} {pending.subject}
                    </DialogTitle>
                    <DialogDescription>
                        {isDispute
                            ? 'Factura contestată nu se plătește până nu se rezolvă. Spuneți de ce o contestați.'
                            : 'Plata se amână până la data aleasă; factura revine apoi la aprobare.'}
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4">
                    {!isDispute && (
                        <div className="grid gap-2">
                            <Label htmlFor="decision-until">
                                Amână până la
                            </Label>
                            <Input
                                id="decision-until"
                                type="date"
                                min={today}
                                value={until}
                                onChange={(e) => setUntil(e.target.value)}
                            />
                            {until !== '' && until <= today && (
                                <span className="text-xs text-destructive">
                                    Alegeți o dată din viitor.
                                </span>
                            )}
                        </div>
                    )}
                    <div className="grid gap-2">
                        <Label htmlFor="decision-comment">
                            {isDispute
                                ? 'Motivul contestației'
                                : 'Comentariu (opțional)'}
                        </Label>
                        <Textarea
                            id="decision-comment"
                            rows={3}
                            value={comment}
                            onChange={(e) => setComment(e.target.value)}
                        />
                    </div>
                </div>
                <DialogFooter>
                    <Button variant="ghost" onClick={onClose}>
                        Renunță
                    </Button>
                    <Button
                        variant={isDispute ? 'destructive' : 'default'}
                        onClick={submit}
                        disabled={busy || !valid}
                    >
                        {isDispute ? 'Contestă' : 'Amână'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

PaymentRunShow.layout = (page: React.ReactNode) => (
    <AppLayout
        breadcrumbs={[{ title: 'Rulaje de plată', href: paymentRunsIndex() }]}
    >
        {page}
    </AppLayout>
);
