import { Link } from '@inertiajs/react';
import { ArrowUpRight, Network } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { primite as facturiPrimite } from '@/routes/invoices';
import type { FinalSuppliers } from './types';

const lei = (value: number) =>
    value.toLocaleString('ro-RO', { maximumFractionDigits: 0 });

const percent = (value: number) =>
    (value * 100).toLocaleString('ro-RO', { maximumFractionDigits: 1 });

/**
 * Cine stă, de fapt, în spatele unei facturi refacturate.
 *
 * Factura vine de la Memento Air, dar zborul l-a făcut Anima Wings. La
 * cazări și chirii se știe chiar documentul furnizorului; la charter se știe
 * luna, iar suma se repartizează — și scrie pe față că e o repartizare, ca
 * omul care aprobă să nu citească o estimare drept o certitudine.
 */
export function FinalSuppliersCard({ sources }: { sources: FinalSuppliers }) {
    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle className="flex items-center gap-2 text-base">
                    <Network className="size-4 text-muted-foreground" />
                    În spatele acestei facturi
                </CardTitle>
                <p className="text-xs text-muted-foreground">
                    {sources.exact
                        ? `Potrivit după ${sources.basis_label}, la nivel de document.`
                        : `Repartizat după ${sources.basis_label}: se cumpără pe perioade și se vinde pe zboruri, așa că sumele sunt o împărțire, nu o egalitate.`}
                </p>
            </CardHeader>
            <CardContent className="grid gap-2">
                {sources.suppliers.map((supplier) => (
                    <div
                        key={supplier.name}
                        className="flex items-start justify-between gap-3 rounded-md border bg-muted/30 p-2.5"
                    >
                        <div className="min-w-0">
                            <Link
                                href={
                                    facturiPrimite({
                                        query: {
                                            final_supplier: supplier.name,
                                        },
                                    }).url
                                }
                                className="inline-flex items-center gap-1 font-medium hover:underline"
                            >
                                {supplier.name}
                                <ArrowUpRight className="size-3 opacity-50" />
                            </Link>
                            <div className="mt-0.5 truncate text-xs text-muted-foreground">
                                {supplier.tax_id ? `${supplier.tax_id} · ` : ''}
                                {supplier.documents.length > 0
                                    ? supplier.documents.join(', ')
                                    : supplier.references.join(', ')}
                            </div>
                        </div>
                        <div className="shrink-0 text-right">
                            <div className="font-medium tabular-nums">
                                {lei(supplier.lei)} lei
                            </div>
                            <Badge
                                variant="outline"
                                className="mt-0.5 text-[10px]"
                            >
                                {percent(supplier.share)}%
                                {supplier.exact ? '' : ' repartizat'}
                            </Badge>
                        </div>
                    </div>
                ))}

                <p className="text-xs text-muted-foreground">
                    Acoperit {percent(sources.covered)}% din{' '}
                    {lei(sources.net_lei)} lei fără TVA.
                </p>
            </CardContent>
        </Card>
    );
}
