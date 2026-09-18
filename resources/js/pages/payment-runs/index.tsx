import { Form, Head, Link } from '@inertiajs/react';
import { CalendarClock, Plus } from 'lucide-react';
import { useState } from 'react';
import PaymentRunController from '@/actions/App/Http/Controllers/Approvals/PaymentRunController';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatByCurrency, formatDate } from '@/lib/money';
import { cn } from '@/lib/utils';
import { index as paymentRunsIndex } from '@/routes/payment-runs';
import type { PaymentRunStatus, PaymentRunsPageProps } from '@/types/approvals';

export const runStatusLabels: Record<PaymentRunStatus, string> = {
    review: 'La departamente',
    final: 'La Top Management',
    approved: 'Aprobat – de trimis la bancă',
    exported: 'Trimis la bancă',
    closed: 'Închis',
    cancelled: 'Anulat',
};

const runStatusTone: Record<PaymentRunStatus, string> = {
    review: 'border-amber-600/40 bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
    final: 'border-sky-600/40 bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
    approved:
        'border-green-600/40 bg-green-50 text-green-700 dark:bg-green-500/10 dark:text-green-300',
    exported:
        'border-violet-600/40 bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300',
    closed: 'border-sidebar-border/70 text-muted-foreground',
    cancelled:
        'border-red-600/40 bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300',
};

/** Where a payment run stands. */
export function RunStatusBadge({
    status,
    className,
}: {
    status: PaymentRunStatus;
    className?: string;
}) {
    return (
        <Badge
            variant="outline"
            className={cn(runStatusTone[status], className)}
        >
            {runStatusLabels[status]}
        </Badge>
    );
}

/** A person and the day they did something, or a dash. */
function ByLine({ name, at }: { name: string | null; at: string | null }) {
    if (!name) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <div className="flex flex-col">
            <span>{name}</span>
            {at && (
                <span className="text-xs text-muted-foreground">
                    {formatDate(at)}
                </span>
            )}
        </div>
    );
}

export default function PaymentRunsIndex({
    runs,
    defaults,
    can,
}: PaymentRunsPageProps) {
    return (
        <>
            <Head title="Rulaje de plată" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">
                            Rulaje de plată
                        </h1>
                        <p className="max-w-3xl text-sm text-muted-foreground">
                            Plățile săptămânale către furnizori: departamentele
                            își aprobă facturile, Top Management aprobă rulajul,
                            Trezoreria îl trimite la bancă, iar rulajul se
                            închide când OMC înregistrează plățile.
                        </p>
                    </div>

                    {can.create && <CreateRunDialog defaults={defaults} />}
                </div>

                <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Referință</TableHead>
                                <TableHead>Data plății</TableHead>
                                <TableHead>Scadență până la</TableHead>
                                <TableHead className="text-right">
                                    Facturi
                                </TableHead>
                                <TableHead className="text-right">
                                    Total
                                </TableHead>
                                <TableHead>Stare</TableHead>
                                <TableHead>Aprobat de</TableHead>
                                <TableHead>Trimis de</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {runs.data.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={8}
                                        className="py-12 text-center text-muted-foreground"
                                    >
                                        <div className="flex flex-col items-center gap-2">
                                            <CalendarClock className="size-8 opacity-50" />
                                            <span>
                                                Nu există încă rulaje de plată.
                                            </span>
                                            {can.create && (
                                                <span className="text-xs">
                                                    Creați primul rulaj cu
                                                    butonul „Rulaj nou”.
                                                </span>
                                            )}
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ) : (
                                runs.data.map((run) => (
                                    <TableRow key={run.id}>
                                        <TableCell className="font-medium">
                                            <Link
                                                href={PaymentRunController.show(
                                                    run.id,
                                                )}
                                                className="hover:underline"
                                            >
                                                {run.reference}
                                            </Link>
                                            {run.created_by && (
                                                <div className="text-xs font-normal text-muted-foreground">
                                                    creat de {run.created_by}
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell className="tabular-nums">
                                            {formatDate(run.pay_date)}
                                        </TableCell>
                                        <TableCell className="tabular-nums">
                                            {formatDate(run.due_until)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {run.included_count}
                                        </TableCell>
                                        <TableCell className="text-right whitespace-nowrap tabular-nums">
                                            {formatByCurrency(
                                                run.totals.by_currency,
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <RunStatusBadge
                                                status={run.status}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <ByLine
                                                name={run.approved_by}
                                                at={run.approved_at}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <ByLine
                                                name={run.exported_by}
                                                at={run.exported_at}
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </div>

                <div className="flex justify-center">
                    <Pagination links={runs.links} />
                </div>
            </div>
        </>
    );
}

function CreateRunDialog({
    defaults,
}: {
    defaults: PaymentRunsPageProps['defaults'];
}) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button>
                    <Plus />
                    Rulaj nou
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Rulaj de plată nou</DialogTitle>
                    <DialogDescription>
                        Include facturile deschise cu scadența până la data
                        aleasă, necontestate, neamânate și care nu sunt în alt
                        rulaj.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...PaymentRunController.store.form()}
                    onSuccess={() => setOpen(false)}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="run-pay-date">
                                        Data plății
                                    </Label>
                                    <Input
                                        id="run-pay-date"
                                        type="date"
                                        name="pay_date"
                                        defaultValue={defaults.pay_date}
                                        required
                                    />
                                    {errors.pay_date && (
                                        <span className="text-xs text-destructive">
                                            {errors.pay_date}
                                        </span>
                                    )}
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="run-due-until">
                                        Scadență până la
                                    </Label>
                                    <Input
                                        id="run-due-until"
                                        type="date"
                                        name="due_until"
                                        defaultValue={defaults.due_until}
                                        required
                                    />
                                    {errors.due_until && (
                                        <span className="text-xs text-destructive">
                                            {errors.due_until}
                                        </span>
                                    )}
                                </div>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="run-note">
                                    Notă (opțional)
                                </Label>
                                <Textarea
                                    id="run-note"
                                    name="note"
                                    rows={3}
                                    placeholder="Ex: plăți înainte de sezon"
                                />
                                {errors.note && (
                                    <span className="text-xs text-destructive">
                                        {errors.note}
                                    </span>
                                )}
                            </div>
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => setOpen(false)}
                                >
                                    Renunță
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    <Plus />
                                    Creează rulajul
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

PaymentRunsIndex.layout = (page: React.ReactNode) => (
    <AppLayout
        breadcrumbs={[{ title: 'Rulaje de plată', href: paymentRunsIndex() }]}
    >
        {page}
    </AppLayout>
);
