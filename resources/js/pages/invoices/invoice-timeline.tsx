import { Form } from '@inertiajs/react';
import {
    BadgeCheck,
    Check,
    CircleDollarSign,
    Hourglass,
    MessageSquare,
    ShieldCheck,
    Undo2,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { approve as approveInvoiceRoute } from '@/routes/invoices';
import { revoke as revokeApprovalRoute } from '@/routes/invoices/approvals';
import { store as storeCommentRoute } from '@/routes/invoices/comments';
import type { Approval, CurrentUser, TimelineEvent } from './types';

const labels: Record<TimelineEvent['type'], string> = {
    approved: 'Aprobat',
    approval_revoked: 'Aprobare retrasă',
    commented: 'Comentariu',
    payment_status_changed: 'Status plată',
};

const accent: Record<TimelineEvent['type'], string> = {
    approved:
        'bg-green-100 text-green-700 dark:bg-green-500/15 dark:text-green-300',
    approval_revoked:
        'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300',
    commented: 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
    payment_status_changed:
        'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300',
};

function eventIcon(type: TimelineEvent['type']) {
    switch (type) {
        case 'approved':
            return <BadgeCheck className="size-3.5" />;
        case 'approval_revoked':
            return <Undo2 className="size-3.5" />;
        case 'commented':
            return <MessageSquare className="size-3.5" />;
        case 'payment_status_changed':
            return <CircleDollarSign className="size-3.5" />;
    }
}

const paymentStatusLabel: Record<string, string> = {
    paid: 'plătită',
    partial: 'parțial plătită',
    unpaid: 'neplătită',
};

function formatTimestamp(value: string): string {
    return new Intl.DateTimeFormat('ro-RO', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function describe(event: TimelineEvent): string {
    const role = (event.payload?.role as string | undefined) ?? null;
    const dept = event.department?.name;

    switch (event.type) {
        case 'approved':
            if (role === 'ordonator') {
                return 'a marcat factura ca Bun de plată final';
            }

            return dept ? `a aprobat pe partea ${dept}` : 'a aprobat factura';
        case 'approval_revoked':
            return dept
                ? `a retras aprobarea pentru ${dept}`
                : 'a retras aprobarea';
        case 'commented':
            return 'a comentat';
        case 'payment_status_changed': {
            const to = (event.payload?.to as string | undefined) ?? '';
            const label = paymentStatusLabel[to] ?? to;

            return `a marcat factura ca ${label}`;
        }
    }
}

type PendingStep =
    | {
          kind: 'responsabil';
          key: string;
          departmentId: number;
          departmentName: string;
          canApprove: boolean;
      }
    | {
          kind: 'ordonator';
          key: string;
          departmentId: number;
          canApprove: boolean;
      };

function buildPendingSteps(
    approval: Approval,
    currentUser: CurrentUser,
): PendingStep[] {
    if (!approval.needs_approval || approval.is_fully_approved) {
        return [];
    }

    const steps: PendingStep[] = [];

    for (const step of approval.responsabil_steps) {
        if (step.approved) {
            continue;
        }

        steps.push({
            kind: 'responsabil',
            key: `r-${step.department_id}`,
            departmentId: step.department_id,
            departmentName: step.department_name,
            canApprove: currentUser.responsabil_department_ids.includes(
                step.department_id,
            ),
        });
    }

    if (approval.responsabili_approved_at !== null && !approval.ordonator) {
        const ordonatorDeptId = currentUser.ordonator_department_ids[0];

        steps.push({
            kind: 'ordonator',
            key: 'o',
            departmentId: ordonatorDeptId ?? 0,
            canApprove: ordonatorDeptId !== undefined,
        });
    }

    return steps;
}

function ApproveButton({
    invoiceId,
    departmentId,
    kind,
}: {
    invoiceId: number;
    departmentId: number;
    kind: 'responsabil' | 'ordonator';
}) {
    const Icon = kind === 'responsabil' ? Check : ShieldCheck;
    const colorClass =
        kind === 'responsabil'
            ? 'border-green-600/40 bg-green-50 text-green-700 hover:bg-green-100 dark:bg-green-500/10 dark:text-green-300 dark:hover:bg-green-500/20'
            : 'border-sky-600/40 bg-sky-50 text-sky-700 hover:bg-sky-100 dark:bg-sky-500/10 dark:text-sky-300 dark:hover:bg-sky-500/20';

    return (
        <Form
            {...approveInvoiceRoute.form(invoiceId)}
            options={{ preserveScroll: true }}
        >
            {({ processing }) => (
                <>
                    <input
                        type="hidden"
                        name="department_id"
                        value={departmentId}
                    />
                    <Button
                        type="submit"
                        size="sm"
                        variant="outline"
                        disabled={processing}
                        className={cn('h-7 gap-1.5 text-xs', colorClass)}
                    >
                        <Icon className="size-3" />
                        Aprobă
                    </Button>
                </>
            )}
        </Form>
    );
}

function RevokeButton({
    invoiceId,
    approvalId,
    label,
}: {
    invoiceId: number;
    approvalId: number;
    label: string;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="h-7 gap-1 text-xs text-muted-foreground hover:text-foreground"
                >
                    <Undo2 className="size-3" />
                    Retrage
                </Button>
            </DialogTrigger>
            <DialogContent>
                <Form
                    {...revokeApprovalRoute.form({
                        invoice: invoiceId,
                        approval: approvalId,
                    })}
                    options={{
                        preserveScroll: true,
                        onSuccess: () => setOpen(false),
                    }}
                >
                    {({ processing, errors }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>Retrage aprobarea</DialogTitle>
                                <DialogDescription>{label}</DialogDescription>
                            </DialogHeader>
                            <div className="space-y-2">
                                <Textarea
                                    name="reason"
                                    rows={3}
                                    minLength={3}
                                    maxLength={2000}
                                    required
                                    placeholder="Motivul retragerii (obligatoriu)"
                                />
                                {errors.reason && (
                                    <p className="text-xs text-red-600">
                                        {errors.reason}
                                    </p>
                                )}
                            </div>
                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                    >
                                        Anulează
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    size="sm"
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    {processing
                                        ? 'Se retrage...'
                                        : 'Retrage aprobarea'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

export function InvoiceTimeline({
    invoiceId,
    events,
    approval,
    currentUser,
}: {
    invoiceId: number;
    events: TimelineEvent[];
    approval: Approval | null;
    currentUser: CurrentUser;
}) {
    const pendingSteps = useMemo(
        () => (approval ? buildPendingSteps(approval, currentUser) : []),
        [approval, currentUser],
    );

    const activeApprovalIds = useMemo(() => {
        if (!approval) {
            return new Set<number>();
        }

        const ids = new Set<number>();

        for (const step of approval.responsabil_steps) {
            if (step.approved && step.approval_id !== null) {
                ids.add(step.approval_id);
            }
        }

        if (approval.ordonator) {
            ids.add(approval.ordonator.approval_id);
        }

        return ids;
    }, [approval]);

    const hasAnyItem = pendingSteps.length > 0 || events.length > 0;

    return (
        <div className="space-y-4">
            <Form
                {...storeCommentRoute.form(invoiceId)}
                options={{ preserveScroll: true }}
                resetOnSuccess
            >
                {({ processing, errors }) => (
                    <div className="space-y-2">
                        <Textarea
                            name="body"
                            rows={3}
                            maxLength={5000}
                            placeholder="Adaugă un comentariu..."
                            className="resize-y"
                        />
                        {errors.body && (
                            <p className="text-xs text-red-600">
                                {errors.body}
                            </p>
                        )}
                        <div className="flex justify-end">
                            <Button
                                type="submit"
                                size="sm"
                                disabled={processing}
                            >
                                {processing
                                    ? 'Se trimite...'
                                    : 'Adaugă comentariu'}
                            </Button>
                        </div>
                    </div>
                )}
            </Form>

            {!hasAnyItem ? (
                <p className="rounded-md border border-dashed border-sidebar-border/70 px-4 py-6 text-center text-sm text-muted-foreground dark:border-sidebar-border">
                    Nicio activitate până acum.
                </p>
            ) : (
                <ol className="relative ml-3 border-l-2 border-sidebar-border/70 pl-6 dark:border-sidebar-border">
                    {pendingSteps.map((step, index) => (
                        <li
                            key={step.key}
                            className={
                                index === pendingSteps.length - 1 &&
                                events.length === 0
                                    ? 'relative'
                                    : 'relative pb-5'
                            }
                        >
                            <span
                                className="absolute top-0 -left-[37px] inline-flex size-7 items-center justify-center rounded-full bg-amber-100 text-amber-700 ring-4 ring-background dark:bg-amber-500/15 dark:text-amber-300"
                                aria-hidden
                            >
                                <Hourglass className="size-3.5" />
                            </span>
                            <div className="min-w-0 text-sm">
                                <div className="flex flex-wrap items-baseline gap-x-1.5 gap-y-0.5 leading-tight">
                                    <span className="font-medium">
                                        {step.kind === 'responsabil'
                                            ? `Așteaptă ${step.departmentName}`
                                            : 'Așteaptă aprobare finală (ordonator)'}
                                    </span>
                                </div>
                                <div className="mt-0.5 text-[11px] tracking-wide text-muted-foreground uppercase">
                                    {step.kind === 'responsabil'
                                        ? 'în așteptare · responsabil'
                                        : 'în așteptare · ordonator'}
                                </div>
                                {step.canApprove && (
                                    <div className="mt-2">
                                        <ApproveButton
                                            invoiceId={invoiceId}
                                            departmentId={step.departmentId}
                                            kind={step.kind}
                                        />
                                    </div>
                                )}
                            </div>
                        </li>
                    ))}

                    {events.map((event, index) => {
                        const approvalId =
                            event.type === 'approved'
                                ? ((event.payload?.approval_id as
                                      | number
                                      | undefined) ?? null)
                                : null;
                        const canRevoke =
                            event.type === 'approved' &&
                            approvalId !== null &&
                            activeApprovalIds.has(approvalId) &&
                            event.user?.id === currentUser.id;

                        return (
                            <li
                                key={event.id}
                                className={
                                    index === events.length - 1
                                        ? 'relative'
                                        : 'relative pb-5'
                                }
                            >
                                <span
                                    className={`absolute top-0 -left-[37px] inline-flex size-7 items-center justify-center rounded-full ring-4 ring-background ${accent[event.type]}`}
                                    aria-hidden
                                >
                                    {eventIcon(event.type)}
                                </span>
                                <div className="min-w-0 text-sm">
                                    <div className="flex flex-wrap items-baseline gap-x-1.5 gap-y-0.5 leading-tight">
                                        <span className="font-medium">
                                            {event.user?.name ?? 'Sistem'}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {describe(event)}
                                        </span>
                                    </div>
                                    <div className="mt-0.5 text-[11px] text-muted-foreground">
                                        <span title={event.created_at}>
                                            {formatTimestamp(event.created_at)}
                                        </span>
                                        <span className="mx-1.5 opacity-50">
                                            ·
                                        </span>
                                        <span className="tracking-wide uppercase">
                                            {labels[event.type]}
                                            {event.department
                                                ? ` · ${event.department.name}`
                                                : ''}
                                        </span>
                                    </div>
                                    {event.body && (
                                        <div className="mt-2 rounded-md border border-sidebar-border/70 bg-muted/30 px-3 py-2 text-sm whitespace-pre-wrap dark:border-sidebar-border">
                                            {event.body}
                                        </div>
                                    )}
                                    {canRevoke && approvalId !== null && (
                                        <div className="mt-2">
                                            <RevokeButton
                                                invoiceId={invoiceId}
                                                approvalId={approvalId}
                                                label={
                                                    event.department
                                                        ? `Aprobare ${event.department.name}`
                                                        : 'Aprobare'
                                                }
                                            />
                                        </div>
                                    )}
                                </div>
                            </li>
                        );
                    })}
                </ol>
            )}
        </div>
    );
}
