import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Search, X } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { show as invoiceShow } from '@/routes/invoices';
import {
    index as opexIndex,
    invoices as opexInvoicesRoute,
} from '@/routes/reports/opex';

type Invoice = {
    data_doc: string;
    month: number;
    tip_doc: string;
    nr_doc: string;
    partner: string;
    sediu: string | null;
    moneda: string | null;
    val_mon: number | null;
    line_total_lei: number;
    invoice_id: number | null;
};

type Filters = {
    year: number;
    leaves: string[];
    sediu: string | null;
    month: number | null;
    tip_doc: string | null;
    partner: string | null;
    q: string | null;
    category_label: string | null;
};

type Props = {
    company: { id: number; name: string };
    filters: Filters;
    invoices: Invoice[];
    options: { tip_doc: string[]; partners: string[] };
    summary: { count_total: number; count_filtered: number; total_lei: number };
};

const MONTHS = [
    { v: 1, l: 'Ianuarie' },
    { v: 2, l: 'Februarie' },
    { v: 3, l: 'Martie' },
    { v: 4, l: 'Aprilie' },
    { v: 5, l: 'Mai' },
    { v: 6, l: 'Iunie' },
    { v: 7, l: 'Iulie' },
    { v: 8, l: 'August' },
    { v: 9, l: 'Septembrie' },
    { v: 10, l: 'Octombrie' },
    { v: 11, l: 'Noiembrie' },
    { v: 12, l: 'Decembrie' },
];

function formatLei(value: number): string {
    return new Intl.NumberFormat('ro-RO', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(value);
}

export default function OpExInvoices({
    company,
    filters,
    invoices,
    options,
    summary,
}: Props) {
    const [q, setQ] = useState(filters.q ?? '');

    const apply = (next: Partial<Filters>) => {
        const merged = { ...filters, ...next };
        const params = new URLSearchParams();
        params.set('year', String(merged.year));
        for (const leaf of merged.leaves) {
            params.append('leaves[]', leaf);
        }
        if (merged.sediu !== null && merged.sediu !== undefined) {
            params.set('sediu', merged.sediu);
        }
        if (merged.month) params.set('month', String(merged.month));
        if (merged.tip_doc) params.set('tip_doc', merged.tip_doc);
        if (merged.partner) params.set('partner', merged.partner);
        if (merged.q) params.set('q', merged.q);
        if (merged.category_label)
            params.set('category_label', merged.category_label);

        router.visit(
            `${opexInvoicesRoute(company.id).url}?${params.toString()}`,
            { preserveScroll: true, preserveState: false, replace: true },
        );
    };

    const submitSearch = (e: React.FormEvent) => {
        e.preventDefault();
        apply({ q: q || null });
    };

    const clearFilters = () => {
        setQ('');
        apply({
            month: null,
            tip_doc: null,
            partner: null,
            q: null,
        });
    };

    const hasActiveFilters =
        filters.month !== null ||
        (filters.tip_doc !== null && filters.tip_doc !== '') ||
        (filters.partner !== null && filters.partner !== '') ||
        (filters.q !== null && filters.q !== '');

    return (
        <>
            <Head
                title={`Facturi · ${filters.category_label ?? 'OpEx'} · ${filters.year}`}
            />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <Button
                            asChild
                            variant="ghost"
                            size="sm"
                            className="-ml-2 mb-2"
                        >
                            <Link href={opexIndex().url}>
                                <ArrowLeft />
                                Înapoi la raport
                            </Link>
                        </Button>
                        <h1 className="text-2xl font-semibold">
                            Facturi · {filters.category_label ?? 'OpEx'}
                        </h1>
                        <div className="mt-1 flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                            <span>{company.name}</span>
                            <span>·</span>
                            <span>{filters.year}</span>
                            {filters.sediu !== null &&
                                filters.sediu !== undefined && (
                                    <>
                                        <span>·</span>
                                        <Badge variant="outline">
                                            {filters.sediu === ''
                                                ? '(fără sediu)'
                                                : filters.sediu}
                                        </Badge>
                                    </>
                                )}
                        </div>
                    </div>
                    <div className="text-right">
                        <div className="text-2xl font-semibold tabular-nums">
                            {formatLei(summary.total_lei)}{' '}
                            <span className="text-sm font-normal text-muted-foreground">
                                RON
                            </span>
                        </div>
                        <div className="text-xs text-muted-foreground">
                            {summary.count_filtered} / {summary.count_total}{' '}
                            documente
                        </div>
                    </div>
                </div>

                <Card>
                    <CardContent className="flex flex-wrap items-end gap-3 py-4">
                        <div className="grid gap-1">
                            <Label className="text-xs">Lună</Label>
                            <Select
                                value={
                                    filters.month
                                        ? String(filters.month)
                                        : 'all'
                                }
                                onValueChange={(v) =>
                                    apply({
                                        month: v === 'all' ? null : Number(v),
                                    })
                                }
                            >
                                <SelectTrigger className="min-h-11 w-[160px]">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        Toate lunile
                                    </SelectItem>
                                    {MONTHS.map((m) => (
                                        <SelectItem
                                            key={m.v}
                                            value={String(m.v)}
                                        >
                                            {m.l}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-1">
                            <Label className="text-xs">Tip document</Label>
                            <Select
                                value={filters.tip_doc ?? 'all'}
                                onValueChange={(v) =>
                                    apply({
                                        tip_doc: v === 'all' ? null : v,
                                    })
                                }
                            >
                                <SelectTrigger className="min-h-11 w-[160px]">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Toate</SelectItem>
                                    {options.tip_doc.map((t) => (
                                        <SelectItem key={t} value={t}>
                                            {t}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-1">
                            <Label className="text-xs">Furnizor</Label>
                            <Select
                                value={filters.partner ?? 'all'}
                                onValueChange={(v) =>
                                    apply({
                                        partner: v === 'all' ? null : v,
                                    })
                                }
                            >
                                <SelectTrigger className="min-h-11 w-[280px]">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        Toți furnizorii
                                    </SelectItem>
                                    {options.partners.map((p) => (
                                        <SelectItem key={p} value={p}>
                                            {p}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <form onSubmit={submitSearch} className="grid gap-1">
                            <Label className="text-xs">
                                Caută (nr. doc / partener)
                            </Label>
                            <div className="flex items-center gap-2">
                                <Input
                                    value={q}
                                    onChange={(e) => setQ(e.target.value)}
                                    placeholder="ex. 12345 sau ACME"
                                    className="min-h-11 w-[260px]"
                                />
                                <Button
                                    type="submit"
                                    size="sm"
                                    variant="secondary"
                                >
                                    <Search />
                                </Button>
                            </div>
                        </form>
                        {hasActiveFilters && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={clearFilters}
                                className="min-h-11"
                            >
                                <X />
                                Resetează
                            </Button>
                        )}
                    </CardContent>
                </Card>

                <div className="overflow-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <Table>
                        <TableHeader className="sticky top-0 z-10 bg-background">
                            <TableRow>
                                <TableHead className="w-28">Data</TableHead>
                                <TableHead className="w-20">Tip</TableHead>
                                <TableHead className="w-32">Nr. doc</TableHead>
                                <TableHead>Partener</TableHead>
                                <TableHead>Sediu</TableHead>
                                <TableHead className="w-40 text-right">
                                    Valoare
                                </TableHead>
                                <TableHead className="w-36 text-right">
                                    Total RON
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {invoices.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={7}
                                        className="py-10 text-center text-muted-foreground"
                                    >
                                        Nicio factură pentru filtrele alese.
                                    </TableCell>
                                </TableRow>
                            )}
                            {invoices.map((inv) => (
                                <TableRow
                                    key={`${inv.data_doc}-${inv.tip_doc}-${inv.nr_doc}-${inv.sediu ?? ''}`}
                                >
                                    <TableCell className="text-xs">
                                        {inv.data_doc}
                                    </TableCell>
                                    <TableCell>
                                        <Badge
                                            variant="secondary"
                                            className="text-[10px]"
                                        >
                                            {inv.tip_doc}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {inv.invoice_id ? (
                                            <Link
                                                href={
                                                    invoiceShow(
                                                        inv.invoice_id,
                                                    ).url
                                                }
                                                className="text-primary hover:underline"
                                            >
                                                {inv.nr_doc}
                                            </Link>
                                        ) : (
                                            inv.nr_doc
                                        )}
                                    </TableCell>
                                    <TableCell className="truncate text-sm">
                                        {inv.partner || '—'}
                                    </TableCell>
                                    <TableCell className="text-xs text-muted-foreground">
                                        {inv.sediu || '—'}
                                    </TableCell>
                                    <TableCell className="text-right text-xs tabular-nums">
                                        {inv.val_mon !== null
                                            ? `${formatLei(inv.val_mon)} ${inv.moneda ?? ''}`.trim()
                                            : '—'}
                                    </TableCell>
                                    <TableCell className="text-right font-medium tabular-nums">
                                        {formatLei(inv.line_total_lei)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </>
    );
}

OpExInvoices.layout = (page: React.ReactNode) => (
    <AppLayout
        breadcrumbs={[
            { title: 'Rapoarte', href: opexIndex() },
            { title: 'OpEx', href: opexIndex() },
            { title: 'Facturi', href: opexIndex() },
        ]}
    >
        {page}
    </AppLayout>
);
