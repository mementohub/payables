import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import Pagination from '@/components/pagination';
import { Avatar, AvatarFallback, AvatarGroup, AvatarGroupCount } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { clienti as clientiRoute, furnizori as furnizoriRoute, show as partnerShow } from '@/routes/partners';
import type { Paginated } from '@/types/pagination';

type Responsible = {
    id: number;
    name: string;
    initials: string;
};

type Partner = {
    id: number;
    name: string;
    cui: string | null;
    reg_com: string | null;
    city: string | null;
    country: string | null;
    phone: string | null;
    email: string | null;
    is_furnizor: boolean;
    is_client: boolean;
    invoices_count: number;
    company: { id: number; name: string };
    responsibles: Responsible[];
};

type Props = {
    partners: Paginated<Partner>;
    scope: 'furnizori' | 'clienti';
    filters: { search: string | null; company_id: number | null };
    companies: { id: number; name: string }[];
};

function ResponsibleAvatars({ users }: { users: Responsible[] }) {
    if (users.length === 0) {
        return <span className="text-muted-foreground text-xs">—</span>;
    }

    const visible = users.slice(0, 3);
    const extra = users.length - visible.length;

    return (
        <AvatarGroup>
            {visible.map((user) => (
                <Tooltip key={user.id}>
                    <TooltipTrigger asChild>
                        <Avatar size="sm">
                            <AvatarFallback>{user.initials}</AvatarFallback>
                        </Avatar>
                    </TooltipTrigger>
                    <TooltipContent>{user.name}</TooltipContent>
                </Tooltip>
            ))}
            {extra > 0 && (
                <Tooltip>
                    <TooltipTrigger asChild>
                        <AvatarGroupCount>+{extra}</AvatarGroupCount>
                    </TooltipTrigger>
                    <TooltipContent>
                        {users
                            .slice(3)
                            .map((u) => u.name)
                            .join(', ')}
                    </TooltipContent>
                </Tooltip>
            )}
        </AvatarGroup>
    );
}

export default function PartnersIndex({ partners, scope, filters, companies }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const label = scope === 'furnizori' ? 'Furnizori' : 'Clienti';
    const href = scope === 'furnizori' ? furnizoriRoute() : clientiRoute();
    const baseUrl = href.url;

    const applyFilter = (next: Partial<Props['filters']>) => {
        router.get(
            baseUrl,
            {
                search: next.search ?? filters.search ?? undefined,
                company_id: next.company_id ?? filters.company_id ?? undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const columns = scope === 'furnizori' ? 7 : 6;

    return (
        <>
            <Head title={label} />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">{label}</h1>
                    <p className="text-sm text-muted-foreground">
                        Parteneri sincronizați din facturile din BD-urile companiilor ({scope === 'furnizori' ? 'FactFI / FactFE' : 'FactCI / FactCE / FactINT'}).
                    </p>
                </div>

                <form
                    className="flex flex-wrap items-center gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        applyFilter({ search });
                    }}
                >
                    <Input
                        className="max-w-xs"
                        placeholder="Caută nume sau CUI…"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
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
                    <Button type="submit" variant="secondary">
                        Caută
                    </Button>
                </form>

                <div className="overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nume</TableHead>
                                <TableHead>CUI</TableHead>
                                <TableHead>Locație</TableHead>
                                <TableHead>Contact</TableHead>
                                {scope === 'furnizori' && <TableHead>Responsabili</TableHead>}
                                <TableHead>Companie</TableHead>
                                <TableHead className="text-right">Facturi</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {partners.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={columns}
                                        className="py-6 text-center text-muted-foreground"
                                    >
                                        Niciun partener încă. Pornește o sincronizare din Companii.
                                    </TableCell>
                                </TableRow>
                            )}
                            {partners.data.map((partner) => (
                                <TableRow key={partner.id} className="align-top">
                                    <TableCell className="font-medium">
                                        {scope === 'furnizori' ? (
                                            <Link className="hover:underline" href={partnerShow(partner.id)}>
                                                {partner.name}
                                            </Link>
                                        ) : (
                                            partner.name
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <div>{partner.cui ?? '—'}</div>
                                        {partner.reg_com && (
                                            <div className="text-xs text-muted-foreground">J{partner.reg_com}</div>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {[partner.city, partner.country].filter(Boolean).join(', ') || '—'}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {partner.email && <div>{partner.email}</div>}
                                        {partner.phone && <div>{partner.phone}</div>}
                                        {!partner.email && !partner.phone && '—'}
                                    </TableCell>
                                    {scope === 'furnizori' && (
                                        <TableCell>
                                            <ResponsibleAvatars users={partner.responsibles} />
                                        </TableCell>
                                    )}
                                    <TableCell className="text-muted-foreground">{partner.company.name}</TableCell>
                                    <TableCell className="text-right tabular-nums">{partner.invoices_count}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <div className="flex items-center justify-between">
                    <span className="text-xs text-muted-foreground">
                        {partners.from ?? 0}–{partners.to ?? 0} din {partners.total}
                    </span>
                    <Pagination links={partners.links} />
                </div>
            </div>
        </>
    );
}

function PartnersLayout({ children }: { children: React.ReactNode }) {
    const { scope } = usePage<Props>().props;
    const label = scope === 'furnizori' ? 'Furnizori' : 'Clienti';
    const href = scope === 'furnizori' ? furnizoriRoute() : clientiRoute();
    return <AppLayout breadcrumbs={[{ title: label, href }]}>{children}</AppLayout>;
}

PartnersIndex.layout = (page: React.ReactNode) => <PartnersLayout>{page}</PartnersLayout>;
