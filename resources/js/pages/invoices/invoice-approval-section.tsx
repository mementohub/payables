import { Form } from '@inertiajs/react';
import { Check, ShieldCheck, Undo2 } from 'lucide-react';
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
import type { Approval, CurrentUser, ResponsabilStep } from './types';

function formatTimestamp(value: string | null): string | null {
    if (!value) {
        return null;
    }

    return new Intl.DateTimeFormat('ro-RO', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
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

function ApproveButton({
    invoiceId,
    departmentId,
    label,
    hint,
    kind,
}: {
    invoiceId: number;
    departmentId: number;
    label: string;
    hint?: string;
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
                        className={cn('h-8 gap-1.5', colorClass)}
                    >
                        <Icon className="size-3.5" />
                        {label}
                        {hint && (
                            <span className="text-xs opacity-70">· {hint}</span>
                        )}
                    </Button>
                </>
            )}
        </Form>
    );
}

function ResponsabilStepRow({
    invoiceId,
    step,
    currentUser,
}: {
    invoiceId: number;
    step: ResponsabilStep;
    currentUser: CurrentUser;
}) {
    const isMember = currentUser.responsabil_department_ids.includes(
        step.department_id,
    );
    const canApprove = !step.approved && isMember;
    const canRevoke =
        step.approved &&
        step.approval_id !== null &&
        step.approved_by?.id === currentUser.id;

    return (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-sidebar-border/70 px-3 py-2 dark:border-sidebar-border">
            <div className="flex items-center gap-2 text-sm">
                {step.approved ? (
                    <Check className="size-4 text-green-600" />
                ) : (
                    <span className="inline-block size-2 rounded-full bg-amber-400" />
                )}
                <div>
                    <div className="font-medium">{step.department_name}</div>
                    <div className="text-xs text-muted-foreground">
                        {step.approved && step.approved_by
                            ? `${step.approved_by.name} · ${formatTimestamp(step.approved_at)}`
                            : 'în așteptare'}
                    </div>
                </div>
            </div>
            <div className="flex items-center gap-1">
                {canApprove && (
                    <ApproveButton
                        invoiceId={invoiceId}
                        departmentId={step.department_id}
                        label="Aprobă"
                        kind="responsabil"
                    />
                )}
                {canRevoke && step.approval_id !== null && (
                    <RevokeButton
                        invoiceId={invoiceId}
                        approvalId={step.approval_id}
                        label={`Aprobare ${step.department_name}`}
                    />
                )}
            </div>
        </div>
    );
}

export function InvoiceApprovalSection({
    invoiceId,
    approval,
    currentUser,
}: {
    invoiceId: number;
    approval: Approval;
    currentUser: CurrentUser;
}) {
    const ordonatorAction = useMemo(() => {
        if (
            !approval.needs_approval ||
            approval.is_fully_approved ||
            approval.responsabili_approved_at === null ||
            currentUser.ordonator_department_ids.length === 0
        ) {
            return null;
        }

        return currentUser.ordonator_department_ids[0];
    }, [approval, currentUser]);

    const ordonatorCanRevoke =
        approval.ordonator !== null &&
        approval.ordonator.approved_by?.id === currentUser.id;

    if (!approval.needs_approval) {
        return (
            <div className="rounded-xl border border-sidebar-border/70 p-4 text-sm text-muted-foreground dark:border-sidebar-border">
                Furnizorul nu are departamente responsabile configurate, deci
                factura nu necesită aprobare.
            </div>
        );
    }

    return (
        <div className="space-y-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
            <div className="flex items-baseline justify-between gap-3">
                <h2 className="text-sm font-semibold text-muted-foreground uppercase">
                    Aprobări
                </h2>
                {approval.is_fully_approved && (
                    <span className="text-xs font-medium text-green-700 dark:text-green-300">
                        Bun de plată ·{' '}
                        {formatTimestamp(approval.fully_approved_at)}
                    </span>
                )}
            </div>

            <div className="space-y-2">
                <div className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    Responsabili
                </div>
                {approval.responsabil_steps.map((step) => (
                    <ResponsabilStepRow
                        key={step.department_id}
                        invoiceId={invoiceId}
                        step={step}
                        currentUser={currentUser}
                    />
                ))}
            </div>

            <div className="space-y-2">
                <div className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    Ordonator
                </div>
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-sidebar-border/70 px-3 py-2 dark:border-sidebar-border">
                    <div className="flex items-center gap-2 text-sm">
                        {approval.ordonator ? (
                            <Check className="size-4 text-green-600" />
                        ) : (
                            <ShieldCheck className="size-4 text-sky-500" />
                        )}
                        <div>
                            <div className="font-medium">
                                {approval.ordonator?.department_name ??
                                    'Etapa finală'}
                            </div>
                            <div className="text-xs text-muted-foreground">
                                {approval.ordonator
                                    ? `${approval.ordonator.approved_by?.name ?? ''} · ${formatTimestamp(approval.ordonator.approved_at)}`
                                    : approval.responsabili_approved_at
                                      ? 'gata pentru aprobare finală'
                                      : 'după aprobarea responsabililor'}
                            </div>
                        </div>
                    </div>
                    <div className="flex items-center gap-1">
                        {!approval.is_fully_approved && ordonatorAction && (
                            <ApproveButton
                                invoiceId={invoiceId}
                                departmentId={ordonatorAction}
                                label="Aprobă"
                                hint="final"
                                kind="ordonator"
                            />
                        )}
                        {ordonatorCanRevoke && approval.ordonator && (
                            <RevokeButton
                                invoiceId={invoiceId}
                                approvalId={approval.ordonator.approval_id}
                                label="Aprobare finală (ordonator)"
                            />
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
