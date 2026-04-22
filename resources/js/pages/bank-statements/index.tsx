import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import DatePicker from '@/components/date-picker';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { index as bankStatementsIndex, show as bankStatementsShow } from '@/routes/bank-statements';
import type { Paginated } from '@/types/pagination';

type Statement = {
    id: number;
    data_extras: string;
    banca: string | null;
    iban: string;
    operator: string | null;
    moneda: string | null;
    lines_count: number;
    unallocated_count: number;
    total_incoming: number;
    total_outgoing: number;
    total_unallocated: number;
    company: { id: number; name: string };
};

type Props = {
    statements: Paginated<Statement>;
    filters: {
        company_id: number | null;
        from: string | null;
        to: string | null;
        only_unallocated: boolean;
    };
    companies: { id: number; name: string }[];
};

function formatAmount(value: number, currency: string | null) {
    return `${new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)} ${currency ?? ''}`.trim();
}

export default function BankStatementsIndex({ statements, filters, companies }: Props) {
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');

    const applyFilter = (next: Partial<Props['filters']>) => {
        router.get(
            bankStatementsIndex().url,
            {
                company_id: next.company_id ?? filters.company_id ?? undefined,
                from: next.from ?? filters.from ?? undefined,
                to: next.to ?? filters.to ?? undefined,
                only_unallocated: (next.only_unallocated ?? filters.only_unallocated) ? 1 : undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <>
            <Head title="Extrase bancare" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">Extrase bancare</h1>
                    <p className="text-sm text-muted-foreground">
                        Extrase de cont importate din BD-urile companiilor. Evidențiem tranzacțiile care nu sunt alocate pe facturi.
                    </p>
                </div>

                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        applyFilter({ from, to });
                    }}
                >
                    <div className="grid gap-1">
                        <Label className="text-xs">Companie</Label>
                        <Select
                            value={filters.company_id ? String(filters.company_id) : 'all'}
                            onValueChange={(v) => applyFilter({ company_id: v === 'all' ? null : Number(v) })}
                        >
                            <SelectTrigger className="w-[200px]">
                                <SelectValue placeholder="Companie" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Toate companiile</SelectItem>
                                {companies.map((c) => (
                                    <SelectItem key={c.id} value={String(c.id)}>
                                        {c.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-1">
                        <Label className="text-xs">De la</Label>
                        <DatePicker
                            className="w-[180px]"
                            value={from}
                            onChange={setFrom}
                            placeholder="De la"
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label className="text-xs">Până la</Label>
                        <DatePicker
                            className="w-[180px]"
                            value={to}
                            onChange={setTo}
                            placeholder="Până la"
                        />
                    </div>
                    <Button type="submit" variant="secondary">
                        Aplică
                    </Button>
                    <Button
                        type="button"
                        variant={filters.only_unallocated ? 'default' : 'outline'}
                        onClick={() => applyFilter({ only_unallocated: !filters.only_unallocated })}
                    >
                        <AlertTriangle />
                        {filters.only_unallocated ? 'Doar cu nealocate' : 'Toate'}
                    </Button>
                </form>

                <div className="overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-28">Dată</TableHead>
                                <TableHead>Bancă</TableHead>
                                <TableHead>IBAN</TableHead>
                                <TableHead>Companie</TableHead>
                                <TableHead className="text-right">Linii</TableHead>
                                <TableHead className="text-right">Nealocate</TableHead>
                                <TableHead className="text-right">Încasări</TableHead>
                                <TableHead className="text-right">Plăți</TableHead>
                                <TableHead className="text-right">Sumă nealocată</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {statements.data.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={9} className="py-6 text-center text-muted-foreground">
                                        Niciun extras bancar. Pornește o sincronizare din Companii.
                                    </TableCell>
                                </TableRow>
                            )}
                            {statements.data.map((s) => (
                                <TableRow
                                    key={s.id}
                                    onClick={() => router.visit(bankStatementsShow(s.id).url)}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter' || e.key === ' ') {
                                            e.preventDefault();
                                            router.visit(bankStatementsShow(s.id).url);
                                        }
                                    }}
                                    tabIndex={0}
                                    role="link"
                                    className={`cursor-pointer hover:bg-muted/70 focus-visible:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring ${s.unallocated_count > 0 ? 'bg-amber-50/50 dark:bg-amber-500/5' : ''}`}
                                >
                                    <TableCell className="font-medium">
                                        <Link
                                            className="hover:underline"
                                            href={bankStatementsShow(s.id)}
                                            onClick={(e) => e.stopPropagation()}
                                        >
                                            {s.data_extras}
                                        </Link>
                                    </TableCell>
                                    <TableCell>{s.banca ?? '—'}</TableCell>
                                    <TableCell className="font-mono text-xs">{s.iban}</TableCell>
                                    <TableCell className="text-muted-foreground">{s.company.name}</TableCell>
                                    <TableCell className="text-right tabular-nums">{s.lines_count}</TableCell>
                                    <TableCell className="text-right">
                                        {s.unallocated_count > 0 ? (
                                            <Badge variant="outline" className="border-amber-600/40 bg-amber-100/50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                                                <AlertTriangle className="mr-1 size-3" />
                                                {s.unallocated_count}
                                            </Badge>
                                        ) : (
                                            <span className="text-xs text-muted-foreground">0</span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums text-green-700 dark:text-green-400">
                                        {formatAmount(s.total_incoming, s.moneda)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums text-red-700 dark:text-red-400">
                                        {formatAmount(s.total_outgoing, s.moneda)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums font-medium">
                                        {s.total_unallocated > 0.01
                                            ? formatAmount(s.total_unallocated, s.moneda)
                                            : '—'}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <div className="flex items-center justify-between">
                    <span className="text-xs text-muted-foreground">
                        {statements.from ?? 0}–{statements.to ?? 0} din {statements.total}
                    </span>
                    <Pagination links={statements.links} />
                </div>
            </div>
        </>
    );
}

BankStatementsIndex.layout = (page: React.ReactNode) => (
    <AppLayout breadcrumbs={[{ title: 'Extrase bancare', href: bankStatementsIndex() }]}>{page}</AppLayout>
);
