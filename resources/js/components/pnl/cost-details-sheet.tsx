import { Link, router } from '@inertiajs/react';
import { ExternalLink, Loader2, Undo2 } from 'lucide-react';
import {
    MoveChannelButton,
    MoveCostButton,
    MoveProductButton,
} from '@/components/pnl/move-cost-button';
import type { MovePayload } from '@/components/pnl/move-cost-button';
import { useCostDetails } from '@/components/pnl/use-cost-details';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';

import { show as invoiceShow } from '@/routes/invoices';
import { move as moveRoute } from '@/routes/reports/pnl';

const lei = (value: number) =>
    new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 0 }).format(value);

/** Stările din fluxul de aprobare, cum le știe `WorkflowStatus`. */
const APPROVAL_LABELS: Record<string, string> = {
    routing: 'de rutat',
    department: 'la departament',
    final: 'la aprobare finală',
    approved: 'aprobată',
    disputed: 'contestată',
    postponed: 'amânată',
};

const PAYMENT_LABELS: Record<string, string> = {
    unpaid: 'neachitată',
    partial: 'parțial achitată',
    paid: 'achitată',
};

/** Un câmp al facturii; ce lipsește în registru nu ocupă loc degeaba. */
function Field({ label, value }: { label: string; value?: string | null }) {
    if (!value) {
        return null;
    }

    return (
        <div className="min-w-0">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="truncate" title={value}>
                {value}
            </dd>
        </div>
    );
}

/**
 * Ce s-a adunat într-o linie de cheltuială. Fiecare semnătură
 * (cont | punct de lucru | partener) se poate trage pe altă linie din raport;
 * documentele de sub ea sunt doar de citit.
 */
export function CostDetailsSheet({
    companyId,
    year,
    period,
    saf,
    column,
    detailsAxis,
    catalogue,
    axis,
    products,
    onClose,
}: {
    companyId: number;
    year: number;
    period: string;
    saf: string | null;
    /** Coloana deschisă în tabel; documentele o urmează. */
    column: string | null;
    detailsAxis: 'channel' | 'product' | 'branch';
    catalogue: Record<string, { group: string; label: string }>;
    axis: 'channel' | 'product';
    products: string[];
    onClose: () => void;
}) {
    // Rezultatul se ține împreună cu linia pentru care a fost cerut: așa nu se
    // vede o clipă detaliul liniei precedente și nu se scrie starea sincron
    // din efect.
    const { details, loading } = useCostDetails(
        companyId,
        year,
        period,
        saf,
        column,
        detailsAxis,
    );

    return (
        <Sheet open={saf !== null} onOpenChange={(open) => !open && onClose()}>
            {/*
                Jumătate de ecran: pe fiecare factură încap acum scadența,
                moneda, TVA-ul și starea ei, nu doar patru coloane strânse.
                Clasele poartă aceeași variantă ca cele din componentă
                (`data-[side=right]`), altfel lățimea implicită le-ar bate.
            */}
            <SheetContent className="flex flex-col gap-0 data-[side=right]:w-full data-[side=right]:sm:w-1/2 data-[side=right]:sm:max-w-none">
                <SheetHeader>
                    <SheetTitle>
                        {details?.label ?? 'Detalii cheltuială'}
                    </SheetTitle>
                    <SheetDescription>
                        {details
                            ? `${details.group} · ${lei(details.total)} lei · ${details.documents_total} documente`
                            : 'Se încarcă…'}
                    </SheetDescription>
                </SheetHeader>

                {loading && (
                    <div className="flex items-center gap-2 p-4 text-sm text-muted-foreground">
                        <Loader2 className="size-4 animate-spin" />
                        Se citesc înregistrările din registru…
                    </div>
                )}

                {details && !loading && (
                    <div className="flex-1 space-y-6 overflow-y-auto p-4">
                        <section className="space-y-2">
                            <h3 className="text-sm font-semibold">
                                Documente
                                {details.documents_total >
                                    details.documents.length &&
                                    ` (primele ${details.documents.length} din ${details.documents_total})`}
                            </h3>
                            <div className="space-y-2">
                                {details.documents.map((doc, index) => (
                                    <article
                                        key={`${doc.tip_doc}-${doc.nr_doc}-${index}`}
                                        className="rounded-md border p-3"
                                    >
                                        <header className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium">
                                                    {doc.partner ||
                                                        doc.sediu ||
                                                        '—'}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {doc.tip_doc} {doc.nr_doc} ·{' '}
                                                    {doc.data_doc}
                                                    {doc.due &&
                                                        ` · scadent ${doc.due}`}
                                                </p>
                                            </div>
                                            <div className="text-right">
                                                <p className="text-sm font-semibold tabular-nums">
                                                    {lei(doc.lei)} lei
                                                </p>
                                                {Math.abs(
                                                    doc.doc_total - doc.lei,
                                                ) > 0.5 && (
                                                    <p className="text-xs text-muted-foreground tabular-nums">
                                                        din {lei(doc.doc_total)}{' '}
                                                        {doc.currency || 'Lei'}
                                                        {doc.rate !== 1 &&
                                                            ` × ${doc.rate}`}
                                                    </p>
                                                )}
                                            </div>
                                        </header>

                                        <dl className="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs sm:grid-cols-3">
                                            <Field
                                                label="Cont"
                                                value={doc.account}
                                            />
                                            <Field
                                                label="Notă contabilă"
                                                value={doc.booked}
                                            />
                                            <Field
                                                label={
                                                    doc.counterpart.startsWith(
                                                        '471',
                                                    )
                                                        ? 'Din cheltuieli în avans'
                                                        : 'Contrapartidă'
                                                }
                                                value={doc.counterpart}
                                            />
                                            <Field
                                                label="Punct de lucru"
                                                value={doc.sediu}
                                            />
                                            <Field
                                                label="Cont partener"
                                                value={doc.partner_account}
                                            />
                                            <Field
                                                label="TVA"
                                                value={
                                                    doc.doc_vat
                                                        ? `${lei(doc.doc_vat)} ${doc.currency || 'Lei'}`
                                                        : null
                                                }
                                            />
                                            <Field
                                                label="Emisă"
                                                value={doc.issued}
                                            />
                                            <Field
                                                label="Operator"
                                                value={doc.issuer}
                                            />
                                            <Field
                                                label="Jurnal"
                                                value={doc.journal}
                                            />
                                            <Field
                                                label="Sediul partenerului"
                                                value={doc.partner_office}
                                            />
                                            {doc.invoice && (
                                                <>
                                                    <Field
                                                        label="Departament"
                                                        value={
                                                            doc.invoice
                                                                .department
                                                        }
                                                    />
                                                    <Field
                                                        label="Aprobare"
                                                        value={
                                                            APPROVAL_LABELS[
                                                                doc.invoice
                                                                    .approval_status ??
                                                                    ''
                                                            ] ??
                                                            doc.invoice
                                                                .approval_status
                                                        }
                                                    />
                                                    <Field
                                                        label="Plată"
                                                        value={
                                                            PAYMENT_LABELS[
                                                                doc.invoice
                                                                    .payment_status
                                                            ] ??
                                                            doc.invoice
                                                                .payment_status
                                                        }
                                                    />
                                                    <Field
                                                        label="Achitat"
                                                        value={
                                                            doc.invoice.paid
                                                                ? `${lei(doc.invoice.paid)} ${doc.currency || 'Lei'}`
                                                                : null
                                                        }
                                                    />
                                                    <Field
                                                        label="Închisă"
                                                        value={
                                                            doc.invoice
                                                                .closed_at
                                                        }
                                                    />
                                                    <Field
                                                        label="Comandă internă"
                                                        value={
                                                            doc.invoice.internal
                                                        }
                                                    />
                                                </>
                                            )}
                                        </dl>

                                        {(doc.note || doc.observation) && (
                                            <p className="mt-2 text-xs text-muted-foreground">
                                                {[doc.note, doc.observation]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </p>
                                        )}

                                        <footer className="mt-2 flex flex-wrap items-center justify-end gap-1">
                                            {doc.invoice && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="h-6 gap-1 px-2 text-xs"
                                                    asChild
                                                >
                                                    <Link
                                                        href={invoiceShow.url({
                                                            invoice:
                                                                doc.invoice.id,
                                                        })}
                                                    >
                                                        <ExternalLink className="size-3" />
                                                        Vezi factura
                                                    </Link>
                                                </Button>
                                            )}
                                            {axis === 'channel' ? (
                                                <MoveChannelButton
                                                    payload={documentPayload(
                                                        doc,
                                                    )}
                                                    onMove={(
                                                        payload,
                                                        channel,
                                                    ) =>
                                                        movePnlCostAxis(
                                                            companyId,
                                                            year,
                                                            payload,
                                                            { channel },
                                                        )
                                                    }
                                                />
                                            ) : (
                                                <MoveProductButton
                                                    products={products}
                                                    payload={documentPayload(
                                                        doc,
                                                    )}
                                                    onMove={(
                                                        payload,
                                                        product,
                                                    ) =>
                                                        movePnlCostAxis(
                                                            companyId,
                                                            year,
                                                            payload,
                                                            { product },
                                                        )
                                                    }
                                                />
                                            )}
                                            <MoveCostButton
                                                lines={catalogue}
                                                currentSaf={details.saf}
                                                payload={documentPayload(doc)}
                                                onMove={(payload, target) =>
                                                    movePnlCost(
                                                        companyId,
                                                        year,
                                                        payload,
                                                        target,
                                                    )
                                                }
                                            />
                                        </footer>
                                    </article>
                                ))}
                            </div>
                        </section>
                    </div>
                )}
            </SheetContent>
        </Sheet>
    );
}

/**
 * Mută o cheltuială (sau o linie întreagă) pe linia pe care a fost lăsată.
 */
/**
 * Semnătura unui document, așa cum o recunoaște corectura: data, tipul și
 * numărul lui.
 */
function documentPayload(doc: {
    data_doc: string;
    tip_doc: string;
    nr_doc: string;
    partner: string;
    sediu: string;
}): MovePayload {
    return {
        scope: 'document',
        match_key: [doc.data_doc, doc.tip_doc, doc.nr_doc].join('|'),
        label: `${doc.tip_doc} ${doc.nr_doc} · ${doc.partner || doc.sediu}`,
    };
}

/**
 * Aceeași corectură, dar pe axa de canal sau de categorie: linia rămâne unde
 * e, se schimbă doar coloana pe care stă cheltuiala.
 */
export function movePnlCostAxis(
    companyId: number,
    year: number,
    payload: { scope: string; match_key: string; label?: string },
    axis: { channel?: string; product?: string },
) {
    router.post(
        moveRoute.url({ company: companyId }),
        { ...payload, ...axis, year },
        { preserveScroll: true },
    );
}

export function movePnlCost(
    companyId: number,
    year: number,
    payload: { scope: string; match_key: string; label?: string },
    saf: string,
) {
    router.post(
        moveRoute.url({ company: companyId }),
        { ...payload, saf, year },
        { preserveScroll: true },
    );
}

export function UndoButton({
    companyId,
    overrideId,
    year,
}: {
    companyId: number;
    overrideId: number;
    year: number;
}) {
    return (
        <Button
            variant="ghost"
            size="sm"
            onClick={() =>
                router.delete(
                    `/reports/pnl/${companyId}/overrides/${overrideId}?year=${year}`,
                    { preserveScroll: true },
                )
            }
        >
            <Undo2 className="size-3.5" />
            Anulează
        </Button>
    );
}
