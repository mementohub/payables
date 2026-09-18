import { Form } from '@inertiajs/react';
import {
    Ban,
    BadgeCheck,
    Banknote,
    CircleDollarSign,
    ClipboardCheck,
    Clock,
    Forward,
    Landmark,
    ListMinus,
    ListPlus,
    MessageSquare,
    RotateCcw,
    ShieldCheck,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { formatDate } from '@/lib/money';
import { store as storeCommentRoute } from '@/routes/invoices/comments';
import type { TimelineEvent } from './types';

type Look = { label: string; accent: string; icon: ReactNode };

const green =
    'bg-green-100 text-green-700 dark:bg-green-500/15 dark:text-green-300';
const sky = 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300';
const rose = 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300';
const slate =
    'bg-slate-100 text-slate-700 dark:bg-slate-500/15 dark:text-slate-300';
const violet =
    'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300';
const amber =
    'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300';
const icon = 'size-3.5';

const looks: Record<string, Look> = {
    department_approved: {
        label: 'Aprobat',
        accent: green,
        icon: <BadgeCheck className={icon} />,
    },
    department_disputed: {
        label: 'Contestat',
        accent: rose,
        icon: <Ban className={icon} />,
    },
    department_postponed: {
        label: 'Amânat',
        accent: slate,
        icon: <Clock className={icon} />,
    },
    department_redirected: {
        label: 'Redirecționată',
        accent: violet,
        icon: <Forward className={icon} />,
    },
    final_approved: {
        label: 'Aprobare finală',
        accent: sky,
        icon: <ShieldCheck className={icon} />,
    },
    final_disputed: {
        label: 'Contestat final',
        accent: rose,
        icon: <Ban className={icon} />,
    },
    final_postponed: {
        label: 'Amânat final',
        accent: slate,
        icon: <Clock className={icon} />,
    },
    reopened: {
        label: 'Redeschisă',
        accent: amber,
        icon: <RotateCcw className={icon} />,
    },
    run_included: {
        label: 'Rulaj de plată',
        accent: violet,
        icon: <ListPlus className={icon} />,
    },
    run_excluded: {
        label: 'Rulaj de plată',
        accent: slate,
        icon: <ListMinus className={icon} />,
    },
    exported: {
        label: 'Trimisă la bancă',
        accent: green,
        icon: <Landmark className={icon} />,
    },
    commented: {
        label: 'Comentariu',
        accent: sky,
        icon: <MessageSquare className={icon} />,
    },
    payment_status_changed: {
        label: 'Status plată',
        accent: violet,
        icon: <CircleDollarSign className={icon} />,
    },
    payment_request_linked: {
        label: 'Cerere de plată',
        accent: amber,
        icon: <ClipboardCheck className={icon} />,
    },
};

const fallback: Look = {
    label: 'Eveniment',
    accent: slate,
    icon: <Banknote className={icon} />,
};

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
    const dept = event.department?.name;
    const until = event.payload?.until as string | undefined;
    const run = event.payload?.run as string | undefined;
    const via = event.payload?.via as string | undefined;
    const part = dept ? ` partea ${dept}` : '';

    switch (event.type) {
        case 'department_approved':
            return `a aprobat${part}`;
        case 'department_disputed':
            return `a contestat${part}`;
        case 'department_postponed':
            return `a amânat${part}${until ? ` până la ${formatDate(until)}` : ''}`;
        case 'department_redirected': {
            const to = event.payload?.to as string | undefined;

            return `a trimis${part} la ${to ?? 'alt departament'}`;
        }
        case 'final_approved':
            return via
                ? `a aprobat final, prin rulajul ${via}`
                : 'a aprobat final factura';
        case 'final_disputed':
            return 'a contestat factura (decizie finală)';
        case 'final_postponed':
            return `a amânat plata${until ? ` până la ${formatDate(until)}` : ''}`;
        case 'reopened':
            return 'a trimis factura înapoi la departamente';
        case 'run_included':
            return `a readăugat factura în ${run ?? 'rulaj'}`;
        case 'run_excluded':
            return `a scos factura din ${run ?? 'rulaj'}`;
        case 'exported':
            return `a trimis plata la bancă${run ? ` (${run})` : ''}`;
        case 'commented':
            return 'a comentat';
        case 'payment_status_changed': {
            const to = (event.payload?.to as string | undefined) ?? '';

            return `a marcat factura ca ${paymentStatusLabel[to] ?? to}`;
        }
        case 'payment_request_linked': {
            const id = event.payload?.payment_request_id as number | undefined;
            const pct = event.payload?.difference_pct as
                | number
                | null
                | undefined;
            const difference =
                pct === null || pct === undefined
                    ? ''
                    : ` (${pct > 0 ? '+' : ''}${pct}% față de așteptat)`;

            return `a legat cererea de plată${id ? ` #${id}` : ''}${difference}`;
        }
        default:
            return event.type;
    }
}

/**
 * Everything that happened to the invoice, newest first, with a box to add
 * a comment. The decisions themselves are taken in the approval card.
 */
export function InvoiceTimeline({
    invoiceId,
    events,
}: {
    invoiceId: number;
    events: TimelineEvent[];
}) {
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

            {events.length === 0 ? (
                <p className="rounded-md border border-dashed border-sidebar-border/70 px-4 py-6 text-center text-sm text-muted-foreground dark:border-sidebar-border">
                    Nicio activitate până acum.
                </p>
            ) : (
                <ol className="relative ml-3 border-l-2 border-sidebar-border/70 pl-6 dark:border-sidebar-border">
                    {events.map((event, index) => {
                        const look = looks[event.type] ?? fallback;

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
                                    className={`absolute top-0 -left-[37px] inline-flex size-7 items-center justify-center rounded-full ring-4 ring-background ${look.accent}`}
                                    aria-hidden
                                >
                                    {look.icon}
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
                                            {look.label}
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
                                </div>
                            </li>
                        );
                    })}
                </ol>
            )}
        </div>
    );
}
