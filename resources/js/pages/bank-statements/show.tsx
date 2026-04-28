import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowDownCircle,
    ArrowLeft,
    ArrowUpCircle,
    ChevronDown,
    ChevronRight,
} from 'lucide-react';
import { Fragment, useState } from 'react';
import PaymentStatusBadge from '@/components/payment-status-badge';
import type { PaymentStatus } from '@/components/payment-status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import {
    index as bankStatementsIndex,
    show as bankStatementsShow,
} from '@/routes/bank-statements';
import { show as invoiceShow } from '@/routes/invoices';

type InvoiceRef = {
    id: number;
    nr_doc: string;
    tip_doc: string;
    data_doc: string;
    moneda: string | null;
    val_mon: number;
    val_mon_tva: number;
    val_mon_paid: number;
    partner: { id: number; name: string } | null;
};

type Allocation = {
    id: number;
    data_doc_com: string;
    tip_doc_com: string;
    nr_doc_com: string;
    val_fin: number;
    val_com: number;
    invoice: InvoiceRef | null;
};

type Line = {
    id: number;
    data_doc: string;
    tip_doc: string;
    nr_doc: string;
    direction: 'incoming' | 'outgoing';
    partener_name: string | null;
    partner: { id: number; name: string } | null;
    emitent: string | null;
    cine_preda: string | null;
    cine_primeste: string | null;
    obs_txt: string | null;
    moneda: string | null;
    val_mon: number;
    val_allocated: number;
    unallocated: number;
    is_unallocated: boolean;
    allocations: Allocation[];
};

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

type Filters = { only_unallocated: boolean; direction: string | null };

type Props = { statement: Statement; lines: Line[]; filters: Filters };

function formatAmount(value: number, currency: string | null) {
    return `${new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)} ${currency ?? ''}`.trim();
}

function invoicePaymentStatus(inv: InvoiceRef): PaymentStatus {
    const total = inv.val_mon + inv.val_mon_tva;
    const paid = inv.val_mon_paid;

    if (paid <= 0.009) {
        return 'unpaid';
    }

    if (paid + 0.01 >= total) {
        return 'paid';
    }

    return 'partial';
}

export default function BankStatementShow({
    statement,
    lines,
    filters,
}: Props) {
    const [expandedLines, setExpandedLines] = useState<Set<number>>(
        () => new Set(),
    );

    const toggleLine = (id: number) => {
        setExpandedLines((prev) => {
            const next = new Set(prev);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });
    };

    const applyFilter = (next: Partial<Filters>) => {
        router.get(
            bankStatementsShow(statement.id).url,
            {
                only_unallocated:
                    (next.only_unallocated ?? filters.only_unallocated)
                        ? 1
                        : undefined,
                direction: next.direction ?? filters.direction ?? undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <>
            <Head title={`Extras ${statement.data_extras}`} />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div>
                    <Button asChild variant="ghost" size="sm">
                        <Link href={bankStatementsIndex()}>
                            <ArrowLeft />
                            Înapoi la extrase
                        </Link>
                    </Button>
                </div>

                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2">
                            <h1 className="text-2xl font-semibold">
                                Extras {statement.data_extras}
                            </h1>
                            {statement.moneda && (
                                <Badge variant="secondary">
                                    {statement.moneda}
                                </Badge>
                            )}
                            {statement.unallocated_count > 0 && (
                                <Badge
                                    variant="outline"
                                    className="border-amber-600/40 bg-amber-100/50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300"
                                >
                                    <AlertTriangle className="mr-1 size-3" />
                                    {statement.unallocated_count} tranzacții
                                    nealocate
                                </Badge>
                            )}
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {statement.banca ?? 'Bancă necunoscută'} ·{' '}
                            <span className="font-mono">{statement.iban}</span>{' '}
                            · {statement.company.name}
                            {statement.operator
                                ? ` · ${statement.operator}`
                                : ''}
                        </p>
                    </div>
                </div>

                <div className="grid gap-4 md:grid-cols-3">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-xs font-semibold text-muted-foreground uppercase">
                                Încasări
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-xl font-semibold text-green-700 tabular-nums dark:text-green-400">
                                {formatAmount(
                                    statement.total_incoming,
                                    statement.moneda,
                                )}
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-xs font-semibold text-muted-foreground uppercase">
                                Plăți
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-xl font-semibold text-red-700 tabular-nums dark:text-red-400">
                                {formatAmount(
                                    statement.total_outgoing,
                                    statement.moneda,
                                )}
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-xs font-semibold text-muted-foreground uppercase">
                                Nealocat
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div
                                className={`text-xl font-semibold tabular-nums ${statement.total_unallocated > 0.01 ? 'text-amber-700 dark:text-amber-400' : ''}`}
                            >
                                {statement.total_unallocated > 0.01
                                    ? formatAmount(
                                          statement.total_unallocated,
                                          statement.moneda,
                                      )
                                    : '—'}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <Select
                        value={filters.direction ?? 'all'}
                        onValueChange={(v) =>
                            applyFilter({ direction: v === 'all' ? null : v })
                        }
                    >
                        <SelectTrigger className="w-[160px]">
                            <SelectValue placeholder="Sens" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Toate sensurile</SelectItem>
                            <SelectItem value="incoming">
                                Doar încasări
                            </SelectItem>
                            <SelectItem value="outgoing">Doar plăți</SelectItem>
                        </SelectContent>
                    </Select>
                    <Button
                        type="button"
                        variant={
                            filters.only_unallocated ? 'default' : 'outline'
                        }
                        onClick={() =>
                            applyFilter({
                                only_unallocated: !filters.only_unallocated,
                            })
                        }
                    >
                        <AlertTriangle />
                        {filters.only_unallocated
                            ? 'Doar nealocate'
                            : 'Toate tranzacțiile'}
                    </Button>
                </div>

                <div className="overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-10"></TableHead>
                                <TableHead className="w-10"></TableHead>
                                <TableHead className="w-28">Dată</TableHead>
                                <TableHead className="w-28">Tip</TableHead>
                                <TableHead>Număr</TableHead>
                                <TableHead>Partener</TableHead>
                                <TableHead className="w-24 text-right">
                                    Alocări
                                </TableHead>
                                <TableHead className="w-36 text-right">
                                    Sumă
                                </TableHead>
                                <TableHead className="w-36 text-right">
                                    Alocat
                                </TableHead>
                                <TableHead className="w-36 text-right">
                                    Nealocat
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {lines.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={10}
                                        className="py-6 text-center text-muted-foreground"
                                    >
                                        Nicio tranzacție pe filtrele curente.
                                    </TableCell>
                                </TableRow>
                            )}
                            {lines.map((line) => {
                                const isExpanded = expandedLines.has(line.id);
                                const hasAllocations =
                                    line.allocations.length > 0;
                                const rowClass = line.is_unallocated
                                    ? 'bg-amber-50/60 hover:bg-amber-50 dark:bg-amber-500/10 dark:hover:bg-amber-500/15'
                                    : '';

                                return (
                                    <Fragment key={line.id}>
                                        <TableRow className={rowClass}>
                                            <TableCell className="p-0 text-center">
                                                {hasAllocations && (
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            toggleLine(line.id)
                                                        }
                                                        className="inline-flex size-6 items-center justify-center rounded hover:bg-muted"
                                                        aria-label={
                                                            isExpanded
                                                                ? 'Ascunde alocările'
                                                                : 'Arată alocările'
                                                        }
                                                    >
                                                        {isExpanded ? (
                                                            <ChevronDown className="size-4" />
                                                        ) : (
                                                            <ChevronRight className="size-4" />
                                                        )}
                                                    </button>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {line.direction ===
                                                'incoming' ? (
                                                    <ArrowDownCircle className="size-4 text-green-600 dark:text-green-400" />
                                                ) : (
                                                    <ArrowUpCircle className="size-4 text-red-600 dark:text-red-400" />
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {line.data_doc}
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant="secondary">
                                                    {line.tip_doc}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="font-medium">
                                                <div>{line.nr_doc}</div>
                                                {line.obs_txt && (
                                                    <Tooltip>
                                                        <TooltipTrigger asChild>
                                                            <div className="mt-0.5 max-w-[260px] truncate text-xs font-normal text-muted-foreground">
                                                                {line.obs_txt}
                                                            </div>
                                                        </TooltipTrigger>
                                                        <TooltipContent className="max-w-md break-words whitespace-pre-wrap">
                                                            {line.obs_txt}
                                                        </TooltipContent>
                                                    </Tooltip>
                                                )}
                                                {(line.emitent ||
                                                    line.cine_preda ||
                                                    line.cine_primeste) && (
                                                    <div className="mt-0.5 text-[10px] font-normal tracking-wide text-muted-foreground/70 uppercase">
                                                        {line.emitent && (
                                                            <span>
                                                                op:{' '}
                                                                {line.emitent}
                                                            </span>
                                                        )}
                                                        {line.cine_preda && (
                                                            <span className="ml-2">
                                                                predă:{' '}
                                                                {
                                                                    line.cine_preda
                                                                }
                                                            </span>
                                                        )}
                                                        {line.cine_primeste && (
                                                            <span className="ml-2">
                                                                primește:{' '}
                                                                {
                                                                    line.cine_primeste
                                                                }
                                                            </span>
                                                        )}
                                                    </div>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {line.partener_name ?? '—'}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {hasAllocations ? (
                                                    <Badge variant="outline">
                                                        {
                                                            line.allocations
                                                                .length
                                                        }
                                                    </Badge>
                                                ) : line.is_unallocated ? (
                                                    <Badge
                                                        variant="outline"
                                                        className="border-amber-600/40 bg-amber-100/50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300"
                                                    >
                                                        Nealocat
                                                    </Badge>
                                                ) : (
                                                    <span className="text-xs text-muted-foreground">
                                                        —
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right font-medium tabular-nums">
                                                {formatAmount(
                                                    line.val_mon,
                                                    line.moneda,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right text-muted-foreground tabular-nums">
                                                {formatAmount(
                                                    line.val_allocated,
                                                    line.moneda,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {line.unallocated > 0.01 ? (
                                                    <span className="font-semibold text-amber-700 dark:text-amber-400">
                                                        {formatAmount(
                                                            line.unallocated,
                                                            line.moneda,
                                                        )}
                                                    </span>
                                                ) : (
                                                    '—'
                                                )}
                                            </TableCell>
                                        </TableRow>
                                        {isExpanded && hasAllocations && (
                                            <TableRow className={rowClass}>
                                                <TableCell
                                                    colSpan={10}
                                                    className="bg-muted/30 p-0"
                                                >
                                                    <div className="p-3">
                                                        <div className="mb-2 text-xs font-semibold text-muted-foreground uppercase">
                                                            Facturi pe care s-a
                                                            alocat (
                                                            {
                                                                line.allocations
                                                                    .length
                                                            }
                                                            )
                                                        </div>
                                                        <Table>
                                                            <TableHeader>
                                                                <TableRow>
                                                                    <TableHead className="w-28">
                                                                        Dată
                                                                        factură
                                                                    </TableHead>
                                                                    <TableHead className="w-24">
                                                                        Tip
                                                                    </TableHead>
                                                                    <TableHead>
                                                                        Număr
                                                                        factură
                                                                    </TableHead>
                                                                    <TableHead>
                                                                        Partener
                                                                    </TableHead>
                                                                    <TableHead className="w-32">
                                                                        Stare
                                                                    </TableHead>
                                                                    <TableHead className="w-36 text-right">
                                                                        Alocat
                                                                        (monedă
                                                                        fact.)
                                                                    </TableHead>
                                                                    <TableHead className="w-36 text-right">
                                                                        Alocat
                                                                        (monedă
                                                                        plată)
                                                                    </TableHead>
                                                                    <TableHead className="w-36 text-right">
                                                                        Total
                                                                        factură
                                                                    </TableHead>
                                                                </TableRow>
                                                            </TableHeader>
                                                            <TableBody>
                                                                {line.allocations.map(
                                                                    (alloc) => {
                                                                        const inv =
                                                                            alloc.invoice;

                                                                        return (
                                                                            <TableRow
                                                                                key={
                                                                                    alloc.id
                                                                                }
                                                                            >
                                                                                <TableCell>
                                                                                    {
                                                                                        alloc.data_doc_com
                                                                                    }
                                                                                </TableCell>
                                                                                <TableCell>
                                                                                    <Badge variant="secondary">
                                                                                        {
                                                                                            alloc.tip_doc_com
                                                                                        }
                                                                                    </Badge>
                                                                                </TableCell>
                                                                                <TableCell className="font-medium">
                                                                                    {inv ? (
                                                                                        <Link
                                                                                            href={invoiceShow(
                                                                                                inv.id,
                                                                                            )}
                                                                                            className="hover:underline"
                                                                                        >
                                                                                            {
                                                                                                alloc.nr_doc_com
                                                                                            }
                                                                                        </Link>
                                                                                    ) : (
                                                                                        <span title="Factura nu este sincronizată local">
                                                                                            {
                                                                                                alloc.nr_doc_com
                                                                                            }
                                                                                            <span className="ml-1 text-xs text-muted-foreground">
                                                                                                (nesincronizată)
                                                                                            </span>
                                                                                        </span>
                                                                                    )}
                                                                                </TableCell>
                                                                                <TableCell className="text-muted-foreground">
                                                                                    {inv
                                                                                        ?.partner
                                                                                        ?.name ??
                                                                                        '—'}
                                                                                </TableCell>
                                                                                <TableCell>
                                                                                    {inv ? (
                                                                                        <PaymentStatusBadge
                                                                                            status={invoicePaymentStatus(
                                                                                                inv,
                                                                                            )}
                                                                                        />
                                                                                    ) : (
                                                                                        '—'
                                                                                    )}
                                                                                </TableCell>
                                                                                <TableCell className="text-right tabular-nums">
                                                                                    {formatAmount(
                                                                                        alloc.val_com,
                                                                                        inv?.moneda ??
                                                                                            null,
                                                                                    )}
                                                                                </TableCell>
                                                                                <TableCell className="text-right tabular-nums">
                                                                                    {formatAmount(
                                                                                        alloc.val_fin,
                                                                                        line.moneda,
                                                                                    )}
                                                                                </TableCell>
                                                                                <TableCell className="text-right text-muted-foreground tabular-nums">
                                                                                    {inv
                                                                                        ? formatAmount(
                                                                                              inv.val_mon +
                                                                                                  inv.val_mon_tva,
                                                                                              inv.moneda,
                                                                                          )
                                                                                        : '—'}
                                                                                </TableCell>
                                                                            </TableRow>
                                                                        );
                                                                    },
                                                                )}
                                                            </TableBody>
                                                        </Table>
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        )}
                                    </Fragment>
                                );
                            })}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </>
    );
}

function BankStatementShowLayout({ children }: { children: React.ReactNode }) {
    const { statement } = usePage<Props>().props;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Extrase bancare', href: bankStatementsIndex() },
                {
                    title: statement.data_extras,
                    href: bankStatementsShow(statement.id),
                },
            ]}
        >
            {children}
        </AppLayout>
    );
}

BankStatementShow.layout = (page: React.ReactNode) => (
    <BankStatementShowLayout>{page}</BankStatementShowLayout>
);
