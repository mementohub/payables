import { Head, Link, router } from '@inertiajs/react';
import { Landmark, Search } from 'lucide-react';
import { useState } from 'react';
import DatePicker from '@/components/date-picker';
import Pagination from '@/components/pagination';
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
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/money';
import { cn } from '@/lib/utils';
import { show as invoiceShow } from '@/routes/invoices';
import {
    index as bankStatementsIndex,
    show as bankStatementsShow,
    transactions as bankTransactions,
} from '@/routes/bank-statements';
import type { Paginated } from '@/types/pagination';

type Filters = {
    partner: string | null;
    banca: string | null;
    direction: 'incoming' | 'outgoing' | null;
    from: string | null;
    to: string | null;
    company_id: number | null;
};

type Line = {
    id: number;
    data_doc: string | null;
    tip_doc: string | null;
    nr_doc: string | null;
    direction: 'incoming' | 'outgoing' | null;
    moneda: string | null;
    val_mon: number;
    unallocated: number;
    partner: string | null;
    counterparty: string | null;
    obs_txt: string | null;
    statement: {
        id: number;
        data_extras: string | null;
        banca: string | null;
        iban: string;
        company: string | null;
    } | null;
    invoices: {
        id: number | null;
        nr_doc: string | null;
        tip_doc: string | null;
        val_fin: number;
    }[];
};

/** Cât s-a plătit și cât s-a încasat, pe monedă. */
type Sums = {
    moneda: string | null;
    direction: 'incoming' | 'outgoing' | null;
    lines: number;
    total: number;
}[];

type Props = {
    lines: Paginated<Line>;
    totals: { lines: number; by_currency: Sums };
    by_bank: {
        banca: string | null;
        accounts: number;
        lines: number;
        totals: Sums;
    }[];
    filters: Filters;
    companies: { id: number; name: string }[];
    banks: string[];
};

/** Plătit și încasat, pe monede, într-un rând. */
function Sums({ totals, big = false }: { totals: Sums; big?: boolean }) {
    if (totals.length === 0) {
        return null;
    }

    return (
        <div className="mt-1 flex flex-wrap items-baseline gap-x-4 gap-y-0.5">
            {totals.map((row) => (
                <span
                    key={`${row.direction}-${row.moneda}`}
                    className={cn(
                        'inline-flex items-baseline gap-1 tabular-nums',
                        big ? 'text-base font-semibold' : 'text-sm font-medium',
                        row.direction === 'outgoing'
                            ? 'text-red-600 dark:text-red-400'
                            : 'text-green-700 dark:text-green-400',
                    )}
                >
                    {row.direction === 'outgoing' ? 'plătit' : 'încasat'}
                    <span>{amount(row.total, row.moneda)}</span>
                </span>
            ))}
        </div>
    );
}

function amount(value: number, currency: string | null) {
    return `${new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)} ${currency ?? ''}`.trim();
}

/**
 * Tranzacțiile din toate extrasele, căutate după partener.
 *
 * Un furnizor e plătit din mai multe conturi, în luni diferite: întrebarea
 * „ce i-am plătit și din ce bancă” nu încape într-un singur extras.
 */
export default function BankTransactions({
    lines,
    totals,
    by_bank: byBank,
    filters,
    companies,
    banks,
}: Props) {
    const [partner, setPartner] = useState(filters.partner ?? '');
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');
    const everything =
        !filters.partner &&
        !filters.banca &&
        !filters.direction &&
        !filters.company_id;

    const applyFilter = (next: Partial<Filters>) => {
        const merged = { ...filters, ...next };

        router.get(
            bankTransactions().url,
            {
                partner: merged.partner || undefined,
                banca: merged.banca ?? undefined,
                direction: merged.direction ?? undefined,
                from: merged.from ?? undefined,
                to: merged.to ?? undefined,
                company_id: merged.company_id ?? undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <>
            <Head title="Tranzacții bancare" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">
                            Tranzacții bancare
                        </h1>
                        <p className="max-w-3xl text-sm text-muted-foreground">
                            Toate operațiunile din extrase, căutate după
                            partener: ce i-am plătit, ce am încasat de la el și
                            din ce cont.
                            {everything && (
                                <>
                                    {' '}
                                    Fără o căutare se arată ultimele trei luni.
                                </>
                            )}
                        </p>
                    </div>
                    <Button asChild variant="outline">
                        <Link href={bankStatementsIndex()}>
                            Lista extraselor
                        </Link>
                    </Button>
                </div>

                <form
                    aria-label="Filtre tranzacții"
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        applyFilter({ partner: partner.trim(), from, to });
                    }}
                >
                    <div className="grid gap-1">
                        <Label className="text-xs" htmlFor="tx-partner">
                            Partener
                        </Label>
                        <div className="flex gap-2">
                            <Input
                                id="tx-partner"
                                className="min-h-11 w-[260px]"
                                placeholder="Furnizor, client sau CUI..."
                                value={partner}
                                onChange={(e) => setPartner(e.target.value)}
                            />
                            <Button
                                type="submit"
                                variant="secondary"
                                className="min-h-11"
                            >
                                <Search />
                            </Button>
                        </div>
                    </div>
                    <div className="grid gap-1">
                        <Label className="text-xs">De la</Label>
                        <DatePicker
                            className="min-h-11 w-[170px]"
                            value={from}
                            onChange={setFrom}
                            placeholder="yyyy-mm-dd"
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label className="text-xs">Până la</Label>
                        <DatePicker
                            className="min-h-11 w-[170px]"
                            value={to}
                            onChange={setTo}
                            placeholder="yyyy-mm-dd"
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label className="text-xs">Sens</Label>
                        <Select
                            value={filters.direction ?? 'all'}
                            onValueChange={(v) =>
                                applyFilter({
                                    direction:
                                        v === 'all'
                                            ? null
                                            : (v as 'incoming' | 'outgoing'),
                                })
                            }
                        >
                            <SelectTrigger className="min-h-11 w-[170px]">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Toate</SelectItem>
                                <SelectItem value="outgoing">Plăți</SelectItem>
                                <SelectItem value="incoming">
                                    Încasări
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-1">
                        <Label className="text-xs">Companie</Label>
                        <Select
                            value={
                                filters.company_id
                                    ? String(filters.company_id)
                                    : 'all'
                            }
                            onValueChange={(v) =>
                                applyFilter({
                                    company_id: v === 'all' ? null : Number(v),
                                    banca: null,
                                })
                            }
                        >
                            <SelectTrigger className="min-h-11 w-[190px]">
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
                    <div className="grid gap-1">
                        <Label className="text-xs">Bancă</Label>
                        <Select
                            value={filters.banca ?? 'all'}
                            onValueChange={(v) =>
                                applyFilter({ banca: v === 'all' ? null : v })
                            }
                        >
                            <SelectTrigger className="min-h-11 w-[240px]">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    Toate băncile
                                </SelectItem>
                                {banks.map((bank) => (
                                    <SelectItem key={bank} value={bank}>
                                        {bank}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </form>

                {totals.lines > 0 && (
                    <div className="space-y-3">
                        {/* Totalul a tot ce s-a filtrat. */}
                        <div className="rounded-xl border border-sidebar-border/70 bg-muted/30 p-3 dark:border-sidebar-border">
                            <div className="flex flex-wrap items-baseline gap-x-6 gap-y-1">
                                <span className="text-xs text-muted-foreground uppercase">
                                    Total · {totals.lines}{' '}
                                    {totals.lines === 1
                                        ? 'tranzacție'
                                        : 'tranzacții'}
                                </span>
                                <Sums totals={totals.by_currency} big />
                            </div>
                        </div>

                        {/* O bancă, un card. Conturile ei se desfac la click. */}
                        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            {byBank.map((bank) => (
                                <button
                                    type="button"
                                    key={bank.banca ?? 'fara-banca'}
                                    onClick={() =>
                                        applyFilter({
                                            banca:
                                                filters.banca === bank.banca
                                                    ? null
                                                    : bank.banca,
                                        })
                                    }
                                    className={cn(
                                        'rounded-xl border p-3 text-left transition hover:border-primary/50',
                                        filters.banca === bank.banca
                                            ? 'border-primary bg-primary/5'
                                            : 'border-sidebar-border/70 dark:border-sidebar-border',
                                    )}
                                >
                                    <div className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
                                        <span className="flex items-center gap-1.5 font-medium text-foreground">
                                            <Landmark className="size-3.5" />
                                            {bank.banca ?? 'Fără bancă'}
                                        </span>
                                        <span>
                                            {bank.accounts}{' '}
                                            {bank.accounts === 1
                                                ? 'cont'
                                                : 'conturi'}{' '}
                                            · {bank.lines} tranz.
                                        </span>
                                    </div>
                                    <Sums totals={bank.totals} />
                                </button>
                            ))}
                        </div>

                    </div>
                )}

                <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <Table>
                        <TableHeader className="bg-muted/50">
                            <TableRow>
                                <TableHead>Data</TableHead>
                                <TableHead>Bancă / cont</TableHead>
                                <TableHead>Partener</TableHead>
                                <TableHead>Document</TableHead>
                                <TableHead className="text-right">
                                    Sumă
                                </TableHead>
                                <TableHead>Alocat pe</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {lines.data.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={6}
                                        className="py-12 text-center text-muted-foreground"
                                    >
                                        {filters.partner
                                            ? 'Nicio tranzacție cu partenerul căutat.'
                                            : 'Caută un partener ca să vezi ce s-a plătit și din ce cont.'}
                                    </TableCell>
                                </TableRow>
                            ) : (
                                lines.data.map((line) => (
                                    <TableRow key={line.id}>
                                        <TableCell className="whitespace-nowrap tabular-nums">
                                            {formatDate(line.data_doc)}
                                        </TableCell>
                                        <TableCell className="text-xs">
                                            {line.statement && (
                                                <Link
                                                    href={bankStatementsShow(
                                                        line.statement.id,
                                                    )}
                                                    className="hover:underline"
                                                >
                                                    <span className="font-medium">
                                                        {line.statement.banca ??
                                                            '—'}
                                                    </span>
                                                    <div className="font-mono text-muted-foreground">
                                                        {line.statement.iban}
                                                    </div>
                                                </Link>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <div className="font-medium">
                                                {line.partner ?? '—'}
                                            </div>
                                            {line.counterparty && (
                                                <div className="text-xs text-muted-foreground">
                                                    {line.counterparty}
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-xs">
                                            {line.tip_doc} {line.nr_doc}
                                            {line.obs_txt && (
                                                <div
                                                    className="max-w-[280px] truncate text-muted-foreground"
                                                    title={line.obs_txt}
                                                >
                                                    {line.obs_txt}
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right whitespace-nowrap tabular-nums">
                                            <span
                                                className={
                                                    line.direction ===
                                                    'outgoing'
                                                        ? 'font-medium text-red-600 dark:text-red-400'
                                                        : 'font-medium text-green-700 dark:text-green-400'
                                                }
                                            >
                                                {line.direction === 'outgoing'
                                                    ? '−'
                                                    : '+'}
                                                {amount(
                                                    line.val_mon,
                                                    line.moneda,
                                                )}
                                            </span>
                                            {line.unallocated > 0.01 && (
                                                <div className="text-xs text-amber-700 dark:text-amber-300">
                                                    nealocat{' '}
                                                    {amount(
                                                        line.unallocated,
                                                        line.moneda,
                                                    )}
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-xs">
                                            {line.invoices.length === 0 ? (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            ) : (
                                                <div className="flex flex-col gap-0.5">
                                                    {line.invoices.map(
                                                        (invoice, index) =>
                                                            invoice.id ? (
                                                                <Link
                                                                    key={`${invoice.id}-${index}`}
                                                                    href={invoiceShow(
                                                                        invoice.id,
                                                                    )}
                                                                    className="hover:underline"
                                                                >
                                                                    {
                                                                        invoice.nr_doc
                                                                    }
                                                                </Link>
                                                            ) : (
                                                                <span
                                                                    key={`${invoice.nr_doc}-${index}`}
                                                                >
                                                                    {
                                                                        invoice.nr_doc
                                                                    }
                                                                </span>
                                                            ),
                                                    )}
                                                </div>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </div>

                <div className="flex justify-center">
                    <Pagination links={lines.links} />
                </div>
            </div>
        </>
    );
}

BankTransactions.layout = (page: React.ReactNode) => (
    <AppLayout
        breadcrumbs={[
            { title: 'Extrase bancare', href: bankStatementsIndex() },
            { title: 'Tranzacții', href: bankTransactions() },
        ]}
    >
        {page}
    </AppLayout>
);
