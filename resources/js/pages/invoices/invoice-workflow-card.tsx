import { router } from '@inertiajs/react';
import { Ban, Check, Clock, RotateCcw, Route, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import ApprovalController from '@/actions/App/Http/Controllers/Approvals/ApprovalController';
import RoutingController from '@/actions/App/Http/Controllers/Approvals/RoutingController';
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
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import WorkflowStatusBadge, {
    DecisionBadge,
} from '@/components/workflow-status-badge';
import { formatDate, formatMoney } from '@/lib/money';
import type { DepartmentRef } from '@/types/approvals';
import type { CurrentUser, RoutedLine, Workflow } from './types';

type Decision = 'approved' | 'disputed' | 'postponed';

type Payload = Record<
    string,
    string | number | boolean | number[] | null | undefined
>;

type Pending = {
    decision: Decision;
    /** null: Top Management's final decision. */
    departmentId: number | null;
    title: string;
} | null;

const ruleLabels: Record<string, string> = {
    loc: 'Loc de cheltuială',
    charter: 'Contract charter',
    ticket: 'Bilet Tina',
    booking: 'Rezervare eTrip',
    office: 'Birou OMC',
    partner: 'Regulă furnizor',
    history: 'Istoric furnizor',
    account: 'Regulă cont',
    manual: 'Manual',
    none: 'Nerutată',
};

const groupLabels: Record<string, string> = {
    product: 'Produse',
    channel: 'Canale de vânzare',
    support: 'Suport',
};

function tomorrow(): string {
    const date = new Date();
    date.setDate(date.getDate() + 1);

    return date.toISOString().slice(0, 10);
}

/**
 * The invoice in the approval flow: its status, each department's share
 * and decision, what the user may decide here, and how its lines were
 * routed.
 */
export function InvoiceWorkflowCard({
    invoiceId,
    currency,
    workflow,
    routing,
    currentUser,
    departments,
}: {
    invoiceId: number;
    currency: string | null;
    workflow: Workflow;
    routing: RoutedLine[];
    currentUser: CurrentUser;
    departments: DepartmentRef[];
}) {
    const [pending, setPending] = useState<Pending>(null);
    const [comment, setComment] = useState('');
    const [until, setUntil] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [routeTo, setRouteTo] = useState('');

    const roles = currentUser.roles;
    const isAdmin = roles.includes('admin');
    const isTop = isAdmin || roles.includes('top_management');
    const isFinance = isAdmin || roles.includes('finance');
    const status = workflow.approval_status;
    const inFlow = status !== null;
    const canReopen =
        (isTop || isFinance) &&
        (status === 'disputed' ||
            status === 'postponed' ||
            workflow.final !== null);

    const open = (next: NonNullable<Pending>) => {
        setPending(next);
        setComment('');
        setUntil('');
        setErrors({});
    };

    const post = (url: string, data: Payload, done?: () => void) =>
        router.post(url, data, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => done?.(),
            onError: (e) => setErrors(e),
        });

    const decide = (
        decision: Decision,
        departmentId: number | null,
        extra: Payload = {},
    ) =>
        departmentId === null
            ? post(
                  ApprovalController.decideFinal().url,
                  { invoice_ids: [invoiceId], decision, ...extra },
                  () => setPending(null),
              )
            : post(
                  ApprovalController.decide().url,
                  {
                      invoice_ids: [invoiceId],
                      department_id: departmentId,
                      decision,
                      ...extra,
                  },
                  () => setPending(null),
              );

    const grouped = Object.entries(groupLabels).map(([group, label]) => ({
        label,
        items: departments.filter((d) => d.group === group),
    }));

    return (
        <div className="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
            <div className="flex flex-wrap items-center justify-between gap-2 bg-muted/50 px-4 py-2">
                <span className="text-sm font-semibold">Aprobare</span>
                <WorkflowStatusBadge status={status} />
            </div>

            <div className="space-y-4 p-4 text-sm">
                {!inFlow && (
                    <p className="text-muted-foreground">
                        Factura nu intră în fluxul de aprobare (era plătită la
                        sosire, este stornare sau nu mai este în OMC).
                    </p>
                )}

                {inFlow && (
                    <p className="text-muted-foreground">
                        {status === 'routing' &&
                            'Nu are încă departament pe toate liniile; Financiar o rutează.'}
                        {status === 'department' &&
                            'Așteaptă departamentele care dețin părți din ea.'}
                        {status === 'final' &&
                            (workflow.approval_track === 'run'
                                ? 'Departamentele au aprobat; Top Management o aprobă prin rulajul de plată.'
                                : 'Departamentele au aprobat; așteaptă aprobarea finală.')}
                        {status === 'approved' && 'Bună de plată.'}
                        {status === 'disputed' &&
                            'Contestată: nu intră în rulajele de plată până nu e redeschisă.'}
                        {status === 'postponed' &&
                            `Amânată${workflow.postponed_until ? ` până la ${formatDate(workflow.postponed_until)}` : ''}.`}
                    </p>
                )}

                {workflow.departments.length > 0 && (
                    <ul className="space-y-2">
                        {workflow.departments.map((share) => {
                            const mine =
                                share.status === 'pending' &&
                                currentUser.department_ids.includes(share.id);

                            return (
                                <li
                                    key={share.id}
                                    className="rounded-md border border-sidebar-border/70 p-2.5 dark:border-sidebar-border"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <span className="font-medium">
                                            {share.name}
                                        </span>
                                        <span className="flex items-center gap-2">
                                            <span className="text-xs text-muted-foreground tabular-nums">
                                                {formatMoney(
                                                    share.amount,
                                                    currency,
                                                )}
                                            </span>
                                            <DecisionBadge
                                                decision={share.status}
                                            />
                                        </span>
                                    </div>
                                    {(share.by || share.comment) && (
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {share.by}
                                            {share.at
                                                ? ` · ${formatDate(share.at)}`
                                                : ''}
                                            {share.postponed_until
                                                ? ` · până la ${formatDate(share.postponed_until)}`
                                                : ''}
                                            {share.comment
                                                ? ` — „${share.comment}”`
                                                : ''}
                                        </p>
                                    )}
                                    {mine && (
                                        <div className="mt-2 flex flex-wrap gap-2">
                                            <Button
                                                size="sm"
                                                disabled={processing}
                                                onClick={() =>
                                                    decide('approved', share.id)
                                                }
                                            >
                                                <Check />
                                                Aprobă
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    open({
                                                        decision: 'disputed',
                                                        departmentId: share.id,
                                                        title: `Contestă partea ${share.name}`,
                                                    })
                                                }
                                            >
                                                <Ban />
                                                Contestă
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    open({
                                                        decision: 'postponed',
                                                        departmentId: share.id,
                                                        title: `Amână partea ${share.name}`,
                                                    })
                                                }
                                            >
                                                <Clock />
                                                Amână
                                            </Button>
                                        </div>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                )}

                {workflow.final && (
                    <p className="rounded-md bg-muted/40 p-2.5 text-xs">
                        Decizie finală: {workflow.final.by ?? 'Top Management'},{' '}
                        {formatDate(workflow.final.at)}
                        {workflow.final.comment
                            ? ` — „${workflow.final.comment}”`
                            : ''}
                    </p>
                )}

                {isTop && inFlow && status !== 'approved' && (
                    <div className="flex flex-wrap gap-2 border-t border-sidebar-border/70 pt-3 dark:border-sidebar-border">
                        {status === 'final' && (
                            <Button
                                size="sm"
                                disabled={processing}
                                onClick={() => decide('approved', null)}
                            >
                                <ShieldCheck />
                                Aprobă final
                            </Button>
                        )}
                        {status !== 'disputed' && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    open({
                                        decision: 'disputed',
                                        departmentId: null,
                                        title: 'Contestă factura (Top Management)',
                                    })
                                }
                            >
                                <Ban />
                                Contestă
                            </Button>
                        )}
                        {status !== 'postponed' && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    open({
                                        decision: 'postponed',
                                        departmentId: null,
                                        title: 'Amână plata (Top Management)',
                                    })
                                }
                            >
                                <Clock />
                                Amână
                            </Button>
                        )}
                    </div>
                )}

                {canReopen && (
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={processing}
                        onClick={() =>
                            post(ApprovalController.reopen(invoiceId).url, {})
                        }
                    >
                        <RotateCcw />
                        Redeschide
                    </Button>
                )}

                {routing.length > 0 && (
                    <div className="space-y-2 border-t border-sidebar-border/70 pt-3 dark:border-sidebar-border">
                        <div className="text-xs font-semibold text-muted-foreground uppercase">
                            Rutare pe linii
                        </div>
                        <ul className="space-y-1.5">
                            {routing.map((line) => (
                                <li key={line.scv} className="text-xs">
                                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                                        <span className="font-medium">
                                            {line.department ??
                                                'Fără departament'}
                                            {line.channel
                                                ? ` · ${line.channel}`
                                                : ''}
                                        </span>
                                        <span className="text-muted-foreground tabular-nums">
                                            {formatMoney(line.amount, currency)}
                                        </span>
                                    </div>
                                    <div className="text-muted-foreground">
                                        #{line.scv}
                                        {line.account
                                            ? ` · cont ${line.account}`
                                            : ''}
                                        {line.loc ? ` · loc ${line.loc}` : ''}
                                        {line.com_int
                                            ? ` · ref. ${line.com_int}`
                                            : ''}
                                        {' — '}
                                        {line.manual_by
                                            ? `rutată manual de ${line.manual_by}`
                                            : (line.detail ??
                                              ruleLabels[line.rule ?? 'none'] ??
                                              line.rule)}
                                    </div>
                                </li>
                            ))}
                        </ul>

                        {isFinance && (
                            <div className="flex flex-wrap items-center gap-2 pt-1">
                                <Select
                                    value={routeTo}
                                    onValueChange={setRouteTo}
                                >
                                    <SelectTrigger
                                        size="sm"
                                        className="w-[220px]"
                                    >
                                        <SelectValue placeholder="Trimite factura la…" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {grouped.map((group) => (
                                            <SelectGroup key={group.label}>
                                                <SelectLabel>
                                                    {group.label}
                                                </SelectLabel>
                                                {group.items.map((d) => (
                                                    <SelectItem
                                                        key={d.id}
                                                        value={String(d.id)}
                                                    >
                                                        {d.parent_id
                                                            ? `— ${d.name}`
                                                            : d.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectGroup>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={routeTo === '' || processing}
                                    onClick={() =>
                                        post(
                                            RoutingController.assign(invoiceId)
                                                .url,
                                            { department_id: Number(routeTo) },
                                            () => setRouteTo(''),
                                        )
                                    }
                                >
                                    <Route />
                                    Rutează
                                </Button>
                                {routing.some(
                                    (line) => line.manual_by !== null,
                                ) && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        disabled={processing}
                                        onClick={() =>
                                            post(
                                                RoutingController.release(
                                                    invoiceId,
                                                ).url,
                                                {},
                                            )
                                        }
                                    >
                                        Înapoi la reguli
                                    </Button>
                                )}
                            </div>
                        )}
                    </div>
                )}

                {Object.keys(errors).length > 0 && pending === null && (
                    <p className="text-xs text-red-600">
                        {Object.values(errors)[0]}
                    </p>
                )}
            </div>

            <Dialog
                open={pending !== null}
                onOpenChange={(value) => !value && setPending(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{pending?.title}</DialogTitle>
                        <DialogDescription>
                            {pending?.decision === 'disputed'
                                ? 'Factura nu se plătește până nu e redeschisă. Spuneți de ce.'
                                : 'Factura nu intră în rulajele de plată de dinaintea datei alese.'}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-3">
                        {pending?.decision === 'postponed' && (
                            <div className="grid gap-1.5">
                                <Label htmlFor="postpone-until">
                                    Amână până la
                                </Label>
                                <Input
                                    id="postpone-until"
                                    type="date"
                                    min={tomorrow()}
                                    value={until}
                                    onChange={(e) => setUntil(e.target.value)}
                                />
                                {errors.until && (
                                    <p className="text-xs text-red-600">
                                        {errors.until}
                                    </p>
                                )}
                            </div>
                        )}
                        <div className="grid gap-1.5">
                            <Label htmlFor="decision-comment">
                                Comentariu
                                {pending?.decision === 'disputed'
                                    ? ''
                                    : ' (opțional)'}
                            </Label>
                            <Textarea
                                id="decision-comment"
                                rows={3}
                                maxLength={2000}
                                value={comment}
                                onChange={(e) => setComment(e.target.value)}
                            />
                            {errors.comment && (
                                <p className="text-xs text-red-600">
                                    {errors.comment}
                                </p>
                            )}
                            {errors.decision && (
                                <p className="text-xs text-red-600">
                                    {errors.decision}
                                </p>
                            )}
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            onClick={() => setPending(null)}
                        >
                            Renunță
                        </Button>
                        <Button
                            disabled={
                                processing ||
                                (pending?.decision === 'disputed' &&
                                    comment.trim() === '') ||
                                (pending?.decision === 'postponed' &&
                                    until === '')
                            }
                            onClick={() =>
                                pending &&
                                decide(pending.decision, pending.departmentId, {
                                    comment: comment || undefined,
                                    until:
                                        pending.decision === 'postponed'
                                            ? until
                                            : undefined,
                                })
                            }
                        >
                            {pending?.decision === 'disputed'
                                ? 'Contestă'
                                : 'Amână'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
