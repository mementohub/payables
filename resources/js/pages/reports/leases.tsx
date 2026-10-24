import { Head, router } from '@inertiajs/react';
import { Building2, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import AppLayout from '@/layouts/app-layout';
import { index as contractsIndex } from '@/routes/contracts';
import { index as leasesIndex } from '@/routes/reports/leases';

type Lease = {
    id: number;
    lessor: string;
    location: string | null;
    currency: string;
    monthly_payment: number;
    in_advance: boolean;
    starts_on: string | null;
    ends_on: string | null;
    months: number;
    discount_rate: number;
    exempt: boolean;
    status: 'draft' | 'active' | 'ended';
    source: string;
    note: string | null;
};

type Props = {
    companies: { id: number; name: string }[];
    filters: { company_id: number | null; year: number };
    leases: Lease[];
    summary: {
        rent: number;
        covered: number;
        depreciation: number;
        interest: number;
        liability: number;
        counted: number;
        drafts: number;
    };
};

const lei = (value: number) =>
    new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 0 }).format(value);

/**
 * Contractele de închiriere, cu ce-i trebuie standardului: chiria, durata și
 * rata. Chiria vine propusă din ce s-a plătit în contabilitate; durata și
 * rata se iau din contract, fiindcă ERP-ul nu le știe.
 */
export default function Leases({ companies, filters, leases, summary }: Props) {
    const [draft, setDraft] = useState<Record<number, Partial<Lease>>>({});

    const go = (next: Partial<Props['filters']>) =>
        router.get(
            leasesIndex().url,
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true },
        );

    const edit = (lease: Lease, patch: Partial<Lease>) =>
        setDraft((prev) => ({ ...prev, [lease.id]: { ...prev[lease.id], ...patch } }));

    const value = <K extends keyof Lease>(lease: Lease, key: K): Lease[K] =>
        (draft[lease.id]?.[key] ?? lease[key]) as Lease[K];

    const save = (lease: Lease, patch: Partial<Lease> = {}) => {
        const merged = { ...lease, ...draft[lease.id], ...patch };

        router.put(
            `/reports/leases/${lease.id}`,
            {
                monthly_payment: merged.monthly_payment,
                currency: merged.currency,
                starts_on: merged.starts_on,
                ends_on: merged.ends_on,
                discount_rate: merged.discount_rate,
                in_advance: merged.in_advance,
                exempt: merged.exempt,
                status: merged.status,
                note: merged.note,
            },
            {
                preserveScroll: true,
                onSuccess: () =>
                    setDraft((prev) => {
                        const next = { ...prev };
                        delete next[lease.id];

                        return next;
                    }),
            },
        );
    };

    const coverage =
        summary.rent !== 0
            ? Math.round((summary.covered / Math.abs(summary.rent)) * 100)
            : 0;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Contracte operaționale', href: contractsIndex().url },
                { title: 'Închirieri (IFRS 16)', href: leasesIndex().url },
            ]}
        >
            <Head title="Închirieri (IFRS 16)" />

            <div className="space-y-4 p-4">
                <div className="flex flex-wrap items-end gap-3">
                    <div className="grid gap-1.5">
                        <Label>Compania</Label>
                        <Select
                            value={String(filters.company_id ?? '')}
                            onValueChange={(v) => go({ company_id: Number(v) })}
                        >
                            <SelectTrigger className="w-64">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
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
                        <Label>Anul</Label>
                        <Select
                            value={String(filters.year)}
                            onValueChange={(v) => go({ year: Number(v) })}
                        >
                            <SelectTrigger className="w-28">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {[0, 1, 2].map((back) => (
                                    <SelectItem
                                        key={back}
                                        value={String(filters.year - back)}
                                    >
                                        {filters.year - back}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <Button
                        variant="outline"
                        onClick={() =>
                            router.post(
                                '/reports/leases/propose',
                                {
                                    company_id: filters.company_id,
                                    year: filters.year,
                                },
                                { preserveScroll: true },
                            )
                        }
                    >
                        <Plus className="size-4" />
                        Propune din chiria plătită
                    </Button>
                </div>

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    {[
                        ['Chirie în contabilitate', lei(summary.rent) + ' lei'],
                        [
                            'Acoperită de contracte',
                            `${lei(summary.covered)} lei · ${coverage}%`,
                        ],
                        ['Amortizare IFRS 16', lei(summary.depreciation) + ' lei'],
                        ['Dobândă IFRS 16', lei(summary.interest) + ' lei'],
                        [
                            'Datorie la sfârșit de an',
                            lei(summary.liability) + ' lei',
                        ],
                    ].map(([label, figure]) => (
                        <Card key={label}>
                            <CardContent className="p-4">
                                <p className="text-xs text-muted-foreground">
                                    {label}
                                </p>
                                <p className="text-lg font-semibold tabular-nums">
                                    {figure}
                                </p>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <p className="text-sm text-muted-foreground">
                    Raportul retratează numai chiria acoperită de contractele
                    confirmate; restul rămâne cheltuială de exploatare, ca în
                    contabilitate. {summary.drafts > 0 && (
                        <span className="text-amber-600 dark:text-amber-400">
                            {summary.drafts} contracte propuse așteaptă durata
                            și rata din contractul adevărat.
                        </span>
                    )}
                </p>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Building2 className="size-4" />
                            Registrul contractelor
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="overflow-x-auto p-0">
                        <Table className="text-sm">
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-64">
                                        Proprietar · spațiu
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Chirie / lună
                                    </TableHead>
                                    <TableHead>Din</TableHead>
                                    <TableHead>Până</TableHead>
                                    <TableHead className="text-right">
                                        Luni
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Rată
                                    </TableHead>
                                    <TableHead>Stare</TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {leases.map((lease) => (
                                    <TableRow key={lease.id}>
                                        <TableCell>
                                            <div className="font-medium">
                                                {lease.lessor}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {lease.location || '—'}
                                                {lease.source === 'omc' &&
                                                    ' · propus din contabilitate'}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Input
                                                className="h-8 w-28 text-right"
                                                value={String(
                                                    value(
                                                        lease,
                                                        'monthly_payment',
                                                    ),
                                                )}
                                                onChange={(e) =>
                                                    edit(lease, {
                                                        monthly_payment: Number(
                                                            e.target.value,
                                                        ),
                                                    })
                                                }
                                                onBlur={() => save(lease)}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <Input
                                                type="date"
                                                className="h-8 w-36"
                                                value={
                                                    value(lease, 'starts_on') ??
                                                    ''
                                                }
                                                onChange={(e) =>
                                                    edit(lease, {
                                                        starts_on:
                                                            e.target.value,
                                                    })
                                                }
                                                onBlur={() => save(lease)}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <Input
                                                type="date"
                                                className="h-8 w-36"
                                                value={
                                                    value(lease, 'ends_on') ??
                                                    ''
                                                }
                                                onChange={(e) =>
                                                    edit(lease, {
                                                        ends_on: e.target.value,
                                                    })
                                                }
                                                onBlur={() => save(lease)}
                                            />
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums text-muted-foreground">
                                            {lease.months}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Input
                                                className="h-8 w-20 text-right"
                                                value={String(
                                                    value(
                                                        lease,
                                                        'discount_rate',
                                                    ),
                                                )}
                                                onChange={(e) =>
                                                    edit(lease, {
                                                        discount_rate: Number(
                                                            e.target.value,
                                                        ),
                                                    })
                                                }
                                                onBlur={() => save(lease)}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <ToggleGroup
                                                type="single"
                                                size="sm"
                                                variant="outline"
                                                value={
                                                    lease.exempt
                                                        ? 'exempt'
                                                        : lease.status
                                                }
                                                onValueChange={(v) =>
                                                    v &&
                                                    save(lease, {
                                                        exempt: v === 'exempt',
                                                        status:
                                                            v === 'exempt'
                                                                ? lease.status
                                                                : (v as Lease['status']),
                                                    })
                                                }
                                            >
                                                <ToggleGroupItem value="draft">
                                                    Ciornă
                                                </ToggleGroupItem>
                                                <ToggleGroupItem value="active">
                                                    Confirmat
                                                </ToggleGroupItem>
                                                <ToggleGroupItem value="exempt">
                                                    Scutit
                                                </ToggleGroupItem>
                                            </ToggleGroup>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    router.delete(
                                                        `/reports/leases/${lease.id}`,
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {leases.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={8}
                                            className="py-10 text-center text-muted-foreground"
                                        >
                                            Niciun contract în registru. Apasă
                                            „Propune din chiria plătită”.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
