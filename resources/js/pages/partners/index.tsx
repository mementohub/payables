import { Head, Link, router, usePage } from '@inertiajs/react';
import { Check, ChevronsUpDown, X } from 'lucide-react';
import { useState } from 'react';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
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
import { cn } from '@/lib/utils';
import AppLayout from '@/layouts/app-layout';
import {
    clienti as clientiRoute,
    furnizori as furnizoriRoute,
    show as partnerShow,
} from '@/routes/partners';
import type {
    IndexProps as Props,
    ResponsabilDepartmentRef as ResponsabilDepartment,
} from './types';

function DepartmentChips({
    departments,
}: {
    departments: ResponsabilDepartment[];
}) {
    if (departments.length === 0) {
        return <span className="text-xs text-muted-foreground">—</span>;
    }

    return (
        <div className="flex flex-wrap gap-1">
            {departments.map((dept) => (
                <Badge key={dept.id} variant="outline" className="text-[10px]">
                    {dept.name}
                </Badge>
            ))}
        </div>
    );
}

function DepartmentMultiSelect({
    options,
    selected,
    onChange,
}: {
    options: { id: number; name: string }[];
    selected: number[];
    onChange: (next: number[]) => void;
}) {
    const [open, setOpen] = useState(false);

    const toggle = (id: number) => {
        onChange(
            selected.includes(id)
                ? selected.filter((s) => s !== id)
                : [...selected, id],
        );
    };

    const label =
        selected.length === 0
            ? 'Toate departamentele'
            : selected.length === 1
              ? (options.find((o) => o.id === selected[0])?.name ?? '1 selectat')
              : `${selected.length} selectate`;

    return (
        <div className="flex items-center gap-1">
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                    <Button
                        variant="outline"
                        role="combobox"
                        aria-expanded={open}
                        className="w-[240px] justify-between font-normal"
                    >
                        <span className="truncate">{label}</span>
                        <ChevronsUpDown className="ml-2 size-4 shrink-0 opacity-50" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent className="w-[260px] p-0" align="start">
                    <Command>
                        <CommandInput placeholder="Caută departament…" />
                        <CommandList>
                            <CommandEmpty>Niciun departament.</CommandEmpty>
                            <CommandGroup>
                                {options.map((dept) => {
                                    const isSelected = selected.includes(
                                        dept.id,
                                    );
                                    return (
                                        <CommandItem
                                            key={dept.id}
                                            value={dept.name}
                                            onSelect={() => toggle(dept.id)}
                                        >
                                            <Check
                                                className={cn(
                                                    'mr-2 size-4',
                                                    isSelected
                                                        ? 'opacity-100'
                                                        : 'opacity-0',
                                                )}
                                            />
                                            {dept.name}
                                        </CommandItem>
                                    );
                                })}
                            </CommandGroup>
                        </CommandList>
                    </Command>
                </PopoverContent>
            </Popover>
            {selected.length > 0 && (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    aria-label="Șterge filtrul"
                    onClick={() => onChange([])}
                >
                    <X className="size-4" />
                </Button>
            )}
        </div>
    );
}

export default function PartnersIndex({
    partners,
    scope,
    filters,
    companies,
    availableDepartments,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const label = scope === 'furnizori' ? 'Furnizori' : 'Clienti';
    const href = scope === 'furnizori' ? furnizoriRoute() : clientiRoute();
    const baseUrl = href.url;

    const applyFilter = (next: Partial<Props['filters']>) => {
        const departmentIds =
            next.department_ids ?? filters.department_ids ?? [];

        router.get(
            baseUrl,
            {
                search: next.search ?? filters.search ?? undefined,
                company_id: next.company_id ?? filters.company_id ?? undefined,
                department_ids:
                    departmentIds.length > 0 ? departmentIds : undefined,
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
                        Parteneri sincronizați din facturile din BD-urile
                        companiilor (
                        {scope === 'furnizori'
                            ? 'FactFI / FactFE'
                            : 'FactCI / FactCE / FactINT'}
                        ).
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
                        value={
                            filters.company_id
                                ? String(filters.company_id)
                                : 'all'
                        }
                        onValueChange={(v) =>
                            applyFilter({
                                company_id: v === 'all' ? null : Number(v),
                            })
                        }
                    >
                        <SelectTrigger className="w-[200px]">
                            <SelectValue placeholder="Companie" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">
                                Toate companiile
                            </SelectItem>
                            {companies.map((c) => (
                                <SelectItem key={c.id} value={String(c.id)}>
                                    {c.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {scope === 'furnizori' &&
                        availableDepartments.length > 0 && (
                            <DepartmentMultiSelect
                                options={availableDepartments}
                                selected={filters.department_ids}
                                onChange={(ids) =>
                                    applyFilter({ department_ids: ids })
                                }
                            />
                        )}
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
                                {scope === 'furnizori' && (
                                    <TableHead>Responsabili</TableHead>
                                )}
                                <TableHead>Companie</TableHead>
                                <TableHead className="text-right">
                                    Facturi
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {partners.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={columns}
                                        className="py-6 text-center text-muted-foreground"
                                    >
                                        Niciun partener încă. Pornește o
                                        sincronizare din Companii.
                                    </TableCell>
                                </TableRow>
                            )}
                            {partners.data.map((partner) => (
                                <TableRow
                                    key={partner.id}
                                    className="align-top"
                                >
                                    <TableCell className="font-medium">
                                        {scope === 'furnizori' ? (
                                            <Link
                                                className="hover:underline"
                                                href={partnerShow(partner.id)}
                                            >
                                                {partner.name}
                                            </Link>
                                        ) : (
                                            partner.name
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <div>{partner.cui ?? '—'}</div>
                                        {partner.reg_com && (
                                            <div className="text-xs text-muted-foreground">
                                                J{partner.reg_com}
                                            </div>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {[partner.city, partner.country]
                                            .filter(Boolean)
                                            .join(', ') || '—'}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {partner.email && (
                                            <div>{partner.email}</div>
                                        )}
                                        {partner.phone && (
                                            <div>{partner.phone}</div>
                                        )}
                                        {!partner.email &&
                                            !partner.phone &&
                                            '—'}
                                    </TableCell>
                                    {scope === 'furnizori' && (
                                        <TableCell>
                                            <DepartmentChips
                                                departments={
                                                    partner.responsabil_departments
                                                }
                                            />
                                        </TableCell>
                                    )}
                                    <TableCell className="text-muted-foreground">
                                        {partner.company.name}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {partner.invoices_count}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <div className="flex items-center justify-between">
                    <span className="text-xs text-muted-foreground">
                        {partners.from ?? 0}–{partners.to ?? 0} din{' '}
                        {partners.total}
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

    return (
        <AppLayout breadcrumbs={[{ title: label, href }]}>{children}</AppLayout>
    );
}

PartnersIndex.layout = (page: React.ReactNode) => (
    <PartnersLayout>{page}</PartnersLayout>
);
