import { Form } from '@inertiajs/react';
import {
    BadgeCheck,
    CircleDollarSign,
    MessageSquare,
    Undo2,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { store as storeCommentRoute } from '@/routes/invoices/comments';
import type { TimelineEvent } from './types';

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
    const date = new Date(value);

    return new Intl.DateTimeFormat('ro-RO', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
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
                            placeholder="Adaugă un comentariu vizibil tuturor utilizatorilor cu acces la factură..."
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
                <ol className="space-y-3">
                    {events.map((event) => (
                        <li
                            key={event.id}
                            className="flex gap-3 rounded-md border border-sidebar-border/70 p-3 dark:border-sidebar-border"
                        >
                            <span
                                className={`mt-0.5 inline-flex size-7 shrink-0 items-center justify-center rounded-full ${accent[event.type]}`}
                                aria-hidden
                            >
                                {eventIcon(event.type)}
                            </span>
                            <div className="min-w-0 flex-1 text-sm">
                                <div className="flex flex-wrap items-baseline gap-x-2">
                                    <span className="font-medium">
                                        {event.user?.name ?? 'Sistem'}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {describe(event)}
                                    </span>
                                    <span
                                        className="text-xs text-muted-foreground"
                                        title={event.created_at}
                                    >
                                        · {formatTimestamp(event.created_at)}
                                    </span>
                                </div>
                                <div className="mt-0.5 text-[10px] tracking-wide text-muted-foreground/80 uppercase">
                                    {labels[event.type]}
                                    {event.department
                                        ? ` · ${event.department.name}`
                                        : ''}
                                </div>
                                {event.body && (
                                    <p className="mt-1.5 text-sm whitespace-pre-wrap">
                                        {event.body}
                                    </p>
                                )}
                            </div>
                        </li>
                    ))}
                </ol>
            )}
        </div>
    );
}
