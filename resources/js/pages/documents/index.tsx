import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    Building2,
    CheckCheck,
    FileText,
    Loader2,
    RefreshCw,
    Search,
    Trash2,
    Upload,
    UserRound,
    X,
} from 'lucide-react';
import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
import { cn } from '@/lib/utils';
import {
    bulk as documentsBulk,
    destroy as documentDestroy,
    index as documentsIndex,
    show as documentShow,
    store as documentsStore,
} from '@/routes/documents';
import { STATUS_LABELS } from './types';
import type { DocumentLabels, DocumentRow, DocumentSlug } from './types';

/** Ce se poate face pe un teanc de documente deodată. */
type Action = 'department' | 'owner' | 'archive' | 'reread' | 'delete';

type Props = {
    type: DocumentSlug;
    labels: DocumentLabels;
    can: { manage: boolean; delete: boolean };
    filters: Record<string, string | number | boolean | null> & {
        sort?: string | null;
        dir?: string | null;
    };
    documents: {
        data: DocumentRow[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
        from: number | null;
        to: number | null;
    };
    summary: {
        total: number;
        active: number;
        draft: number;
        published: number;
        consents: number;
    };
    departments: { id: number; name: string }[];
    people: { id: number; name: string; email: string }[];
    myDepartment: number | null;
    /** Ce unelte de citire are serverul; fără ele, datele se pun cu mâna. */
    ocr: Record<string, boolean>;
    limits: { upload_mb: number; post: string; upload: string };
    consentGroup: string;
};

const dmy = (value: string | null) =>
    value === null ? '—' : new Date(value).toLocaleDateString('ro-RO');

function StatusBadge({ status }: { status: DocumentRow['status'] }) {
    const tone: Record<string, string> = {
        active: 'border-emerald-600/40 text-emerald-700 dark:text-emerald-300',
        draft: '',
        superseded: 'border-amber-600/40 text-amber-700 dark:text-amber-400',
        revoked: 'border-red-600/40 text-red-700 dark:text-red-300',
    };

    return (
        <Badge variant="outline" className={tone[status] ?? ''}>
            {STATUS_LABELS[status]}
        </Badge>
    );
}

/** Capul unei coloane care se poate rândui: o apăsare suie, încă una coboară. */
function SortableHead({
    column,
    label,
    filters,
    onSort,
    className,
}: {
    column: string;
    label: string;
    filters: Props['filters'];
    onSort: (column: string, dir: 'asc' | 'desc') => void;
    className?: string;
}) {
    const active = filters.sort === column;
    const dir = active && filters.dir === 'desc' ? 'desc' : 'asc';
    const Icon = !active ? ArrowUpDown : dir === 'asc' ? ArrowUp : ArrowDown;

    return (
        <TableHead className={className}>
            <button
                type="button"
                onClick={() =>
                    onSort(column, active && dir === 'asc' ? 'desc' : 'asc')
                }
                className={cn(
                    'inline-flex items-center gap-1 hover:text-foreground',
                    active && 'text-foreground',
                )}
            >
                {label}
                <Icon
                    className={cn(
                        'size-3.5',
                        active ? 'opacity-90' : 'opacity-40',
                    )}
                />
            </button>
        </TableHead>
    );
}

export default function DocumentsIndex({
    type,
    labels,
    can,
    filters,
    documents,
    summary,
    departments,
    people,
    myDepartment,
    ocr,
    limits,
    consentGroup,
}: Props) {
    const procedures = type === 'proceduri';
    const [search, setSearch] = useState(String(filters.search ?? ''));
    const [refused, setRefused] = useState<string | null>(null);
    const uploader = useRef<HTMLInputElement>(null);
    const [forDepartment, setForDepartment] = useState(
        myDepartment ? String(myDepartment) : '',
    );
    const upload = useForm<{ files: File[]; department_id: string }>({
        files: [],
        department_id: '',
    });

    const [picked, setPicked] = useState<number[]>([]);
    const [doing, setDoing] = useState<Action | null>(null);
    const [busy, setBusy] = useState(false);
    const bulk = useForm({ department_id: '', owner_id: '' });

    const chosen = (id: number) => picked.includes(id);

    const pick = (id: number, yes: boolean) =>
        setPicked((all) =>
            yes ? [...all, id] : all.filter((one) => one !== id),
        );

    const pickAll = (yes: boolean) =>
        setPicked(yes ? documents.data.map((row) => row.id) : []);

    const run = (action: Action, extra: Record<string, string> = {}) => {
        setBusy(true);

        router.post(
            documentsBulk(type).url,
            { action, ids: picked, ...extra },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setPicked([]);
                    setDoing(null);
                    bulk.reset();
                },
                onFinish: () => setBusy(false),
            },
        );
    };

    const go = (next: Record<string, string | number | boolean | null>) =>
        router.get(
            documentsIndex(type).url,
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true },
        );

    const sortBy = (column: string, dir: 'asc' | 'desc') =>
        go({ sort: column, dir });

    const submitSearch = (event: FormEvent) => {
        event.preventDefault();
        go({ search });
    };

    const send = (files: FileList | null) => {
        if (!files || files.length === 0) {
            return;
        }

        const picked = Array.from(files);
        const cap = limits.upload_mb * 1024 * 1024;
        const heavy = picked.filter((file) => file.size > cap);

        if (heavy.length > 0) {
            setRefused(
                `${heavy.map((file) => `${file.name} (${Math.round(file.size / 1024 / 1024)} MB)`).join(', ')} — serverul primește acum cel mult ${limits.upload_mb} MB.`,
            );

            return;
        }

        setRefused(null);

        // Fișierele se dau chiar trimiterii, nu prin starea formularului:
        // `setData` se vede abia la randarea următoare.
        upload.transform(() => ({
            files: picked,
            department_id: forDepartment,
        }));
        upload.post(documentsStore(type).url, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => upload.reset(),
        });
    };

    const missing = Object.entries(ocr)
        .filter(([, present]) => !present)
        .map(([name]) => name);

    return (
        <AppLayout
            breadcrumbs={[
                { title: labels.many, href: documentsIndex(type).url },
            ]}
        >
            <Head title={labels.many} />

            <div className="flex flex-col gap-4 p-4">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-xs text-muted-foreground uppercase">
                                În vigoare
                            </div>
                            <div className="text-2xl font-semibold tabular-nums">
                                {summary.active}
                            </div>
                            <div className="text-xs text-muted-foreground">
                                din {summary.total} în registru
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-xs text-muted-foreground uppercase">
                                {procedures
                                    ? 'Trimise în companie'
                                    : 'Comunicate'}
                            </div>
                            <div className="text-2xl font-semibold tabular-nums">
                                {summary.published}
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-xs text-muted-foreground uppercase">
                                În lucru
                            </div>
                            <div className="text-2xl font-semibold tabular-nums">
                                {summary.draft}
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-xs text-muted-foreground uppercase">
                                {procedures
                                    ? 'Luări la cunoștință'
                                    : 'Fișiere citite'}
                            </div>
                            <div className="text-2xl font-semibold tabular-nums">
                                {procedures ? summary.consents : summary.total}
                            </div>
                            {procedures && (
                                <div className="text-xs text-muted-foreground">
                                    pe toate procedurile
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {refused !== null && (
                    <p className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300">
                        {refused}
                    </p>
                )}

                {Object.values(upload.errors).length > 0 && (
                    <p className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300">
                        {Object.values(upload.errors).join(' · ')}
                    </p>
                )}

                {can.manage && missing.length > 0 && (
                    <p className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-900 dark:bg-amber-950/40">
                        Serverul n-are încă {missing.join(', ')}, deci
                        documentele scanate nu se pot citi singure. Se încarcă
                        oricum, dar datele se completează cu mâna.
                    </p>
                )}

                {can.manage && consentGroup === '' && (
                    <p className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-900 dark:bg-amber-950/40">
                        Nu sunt puse adresele de grup ale companiei (
                        <code>DOCUMENTS_CONSENT_GROUP</code> în configurare),
                        așa că la trimitere trebuie scrise de fiecare dată cu
                        mâna.
                    </p>
                )}

                <div className="flex flex-wrap items-end gap-3">
                    <form onSubmit={submitSearch} className="grid gap-1.5">
                        <Label htmlFor="search">Caută</Label>
                        <div className="flex gap-2">
                            <Input
                                id="search"
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                                placeholder="număr, titlu, emitent sau un rând din text"
                                className="w-80"
                            />
                            <Button type="submit" variant="outline">
                                <Search className="size-4" />
                            </Button>
                        </div>
                    </form>

                    <div className="grid gap-1.5">
                        <Label>Departament</Label>
                        <Select
                            value={String(filters.department_id ?? 'all')}
                            onValueChange={(value) =>
                                go({
                                    department_id: value === 'all' ? '' : value,
                                })
                            }
                        >
                            <SelectTrigger className="w-52">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Toate</SelectItem>
                                {departments.map((department) => (
                                    <SelectItem
                                        key={department.id}
                                        value={String(department.id)}
                                    >
                                        {department.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="grid gap-1.5">
                        <Label>Stare</Label>
                        <Select
                            value={String(filters.status ?? 'all')}
                            onValueChange={(value) =>
                                go({ status: value === 'all' ? '' : value })
                            }
                        >
                            <SelectTrigger className="w-40">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Toate</SelectItem>
                                {Object.entries(STATUS_LABELS).map(
                                    ([value, label]) => (
                                        <SelectItem key={value} value={value}>
                                            {label}
                                        </SelectItem>
                                    ),
                                )}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="issued_from">Emisă de la</Label>
                        <Input
                            id="issued_from"
                            type="date"
                            className="w-40"
                            value={String(filters.issued_from ?? '')}
                            onChange={(event) =>
                                go({ issued_from: event.target.value })
                            }
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="issued_to">până la</Label>
                        <Input
                            id="issued_to"
                            type="date"
                            className="w-40"
                            value={String(filters.issued_to ?? '')}
                            onChange={(event) =>
                                go({ issued_to: event.target.value })
                            }
                        />
                    </div>

                    <Button
                        variant={filters.archived ? 'default' : 'outline'}
                        onClick={() => go({ archived: !filters.archived })}
                    >
                        Arhivate
                    </Button>
                    <Button
                        variant="ghost"
                        onClick={() => router.get(documentsIndex(type).url)}
                    >
                        Golește
                    </Button>

                    {can.manage && (
                        <div className="ml-auto">
                            <input
                                ref={uploader}
                                type="file"
                                multiple
                                hidden
                                accept=".pdf,.doc,.docx,.odt,.rtf,.txt,.png,.jpg,.jpeg,.tif,.tiff,.webp,.heic"
                                onChange={(event) => {
                                    send(event.target.files);
                                    // Golit, ca același fișier să poată fi ales
                                    // din nou dacă prima încercare a eșuat.
                                    event.target.value = '';
                                }}
                            />
                            <Select
                                value={forDepartment || 'none'}
                                onValueChange={(value) =>
                                    setForDepartment(
                                        value === 'none' ? '' : value,
                                    )
                                }
                            >
                                <SelectTrigger className="mr-2 inline-flex w-[200px]">
                                    <SelectValue placeholder="Departament" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">
                                        Fără departament
                                    </SelectItem>
                                    {departments.map((one) => (
                                        <SelectItem
                                            key={one.id}
                                            value={String(one.id)}
                                        >
                                            {one.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Button
                                onClick={() => uploader.current?.click()}
                                disabled={upload.processing}
                                title="PDF, Word, scanări sau poze"
                            >
                                {upload.processing ? (
                                    <Loader2 className="size-4 animate-spin" />
                                ) : (
                                    <Upload className="size-4" />
                                )}
                                Încarcă {labels.many.toLowerCase()}
                            </Button>
                            <div className="mt-1 text-right text-xs text-muted-foreground">
                                până la {limits.upload_mb} MB · PDF, Word,
                                scanări
                            </div>
                        </div>
                    )}
                </div>

                {can.manage && picked.length > 0 && (
                    <div className="sticky top-2 z-10 flex flex-wrap items-center gap-2 rounded-lg border bg-background/95 p-2 shadow-sm backdrop-blur">
                        <span className="px-2 text-sm font-medium">
                            {picked.length} alese
                        </span>

                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setDoing('department')}
                            disabled={busy}
                        >
                            <Building2 className="size-4" />
                            Departament
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setDoing('owner')}
                            disabled={busy}
                        >
                            <UserRound className="size-4" />
                            Responsabil
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => run('archive')}
                            disabled={busy}
                        >
                            Arhivează
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => run('reread')}
                            disabled={busy}
                            title="Citește din nou documentele și îndreaptă fișele"
                        >
                            <RefreshCw className="size-4" />
                            Recitește
                        </Button>
                        {can.delete && (
                            <Button
                                size="sm"
                                variant="outline"
                                className="text-red-600 hover:text-red-700"
                                disabled={busy}
                                onClick={() => {
                                    if (
                                        window.confirm(
                                            `Ștergi ${picked.length} documente cu totul, împreună cu fișierele lor?`,
                                        )
                                    ) {
                                        run('delete');
                                    }
                                }}
                            >
                                <Trash2 className="size-4" />
                                Șterge
                            </Button>
                        )}

                        <Button
                            size="sm"
                            variant="ghost"
                            className="ml-auto text-muted-foreground"
                            onClick={() => setPicked([])}
                        >
                            <X className="size-4" />
                            Lasă alegerea
                        </Button>
                    </div>
                )}

                <Dialog
                    open={doing === 'department' || doing === 'owner'}
                    onOpenChange={(open) => !open && setDoing(null)}
                >
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>
                                {doing === 'department'
                                    ? 'Dă-le un departament'
                                    : 'Dă-le un responsabil'}
                            </DialogTitle>
                        </DialogHeader>
                        <div className="grid gap-3">
                            <Select
                                value={
                                    doing === 'department'
                                        ? bulk.data.department_id
                                        : bulk.data.owner_id
                                }
                                onValueChange={(value) =>
                                    bulk.setData(
                                        doing === 'department'
                                            ? 'department_id'
                                            : 'owner_id',
                                        value,
                                    )
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Alege" />
                                </SelectTrigger>
                                <SelectContent>
                                    {doing === 'department'
                                        ? departments.map((one) => (
                                              <SelectItem
                                                  key={one.id}
                                                  value={String(one.id)}
                                              >
                                                  {one.name}
                                              </SelectItem>
                                          ))
                                        : people.map((one) => (
                                              <SelectItem
                                                  key={one.id}
                                                  value={String(one.id)}
                                              >
                                                  {one.name}
                                              </SelectItem>
                                          ))}
                                </SelectContent>
                            </Select>
                            <Button
                                onClick={() =>
                                    doing === 'department'
                                        ? run('department', {
                                              department_id:
                                                  bulk.data.department_id,
                                          })
                                        : run('owner', {
                                              owner_id: bulk.data.owner_id,
                                          })
                                }
                                disabled={
                                    busy ||
                                    (doing === 'department'
                                        ? bulk.data.department_id === ''
                                        : bulk.data.owner_id === '')
                                }
                            >
                                {busy && (
                                    <Loader2 className="size-4 animate-spin" />
                                )}
                                Pune pe toate {picked.length}
                            </Button>
                        </div>
                    </DialogContent>
                </Dialog>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    {can.manage && (
                                        <TableHead className="w-10">
                                            <Checkbox
                                                aria-label="Alege tot ce se vede"
                                                checked={
                                                    documents.data.length > 0 &&
                                                    picked.length ===
                                                        documents.data.length
                                                }
                                                onCheckedChange={(value) =>
                                                    pickAll(value === true)
                                                }
                                            />
                                        </TableHead>
                                    )}
                                    {(
                                        [
                                            ['number', 'Număr'],
                                            ['title', 'Titlu'],
                                            ['issuer', 'Emitent'],
                                            ['department', 'Departament'],
                                            ['issued', 'Emisă'],
                                            ['effective', 'În vigoare din'],
                                            ['status', 'Stare'],
                                        ] as [string, string][]
                                    ).map(([column, label]) => (
                                        <SortableHead
                                            key={column}
                                            column={column}
                                            label={label}
                                            filters={filters}
                                            onSort={sortBy}
                                        />
                                    ))}
                                    {procedures && (
                                        <TableHead>
                                            Luare la cunoștință
                                        </TableHead>
                                    )}
                                    {can.delete && <TableHead />}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {documents.data.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={12}
                                            className="py-10 text-center text-muted-foreground"
                                        >
                                            <FileText className="mx-auto mb-2 size-6 opacity-40" />
                                            {can.manage
                                                ? `Niciun document încă. Încarcă primele fișiere — se citesc singure.`
                                                : `Nu e încă nimic în registrul „${labels.many}”.`}
                                        </TableCell>
                                    </TableRow>
                                )}
                                {documents.data.map((row) => (
                                    <TableRow
                                        key={row.id}
                                        data-state={
                                            chosen(row.id)
                                                ? 'selected'
                                                : undefined
                                        }
                                    >
                                        {can.manage && (
                                            <TableCell>
                                                <Checkbox
                                                    aria-label={`Alege ${row.number}`}
                                                    checked={chosen(row.id)}
                                                    onCheckedChange={(value) =>
                                                        pick(
                                                            row.id,
                                                            value === true,
                                                        )
                                                    }
                                                />
                                            </TableCell>
                                        )}
                                        <TableCell className="font-mono text-xs">
                                            <Link
                                                href={
                                                    documentShow({
                                                        type,
                                                        document: row.id,
                                                    }).url
                                                }
                                                className="hover:underline"
                                            >
                                                {row.number}
                                            </Link>
                                            {row.files_count > 1 && (
                                                <div className="text-[10px] text-muted-foreground">
                                                    {row.files_count} fișiere
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell className="max-w-96">
                                            <Link
                                                href={
                                                    documentShow({
                                                        type,
                                                        document: row.id,
                                                    }).url
                                                }
                                                className="font-medium hover:underline"
                                            >
                                                <span className="line-clamp-2">
                                                    {row.title}
                                                </span>
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            {row.issuer ?? (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {row.department ?? (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                            <div className="text-xs text-muted-foreground">
                                                {row.owner ??
                                                    'fără responsabil'}
                                            </div>
                                        </TableCell>
                                        <TableCell className="tabular-nums">
                                            {dmy(row.issued_at)}
                                        </TableCell>
                                        <TableCell className="tabular-nums">
                                            {dmy(row.effective_at)}
                                        </TableCell>
                                        <TableCell>
                                            <StatusBadge status={row.status} />
                                        </TableCell>
                                        {procedures && (
                                            <TableCell>
                                                {!row.needs_consent ? (
                                                    <span className="text-xs text-muted-foreground">
                                                        netrimisă
                                                    </span>
                                                ) : row.i_consented ? (
                                                    <span className="inline-flex items-center gap-1 text-xs text-emerald-700 dark:text-emerald-300">
                                                        <CheckCheck className="size-3.5" />
                                                        ai confirmat ·{' '}
                                                        {row.consents_count} în
                                                        total
                                                    </span>
                                                ) : (
                                                    <Link
                                                        href={
                                                            documentShow({
                                                                type,
                                                                document:
                                                                    row.id,
                                                            }).url
                                                        }
                                                        className="inline-flex items-center gap-1 text-xs font-medium text-amber-700 hover:underline dark:text-amber-400"
                                                    >
                                                        de citit ·{' '}
                                                        {row.consents_count} au
                                                        confirmat
                                                    </Link>
                                                )}
                                            </TableCell>
                                        )}
                                        {can.delete && (
                                            <TableCell className="text-right">
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-7 text-muted-foreground hover:text-red-600"
                                                    title="Șterge documentul"
                                                    onClick={() => {
                                                        if (
                                                            window.confirm(
                                                                `Ștergi ${row.number} cu totul, împreună cu fișierele lui?`,
                                                            )
                                                        ) {
                                                            router.delete(
                                                                documentDestroy(
                                                                    {
                                                                        type,
                                                                        document:
                                                                            row.id,
                                                                    },
                                                                ).url,
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            );
                                                        }
                                                    }}
                                                >
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    <span>
                        {documents.from ?? 0}–{documents.to ?? 0} din{' '}
                        {documents.total}
                    </span>
                    <div className="ml-auto flex gap-1">
                        {documents.links.map((link, index) => (
                            <Button
                                key={index}
                                size="sm"
                                variant={link.active ? 'default' : 'outline'}
                                disabled={link.url === null}
                                onClick={() =>
                                    link.url &&
                                    router.get(
                                        link.url,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
