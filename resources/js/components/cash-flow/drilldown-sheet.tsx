import { ExternalLink } from 'lucide-react';
import { useEffect, useState } from 'react';
import CashFlowReportController from '@/actions/App/Http/Controllers/CashFlowReportController';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import WorkflowStatusBadge from '@/components/workflow-status-badge';
import { formatDate, formatMoney } from '@/lib/money';
import { show as invoiceShow } from '@/routes/invoices';
import type { WorkflowStatus } from '@/types/approvals';

type DrillRow = {
    id: number;
    partner: string | null;
    nr_doc: string;
    data_doc: string | null;
    due: string;
    days_overdue: number;
    kind: 'overdue' | 'due';
    currency: string | null;
    open: number;
    open_lei: number;
    week_lei: number;
    department: string | null;
    approval_status: WorkflowStatus | null;
};

type Drilldown = {
    week_label: string;
    spread_weeks: number;
    overdue_in_week: boolean;
    rows: DrillRow[];
    totals: { overdue: number; due: number; week: number };
};

export type DrillTarget = { line: string; label: string; week: string };

/**
 * The invoices behind one forecast cell, read from the mirror as they stand
 * now, each opening its invoice page.
 */
export default function DrilldownSheet({
    target,
    reportValue,
    onClose,
}: {
    target: DrillTarget | null;
    /** What the stored report shows in the cell, to compare with now. */
    reportValue: number | null;
    onClose: () => void;
}) {
    const [data, setData] = useState<Drilldown | null>(null);
    const [error, setError] = useState<string | null>(null);
    const key = target ? `${target.line}|${target.week}` : null;
    const [loadedKey, setLoadedKey] = useState<string | null>(null);

    // Forget the previous cell's list as soon as another one is asked for.
    if (key !== loadedKey) {
        setLoadedKey(key);
        setData(null);
        setError(null);
    }

    useEffect(() => {
        if (!target) {
            return;
        }

        let cancelled = false;

        fetch(
            CashFlowReportController.drilldown({
                query: { line: target.line, week: target.week },
            }).url,
            { headers: { Accept: 'application/json' } },
        )
            .then(async (res) => {
                if (!res.ok) {
                    throw new Error('Facturile nu au putut fi încărcate.');
                }

                return (await res.json()) as Drilldown;
            })
            .then((payload) => !cancelled && setData(payload))
            .catch((e: Error) => !cancelled && setError(e.message));

        return () => {
            cancelled = true;
        };
    }, [target]);

    const overdue = data?.rows.filter((r) => r.kind === 'overdue') ?? [];
    const due = data?.rows.filter((r) => r.kind === 'due') ?? [];

    return (
        <Sheet
            open={target !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <SheetContent className="w-full overflow-y-auto sm:max-w-3xl">
                <SheetHeader>
                    <SheetTitle>
                        {target?.line} · {data?.week_label ?? target?.week}
                    </SheetTitle>
                    <SheetDescription>{target?.label}</SheetDescription>
                </SheetHeader>

                <div className="grid gap-4 px-4 pb-6 text-sm">
                    {error && <p className="text-destructive">{error}</p>}
                    {!data && !error && (
                        <p className="text-muted-foreground">
                            Se încarcă facturile…
                        </p>
                    )}

                    {data && (
                        <>
                            <div className="grid gap-1 rounded-lg bg-muted/40 p-3">
                                <div className="flex justify-between">
                                    <span>Acum, din facturile de mai jos</span>
                                    <span className="font-semibold tabular-nums">
                                        {formatMoney(data.totals.week, 'lei')}
                                    </span>
                                </div>
                                {reportValue !== null && (
                                    <div className="flex justify-between text-muted-foreground">
                                        <span>În raportul calculat</span>
                                        <span className="tabular-nums">
                                            {formatMoney(reportValue, 'lei')}
                                        </span>
                                    </div>
                                )}
                                <p className="text-xs text-muted-foreground">
                                    {data.overdue_in_week
                                        ? `Facturile restante intră cu 1/${data.spread_weeks} din rest în fiecare din primele ${data.spread_weeks} săptămâni (parametrul „Sold furnizori – săptămâni”); cele scadente în săptămână, integral.`
                                        : 'Doar facturile scadente în această săptămână; restanțele sunt repartizate pe primele săptămâni.'}{' '}
                                    Lista este citită acum, deci poate diferi
                                    puțin de raportul calculat mai devreme.
                                </p>
                            </div>

                            {overdue.length > 0 && (
                                <InvoiceList
                                    title={`Restante (${overdue.length}) · în această săptămână ${formatMoney(data.totals.overdue, 'lei')}`}
                                    rows={overdue}
                                    showShare
                                />
                            )}
                            {due.length > 0 && (
                                <InvoiceList
                                    title={`Scadente în săptămână (${due.length}) · ${formatMoney(data.totals.due, 'lei')}`}
                                    rows={due}
                                    showShare={false}
                                />
                            )}
                            {data.rows.length === 0 && (
                                <p className="text-muted-foreground">
                                    Nicio factură deschisă nu cade în această
                                    săptămână.
                                </p>
                            )}
                        </>
                    )}
                </div>
            </SheetContent>
        </Sheet>
    );
}

function InvoiceList({
    title,
    rows,
    showShare,
}: {
    title: string;
    rows: DrillRow[];
    showShare: boolean;
}) {
    return (
        <section className="grid gap-2">
            <h3 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                {title}
            </h3>
            <div className="overflow-x-auto rounded-lg border border-sidebar-border/70 dark:border-sidebar-border">
                <table className="w-full text-xs">
                    <thead className="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th className="px-2 py-1.5">Furnizor / factură</th>
                            <th className="px-2 py-1.5">Scadență</th>
                            <th className="px-2 py-1.5 text-right">
                                Rest de plată
                            </th>
                            <th className="px-2 py-1.5 text-right">
                                {showShare ? 'În săptămână' : 'Lei'}
                            </th>
                            <th className="px-2 py-1.5">Aprobare</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                        {rows.map((row) => (
                            <tr key={row.id}>
                                <td className="px-2 py-1.5">
                                    <a
                                        href={invoiceShow(row.id).url}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="inline-flex items-center gap-1 font-medium hover:underline"
                                    >
                                        {row.nr_doc}
                                        <ExternalLink className="size-3 text-muted-foreground" />
                                    </a>
                                    <div className="text-muted-foreground">
                                        {row.partner ?? '—'}
                                        {row.department
                                            ? ` · ${row.department}`
                                            : ''}
                                    </div>
                                </td>
                                <td className="px-2 py-1.5 whitespace-nowrap">
                                    {formatDate(row.due)}
                                    {row.days_overdue > 0 && (
                                        <div className="text-destructive">
                                            {row.days_overdue} zile
                                        </div>
                                    )}
                                </td>
                                <td className="px-2 py-1.5 text-right whitespace-nowrap tabular-nums">
                                    {formatMoney(row.open, row.currency)}
                                </td>
                                <td className="px-2 py-1.5 text-right whitespace-nowrap tabular-nums">
                                    {formatMoney(row.week_lei, 'lei')}
                                </td>
                                <td className="px-2 py-1.5">
                                    <WorkflowStatusBadge
                                        status={row.approval_status}
                                    />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </section>
    );
}
