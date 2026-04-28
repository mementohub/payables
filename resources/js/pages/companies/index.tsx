import { Form, Head, Link } from '@inertiajs/react';
import { Pencil, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { useState } from 'react';
import CompanyController from '@/actions/App/Http/Controllers/CompanyController';
import SyncController from '@/actions/App/Http/Controllers/SyncController';
import DatePicker from '@/components/date-picker';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import {
    create as companiesCreate,
    edit as companiesEdit,
    index as companiesIndex,
} from '@/routes/companies';
import type { CompanyListItem as Company } from './types';

function todayISO() {
    return new Date().toISOString().slice(0, 10);
}

function monthAgoISO() {
    const d = new Date();
    d.setMonth(d.getMonth() - 1);

    return d.toISOString().slice(0, 10);
}

function SyncDialog({ company }: { company: Company }) {
    const [open, setOpen] = useState(false);
    const [from, setFrom] = useState(monthAgoISO());
    const [to, setTo] = useState(todayISO());

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="secondary">
                    <RefreshCw />
                    Sincronizează
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Sincronizează {company.name}</DialogTitle>
                    <DialogDescription>
                        Alege intervalul de date (după data documentelor) pentru
                        sincronizare. Va rula în fundal prin Horizon.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...SyncController.store.form(company.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor={`sync-from-${company.id}`}>
                                        De la
                                    </Label>
                                    <DatePicker
                                        id={`sync-from-${company.id}`}
                                        name="from"
                                        value={from}
                                        onChange={setFrom}
                                        required
                                    />
                                    {errors.from && (
                                        <span className="text-xs text-red-600">
                                            {errors.from}
                                        </span>
                                    )}
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor={`sync-to-${company.id}`}>
                                        Până la
                                    </Label>
                                    <DatePicker
                                        id={`sync-to-${company.id}`}
                                        name="to"
                                        value={to}
                                        onChange={setTo}
                                        required
                                    />
                                    {errors.to && (
                                        <span className="text-xs text-red-600">
                                            {errors.to}
                                        </span>
                                    )}
                                </div>
                            </div>
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => setOpen(false)}
                                >
                                    Anulează
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    <RefreshCw />
                                    {processing
                                        ? 'Se pornește…'
                                        : 'Pornește sincronizarea'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

export default function CompaniesIndex({
    companies,
}: {
    companies: Company[];
}) {
    return (
        <>
            <Head title="Companii" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold">Companii</h1>
                        <p className="text-sm text-muted-foreground">
                            Fiecare companie se conectează la propria bază de
                            date PostgreSQL (credențialele sunt criptate la
                            stocare).
                        </p>
                    </div>
                    <Button asChild>
                        <Link href={companiesCreate()}>
                            <Plus />
                            Adaugă companie
                        </Link>
                    </Button>
                </div>

                <div className="overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left text-xs text-muted-foreground uppercase">
                            <tr>
                                <th className="px-4 py-3">Nume</th>
                                <th className="px-4 py-3">CUI</th>
                                <th className="px-4 py-3">Bază de date</th>
                                <th className="px-4 py-3">Parteneri</th>
                                <th className="px-4 py-3">Facturi</th>
                                <th className="px-4 py-3">
                                    Ultima sincronizare
                                </th>
                                <th className="px-4 py-3 text-right">
                                    Acțiuni
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                            {companies.length === 0 && (
                                <tr>
                                    <td
                                        className="px-4 py-6 text-center text-muted-foreground"
                                        colSpan={7}
                                    >
                                        Nicio companie încă. Adaugă una pentru a
                                        începe.
                                    </td>
                                </tr>
                            )}
                            {companies.map((company) => (
                                <tr key={company.id}>
                                    <td className="px-4 py-3 font-medium">
                                        {company.name}
                                    </td>
                                    <td className="px-4 py-3 text-muted-foreground">
                                        {company.cui ?? '—'}
                                    </td>
                                    <td className="px-4 py-3 text-muted-foreground">
                                        {company.db_host}:{company.db_port}/
                                        {company.db_database}
                                    </td>
                                    <td className="px-4 py-3">
                                        {company.partners_count}
                                    </td>
                                    <td className="px-4 py-3">
                                        {company.invoices_count}
                                    </td>
                                    <td className="px-4 py-3 text-muted-foreground">
                                        {company.last_synced_at ?? 'niciodată'}
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex items-center justify-end gap-2">
                                            <SyncDialog company={company} />
                                            <Button
                                                asChild
                                                size="sm"
                                                variant="outline"
                                            >
                                                <Link
                                                    href={companiesEdit(
                                                        company.id,
                                                    )}
                                                >
                                                    <Pencil />
                                                </Link>
                                            </Button>
                                            <Form
                                                {...CompanyController.destroy.form(
                                                    company.id,
                                                )}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                                onBefore={() =>
                                                    confirm(
                                                        `Ștergi ${company.name}?`,
                                                    )
                                                }
                                            >
                                                {({ processing }) => (
                                                    <Button
                                                        size="sm"
                                                        variant="destructive"
                                                        disabled={processing}
                                                    >
                                                        <Trash2 />
                                                    </Button>
                                                )}
                                            </Form>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}

CompaniesIndex.layout = (page: React.ReactNode) => (
    <AppLayout breadcrumbs={[{ title: 'Companii', href: companiesIndex() }]}>
        {page}
    </AppLayout>
);
