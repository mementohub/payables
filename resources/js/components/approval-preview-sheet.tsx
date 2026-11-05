import { Link } from '@inertiajs/react';
import { ExternalLink, Loader2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';
import PaymentStatusBadge from '@/components/payment-status-badge';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import WorkflowStatusBadge, {
    DecisionBadge,
} from '@/components/workflow-status-badge';
import { formatDate, formatDateTime, formatMoney } from '@/lib/money';
import { TimelineEvents } from '@/pages/invoices/invoice-timeline';
import type { TimelineEvent } from '@/pages/invoices/types';
import { preview as previewRoute } from '@/routes/approvals';
import { show as invoicesShow } from '@/routes/invoices';
import type { WorkflowInvoice } from '@/types/approvals';

type Line = {
    id: number;
    articol: string | null;
    detail: string | null;
    quantity: number;
    unit: string | null;
    price: number;
    account: string | null;
    place: string | null;
};

type Preview = {
    invoice: WorkflowInvoice & {
        company: string | null;
        val_mon_tva: number;
        emitent: string | null;
        lines: Line[];
    };
    timeline: TimelineEvent[];
};

async function getPreview(id: number): Promise<Preview> {
    const response = await fetch(previewRoute(id).url, {
        headers: { Accept: 'application/json' },
    });

    if (!response.ok) {
        const body = (await response.json().catch(() => null)) as {
            message?: string;
        } | null;

        throw new Error(body?.message ?? 'Factura nu a putut fi încărcată.');
    }

    return (await response.json()) as Preview;
}

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <div className="text-xs text-muted-foreground">{label}</div>
            <div className="text-sm font-medium">{children}</div>
        </div>
    );
}

/**
 * Factura și tot ce s-a scris pe ea, fără a pleca din căsuța de aprobări.
 *
 * Coada arăta insigna deciziei și numele celui care a dat-o, dar motivul lui
 * stătea ascuns într-un sfat de ecran, iar factura se deschidea în altă filă.
 * Cine semnează final le vrea pe amândouă sub ochi: ce s-a cumpărat, ce a
 * hotărât fiecare departament și ce a scris acolo.
 *
 * Rândul deschis e deja în pagină, așa că antetul se vede pe loc; liniile,
 * comentariile și cronologia vin din server abia la deschidere — altfel coada
 * de cincizeci de facturi ar fi adus cu ea tot ce s-a vorbit vreodată pe ele.
 */
export default function ApprovalPreviewSheet({
    invoice,
    onClose,
    actions,
}: {
    invoice: WorkflowInvoice | null;
    onClose: () => void;
    actions?: ReactNode;
}) {
    // Răspunsul își poartă factura cu el: cine închide repede și deschide
    // alta nu trebuie să vadă liniile celei dinainte cât timp se încarcă.
    const [loaded, setLoaded] = useState<{ id: number; preview: Preview } | null>(
        null,
    );
    const [failed, setFailed] = useState<{ id: number; message: string } | null>(
        null,
    );

    useEffect(() => {
        if (invoice === null) {
            return;
        }

        let dropped = false;
        const id = invoice.id;

        getPreview(id)
            .then((preview) => {
                if (!dropped) {
                    setLoaded({ id, preview });
                }
            })
            .catch((failure: Error) => {
                if (!dropped) {
                    setFailed({ id, message: failure.message });
                }
            });

        return () => {
            dropped = true;
        };
    }, [invoice]);

    const data =
        invoice !== null && loaded?.id === invoice.id ? loaded.preview : null;
    const error =
        invoice !== null && failed?.id === invoice.id ? failed.message : null;
    const full = data?.invoice ?? invoice;
    const shares = full?.departments ?? [];

    return (
        <Sheet
            open={invoice !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <SheetContent className="w-full gap-0 overflow-y-auto p-0 sm:max-w-3xl">
                {invoice !== null && full !== null && (
                    <>
                        <SheetHeader className="border-b">
                            <SheetTitle className="pr-6">
                                {full.partner?.name ?? 'Furnizor necunoscut'}
                            </SheetTitle>
                            <SheetDescription className="flex flex-wrap items-center gap-2">
                                <span>
                                    {full.nr_doc} · {formatDate(full.data_doc)}
                                </span>
                                <WorkflowStatusBadge
                                    status={full.approval_status}
                                />
                                <PaymentStatusBadge
                                    status={full.payment_status}
                                />
                            </SheetDescription>
                        </SheetHeader>

                        <div className="space-y-6 p-4">
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                <Field label="De plată">
                                    {formatMoney(full.outstanding, full.moneda)}
                                </Field>
                                <Field label="Valoarea facturii">
                                    {formatMoney(full.val_mon, full.moneda)}
                                </Field>
                                <Field label="Scadență">
                                    {formatDate(full.data_scadenta)}
                                </Field>
                                <Field label="Casa">
                                    {data?.invoice.company ?? '—'}
                                </Field>
                            </div>

                            <div>
                                <h3 className="mb-2 text-sm font-semibold">
                                    Ce au hotărât departamentele
                                </h3>
                                {shares.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        Factura n-a ajuns încă la niciun
                                        departament.
                                    </p>
                                ) : (
                                    <ul className="grid gap-2">
                                        {shares.map((share) => (
                                            <li
                                                key={share.id}
                                                className="rounded-lg border p-3"
                                            >
                                                <div className="flex flex-wrap items-center gap-2 text-sm">
                                                    <span className="font-medium">
                                                        {share.name ?? '—'}
                                                    </span>
                                                    <span className="text-muted-foreground tabular-nums">
                                                        {formatMoney(
                                                            share.share,
                                                            full.moneda,
                                                        )}
                                                    </span>
                                                    <DecisionBadge
                                                        decision={share.status}
                                                    />
                                                    {share.approved_amount !==
                                                        null &&
                                                        share.status ===
                                                            'approved' && (
                                                            <span className="text-xs font-medium text-amber-700 tabular-nums dark:text-amber-300">
                                                                aprobat doar{' '}
                                                                {formatMoney(
                                                                    share.approved_amount,
                                                                    full.moneda,
                                                                )}
                                                            </span>
                                                        )}
                                                    {share.by && (
                                                        <span className="ml-auto text-xs text-muted-foreground">
                                                            {share.by}
                                                            {share.at
                                                                ? ` · ${formatDateTime(share.at)}`
                                                                : ''}
                                                        </span>
                                                    )}
                                                </div>
                                                {share.postponed_until && (
                                                    <div className="mt-1 text-xs text-muted-foreground">
                                                        amânată până la{' '}
                                                        {formatDate(
                                                            share.postponed_until,
                                                        )}
                                                    </div>
                                                )}
                                                {/* Motivul scris de om, la
                                                    vedere: pentru asta se
                                                    deschide fereastra. */}
                                                {share.comment ? (
                                                    <p className="mt-2 rounded-md bg-muted/50 px-3 py-2 text-sm whitespace-pre-wrap">
                                                        {share.comment}
                                                    </p>
                                                ) : (
                                                    <p className="mt-2 text-xs text-muted-foreground italic">
                                                        Fără comentariu.
                                                    </p>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>

                            {full.final && (
                                <div>
                                    <h3 className="mb-2 text-sm font-semibold">
                                        Decizia finală
                                    </h3>
                                    <div className="rounded-lg border p-3 text-sm">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium">
                                                {full.final.by ?? '—'}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {formatDateTime(full.final.at)}
                                            </span>
                                        </div>
                                        {full.final.comment && (
                                            <p className="mt-2 rounded-md bg-muted/50 px-3 py-2 whitespace-pre-wrap">
                                                {full.final.comment}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            )}

                            <div>
                                <h3 className="mb-2 text-sm font-semibold">
                                    Ce s-a facturat
                                </h3>
                                {error !== null ? (
                                    <p className="text-sm text-red-600">
                                        {error}
                                    </p>
                                ) : data === null ? (
                                    <p className="flex items-center gap-2 text-sm text-muted-foreground">
                                        <Loader2 className="size-4 animate-spin" />
                                        Se încarcă...
                                    </p>
                                ) : data.invoice.lines.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        Factura n-are linii în ERP.
                                    </p>
                                ) : (
                                    <ul className="grid gap-1 text-sm">
                                        {data.invoice.lines.map((line) => (
                                            <li
                                                key={line.id}
                                                className="flex flex-wrap items-baseline gap-x-2 border-b pb-1 last:border-0"
                                            >
                                                <span>
                                                    {line.articol ??
                                                        line.detail ??
                                                        '—'}
                                                </span>
                                                {line.detail &&
                                                    line.articol && (
                                                        <span className="text-xs text-muted-foreground">
                                                            {line.detail}
                                                        </span>
                                                    )}
                                                <span className="ml-auto text-xs text-muted-foreground tabular-nums">
                                                    {line.quantity}
                                                    {line.unit
                                                        ? ` ${line.unit}`
                                                        : ''}{' '}
                                                    ×{' '}
                                                    {formatMoney(
                                                        line.price,
                                                        full.moneda,
                                                    )}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>

                            <div>
                                <h3 className="mb-2 text-sm font-semibold">
                                    Ce s-a vorbit pe factură
                                </h3>
                                {data === null ? (
                                    <p className="flex items-center gap-2 text-sm text-muted-foreground">
                                        <Loader2 className="size-4 animate-spin" />
                                        Se încarcă...
                                    </p>
                                ) : (
                                    <TimelineEvents events={data.timeline} />
                                )}
                            </div>
                        </div>

                        <div className="sticky bottom-0 flex flex-wrap items-center gap-2 border-t bg-background p-4">
                            <Button variant="outline" asChild>
                                <Link
                                    href={invoicesShow(full.id)}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    <ExternalLink className="size-4" />
                                    Deschide factura
                                </Link>
                            </Button>
                            {actions}
                        </div>
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}
