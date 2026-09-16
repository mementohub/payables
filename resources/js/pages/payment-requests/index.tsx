import { Head, Link, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import {
    index as paymentRequestsIndex,
    show as paymentRequestShow,
} from '@/routes/payment-requests';
import type {
    IndexProps,
    PaymentRequestRow,
    RequestLevel,
    RequestStatus,
} from './types';

const LEVEL_PILL: Record<RequestLevel, string> = {
    ok: 'border-emerald-600 text-emerald-700 dark:text-emerald-400',
    warn: 'border-amber-500 text-amber-600 dark:text-amber-400',
    crit: 'border-destructive text-destructive',
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

function fmt(value: number, decimals = 0): string {
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

function describeKind(row: PaymentRequestRow): string {
    if (row.kind === 'checkin') {
        return row.checkin_from === row.checkin_to
            ? `check-in ${dmy(row.checkin_from)}`
            : `check-in ${dmy(row.checkin_from)} – ${dmy(row.checkin_to)}`;
    }

    return row.reference ? `factură ${row.reference}` : 'factură';
}

export default function PaymentRequestsIndex({
    requests,
    filters,
    statuses,
    counts,
}: IndexProps) {
    const [search, setSearch] = useState(filters.search ?? '');

    function apply(next: Partial<typeof filters>) {
        router.get(
            paymentRequestsIndex(),
            {
                status:
                    next.status === undefined ? filters.status : next.status,
                kind: next.kind === undefined ? filters.kind : next.kind,
                search:
                    next.search === undefined ? filters.search : next.search,
            },
            { preserveState: true, replace: true },
        );
    }

    function submitSearch(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        apply({ search: search.trim() || null });
    }

    const total = Object.values(counts).reduce((sum, count) => sum + count, 0);

    return (
        <>
            <Head title="Registru cereri de plată" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">
                        Registru cereri de plată
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Cererile verificate, cu cifrele găsite în eTrip sau în
                        ERP la momentul verificării și statusul curent.
                    </p>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        size="sm"
                        variant={
                            filters.status === null ? 'default' : 'outline'
                        }
                        onClick={() => apply({ status: null })}
                    >
                        Toate ({total})
                    </Button>
                    {(Object.keys(statuses) as RequestStatus[]).map(
                        (status) => (
                            <Button
                                key={status}
                                size="sm"
                                variant={
                                    filters.status === status
                                        ? 'default'
                                        : 'outline'
                                }
                                onClick={() => apply({ status })}
                            >
                                {statuses[status]} ({counts[status] ?? 0})
                            </Button>
                        ),
                    )}
                    <div className="ml-auto flex flex-wrap items-center gap-2">
                        <Select
                            value={filters.kind ?? 'all'}
                            onValueChange={(value) =>
                                apply({
                                    kind:
                                        value === 'all'
                                            ? null
                                            : (value as 'checkin' | 'invoice'),
                                })
                            }
                        >
                            <SelectTrigger
                                className="w-40"
                                aria-label="Tip cerere"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    Toate tipurile
                                </SelectItem>
                                <SelectItem value="checkin">
                                    Pe check-in
                                </SelectItem>
                                <SelectItem value="invoice">
                                    Pe factură
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <form onSubmit={submitSearch} className="flex gap-2">
                            <Input
                                placeholder="furnizor sau referință"
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                                className="w-56"
                            />
                            <Button
                                type="submit"
                                variant="outline"
                                size="icon"
                                aria-label="Caută"
                            >
                                <Search />
                            </Button>
                        </form>
                    </div>
                </div>

                <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left text-xs text-muted-foreground uppercase">
                            <tr>
                                <th className="px-4 py-3">Data</th>
                                <th className="px-4 py-3">Furnizor</th>
                                <th className="px-4 py-3">Cerere</th>
                                <th className="px-4 py-3 text-right">Cerut</th>
                                <th className="px-4 py-3 text-right">
                                    Calculat
                                </th>
                                <th className="px-4 py-3 text-right">
                                    Diferență
                                </th>
                                <th className="px-4 py-3">Status</th>
                                <th className="px-4 py-3 text-right">
                                    Facturi
                                </th>
                                <th className="px-4 py-3">De</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                            {requests.data.length === 0 && (
                                <tr>
                                    <td
                                        className="px-4 py-8 text-center text-muted-foreground"
                                        colSpan={9}
                                    >
                                        Nicio cerere salvată încă. Verifică o
                                        cerere pe check-in sau pe pagina
                                        furnizorului și salveaz-o în registru.
                                    </td>
                                </tr>
                            )}
                            {requests.data.map((row) => (
                                <tr key={row.id} className="hover:bg-muted/30">
                                    <td className="px-4 py-3 whitespace-nowrap text-muted-foreground">
                                        <Link
                                            href={paymentRequestShow(row.id)}
                                            className="hover:underline"
                                        >
                                            {dmy(row.created_at)}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3">
                                        <Link
                                            href={paymentRequestShow(row.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {row.supplier_name}
                                        </Link>
                                        {row.company && (
                                            <div className="text-xs text-muted-foreground">
                                                {row.company.name}
                                            </div>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 whitespace-nowrap text-muted-foreground">
                                        {describeKind(row)}
                                        {row.note && (
                                            <div
                                                className="max-w-xs truncate text-xs"
                                                title={row.note}
                                            >
                                                {row.note}
                                            </div>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-right whitespace-nowrap tabular-nums">
                                        {fmt(row.requested_amount, 2)}{' '}
                                        {row.requested_currency}
                                    </td>
                                    <td className="px-4 py-3 text-right whitespace-nowrap text-muted-foreground tabular-nums">
                                        {row.expected_amount !== null
                                            ? `${fmt(row.expected_amount, 2)} ${row.expected_currency ?? ''}`
                                            : '—'}
                                    </td>
                                    <td className="px-4 py-3 text-right whitespace-nowrap">
                                        {row.difference !== null &&
                                        row.level ? (
                                            <span
                                                className={`inline-block rounded-full border px-2 py-0.5 text-xs font-semibold ${LEVEL_PILL[row.level]}`}
                                            >
                                                {row.difference > 0 ? '+' : ''}
                                                {fmt(row.difference, 2)}
                                                {row.difference_pct !== null &&
                                                    ` (${row.difference_pct > 0 ? '+' : ''}${row.difference_pct}%)`}
                                            </span>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                —
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        <Badge
                                            variant={STATUS_VARIANT[row.status]}
                                        >
                                            {row.status_label}
                                        </Badge>
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {row.invoices_count || '—'}
                                    </td>
                                    <td className="px-4 py-3 text-muted-foreground">
                                        {row.created_by ?? '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {requests.total > requests.data.length && (
                    <div className="flex flex-wrap items-center justify-between gap-2 text-sm text-muted-foreground">
                        <span>
                            {requests.from}–{requests.to} din {requests.total}
                        </span>
                        <div className="flex gap-1">
                            {requests.links.map((link, index) => (
                                <Button
                                    key={index}
                                    size="sm"
                                    variant={
                                        link.active ? 'default' : 'outline'
                                    }
                                    disabled={!link.url}
                                    onClick={() =>
                                        link.url &&
                                        router.get(
                                            link.url,
                                            {},
                                            { preserveState: true },
                                        )
                                    }
                                    dangerouslySetInnerHTML={{
                                        __html: link.label,
                                    }}
                                />
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}

PaymentRequestsIndex.layout = (page: React.ReactNode) => (
    <AppLayout
        breadcrumbs={[
            { title: 'Registru cereri de plată', href: paymentRequestsIndex() },
        ]}
    >
        {page}
    </AppLayout>
);
