import { Head, Link, router } from '@inertiajs/react';
import { CalendarCheck, Download, Search } from 'lucide-react';
import { useState } from 'react';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { formatByCurrency, formatDate, formatMoney } from '@/lib/money';
import { show as invoiceShow } from '@/routes/invoices';
import {
    exportMethod as dueExport,
    index as dueIndex,
} from '@/routes/reports/due';
import type { Paginated } from '@/types/pagination';

type Row = {
    id: number;
    company: string | null;
    partner: string | null;
    cui: string | null;
    tip_doc: string | null;
    nr_doc: string;
    data_doc: string | null;
    data_scadenta: string | null;
    days: number | null;
    moneda: string | null;
    val_mon: number;
    paid: number;
    remaining: number;
    remaining_lei: number;
    department: string | null;
    status: string | null;
};

type Filters = {
    today: string;
    until: string | null;
    company_id: number | null;
    department: number | null;
    search: string;
    status: 'approved' | 'waiting' | null;
};

type Props = {
    rows: Paginated<Row>;
    totals: {
        count: number;
        lei: number;
        by_currency: Record<string, number>;
        buckets: Record<'week' | 'month' | 'later', { count: number; lei: number }>;
    };
    filters: Filters;
    companies: { id: number; name: string }[];
    departments: { id: number; name: string }[];
};

const statusLabels: Record<string, string> = {
    routing: 'De rutat',
    department: 'La departamente',
    final: 'La Top Management',
    approved: 'Aprobată',
    disputed: 'Contestată',
    postponed: 'Amânată',
};

/** Cât mai e până la scadență, în vorbe scurte. */
function dueLabel(days: number | null): string {
    if (days === null) {
        return '—';
    }

    if (days === 0) {
        return 'azi';
    }

    return days === 1 ? 'mâine' : `în ${days} zile`;
}

/**
 * Scadențarul: facturile de furnizor cu rest de plată și scadența de azi
 * înainte. Ce a trecut de scadență nu intră aici — raportul spune ce urmează
 * de plătit, nu ce s-a întârziat.
 */
export default function DueReport({
    rows,
    totals,
    filters,
    companies,
    departments,
}: Props) {
    const [search, setSearch] = useState(filters.search);

    const applyFilter = (next: Partial<Filters>) => {
        const merged = { ...filters, ...next };

        router.get(
            dueIndex().url,
            {
                until: merged.until ?? undefined,
                company_id: merged.company_id ?? undefined,
                department: merged.department ?? undefined,
                status: merged.status ?? undefined,
                search: merged.search || undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const exportUrl = dueExport({
        query: {
            until: filters.until ?? undefined,
            company_id: filters.company_id ?? undefined,
            department: filters.department ?? undefined,
            status: filters.status ?? undefined,
            search: filters.search || undefined,
        },
    }).url;

    const buckets: { key: 'week' | 'month' | 'later'; label: string }[] = [
        { key: 'week', label: 'în 7 zile' },
        { key: 'month', label: 'în 8–30 de zile' },
        { key: 'later', label: 'peste 30 de zile' },
    ];

    return (
        <>
            <Head title="Scadențar" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">Scadențar</h1>
                        <p className="max-w-3xl text-sm text-muted-foreground">
                            Facturile de furnizor cu rest de plată și scadența
                            de azi ({formatDate(filters.today)}) înainte. Sumele
                            sunt ce a mai rămas de plătit, nu valoarea
                            facturii.
                        </p>
                    </div>

                    <Button asChild variant="outline">
                        <a href={exportUrl}>
                            <Download />
                            Descarcă xlsx
                        </a>
                    </Button>
                </div>

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                        <div className="text-xs text-muted-foreground uppercase">
                            Total de plătit
                        </div>
                        <div className="text-2xl font-semibold tabular-nums">
                            {formatMoney(totals.lei, 'RON')}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            {totals.count}{' '}
                            {totals.count === 1 ? 'factură' : 'facturi'} ·{' '}
                            {formatByCurrency(totals.by_currency)}
                        </div>
                    </div>

                    {buckets.map(({ key, label }) => (
                        <div
                            key={key}
                            className="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
                        >
                            <div className="text-xs text-muted-foreground uppercase">
                                Scadente {label}
                            </div>
                            <div className="text-2xl font-semibold tabular-nums">
                                {formatMoney(totals.buckets[key].lei, 'RON')}
                            </div>
                            <div className="text-xs text-muted-foreground">
                                {totals.buckets[key].count}{' '}
                                {totals.buckets[key].count === 1
                                    ? 'factură'
                                    : 'facturi'}
                            </div>
                        </div>
                    ))}
                </div>

                <form
                    aria-label="Filtre scadențar"
                    className="flex flex-wrap items-end gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        applyFilter({ search: search.trim() });
                    }}
                >
                    <div className="grid gap-1.5">
                        <Label htmlFor="due-search">Caută</Label>
                        <div className="flex gap-2">
                            <Input
                                id="due-search"
                                className="w-full sm:w-[260px]"
                                placeholder="Furnizor, CUI sau număr..."
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                            />
                            <Button type="submit" variant="outline" size="icon">
                                <Search />
                            </Button>
                        </div>
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="due-until">Scadență până la</Label>
                        <Input
                            id="due-until"
                            type="date"
                            className="w-full sm:w-[190px]"
                            value={filters.until ?? ''}
                            onChange={(event) =>
                                applyFilter({
                                    until: event.target.value || null,
                                })
                            }
                        />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="due-company">Companie</Label>
                        <Select
                            value={
                                filters.company_id
                                    ? String(filters.company_id)
                                    : 'all'
                            }
                            onValueChange={(value) =>
                                applyFilter({
                                    company_id:
                                        value === 'all' ? null : Number(value),
                                })
                            }
                        >
                            <SelectTrigger
                                id="due-company"
                                className="w-full sm:w-[200px]"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Toate</SelectItem>
                                {companies.map((company) => (
                                    <SelectItem
                                        key={company.id}
                                        value={String(company.id)}
                                    >
                                        {company.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="due-department">Departament</Label>
                        <Select
                            value={
                                filters.department
                                    ? String(filters.department)
                                    : 'all'
                            }
                            onValueChange={(value) =>
                                applyFilter({
                                    department:
                                        value === 'all' ? null : Number(value),
                                })
                            }
                        >
                            <SelectTrigger
                                id="due-department"
                                className="w-full sm:w-[220px]"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Toate</SelectItem>
                                {departments.map((department) => (
                                    <SelectItem
                                        key={department.id}
                                        value={String(department.id)}
                                    >
                                        {department.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="due-status">Aprobare</Label>
                        <Select
                            value={filters.status ?? 'all'}
                            onValueChange={(value) =>
                                applyFilter({
                                    status:
                                        value === 'all'
                                            ? null
                                            : (value as 'approved' | 'waiting'),
                                })
                            }
                        >
                            <SelectTrigger
                                id="due-status"
                                className="w-full sm:w-[200px]"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Oricare</SelectItem>
                                <SelectItem value="approved">
                                    Aprobate final
                                </SelectItem>
                                <SelectItem value="waiting">
                                    Încă neaprobate
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </form>

                <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <Table>
                        <TableHeader className="bg-muted/50">
                            <TableRow>
                                <TableHead>Furnizor / factură</TableHead>
                                <TableHead>Data / Scadență</TableHead>
                                <TableHead className="text-right">
                                    Valoare
                                </TableHead>
                                <TableHead className="text-right">
                                    Rest de plată
                                </TableHead>
                                <TableHead className="text-right">
                                    Rest (lei)
                                </TableHead>
                                <TableHead>Departament</TableHead>
                                <TableHead>Aprobare</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.data.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={7}
                                        className="py-12 text-center text-muted-foreground"
                                    >
                                        <div className="flex flex-col items-center gap-2">
                                            <CalendarCheck className="size-8 opacity-50" />
                                            <span>
                                                Nicio factură cu scadența de
                                                azi înainte.
                                            </span>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ) : (
                                rows.data.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell>
                                            <Link
                                                href={invoiceShow(row.id)}
                                                className="font-medium hover:underline"
                                            >
                                                {row.partner ?? '—'}
                                            </Link>
                                            <div className="text-xs text-muted-foreground">
                                                {row.tip_doc} {row.nr_doc}
                                                {row.company
                                                    ? ` · ${row.company}`
                                                    : ''}
                                            </div>
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap tabular-nums">
                                            {formatDate(row.data_doc)}
                                            <div className="text-xs font-medium">
                                                {formatDate(row.data_scadenta)}
                                                <span className="ml-1 font-normal text-muted-foreground">
                                                    ({dueLabel(row.days)})
                                                </span>
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right whitespace-nowrap tabular-nums">
                                            {formatMoney(
                                                row.val_mon,
                                                row.moneda,
                                            )}
                                            {row.paid > 0 && (
                                                <div className="text-xs text-muted-foreground">
                                                    plătit{' '}
                                                    {formatMoney(
                                                        row.paid,
                                                        row.moneda,
                                                    )}
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right font-medium whitespace-nowrap tabular-nums">
                                            {formatMoney(
                                                row.remaining,
                                                row.moneda,
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right whitespace-nowrap tabular-nums">
                                            {formatMoney(
                                                row.remaining_lei,
                                                'RON',
                                            )}
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {row.department ?? (
                                                <span className="text-muted-foreground">
                                                    Fără departament
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant={
                                                    row.status === 'approved'
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                            >
                                                {row.status
                                                    ? (statusLabels[
                                                          row.status
                                                      ] ?? row.status)
                                                    : 'Neintrată în flux'}
                                            </Badge>
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                        {rows.data.length > 0 && (
                            <TableFooter>
                                <TableRow>
                                    <TableCell colSpan={4}>
                                        Total, pe toate paginile
                                    </TableCell>
                                    <TableCell className="text-right font-semibold tabular-nums">
                                        {formatMoney(totals.lei, 'RON')}
                                    </TableCell>
                                    <TableCell colSpan={2} />
                                </TableRow>
                            </TableFooter>
                        )}
                    </Table>
                </div>

                <div className="flex justify-center">
                    <Pagination links={rows.links} />
                </div>
            </div>
        </>
    );
}

DueReport.layout = (page: React.ReactNode) => (
    <AppLayout breadcrumbs={[{ title: 'Scadențar', href: dueIndex() }]}>
        {page}
    </AppLayout>
);
