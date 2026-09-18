import { Head, Link, router, usePoll } from '@inertiajs/react';
import { Loader2, Pencil, Plus, RefreshCw, Trash2, Undo2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import RoutingController from '@/actions/App/Http/Controllers/Approvals/RoutingController';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
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
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatMoney } from '@/lib/money';
import { show as invoiceShow } from '@/routes/invoices';
import { index as routingIndex } from '@/routes/routing';
import type {
    DepartmentGroup,
    DepartmentRef,
    RoutingAccuracy,
    RoutingPageProps,
    RoutingQueueInvoice,
    RoutingQueueLine,
    RoutingRule,
} from '@/types/approvals';

type RuleKind = RoutingRule['kind'];
type FormErrors = Record<string, string>;

const RULE_KINDS: RuleKind[] = ['loc', 'partner', 'account', 'office'];

const KIND_LABELS: Record<RuleKind, string> = {
    loc: 'Loc de cheltuială OMC',
    partner: 'Furnizor',
    account: 'Cont contabil',
    office: 'Birou OMC',
};

const KIND_PLACEHOLDERS: Record<RuleKind, string> = {
    loc: 'ex. CHARTERE sau ~^CIRC',
    partner: 'ex. numele furnizorului din OMC',
    account: 'ex. 628',
    office: 'ex. BUCURESTI',
};

/** How each rule sent a line to its department (DepartmentAssigner). */
const RULE_LABELS: Record<string, string> = {
    loc: 'Loc de cheltuială',
    charter: 'Contract charter',
    ticket: 'Bilet Tina',
    booking: 'Rezervare eTrip',
    office: 'Birou',
    partner: 'Regulă furnizor',
    history: 'Istoric furnizor',
    account: 'Regulă cont',
    manual: 'Manual',
    none: 'Nerutat',
};

const GROUP_ORDER: DepartmentGroup[] = ['product', 'channel', 'support'];

const GROUP_LABELS: Record<DepartmentGroup, string> = {
    product: 'Produse',
    channel: 'Canale de vânzare',
    support: 'Suport',
};

type DepartmentOption = { department: DepartmentRef; depth: number };
type DepartmentOptionGroup = {
    key: string;
    label: string;
    options: DepartmentOption[];
};

/**
 * Departments grouped as products, sales channels and support, each
 * sub-department right under its parent.
 */
function groupDepartments(
    departments: DepartmentRef[],
): DepartmentOptionGroup[] {
    const groups = [
        ...GROUP_ORDER.map((group) => ({
            key: group,
            label: GROUP_LABELS[group],
            members: departments.filter((d) => d.group === group),
        })),
        {
            key: 'other',
            label: 'Altele',
            members: departments.filter(
                (d) => !d.group || !GROUP_ORDER.includes(d.group),
            ),
        },
    ];

    return groups
        .map(({ key, label, members }) => {
            const ids = new Set(members.map((d) => d.id));
            const options: DepartmentOption[] = [];
            const visited = new Set<number>();

            const walk = (department: DepartmentRef, depth: number) => {
                if (visited.has(department.id)) {
                    return;
                }

                visited.add(department.id);
                options.push({ department, depth });
                members
                    .filter((d) => d.parent_id === department.id)
                    .forEach((child) => walk(child, depth + 1));
            };

            members
                .filter((d) => !d.parent_id || !ids.has(d.parent_id))
                .forEach((root) => walk(root, 0));
            members.forEach((d) => walk(d, 0));

            return { key, label, options };
        })
        .filter((group) => group.options.length > 0);
}

function formatDateTime(value: string | null): string {
    if (!value) {
        return '—';
    }

    return new Date(value).toLocaleString('ro-RO', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function formatPercent(value: number | null): string {
    if (value === null) {
        return '—';
    }

    return `${new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 1 }).format(value)}%`;
}

function formatCount(value: number): string {
    return new Intl.NumberFormat('ro-RO').format(value);
}

export default function RoutingIndex({
    tab,
    departments,
    queue,
    rules,
    accuracy,
    counts,
    filters,
    run,
    can,
}: RoutingPageProps) {
    const changeTab = (value: string) => {
        router.get(
            routingIndex().url,
            { tab: value },
            { preserveState: true, preserveScroll: true },
        );
    };

    const rerun = () => {
        router.post(RoutingController.rerun(), {}, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Rutare" />
            {run.running && <RunPoller />}

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="max-w-3xl">
                        <h1 className="text-2xl font-semibold">
                            Rutare pe departamente
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Fiecare linie a unei facturi de furnizor primește un
                            departament după prima regulă care se potrivește:
                            locul de cheltuială OMC, contractul de charter cu
                            furnizorul, biletul Tina (TN_), rezervarea eTrip de
                            pe linie, biroul OMC, regula pentru furnizor,
                            istoricul furnizorului și, la urmă, regula pentru
                            contul contabil. Liniile pe care nu le prinde nicio
                            regulă așteaptă aici ca Financiar să le trimită unde
                            trebuie.
                        </p>
                    </div>
                    {can.edit && (
                        <div className="flex flex-col items-end gap-1">
                            <Button
                                variant="outline"
                                onClick={rerun}
                                disabled={run.running}
                            >
                                {run.running ? (
                                    <Loader2 className="size-4 animate-spin" />
                                ) : (
                                    <RefreshCw className="size-4" />
                                )}
                                {run.running
                                    ? 'Rulează…'
                                    : 'Rulează din nou rutarea (13 luni)'}
                            </Button>
                            {run.running && (
                                <span className="text-xs text-muted-foreground">
                                    pornită la {formatDateTime(run.started_at)}
                                </span>
                            )}
                        </div>
                    )}
                    {!can.edit && run.running && (
                        <Badge variant="secondary">
                            <Loader2 className="size-3 animate-spin" />
                            Rutarea rulează din {formatDateTime(run.started_at)}
                        </Badge>
                    )}
                </div>

                <Tabs value={tab} onValueChange={changeTab}>
                    <TabsList>
                        <TabsTrigger value="queue">
                            De rutat
                            {counts.queue > 0 && (
                                <Badge variant="secondary" className="ml-1">
                                    {formatCount(counts.queue)}
                                </Badge>
                            )}
                        </TabsTrigger>
                        <TabsTrigger value="rules">Reguli</TabsTrigger>
                        <TabsTrigger value="accuracy">Acuratețe</TabsTrigger>
                    </TabsList>
                </Tabs>

                {tab === 'queue' && queue && (
                    <QueueTab
                        queue={queue}
                        departments={departments}
                        search={filters.search}
                        canEdit={can.edit}
                    />
                )}
                {tab === 'rules' && rules && (
                    <RulesTab
                        rules={rules}
                        departments={departments}
                        canEdit={can.edit}
                    />
                )}
                {tab === 'accuracy' && accuracy && (
                    <AccuracyTab accuracy={accuracy} />
                )}
            </div>
        </>
    );
}

/** Reloads the page while the routing runs in the background. */
function RunPoller() {
    usePoll(10000);

    return null;
}

function DepartmentSelect({
    departments,
    value,
    onChange,
    id,
    className,
}: {
    departments: DepartmentRef[];
    value: string;
    onChange: (value: string) => void;
    id?: string;
    className?: string;
}) {
    const groups = groupDepartments(departments);

    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger id={id} className={className ?? 'w-[240px]'}>
                <SelectValue placeholder="Alege departamentul" />
            </SelectTrigger>
            <SelectContent>
                {groups.map((group) => (
                    <SelectGroup key={group.key}>
                        <SelectLabel>{group.label}</SelectLabel>
                        {group.options.map(({ department, depth }) => (
                            <SelectItem
                                key={department.id}
                                value={String(department.id)}
                            >
                                <span style={{ paddingLeft: depth * 12 }}>
                                    {department.name}
                                </span>
                            </SelectItem>
                        ))}
                    </SelectGroup>
                ))}
            </SelectContent>
        </Select>
    );
}

function FieldError({ message }: { message?: string }) {
    if (!message) {
        return null;
    }

    return <p className="text-sm text-destructive">{message}</p>;
}

/* ------------------------------------------------------------------ */
/* De rutat                                                            */
/* ------------------------------------------------------------------ */

function QueueTab({
    queue,
    departments,
    search: initialSearch,
    canEdit,
}: {
    queue: NonNullable<RoutingPageProps['queue']>;
    departments: DepartmentRef[];
    search: string;
    canEdit: boolean;
}) {
    const [search, setSearch] = useState(initialSearch);

    const submitSearch = (event: FormEvent) => {
        event.preventDefault();
        router.get(
            routingIndex().url,
            { tab: 'queue', search: search.trim() || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <div className="flex flex-col gap-4">
            <form
                className="flex flex-wrap items-center gap-2"
                onSubmit={submitSearch}
            >
                <Input
                    className="max-w-xs"
                    placeholder="Caută furnizor sau număr de factură…"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                />
                <Button type="submit" variant="secondary">
                    Caută
                </Button>
                <span className="text-sm text-muted-foreground">
                    {formatCount(queue.total)}{' '}
                    {queue.total === 1 ? 'factură' : 'facturi'} de rutat, cele
                    mai apropiate de scadență primele.
                </span>
            </form>

            {queue.data.length === 0 ? (
                <Card>
                    <CardContent className="py-10 text-center text-muted-foreground">
                        {initialSearch
                            ? 'Nicio factură de rutat nu se potrivește căutării.'
                            : 'Toate facturile deschise au departament.'}
                    </CardContent>
                </Card>
            ) : (
                queue.data.map((invoice) => (
                    <QueueInvoiceCard
                        key={invoice.id}
                        invoice={invoice}
                        departments={departments}
                        canEdit={canEdit}
                    />
                ))
            )}

            <div className="flex justify-center">
                <Pagination links={queue.links} />
            </div>
        </div>
    );
}

function lineReason(line: RoutingQueueLine): string {
    if (line.detail) {
        return line.detail;
    }

    if (!line.rule || line.rule === 'none') {
        return 'Nicio regulă';
    }

    return RULE_LABELS[line.rule] ?? line.rule;
}

function QueueInvoiceCard({
    invoice,
    departments,
    canEdit,
}: {
    invoice: RoutingQueueInvoice;
    departments: DepartmentRef[];
    canEdit: boolean;
}) {
    const [selected, setSelected] = useState<number[]>([]);
    const [departmentId, setDepartmentId] = useState('');
    const [remember, setRemember] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<FormErrors>({});

    const lineIds = invoice.lines.map((line) => line.scv);
    const allSelected =
        lineIds.length > 0 && selected.length === lineIds.length;
    const hasManualLines = invoice.lines.some((line) => line.rule === 'manual');

    const toggleLine = (scv: number, checked: boolean) => {
        setSelected((current) =>
            checked ? [...current, scv] : current.filter((s) => s !== scv),
        );
    };

    const toggleAll = (checked: boolean) => {
        setSelected(checked ? lineIds : []);
    };

    const assign = () => {
        router.post(
            RoutingController.assign(invoice.id),
            {
                department_id: Number(departmentId),
                ...(selected.length > 0 ? { scvs: selected } : {}),
                remember,
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    setErrors({});
                    setSelected([]);
                    setRemember(false);
                },
                onError: (errs) => setErrors(errs),
            },
        );
    };

    const release = () => {
        router.post(
            RoutingController.release(invoice.id),
            {},
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (errs) => setErrors(errs),
            },
        );
    };

    return (
        <Card className="gap-4">
            <CardHeader>
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <CardTitle className="text-base">
                            {invoice.partner ?? 'Furnizor necunoscut'}
                        </CardTitle>
                        <CardDescription className="flex flex-wrap gap-x-4 gap-y-1">
                            <Link
                                href={invoiceShow(invoice.id)}
                                className="font-medium text-foreground underline-offset-4 hover:underline"
                            >
                                {invoice.nr_doc}
                            </Link>
                            <span>Data: {formatDate(invoice.data_doc)}</span>
                            <span>
                                Scadență: {formatDate(invoice.data_scadenta)}
                            </span>
                            {invoice.office && (
                                <span>Birou: {invoice.office}</span>
                            )}
                        </CardDescription>
                    </div>
                    <div className="text-right">
                        <div className="text-xs text-muted-foreground">
                            De plată
                        </div>
                        <div className="font-semibold tabular-nums">
                            {formatMoney(invoice.outstanding, invoice.moneda)}
                        </div>
                    </div>
                </div>
            </CardHeader>

            <CardContent>
                <div className="overflow-x-auto rounded-lg border border-sidebar-border/70 dark:border-sidebar-border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                {canEdit && (
                                    <TableHead className="w-8">
                                        <Checkbox
                                            aria-label="Toate liniile"
                                            checked={
                                                allSelected
                                                    ? true
                                                    : selected.length > 0
                                                      ? 'indeterminate'
                                                      : false
                                            }
                                            onCheckedChange={(checked) =>
                                                toggleAll(checked === true)
                                            }
                                        />
                                    </TableHead>
                                )}
                                <TableHead>Articol</TableHead>
                                <TableHead>Cont</TableHead>
                                <TableHead>Loc</TableHead>
                                <TableHead>Referință</TableHead>
                                <TableHead className="text-right">
                                    Sumă
                                </TableHead>
                                <TableHead>Departament</TableHead>
                                <TableHead>Motiv</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {invoice.lines.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={canEdit ? 8 : 7}
                                        className="text-center text-muted-foreground"
                                    >
                                        Factura nu are linii importate.
                                    </TableCell>
                                </TableRow>
                            )}
                            {invoice.lines.map((line) => (
                                <TableRow key={line.scv}>
                                    {canEdit && (
                                        <TableCell>
                                            <Checkbox
                                                aria-label={`Linia ${line.scv}`}
                                                checked={selected.includes(
                                                    line.scv,
                                                )}
                                                onCheckedChange={(checked) =>
                                                    toggleLine(
                                                        line.scv,
                                                        checked === true,
                                                    )
                                                }
                                            />
                                        </TableCell>
                                    )}
                                    <TableCell className="max-w-[280px] whitespace-normal">
                                        <div>{line.articol}</div>
                                        {line.detaliu && (
                                            <div className="text-xs text-muted-foreground">
                                                {line.detaliu}
                                            </div>
                                        )}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {line.account ?? '—'}
                                    </TableCell>
                                    <TableCell>{line.loc ?? '—'}</TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {line.com_int ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatMoney(
                                            line.amount,
                                            invoice.moneda,
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {line.department ?? (
                                            <span className="text-muted-foreground">
                                                —
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="max-w-[260px] text-xs whitespace-normal text-muted-foreground">
                                        {lineReason(line)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </CardContent>

            {canEdit && (
                <CardFooter className="flex flex-col items-stretch gap-2">
                    <div className="flex flex-wrap items-center gap-3">
                        <DepartmentSelect
                            departments={departments}
                            value={departmentId}
                            onChange={setDepartmentId}
                        />
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id={`remember-${invoice.id}`}
                                checked={remember}
                                disabled={!invoice.partner}
                                onCheckedChange={(checked) =>
                                    setRemember(checked === true)
                                }
                            />
                            <Label
                                htmlFor={`remember-${invoice.id}`}
                                className="font-normal"
                            >
                                Trimite mereu acest furnizor aici
                            </Label>
                        </div>
                        <div className="ml-auto flex gap-2">
                            {hasManualLines && (
                                <Button
                                    variant="outline"
                                    onClick={release}
                                    disabled={processing}
                                    title="Liniile rutate manual se întorc la reguli"
                                >
                                    <Undo2 className="size-4" />
                                    Eliberează
                                </Button>
                            )}
                            <Button
                                onClick={assign}
                                disabled={processing || departmentId === ''}
                            >
                                {processing && (
                                    <Loader2 className="size-4 animate-spin" />
                                )}
                                {selected.length === 0
                                    ? 'Rutează factura'
                                    : `Rutează ${selected.length} ${selected.length === 1 ? 'linie' : 'linii'}`}
                            </Button>
                        </div>
                    </div>
                    <FieldError message={errors.department_id} />
                    <FieldError message={errors.scvs} />
                    <FieldError message={errors.remember} />
                </CardFooter>
            )}
        </Card>
    );
}

/* ------------------------------------------------------------------ */
/* Reguli                                                              */
/* ------------------------------------------------------------------ */

function RulesTab({
    rules,
    departments,
    canEdit,
}: {
    rules: RoutingRule[];
    departments: DepartmentRef[];
    canEdit: boolean;
}) {
    const [editing, setEditing] = useState<RoutingRule | null>(null);
    const [deleting, setDeleting] = useState<RoutingRule | null>(null);
    const [deleteProcessing, setDeleteProcessing] = useState(false);

    const destroy = () => {
        if (!deleting) {
            return;
        }

        router.delete(RoutingController.destroyRule(deleting.id), {
            preserveScroll: true,
            onStart: () => setDeleteProcessing(true),
            onFinish: () => setDeleteProcessing(false),
            onSuccess: () => setDeleting(null),
        });
    };

    return (
        <div className="flex flex-col gap-4">
            <p className="max-w-3xl text-sm text-muted-foreground">
                În fiecare fel de regulă, potrivirile exacte se verifică
                primele, apoi expresiile regulate („~…”), în ordinea din listă.
                Prima regulă care se potrivește decide departamentul. Regulile
                noi se aplică facturilor care vin de acum încolo; pentru cele
                vechi, rulează din nou rutarea.
            </p>

            {canEdit && (
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Regulă nouă</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <RuleForm departments={departments} />
                    </CardContent>
                </Card>
            )}

            {RULE_KINDS.map((kind) => {
                const kindRules = rules.filter((rule) => rule.kind === kind);

                return (
                    <Card key={kind} className="gap-3">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                {KIND_LABELS[kind]}
                                <Badge variant="secondary">
                                    {kindRules.length}
                                </Badge>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto rounded-lg border border-sidebar-border/70 dark:border-sidebar-border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Tipar</TableHead>
                                            <TableHead>Departament</TableHead>
                                            <TableHead>Notă</TableHead>
                                            <TableHead>Creată de</TableHead>
                                            {canEdit && (
                                                <TableHead className="text-right">
                                                    Acțiuni
                                                </TableHead>
                                            )}
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {kindRules.length === 0 && (
                                            <TableRow>
                                                <TableCell
                                                    colSpan={canEdit ? 5 : 4}
                                                    className="text-center text-muted-foreground"
                                                >
                                                    Nicio regulă.
                                                </TableCell>
                                            </TableRow>
                                        )}
                                        {kindRules.map((rule) => (
                                            <TableRow key={rule.id}>
                                                <TableCell>
                                                    <span className="font-mono text-sm">
                                                        {rule.pattern}
                                                    </span>
                                                    {rule.pattern.startsWith(
                                                        '~',
                                                    ) && (
                                                        <Badge
                                                            variant="outline"
                                                            className="ml-2"
                                                        >
                                                            regex
                                                        </Badge>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {rule.department ?? '—'}
                                                </TableCell>
                                                <TableCell className="max-w-[280px] whitespace-normal text-muted-foreground">
                                                    {rule.note ?? '—'}
                                                </TableCell>
                                                <TableCell className="text-muted-foreground">
                                                    {rule.created_by ?? '—'}
                                                </TableCell>
                                                {canEdit && (
                                                    <TableCell className="text-right">
                                                        <div className="flex justify-end gap-1">
                                                            <Button
                                                                size="icon"
                                                                variant="ghost"
                                                                aria-label="Modifică regula"
                                                                onClick={() =>
                                                                    setEditing(
                                                                        rule,
                                                                    )
                                                                }
                                                            >
                                                                <Pencil className="size-4" />
                                                            </Button>
                                                            <Button
                                                                size="icon"
                                                                variant="ghost"
                                                                aria-label="Șterge regula"
                                                                onClick={() =>
                                                                    setDeleting(
                                                                        rule,
                                                                    )
                                                                }
                                                            >
                                                                <Trash2 className="size-4 text-destructive" />
                                                            </Button>
                                                        </div>
                                                    </TableCell>
                                                )}
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        </CardContent>
                    </Card>
                );
            })}

            <Dialog
                open={editing !== null}
                onOpenChange={(open) => !open && setEditing(null)}
            >
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Modifică regula</DialogTitle>
                        <DialogDescription>
                            Schimbarea se aplică facturilor noi; pentru cele
                            vechi, rulează din nou rutarea.
                        </DialogDescription>
                    </DialogHeader>
                    {editing && (
                        <RuleForm
                            key={editing.id}
                            rule={editing}
                            departments={departments}
                            onDone={() => setEditing(null)}
                        />
                    )}
                </DialogContent>
            </Dialog>

            <Dialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Ștergi regula?</DialogTitle>
                        <DialogDescription>
                            {deleting && (
                                <>
                                    {KIND_LABELS[deleting.kind]}{' '}
                                    <span className="font-mono">
                                        {deleting.pattern}
                                    </span>{' '}
                                    → {deleting.department ?? '—'}. Liniile
                                    rutate deja rămân unde sunt până la
                                    următoarea rulare.
                                </>
                            )}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setDeleting(null)}
                        >
                            Renunță
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={destroy}
                            disabled={deleteProcessing}
                        >
                            Șterge
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

function patternHelp(kind: RuleKind): string {
    const regex =
        'Începe cu „~” pentru o expresie regulată, fără diferență între majuscule și minuscule (ex. „~^CIRC”).';

    if (kind === 'account') {
        return `Contul se potrivește după prefix: „628” prinde 628, 6281, 6282… ${regex}`;
    }

    return `Fără „~”, valoarea trebuie să fie exact aceeași (majusculele nu contează). ${regex}`;
}

function RuleForm({
    rule,
    departments,
    onDone,
}: {
    rule?: RoutingRule;
    departments: DepartmentRef[];
    onDone?: () => void;
}) {
    const [kind, setKind] = useState<RuleKind>(rule?.kind ?? 'loc');
    const [pattern, setPattern] = useState(rule?.pattern ?? '');
    const [departmentId, setDepartmentId] = useState(
        rule ? String(rule.department_id) : '',
    );
    const [note, setNote] = useState(rule?.note ?? '');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<FormErrors>({});

    const idPrefix = rule ? `rule-${rule.id}` : 'rule-new';

    const submit = (event: FormEvent) => {
        event.preventDefault();

        const data = {
            kind,
            pattern: pattern.trim(),
            department_id: departmentId === '' ? null : Number(departmentId),
            note: note.trim() || null,
        };
        const options = {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onError: (errs: FormErrors) => setErrors(errs),
            onSuccess: () => {
                setErrors({});

                if (!rule) {
                    setPattern('');
                    setNote('');
                }

                onDone?.();
            },
        };

        if (rule) {
            router.put(RoutingController.updateRule(rule.id), data, options);
        } else {
            router.post(RoutingController.storeRule(), data, options);
        }
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <div className="grid gap-4 md:grid-cols-[200px_1fr_240px]">
                <div className="flex flex-col gap-2">
                    <Label htmlFor={`${idPrefix}-kind`}>Fel</Label>
                    <Select
                        value={kind}
                        onValueChange={(value) => setKind(value as RuleKind)}
                    >
                        <SelectTrigger
                            id={`${idPrefix}-kind`}
                            className="w-full"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {RULE_KINDS.map((k) => (
                                <SelectItem key={k} value={k}>
                                    {KIND_LABELS[k]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <FieldError message={errors.kind} />
                </div>
                <div className="flex flex-col gap-2">
                    <Label htmlFor={`${idPrefix}-pattern`}>Tipar</Label>
                    <Input
                        id={`${idPrefix}-pattern`}
                        className="font-mono"
                        placeholder={KIND_PLACEHOLDERS[kind]}
                        value={pattern}
                        onChange={(e) => setPattern(e.target.value)}
                    />
                    <p className="text-xs text-muted-foreground">
                        {patternHelp(kind)}
                    </p>
                    <FieldError message={errors.pattern} />
                </div>
                <div className="flex flex-col gap-2">
                    <Label htmlFor={`${idPrefix}-department`}>
                        Departament
                    </Label>
                    <DepartmentSelect
                        id={`${idPrefix}-department`}
                        className="w-full"
                        departments={departments}
                        value={departmentId}
                        onChange={setDepartmentId}
                    />
                    <FieldError message={errors.department_id} />
                </div>
            </div>
            <div className="flex flex-col gap-2">
                <Label htmlFor={`${idPrefix}-note`}>Notă (opțional)</Label>
                <Textarea
                    id={`${idPrefix}-note`}
                    rows={2}
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                />
                <FieldError message={errors.note} />
            </div>
            <div className="flex justify-end">
                <Button
                    type="submit"
                    disabled={
                        processing || pattern.trim() === '' || !departmentId
                    }
                >
                    {processing ? (
                        <Loader2 className="size-4 animate-spin" />
                    ) : (
                        !rule && <Plus className="size-4" />
                    )}
                    {rule ? 'Salvează' : 'Adaugă regula'}
                </Button>
            </div>
        </form>
    );
}

/* ------------------------------------------------------------------ */
/* Acuratețe                                                           */
/* ------------------------------------------------------------------ */

function StatTile({
    label,
    value,
    hint,
}: {
    label: string;
    value: string;
    hint?: string;
}) {
    return (
        <Card className="gap-1 py-4">
            <CardContent className="flex flex-col gap-1 px-4">
                <span className="text-sm text-muted-foreground">{label}</span>
                <span className="text-2xl font-semibold tabular-nums">
                    {value}
                </span>
                {hint && (
                    <span className="text-xs text-muted-foreground">
                        {hint}
                    </span>
                )}
            </CardContent>
        </Card>
    );
}

function AccuracyTab({ accuracy }: { accuracy: RoutingAccuracy }) {
    const predicted = accuracy.tagged_lines - accuracy.silent;
    const agreeShare =
        predicted > 0 ? (accuracy.agree / predicted) * 100 : null;
    const disagree = predicted - accuracy.agree;
    const totalLines = accuracy.by_rule.reduce((sum, r) => sum + r.lines, 0);

    return (
        <div className="flex flex-col gap-4">
            <p className="max-w-3xl text-sm text-muted-foreground">
                Pentru liniile pe care contabilii le-au etichetat cu un loc de
                cheltuială în OMC din {formatDate(accuracy.since)} încoace,
                comparăm departamentul dat de locul de cheltuială cu cel pe care
                l-ar fi ales celelalte reguli (charter, bilet, rezervare, birou,
                furnizor, istoric, cont). Cu cât se potrivesc mai des, cu atât
                ne putem baza pe reguli acolo unde locul de cheltuială lipsește.
            </p>

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile
                    label="Linii cu loc de cheltuială"
                    value={formatCount(accuracy.tagged_lines)}
                />
                <StatTile
                    label="Același departament"
                    value={formatPercent(agreeShare)}
                    hint={`${formatCount(accuracy.agree)} din ${formatCount(predicted)} linii cu predicție`}
                />
                <StatTile
                    label="Alt departament"
                    value={formatCount(disagree)}
                    hint="regulile ar fi trimis linia în altă parte"
                />
                <StatTile
                    label="Fără predicție"
                    value={formatCount(accuracy.silent)}
                    hint="nicio altă regulă nu s-ar fi aplicat"
                />
            </div>

            <div className="grid gap-4 xl:grid-cols-2">
                <Card className="gap-3">
                    <CardHeader>
                        <CardTitle className="text-base">
                            Cum au fost rutate liniile
                        </CardTitle>
                        <CardDescription>
                            Toate liniile din ultimele 12 luni, după regula care
                            a decis.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto rounded-lg border border-sidebar-border/70 dark:border-sidebar-border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Regulă</TableHead>
                                        <TableHead className="text-right">
                                            Linii
                                        </TableHead>
                                        <TableHead className="text-right">
                                            %
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Sumă
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {accuracy.by_rule.length === 0 && (
                                        <TableRow>
                                            <TableCell
                                                colSpan={4}
                                                className="text-center text-muted-foreground"
                                            >
                                                Nicio linie rutată încă.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {accuracy.by_rule.map((row) => (
                                        <TableRow key={row.rule}>
                                            <TableCell>
                                                {RULE_LABELS[row.rule] ??
                                                    row.rule}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatCount(row.lines)}
                                            </TableCell>
                                            <TableCell className="text-right text-muted-foreground tabular-nums">
                                                {formatPercent(
                                                    totalLines > 0
                                                        ? (row.lines /
                                                              totalLines) *
                                                              100
                                                        : null,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatMoney(row.amount, 'LEI')}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>

                <Card className="gap-3">
                    <CardHeader>
                        <CardTitle className="text-base">
                            Unde nu se potrivesc
                        </CardTitle>
                        <CardDescription>
                            Cele mai dese perechi: departamentul dat de locul de
                            cheltuială și cel spus de celelalte reguli.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto rounded-lg border border-sidebar-border/70 dark:border-sidebar-border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Loc de cheltuială</TableHead>
                                        <TableHead>
                                            Regulile ar fi spus
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Linii
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {accuracy.disagreements.length === 0 && (
                                        <TableRow>
                                            <TableCell
                                                colSpan={3}
                                                className="text-center text-muted-foreground"
                                            >
                                                Nicio nepotrivire.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {accuracy.disagreements.map((row) => (
                                        <TableRow
                                            key={`${row.tagged}|${row.predicted}`}
                                        >
                                            <TableCell>{row.tagged}</TableCell>
                                            <TableCell>
                                                {row.predicted}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatCount(row.lines)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}

RoutingIndex.layout = (page: React.ReactNode) => (
    <AppLayout
        breadcrumbs={[
            { title: 'Rutare pe departamente', href: routingIndex() },
        ]}
    >
        {page}
    </AppLayout>
);
