import { Form, Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    CircleAlert,
    CircleCheck,
    CircleX,
    ClipboardCheck,
    Link2,
    Link2Off,
    MessageSquare,
    Plus,
    RefreshCw,
} from 'lucide-react';
import { useState } from 'react';
import PaymentRequestController from '@/actions/App/Http/Controllers/PaymentRequestController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { show as invoiceShow } from '@/routes/invoices';
import { show as partnerShow } from '@/routes/partners';
import { index as paymentChecksIndex } from '@/routes/payment-checks';
import { index as invoiceChecksIndex } from '@/routes/payment-checks/invoices';
import {
    index as paymentRequestsIndex,
    show as paymentRequestShow,
} from '@/routes/payment-requests';
import type {
    LinkedInvoice,
    RequestEvent,
    RequestLevel,
    RequestStatus,
    ShowProps,
} from './types';

const LEVEL_BORDER: Record<RequestLevel, string> = {
    ok: 'border-t-emerald-600',
    warn: 'border-t-amber-500',
    crit: 'border-t-destructive',
};

const LEVEL_TEXT: Record<RequestLevel, string> = {
    ok: 'text-emerald-700 dark:text-emerald-500',
    warn: 'text-amber-600 dark:text-amber-400',
    crit: 'text-destructive',
};

const STATUS_VARIANT: Record<
    RequestStatus,
    'secondary' | 'outline' | 'destructive' | 'default'
> = {
    pending: 'outline',
    payable: 'secondary',
    disputed: 'destructive',
    paid: 'default',
};

const EVENT_LABELS: Record<RequestEvent['type'], string> = {
    created: 'Cerere salvată',
    status_changed: 'Status schimbat',
    commented: 'Comentariu',
    invoice_linked: 'Factură legată',
    invoice_unlinked: 'Factură dezlegată',
};

function fmt(value: number, decimals = 2): string {
    return new Intl.NumberFormat('ro-RO', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }).format(value);
}

function dmy(value: string | null): string {
    if (!value) {
        return '—';
    }

    const [year, month, day] = value.slice(0, 10).split('-');

    return `${day}.${month}.${year}`;
}

function dateTime(value: string | null): string {
    return value ? new Date(value).toLocaleString('ro-RO') : '—';
}

function LevelIcon({ level }: { level: RequestLevel }) {
    const className = `size-4 shrink-0 ${LEVEL_TEXT[level]}`;

    if (level === 'ok') {
        return <CircleCheck className={className} />;
    }

    if (level === 'warn') {
        return <CircleAlert className={className} />;
    }

    return <CircleX className={className} />;
}

function Tile({
    label,
    value,
    detail,
    level,
}: {
    label: string;
    value: React.ReactNode;
    detail?: React.ReactNode;
    level?: RequestLevel | null;
}) {
    return (
        <div
            className={`flex flex-col gap-1 rounded-xl border border-t-[3px] border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border ${
                level ? LEVEL_BORDER[level] : 'border-t-foreground'
            }`}
        >
            <span className="text-xs tracking-wide text-muted-foreground uppercase">
                {label}
            </span>
            <span
                className={`font-heading text-2xl leading-tight font-bold ${
                    level ? LEVEL_TEXT[level] : ''
                }`}
            >
                {value}
            </span>
            {detail && (
                <span className="text-sm text-muted-foreground">{detail}</span>
            )}
        </div>
    );
}

function StatusDialog({
    requestId,
    current,
    statuses,
    canPay,
}: {
    requestId: number;
    current: RequestStatus;
    statuses: Record<RequestStatus, string>;
    canPay: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [status, setStatus] = useState<RequestStatus>(current);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    <RefreshCw />
                    Schimbă statusul
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Schimbă statusul cererii</DialogTitle>
                    <DialogDescription>
                        {canPay
                            ? 'Poți marca cererea ca plătită; schimbarea rămâne în cronologie.'
                            : 'Doar membrii departamentului de plăți pot marca o cerere ca plătită.'}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...PaymentRequestController.updateStatus.form(requestId)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="request-status">Status</Label>
                                <Select
                                    value={status}
                                    onValueChange={(value) =>
                                        setStatus(value as RequestStatus)
                                    }
                                >
                                    <SelectTrigger
                                        id="request-status"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {(
                                            Object.keys(
                                                statuses,
                                            ) as RequestStatus[]
                                        ).map((value) => (
                                            <SelectItem
                                                key={value}
                                                value={value}
                                                disabled={
                                                    value === 'paid' && !canPay
                                                }
                                            >
                                                {statuses[value]}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <input
                                    type="hidden"
                                    name="status"
                                    value={status}
                                />
                                {errors.status && (
                                    <span className="text-xs text-destructive">
                                        {errors.status}
                                    </span>
                                )}
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="request-status-note">
                                    Notă (opțional)
                                </Label>
                                <Textarea
                                    id="request-status-note"
                                    name="note"
                                    rows={3}
                                />
                                {errors.note && (
                                    <span className="text-xs text-destructive">
                                        {errors.note}
                                    </span>
                                )}
                            </div>
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => setOpen(false)}
                                >
                                    Anulează
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    Salvează
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function LinkInvoiceDialog({
    requestId,
    candidates,
    hasPartner,
}: {
    requestId: number;
    candidates: LinkedInvoice[];
    hasPartner: boolean;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    <Plus />
                    Leagă factură
                </Button>
            </DialogTrigger>
            <DialogContent className="max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Leagă o factură de cerere</DialogTitle>
                    <DialogDescription>
                        {hasPartner
                            ? 'Facturile neachitate ale furnizorului, din ERP, care nu sunt încă legate de această cerere.'
                            : 'Cererea nu are un partener ERP asociat; leagă furnizorul eTrip de un partener pe pagina furnizorului ca să apară facturile lui aici.'}
                    </DialogDescription>
                </DialogHeader>
                {candidates.length === 0 ? (
                    <p className="rounded-md bg-muted/50 p-3 text-sm text-muted-foreground">
                        Nicio factură neachitată disponibilă.
                    </p>
                ) : (
                    <div className="max-h-80 overflow-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground uppercase">
                                <tr>
                                    <th className="px-3 py-2">Data</th>
                                    <th className="px-3 py-2">Număr</th>
                                    <th className="px-3 py-2 text-right">
                                        Rest
                                    </th>
                                    <th className="px-3 py-2" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                                {candidates.map((invoice) => (
                                    <tr key={invoice.id}>
                                        <td className="px-3 py-2 whitespace-nowrap text-muted-foreground">
                                            {dmy(invoice.data_doc)}
                                        </td>
                                        <td className="px-3 py-2 font-medium">
                                            {invoice.tip_doc} {invoice.nr_doc}
                                        </td>
                                        <td className="px-3 py-2 text-right whitespace-nowrap tabular-nums">
                                            {fmt(invoice.rest)} {invoice.moneda}
                                        </td>
                                        <td className="px-3 py-2 text-right">
                                            <Form
                                                {...PaymentRequestController.linkInvoice.form(
                                                    requestId,
                                                )}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                                onSuccess={() => setOpen(false)}
                                            >
                                                {({ processing }) => (
                                                    <>
                                                        <input
                                                            type="hidden"
                                                            name="invoice_id"
                                                            value={invoice.id}
                                                        />
                                                        <Button
                                                            size="sm"
                                                            variant="secondary"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            <Link2 />
                                                            Leagă
                                                        </Button>
                                                    </>
                                                )}
                                            </Form>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

function describeEvent(
    event: RequestEvent,
    statuses: Record<RequestStatus, string>,
): string | null {
    const payload = event.payload ?? {};

    switch (event.type) {
        case 'status_changed': {
            const from = payload.from as RequestStatus | undefined;
            const to = payload.to as RequestStatus | undefined;

            return `${from ? (statuses[from] ?? from) : '—'} → ${to ? (statuses[to] ?? to) : '—'}`;
        }
        case 'invoice_linked':
        case 'invoice_unlinked':
            return `${payload.tip_doc ?? ''} ${payload.nr_doc ?? ''} din ${dmy((payload.data_doc as string | null) ?? null)}`;
        case 'created': {
            const status = payload.status as RequestStatus | undefined;

            return status
                ? `Status inițial: ${statuses[status] ?? status}`
                : null;
        }
        default:
            return null;
    }
}

export default function PaymentRequestShow({
    request,
    candidateInvoices,
    statuses,
    categories,
    currentUser,
}: ShowProps) {
    const snapshot = request.snapshot ?? {};
    const rate = snapshot.rate as
        | { from: string; to: string; value: number; date: string }
        | null
        | undefined;

    return (
        <>
            <Head title={`Cerere #${request.id} · ${request.supplier_name}`} />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex items-center gap-2">
                    <Button asChild variant="ghost" size="sm">
                        <Link href={paymentRequestsIndex()}>
                            <ArrowLeft />
                            Înapoi la registru
                        </Link>
                    </Button>
                </div>

                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="flex flex-wrap items-center gap-2 text-2xl font-semibold">
                            Cerere #{request.id} · {request.supplier_name}
                            <Badge variant={STATUS_VARIANT[request.status]}>
                                {request.status_label}
                            </Badge>
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {request.kind === 'checkin'
                                ? `Plată pe check-in ${dmy(request.checkin_from)} – ${dmy(request.checkin_to)} · ${categories[request.category ?? ''] ?? request.category ?? ''}`
                                : `Plată pe factură${request.reference ? ` · ${request.reference}` : ''}`}
                            {request.company && ` · ${request.company.name}`}
                            {' · salvată '}
                            {dateTime(request.created_at)}
                            {request.created_by && ` de ${request.created_by}`}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {request.partner && (
                            <Button asChild variant="outline" size="sm">
                                <Link href={partnerShow(request.partner.id)}>
                                    Pagina furnizorului
                                </Link>
                            </Button>
                        )}
                        {request.kind === 'checkin' &&
                            request.company &&
                            request.etrip_supplier && (
                                <Button asChild variant="outline" size="sm">
                                    <Link
                                        href={paymentChecksIndex({
                                            query: {
                                                company_id: request.company.id,
                                                supplier:
                                                    request.etrip_supplier.code,
                                                from:
                                                    request.checkin_from ?? '',
                                                to: request.checkin_to ?? '',
                                                category:
                                                    request.category ?? 'hotel',
                                                amount: String(
                                                    request.requested_amount,
                                                ),
                                                currency:
                                                    request.requested_currency,
                                            },
                                        })}
                                    >
                                        <ClipboardCheck />
                                        Reverifică live
                                    </Link>
                                </Button>
                            )}
                        {request.kind === 'invoice' &&
                            request.company &&
                            request.partner && (
                                <Button asChild variant="outline" size="sm">
                                    <Link
                                        href={invoiceChecksIndex({
                                            query: {
                                                company_id: request.company.id,
                                                partner_id: request.partner.id,
                                                amount: String(
                                                    request.requested_amount,
                                                ),
                                                currency:
                                                    request.requested_currency,
                                            },
                                        })}
                                    >
                                        <ClipboardCheck />
                                        Reverifică în OMC
                                    </Link>
                                </Button>
                            )}
                        <StatusDialog
                            requestId={request.id}
                            current={request.status}
                            statuses={statuses}
                            canPay={currentUser.is_plati}
                        />
                    </div>
                </div>

                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <Tile
                        label="Suma cerută"
                        value={`${fmt(request.requested_amount)} ${request.requested_currency}`}
                        detail={
                            rate
                                ? `curs BNR ${dmy(rate.date)}: 1 ${rate.from} = ${rate.value} ${rate.to}`
                                : undefined
                        }
                    />
                    <Tile
                        label={
                            request.kind === 'checkin'
                                ? 'Cost eTrip'
                                : 'Rest în ERP'
                        }
                        value={
                            request.expected_amount !== null
                                ? `${fmt(request.expected_amount)} ${request.expected_currency ?? ''}`
                                : '—'
                        }
                        detail={
                            request.kind === 'checkin'
                                ? `${String(snapshot.items ?? '—')} servicii · ${String(snapshot.bookings ?? '—')} dosare`
                                : (snapshot.message as string | undefined)
                        }
                    />
                    <Tile
                        label="Diferența"
                        level={request.level}
                        value={
                            request.difference !== null
                                ? `${request.difference > 0 ? '+' : ''}${fmt(request.difference)} ${request.expected_currency ?? ''}`
                                : '—'
                        }
                        detail={
                            request.level ? (
                                <span className="flex items-start gap-1.5">
                                    <LevelIcon level={request.level} />
                                    <span>
                                        {request.difference_pct !== null &&
                                            `${request.difference_pct > 0 ? '+' : ''}${request.difference_pct}% · `}
                                        {(snapshot.message as
                                            | string
                                            | undefined) ??
                                            request.verdict ??
                                            ''}
                                    </span>
                                </span>
                            ) : (
                                ((snapshot.message as string | undefined) ??
                                undefined)
                            )
                        }
                    />
                    <Tile
                        label="Status"
                        value={request.status_label}
                        detail={
                            request.status_updated_at
                                ? `${dateTime(request.status_updated_at)}${request.status_updated_by ? ` · ${request.status_updated_by}` : ''}`
                                : undefined
                        }
                    />
                </div>

                <div className="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <div className="space-y-4">
                        {request.note && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Notă</CardTitle>
                                </CardHeader>
                                <CardContent className="text-sm whitespace-pre-wrap">
                                    {request.note}
                                </CardContent>
                            </Card>
                        )}

                        <Card>
                            <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3 space-y-0">
                                <div>
                                    <CardTitle>
                                        Facturi care acoperă cererea
                                    </CardTitle>
                                    <CardDescription>
                                        Când factura ajunge în ERP, leag-o aici;
                                        verificarea apare apoi în cronologia
                                        facturii, pentru responsabili și
                                        ordonator.
                                    </CardDescription>
                                </div>
                                <LinkInvoiceDialog
                                    requestId={request.id}
                                    candidates={candidateInvoices}
                                    hasPartner={request.partner !== null}
                                />
                            </CardHeader>
                            <CardContent>
                                {request.invoices.length === 0 ? (
                                    <p className="rounded-md bg-muted/50 p-3 text-sm text-muted-foreground">
                                        Nicio factură legată încă.
                                    </p>
                                ) : (
                                    <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                                        <table className="w-full text-sm">
                                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground uppercase">
                                                <tr>
                                                    <th className="px-3 py-2">
                                                        Data
                                                    </th>
                                                    <th className="px-3 py-2">
                                                        Număr
                                                    </th>
                                                    <th className="px-3 py-2 text-right">
                                                        Valoare
                                                    </th>
                                                    <th className="px-3 py-2 text-right">
                                                        Rest
                                                    </th>
                                                    <th className="px-3 py-2">
                                                        Aprobare
                                                    </th>
                                                    <th className="px-3 py-2" />
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                                                {request.invoices.map(
                                                    (invoice) => (
                                                        <tr key={invoice.id}>
                                                            <td className="px-3 py-2 whitespace-nowrap text-muted-foreground">
                                                                {dmy(
                                                                    invoice.data_doc,
                                                                )}
                                                            </td>
                                                            <td className="px-3 py-2 font-medium">
                                                                <Link
                                                                    href={invoiceShow(
                                                                        invoice.id,
                                                                    )}
                                                                    className="hover:underline"
                                                                >
                                                                    {
                                                                        invoice.tip_doc
                                                                    }{' '}
                                                                    {
                                                                        invoice.nr_doc
                                                                    }
                                                                </Link>
                                                            </td>
                                                            <td className="px-3 py-2 text-right whitespace-nowrap tabular-nums">
                                                                {fmt(
                                                                    invoice.val_mon,
                                                                )}{' '}
                                                                {invoice.moneda}
                                                            </td>
                                                            <td className="px-3 py-2 text-right whitespace-nowrap tabular-nums">
                                                                {fmt(
                                                                    invoice.rest,
                                                                )}{' '}
                                                                {invoice.moneda}
                                                            </td>
                                                            <td className="px-3 py-2">
                                                                <Badge
                                                                    variant={
                                                                        invoice.is_fully_approved
                                                                            ? 'secondary'
                                                                            : 'outline'
                                                                    }
                                                                >
                                                                    {invoice.is_fully_approved
                                                                        ? 'Bun de plată'
                                                                        : invoice.responsabili_approved
                                                                          ? 'Așteaptă ordonatorul'
                                                                          : 'Așteaptă responsabilii'}
                                                                </Badge>
                                                            </td>
                                                            <td className="px-3 py-2 text-right">
                                                                <Form
                                                                    {...PaymentRequestController.unlinkInvoice.form(
                                                                        {
                                                                            paymentRequest:
                                                                                request.id,
                                                                            invoice:
                                                                                invoice.id,
                                                                        },
                                                                    )}
                                                                    options={{
                                                                        preserveScroll: true,
                                                                    }}
                                                                    onBefore={() =>
                                                                        confirm(
                                                                            `Dezlegi factura ${invoice.nr_doc}?`,
                                                                        )
                                                                    }
                                                                >
                                                                    {({
                                                                        processing,
                                                                    }) => (
                                                                        <Button
                                                                            size="sm"
                                                                            variant="ghost"
                                                                            disabled={
                                                                                processing
                                                                            }
                                                                            title="Dezleagă"
                                                                        >
                                                                            <Link2Off />
                                                                        </Button>
                                                                    )}
                                                                </Form>
                                                            </td>
                                                        </tr>
                                                    ),
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    <aside className="lg:sticky lg:top-4 lg:self-start">
                        <div className="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                            <div className="bg-muted/50 px-4 py-2 text-sm font-semibold">
                                Cronologie
                            </div>
                            <div className="space-y-4 p-4">
                                <Form
                                    {...PaymentRequestController.comment.form(
                                        request.id,
                                    )}
                                    options={{ preserveScroll: true }}
                                    resetOnSuccess
                                    className="space-y-2"
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <Textarea
                                                name="body"
                                                rows={2}
                                                placeholder="Adaugă un comentariu…"
                                            />
                                            {errors.body && (
                                                <span className="text-xs text-destructive">
                                                    {errors.body}
                                                </span>
                                            )}
                                            <Button
                                                size="sm"
                                                variant="secondary"
                                                disabled={processing}
                                            >
                                                <MessageSquare />
                                                Comentează
                                            </Button>
                                        </>
                                    )}
                                </Form>

                                <ol className="space-y-3">
                                    {request.events.map((event) => (
                                        <li key={event.id} className="text-sm">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Badge variant="outline">
                                                    {EVENT_LABELS[event.type]}
                                                </Badge>
                                                <span className="text-xs text-muted-foreground">
                                                    {dateTime(event.created_at)}
                                                    {event.user &&
                                                        ` · ${event.user}`}
                                                </span>
                                            </div>
                                            {describeEvent(event, statuses) && (
                                                <div className="mt-1 text-muted-foreground">
                                                    {describeEvent(
                                                        event,
                                                        statuses,
                                                    )}
                                                </div>
                                            )}
                                            {event.body && (
                                                <div className="mt-1 whitespace-pre-wrap">
                                                    {event.body}
                                                </div>
                                            )}
                                        </li>
                                    ))}
                                </ol>
                            </div>
                        </div>
                    </aside>
                </div>
            </div>
        </>
    );
}

function PaymentRequestShowLayout({ children }: { children: React.ReactNode }) {
    const { request } = usePage<{ request: { id: number } }>().props;

    return (
        <AppLayout
            breadcrumbs={[
                {
                    title: 'Registru cereri de plată',
                    href: paymentRequestsIndex(),
                },
                {
                    title: `Cerere #${request.id}`,
                    href: paymentRequestShow(request.id),
                },
            ]}
        >
            {children}
        </AppLayout>
    );
}

PaymentRequestShow.layout = (page: React.ReactNode) => (
    <PaymentRequestShowLayout>{page}</PaymentRequestShowLayout>
);
