import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    Building2,
    FileSignature,
    Loader2,
    RefreshCw,
    Search,
    Send,
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
import { Textarea } from '@/components/ui/textarea';
import {
    bulk as contractsBulk,
    destroy as contractDestroy,
    index as contractsIndex,
    show as contractShow,
    store as contractsStore,
} from '@/routes/contracts';
import { KIND_LABELS, STATUS_LABELS } from './types';
import type { ContractRow } from './types';

/** Ce se poate face pe un teanc de contracte deodată. */
type Action =
    | 'share'
    | 'department'
    | 'owner'
    | 'archive'
    | 'reread'
    | 'delete';

/** Cât ține, din capul locului, legătura trimisă pe mail. */
const defaultShareDays = 15;

type Props = {
    can: { delete: boolean; all: boolean };
    filters: Record<string, string | number | boolean | null> & {
        sort?: string | null;
        dir?: string | null;
    };
    contracts: {
        data: ContractRow[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
        from: number | null;
        to: number | null;
    };
    summary: {
        total: number;
        active: number;
        expiring: number;
        unowned: number;
        value_ron: number;
    };
    departments: { id: number; name: string }[];
    people: { id: number; name: string; email: string }[];
    partners: string[];
    /** Ce unelte de citire are serverul; fără ele, datele se pun cu mâna. */
    ocr: Record<string, boolean>;
    /** Cât primește serverul acum, ca omul să afle înainte de a trimite. */
    limits: { upload_mb: number; post: string; upload: string };
};

const lei = (value: number) =>
    new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 0 }).format(value);

const money = (value: number | null, currency: string | null) =>
    value === null ? '—' : `${lei(value)} ${currency ?? 'RON'}`;

const dmy = (value: string | null) =>
    value === null ? '—' : new Date(value).toLocaleDateString('ro-RO');

/** Cât de aproape e scadența: de aici vine culoarea rândului. */
function Deadline({ row }: { row: ContractRow }) {
    if (row.expires_at === null) {
        return <span className="text-muted-foreground">fără termen</span>;
    }

    const left = row.days_left ?? 0;
    const tone =
        left < 0
            ? 'text-red-600 dark:text-red-400'
            : left <= 30
              ? 'text-red-600 dark:text-red-400'
              : left <= 90
                ? 'text-amber-700 dark:text-amber-400'
                : '';

    return (
        <span className={cn('tabular-nums', tone)}>
            {dmy(row.expires_at)}
            <span className="ml-1 text-xs">
                {left < 0 ? `(acum ${-left} z.)` : `(în ${left} z.)`}
            </span>
        </span>
    );
}

function StatusBadge({ status }: { status: ContractRow['status'] }) {
    const tone: Record<string, string> = {
        active: 'border-emerald-600/40 text-emerald-700 dark:text-emerald-300',
        expired: 'border-red-600/40 text-red-700 dark:text-red-300',
        terminated: 'border-red-600/40 text-red-700 dark:text-red-300',
        draft: '',
        negotiation: '',
        approval: 'border-amber-600/40 text-amber-700 dark:text-amber-300',
        signing: 'border-amber-600/40 text-amber-700 dark:text-amber-300',
    };

    return (
        <Badge variant="outline" className={tone[status] ?? ''}>
            {STATUS_LABELS[status]}
        </Badge>
    );
}

/**
 * Capul unei coloane care se poate rândui: o apăsare suie, încă una coboară.
 */
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
                    className?.includes('text-right') && 'flex-row-reverse',
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

export default function ContractsIndex({
    can,
    filters,
    contracts,
    summary,
    departments,
    people,
    partners,
    ocr,
    limits,
}: Props) {
    const [search, setSearch] = useState(String(filters.search ?? ''));
    // Fișierul prea mare se oprește aici, nu după ce a urcat degeaba: serverul
    // web îl taie înainte să ajungă la aplicație, iar omul n-ar afla de ce.
    const [refused, setRefused] = useState<string | null>(null);
    const uploader = useRef<HTMLInputElement>(null);
    const upload = useForm<{ files: File[] }>({ files: [] });

    // Alegerea ține cât stă omul pe pagină: trece la pagina următoare și se
    // golește, fiindcă n-ar mai vedea pe ce lucrează.
    const [picked, setPicked] = useState<number[]>([]);
    const [doing, setDoing] = useState<Action | null>(null);
    const [busy, setBusy] = useState(false);
    const bulk = useForm({
        emails: '',
        permission: 'view',
        days: String(defaultShareDays),
        note: '',
        department_id: '',
        owner_id: '',
    });

    const chosen = (id: number) => picked.includes(id);

    const pick = (id: number, yes: boolean) =>
        setPicked((all) =>
            yes ? [...all, id] : all.filter((one) => one !== id),
        );

    const pickAll = (yes: boolean) =>
        setPicked(yes ? contracts.data.map((row) => row.id) : []);

    /** Trimite fapta la server și golește alegerea dacă a mers. */
    const run = (action: Action, extra: Record<string, string> = {}) => {
        setBusy(true);

        router.post(
            contractsBulk().url,
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
            contractsIndex().url,
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

        const chosen = Array.from(files);
        const cap = limits.upload_mb * 1024 * 1024;
        const heavy = chosen.filter((file) => file.size > cap);

        if (heavy.length > 0) {
            setRefused(
                `${heavy.map((file) => `${file.name} (${Math.round(file.size / 1024 / 1024)} MB)`).join(', ')} — serverul primește acum cel mult ${limits.upload_mb} MB. Ridică limita din panoul de găzduire.`,
            );

            return;
        }

        setRefused(null);

        // Fișierele se dau chiar trimiterii, nu prin starea formularului:
        // `setData` se vede abia la randarea următoare, așa că prima apăsare
        // pleca fără ele, iar serverul răspundea „câmpul files e obligatoriu”.
        upload.transform(() => ({ files: chosen }));
        upload.post(contractsStore().url, {
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
            breadcrumbs={[{ title: 'Contracte', href: contractsIndex().url }]}
        >
            <Head title="Contracte" />

            <div className="flex flex-col gap-4 p-4">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-xs text-muted-foreground uppercase">
                                Contracte active
                            </div>
                            <div className="text-2xl font-semibold tabular-nums">
                                {summary.active}
                            </div>
                            <div className="text-xs text-muted-foreground">
                                din {summary.total} în repertoriu
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-xs text-muted-foreground uppercase">
                                Valoare în lei
                            </div>
                            <div className="text-2xl font-semibold tabular-nums">
                                {lei(summary.value_ron)}
                            </div>
                            <div className="text-xs text-muted-foreground">
                                contractele în valută se adună separat
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-xs text-muted-foreground uppercase">
                                Expiră în 90 de zile
                            </div>
                            <div className="text-2xl font-semibold text-amber-700 tabular-nums dark:text-amber-400">
                                {summary.expiring}
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-xs text-muted-foreground uppercase">
                                Fără responsabil
                            </div>
                            <div
                                className={cn(
                                    'text-2xl font-semibold tabular-nums',
                                    summary.unowned > 0 &&
                                        'text-red-600 dark:text-red-400',
                                )}
                            >
                                {summary.unowned}
                            </div>
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

                {missing.length > 0 && (
                    <p className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-900 dark:bg-amber-950/40">
                        Serverul n-are încă {missing.join(', ')}, deci
                        documentele scanate nu se pot citi singure. Contractele
                        se încarcă oricum, dar datele se completează cu mâna.
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
                                placeholder="număr, partener, obiect sau o clauză din text"
                                className="w-80"
                            />
                            <Button type="submit" variant="outline">
                                <Search className="size-4" />
                            </Button>
                        </div>
                    </form>

                    <div className="grid gap-1.5">
                        <Label>Partener</Label>
                        <Select
                            value={String(filters.partner ?? 'all')}
                            onValueChange={(value) =>
                                go({ partner: value === 'all' ? '' : value })
                            }
                        >
                            <SelectTrigger className="w-56">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Toți</SelectItem>
                                {partners.map((partner) => (
                                    <SelectItem key={partner} value={partner}>
                                        {partner}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="grid gap-1.5">
                        <Label>Tip</Label>
                        <Select
                            value={String(filters.kind ?? 'all')}
                            onValueChange={(value) =>
                                go({ kind: value === 'all' ? '' : value })
                            }
                        >
                            <SelectTrigger className="w-36">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Toate</SelectItem>
                                {Object.entries(KIND_LABELS).map(
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
                        <Label htmlFor="signed_from">Semnat de la</Label>
                        <Input
                            id="signed_from"
                            type="date"
                            className="w-40"
                            value={String(filters.signed_from ?? '')}
                            onChange={(event) =>
                                go({ signed_from: event.target.value })
                            }
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="signed_to">până la</Label>
                        <Input
                            id="signed_to"
                            type="date"
                            className="w-40"
                            value={String(filters.signed_to ?? '')}
                            onChange={(event) =>
                                go({ signed_to: event.target.value })
                            }
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="expires_to">Expiră până la</Label>
                        <Input
                            id="expires_to"
                            type="date"
                            className="w-40"
                            value={String(filters.expires_to ?? '')}
                            onChange={(event) =>
                                go({ expires_to: event.target.value })
                            }
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="value_from">Valoare de la</Label>
                        <Input
                            id="value_from"
                            className="w-32"
                            value={String(filters.value_from ?? '')}
                            onChange={(event) =>
                                go({ value_from: event.target.value })
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
                        onClick={() => router.get(contractsIndex().url)}
                    >
                        Golește
                    </Button>

                    <div className="ml-auto">
                        <input
                            ref={uploader}
                            type="file"
                            multiple
                            hidden
                            accept=".pdf,.doc,.docx,.odt,.rtf,.txt,.png,.jpg,.jpeg,.tif,.tiff,.webp,.heic"
                            onChange={(event) => {
                                send(event.target.files);
                                // Golit, ca același fișier să poată fi ales din
                                // nou dacă prima încercare a eșuat.
                                event.target.value = '';
                            }}
                        />
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
                            Încarcă contracte
                        </Button>
                        <div className="mt-1 text-right text-xs text-muted-foreground">
                            până la {limits.upload_mb} MB · PDF, Word, scanări
                        </div>
                    </div>
                </div>

                {picked.length > 0 && (
                    <div className="sticky top-2 z-10 flex flex-wrap items-center gap-2 rounded-lg border bg-background/95 p-2 shadow-sm backdrop-blur">
                        <span className="px-2 text-sm font-medium">
                            {picked.length}{' '}
                            {picked.length === 1
                                ? 'contract ales'
                                : 'contracte alese'}
                        </span>

                        <Button
                            size="sm"
                            onClick={() => setDoing('share')}
                            disabled={busy}
                        >
                            <Send className="size-4" />
                            Trimite pe mail
                        </Button>
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
                                            `Ștergi ${picked.length} contracte cu totul, împreună cu fișierele lor?`,
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
                    open={doing === 'share'}
                    onOpenChange={(open) => !open && setDoing(null)}
                >
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>
                                Trimite {picked.length} contracte
                            </DialogTitle>
                        </DialogHeader>
                        <div className="grid gap-3">
                            <div className="grid gap-1.5">
                                <Label htmlFor="bulk-emails">
                                    Către (adrese despărțite prin virgulă)
                                </Label>
                                <Input
                                    id="bulk-emails"
                                    value={bulk.data.emails}
                                    placeholder="cineva@christiantour.ro, altcineva@..."
                                    onChange={(event) =>
                                        bulk.setData(
                                            'emails',
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="grid grid-cols-2 gap-3">
                                <div className="grid gap-1.5">
                                    <Label>Ce poate face</Label>
                                    <Select
                                        value={bulk.data.permission}
                                        onValueChange={(value) =>
                                            bulk.setData('permission', value)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="view">
                                                Citește și descarcă
                                            </SelectItem>
                                            <SelectItem value="comment">
                                                Citește și comentează
                                            </SelectItem>
                                            <SelectItem value="edit">
                                                Poate schimba datele
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="bulk-days">
                                        Cât ține legătura (zile)
                                    </Label>
                                    <Input
                                        id="bulk-days"
                                        type="number"
                                        min={1}
                                        max={365}
                                        value={bulk.data.days}
                                        onChange={(event) =>
                                            bulk.setData(
                                                'days',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="bulk-note">
                                    Un rând de la tine (nu e obligatoriu)
                                </Label>
                                <Textarea
                                    id="bulk-note"
                                    rows={2}
                                    value={bulk.data.note}
                                    onChange={(event) =>
                                        bulk.setData('note', event.target.value)
                                    }
                                />
                            </div>
                            <p className="text-xs text-muted-foreground">
                                Pleacă un singur mail către fiecare adresă, cu
                                toate contractele alese. Fișierele nu se
                                atașează: fiecare contract are legătura lui, iar
                                deschiderile se văd în jurnal.
                            </p>
                            <Button
                                onClick={() =>
                                    run('share', {
                                        emails: bulk.data.emails,
                                        permission: bulk.data.permission,
                                        days: bulk.data.days,
                                        note: bulk.data.note,
                                    })
                                }
                                disabled={
                                    busy || bulk.data.emails.trim() === ''
                                }
                            >
                                {busy && (
                                    <Loader2 className="size-4 animate-spin" />
                                )}
                                Trimite
                            </Button>
                        </div>
                    </DialogContent>
                </Dialog>

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
                                    <TableHead className="w-10">
                                        <Checkbox
                                            aria-label="Alege tot ce se vede"
                                            checked={
                                                contracts.data.length > 0 &&
                                                picked.length ===
                                                    contracts.data.length
                                            }
                                            onCheckedChange={(value) =>
                                                pickAll(value === true)
                                            }
                                        />
                                    </TableHead>
                                    {(
                                        [
                                            ['number', 'Număr', ''],
                                            ['partner', 'Partener', ''],
                                            ['object', 'Obiect', ''],
                                            ['department', 'Departament', ''],
                                            ['value', 'Valoare', 'text-right'],
                                            ['signed', 'Semnat', ''],
                                            ['expires', 'Expiră', ''],
                                            ['status', 'Stare', ''],
                                        ] as [string, string, string][]
                                    ).map(([column, label, className]) => (
                                        <SortableHead
                                            key={column}
                                            column={column}
                                            label={label}
                                            filters={filters}
                                            onSort={sortBy}
                                            className={className}
                                        />
                                    ))}
                                    {can.delete && <TableHead />}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {contracts.data.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={can.delete ? 10 : 9}
                                            className="py-10 text-center text-muted-foreground"
                                        >
                                            <FileSignature className="mx-auto mb-2 size-6 opacity-40" />
                                            Niciun contract încă. Încarcă
                                            primele fișiere — se citesc singure.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {contracts.data.map((row) => (
                                    <TableRow
                                        key={row.id}
                                        data-state={
                                            chosen(row.id)
                                                ? 'selected'
                                                : undefined
                                        }
                                    >
                                        <TableCell>
                                            <Checkbox
                                                aria-label={`Alege ${row.number}`}
                                                checked={chosen(row.id)}
                                                onCheckedChange={(value) =>
                                                    pick(row.id, value === true)
                                                }
                                            />
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">
                                            <Link
                                                href={contractShow(row.id).url}
                                                className="hover:underline"
                                            >
                                                {row.number}
                                            </Link>
                                            {row.files_count > 1 && (
                                                <div className="text-[10px] text-muted-foreground">
                                                    {row.files_count} versiuni
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <Link
                                                href={contractShow(row.id).url}
                                                className="font-medium hover:underline"
                                            >
                                                {row.partner_name}
                                            </Link>
                                            <div className="text-xs text-muted-foreground">
                                                {KIND_LABELS[row.kind]}
                                            </div>
                                        </TableCell>
                                        <TableCell className="max-w-80">
                                            <div className="truncate">
                                                {row.title}
                                            </div>
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
                                        <TableCell className="text-right tabular-nums">
                                            {money(row.value, row.currency)}
                                        </TableCell>
                                        <TableCell className="tabular-nums">
                                            {dmy(row.signed_at)}
                                        </TableCell>
                                        <TableCell>
                                            <Deadline row={row} />
                                        </TableCell>
                                        <TableCell>
                                            <StatusBadge status={row.status} />
                                        </TableCell>
                                        {can.delete && (
                                            <TableCell className="text-right">
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-7 text-muted-foreground hover:text-red-600"
                                                    title="Șterge contractul"
                                                    onClick={() => {
                                                        if (
                                                            window.confirm(
                                                                `Ștergi ${row.number} cu totul, împreună cu fișierele lui?`,
                                                            )
                                                        ) {
                                                            router.delete(
                                                                contractDestroy(
                                                                    row.id,
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
                        {contracts.from ?? 0}–{contracts.to ?? 0} din{' '}
                        {contracts.total}
                        {!can.all && ' · contractele dumneavoastră'}
                    </span>
                    <div className="ml-auto flex gap-1">
                        {contracts.links.map((link, index) => (
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
