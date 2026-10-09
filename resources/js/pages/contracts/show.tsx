import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Archive,
    ArrowLeft,
    Download,
    Eye,
    FileText,
    Loader2,
    Search,
    Send,
    Trash2,
    Upload,
} from 'lucide-react';
import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import {
    archive as archiveRoute,
    ask as askRoute,
    destroy as destroyRoute,
    index as contractsIndex,
    share as shareRoute,
    show as contractShow,
    update as updateRoute,
} from '@/routes/contracts';
import {
    download as downloadRoute,
    preview as previewRoute,
    store as fileStore,
} from '@/routes/contracts/files';
import { KIND_LABELS, STATUS_LABELS } from './types';
import type {
    Contract,
    ContractFile as ContractFileRow,
    OcrField,
} from './types';

type Props = {
    can: { delete: boolean };
    contract: Contract;
    departments: { id: number; name: string }[];
    people: { id: number; name: string; email: string }[];
};

/** Cât ține, din capul locului, legătura trimisă pe mail. */
const defaultShareDays = 15;

const dmy = (value: string | null) =>
    value === null ? '—' : new Date(value).toLocaleDateString('ro-RO');

const when = (value: string | null) =>
    value === null
        ? '—'
        : new Date(value).toLocaleString('ro-RO', {
              dateStyle: 'short',
              timeStyle: 'short',
          });

/**
 * Cât de sigură e mașina pe câmpul ăsta. Sub 85% se arată galben: nu e greșit
 * neapărat, dar merită o privire.
 */
function Confidence({ field }: { field: OcrField | undefined }) {
    if (field === undefined) {
        return null;
    }

    const percent = Math.round(field.confidence * 100);
    const tone =
        field.confidence >= 0.85
            ? 'border-emerald-600/40 text-emerald-700 dark:text-emerald-300'
            : field.confidence >= 0.7
              ? 'border-amber-600/40 text-amber-700 dark:text-amber-300'
              : 'border-red-600/40 text-red-700 dark:text-red-300';

    return (
        <Badge
            variant="outline"
            className={cn('ml-2 text-[10px]', tone)}
            title={field.source ?? undefined}
        >
            citit {percent}%
        </Badge>
    );
}

export default function ContractShow({
    can,
    contract,
    departments,
    people,
}: Props) {
    const uploader = useRef<HTMLInputElement>(null);
    // Contractul se citește în fereastră, nu se descarcă de fiecare dată.
    const [previewing, setPreviewing] = useState<ContractFileRow | null>(null);

    const form = useForm({
        title: contract.title,
        partner_name: contract.partner_name,
        partner_tax_id: contract.partner_tax_id ?? '',
        kind: contract.kind,
        object: contract.object ?? '',
        department_id: contract.department_id
            ? String(contract.department_id)
            : '',
        owner_id: contract.owner_id ? String(contract.owner_id) : '',
        value: contract.value !== null ? String(contract.value) : '',
        currency: contract.currency ?? 'RON',
        signed_at: contract.signed_at ?? '',
        starts_at: contract.starts_at ?? '',
        expires_at: contract.expires_at ?? '',
        notice_days:
            contract.notice_days !== null ? String(contract.notice_days) : '',
        auto_renew: contract.auto_renew,
        payment_terms: contract.payment_terms ?? '',
        governing_law: contract.governing_law ?? '',
        status: contract.status,
        notes: contract.notes ?? '',
    });

    const share = useForm({
        emails: '',
        permission: 'view',
        days: String(defaultShareDays),
        note: '',
    });
    const version = useForm<{ files: File[]; kind: string }>({
        files: [],
        kind: 'addendum',
    });
    // Ce se adaugă la un contract e, de obicei, un act adițional — nu altă
    // versiune a aceluiași document.
    const [adding, setAdding] = useState(false);

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.put(updateRoute(contract.id).url, { preserveScroll: true });
    };

    const sendShare = (event: FormEvent) => {
        event.preventDefault();
        share.post(shareRoute(contract.id).url, {
            preserveScroll: true,
            onSuccess: () => share.reset('emails'),
        });
    };

    const sendVersion = (files: FileList | null) => {
        if (!files || files.length === 0) {
            return;
        }

        // Ca la încărcarea din repertoriu: fișierele se dau trimiterii, fiindcă
        // starea formularului se vede abia la randarea următoare.
        version.transform((data) => ({ ...data, files: Array.from(files) }));
        version.post(fileStore(contract.id).url, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                version.reset();
                setAdding(false);
            },
        });
    };

    const ocr = contract.ocr_fields;

    // Întrebare despre contractul ăsta: răspunsul vine din textul lui, nu
    // dintr-o părere.
    const [question, setQuestion] = useState('');
    const [asking, setAsking] = useState(false);
    const [answers, setAnswers] = useState<
        | { text: string; score: number; words: string[]; document: string }[]
        | null
    >(null);

    const askContract = async (event: FormEvent) => {
        event.preventDefault();

        if (question.trim().length < 3) {
            return;
        }

        setAsking(true);

        try {
            const response = await fetch(askRoute(contract.id).url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(
                        document.cookie
                            .split('; ')
                            .find((part) => part.startsWith('XSRF-TOKEN='))
                            ?.split('=')[1] ?? '',
                    ),
                },
                body: JSON.stringify({ question }),
            });

            const data = await response.json();
            setAnswers(data.answers ?? []);
        } catch {
            setAnswers([]);
        } finally {
            setAsking(false);
        }
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Contracte', href: contractsIndex().url },
                { title: contract.number, href: contractShow(contract.id).url },
            ]}
        >
            <Head title={`${contract.number} · ${contract.partner_name}`} />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center gap-3">
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={contractsIndex().url}>
                            <ArrowLeft className="size-4" />
                            Înapoi la repertoriu
                        </Link>
                    </Button>
                    <div>
                        <div className="font-mono text-xs text-muted-foreground">
                            {contract.number}
                        </div>
                        <h1 className="text-lg font-semibold">
                            {contract.title}
                        </h1>
                    </div>
                    <Badge variant="outline">
                        {STATUS_LABELS[contract.status]}
                    </Badge>
                    {contract.archived && (
                        <Badge variant="outline">Arhivat</Badge>
                    )}
                    <div className="ml-auto flex gap-2">
                        <input
                            ref={uploader}
                            type="file"
                            multiple
                            accept=".pdf,.doc,.docx,.odt,.rtf,.txt,.png,.jpg,.jpeg,.tif,.tiff,.webp,.heic"
                            hidden
                            onChange={(event) => {
                                sendVersion(event.target.files);
                                event.target.value = '';
                            }}
                        />
                        <Button
                            variant="outline"
                            onClick={() => setAdding(!adding)}
                            disabled={version.processing}
                        >
                            {version.processing ? (
                                <Loader2 className="size-4 animate-spin" />
                            ) : (
                                <Upload className="size-4" />
                            )}
                            Adaugă document
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    archiveRoute(contract.id).url,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <Archive className="size-4" />
                            {contract.archived
                                ? 'Scoate din arhivă'
                                : 'Arhivează'}
                        </Button>
                        {can.delete && (
                            <Button
                                variant="outline"
                                className="text-red-600 hover:text-red-700 dark:text-red-400"
                                onClick={() => {
                                    if (
                                        window.confirm(
                                            `Ștergi contractul ${contract.number} cu totul, împreună cu fișierele lui? Nu se mai poate scoate înapoi.`,
                                        )
                                    ) {
                                        router.delete(
                                            destroyRoute(contract.id).url,
                                        );
                                    }
                                }}
                            >
                                <Trash2 className="size-4" />
                                Șterge
                            </Button>
                        )}
                    </div>
                </div>

                <div className="grid gap-4 lg:grid-cols-[1.4fr_1fr]">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Datele contractului
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={save} className="grid gap-3">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="title">Titlu</Label>
                                    <Input
                                        id="title"
                                        value={form.data.title}
                                        onChange={(event) =>
                                            form.setData(
                                                'title',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>

                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="partner_name">
                                            Partener
                                            <Confidence
                                                field={ocr.partner_name}
                                            />
                                        </Label>
                                        <Input
                                            id="partner_name"
                                            value={form.data.partner_name}
                                            onChange={(event) =>
                                                form.setData(
                                                    'partner_name',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="partner_tax_id">
                                            CUI
                                            <Confidence
                                                field={ocr.partner_tax_id}
                                            />
                                        </Label>
                                        <Input
                                            id="partner_tax_id"
                                            value={form.data.partner_tax_id}
                                            onChange={(event) =>
                                                form.setData(
                                                    'partner_tax_id',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="object">
                                        Obiectul contractului
                                        <Confidence field={ocr.object} />
                                    </Label>
                                    <Textarea
                                        id="object"
                                        rows={3}
                                        value={form.data.object}
                                        onChange={(event) =>
                                            form.setData(
                                                'object',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>

                                <div className="grid gap-3 sm:grid-cols-3">
                                    <div className="grid gap-1.5">
                                        <Label>Tip</Label>
                                        <Select
                                            value={form.data.kind}
                                            onValueChange={(value) =>
                                                form.setData(
                                                    'kind',
                                                    value as Contract['kind'],
                                                )
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {Object.entries(
                                                    KIND_LABELS,
                                                ).map(([value, label]) => (
                                                    <SelectItem
                                                        key={value}
                                                        value={value}
                                                    >
                                                        {label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label>Departament</Label>
                                        <Select
                                            value={
                                                form.data.department_id ||
                                                'none'
                                            }
                                            onValueChange={(value) =>
                                                form.setData(
                                                    'department_id',
                                                    value === 'none'
                                                        ? ''
                                                        : value,
                                                )
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="none">
                                                    —
                                                </SelectItem>
                                                {departments.map(
                                                    (department) => (
                                                        <SelectItem
                                                            key={department.id}
                                                            value={String(
                                                                department.id,
                                                            )}
                                                        >
                                                            {department.name}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label>Responsabil</Label>
                                        <Select
                                            value={form.data.owner_id || 'none'}
                                            onValueChange={(value) =>
                                                form.setData(
                                                    'owner_id',
                                                    value === 'none'
                                                        ? ''
                                                        : value,
                                                )
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="none">
                                                    —
                                                </SelectItem>
                                                {people.map((person) => (
                                                    <SelectItem
                                                        key={person.id}
                                                        value={String(
                                                            person.id,
                                                        )}
                                                    >
                                                        {person.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>

                                <div className="grid gap-3 sm:grid-cols-3">
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="value">
                                            Valoare
                                            <Confidence field={ocr.value} />
                                        </Label>
                                        <Input
                                            id="value"
                                            value={form.data.value}
                                            onChange={(event) =>
                                                form.setData(
                                                    'value',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="currency">Moneda</Label>
                                        <Input
                                            id="currency"
                                            maxLength={3}
                                            value={form.data.currency}
                                            onChange={(event) =>
                                                form.setData(
                                                    'currency',
                                                    event.target.value.toUpperCase(),
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label>Stare</Label>
                                        <Select
                                            value={form.data.status}
                                            onValueChange={(value) =>
                                                form.setData(
                                                    'status',
                                                    value as Contract['status'],
                                                )
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {Object.entries(
                                                    STATUS_LABELS,
                                                ).map(([value, label]) => (
                                                    <SelectItem
                                                        key={value}
                                                        value={value}
                                                    >
                                                        {label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>

                                <div className="grid gap-3 sm:grid-cols-4">
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="signed_at">
                                            Semnat
                                            <Confidence field={ocr.signed_at} />
                                        </Label>
                                        <Input
                                            id="signed_at"
                                            type="date"
                                            value={form.data.signed_at}
                                            onChange={(event) =>
                                                form.setData(
                                                    'signed_at',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="starts_at">
                                            În vigoare din
                                        </Label>
                                        <Input
                                            id="starts_at"
                                            type="date"
                                            value={form.data.starts_at}
                                            onChange={(event) =>
                                                form.setData(
                                                    'starts_at',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="expires_at">
                                            Expiră
                                            <Confidence
                                                field={ocr.expires_at}
                                            />
                                        </Label>
                                        <Input
                                            id="expires_at"
                                            type="date"
                                            value={form.data.expires_at}
                                            onChange={(event) =>
                                                form.setData(
                                                    'expires_at',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="notice_days">
                                            Preaviz (zile)
                                            <Confidence
                                                field={ocr.notice_days}
                                            />
                                        </Label>
                                        <Input
                                            id="notice_days"
                                            value={form.data.notice_days}
                                            onChange={(event) =>
                                                form.setData(
                                                    'notice_days',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                </div>

                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="payment_terms">
                                            Termen de plată
                                            <Confidence
                                                field={ocr.payment_terms}
                                            />
                                        </Label>
                                        <Input
                                            id="payment_terms"
                                            value={form.data.payment_terms}
                                            onChange={(event) =>
                                                form.setData(
                                                    'payment_terms',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="governing_law">
                                            Lege aplicabilă
                                            <Confidence
                                                field={ocr.governing_law}
                                            />
                                        </Label>
                                        <Input
                                            id="governing_law"
                                            value={form.data.governing_law}
                                            onChange={(event) =>
                                                form.setData(
                                                    'governing_law',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                </div>

                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={form.data.auto_renew}
                                        onChange={(event) =>
                                            form.setData(
                                                'auto_renew',
                                                event.target.checked,
                                            )
                                        }
                                    />
                                    Se reînnoiește tacit
                                </label>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="notes">Observații</Label>
                                    <Textarea
                                        id="notes"
                                        rows={2}
                                        value={form.data.notes}
                                        onChange={(event) =>
                                            form.setData(
                                                'notes',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>

                                <div className="flex items-center gap-3">
                                    <Button
                                        type="submit"
                                        disabled={form.processing}
                                    >
                                        {form.processing && (
                                            <Loader2 className="size-4 animate-spin" />
                                        )}
                                        Salvează
                                    </Button>
                                    {contract.notice_on && (
                                        <span className="text-sm text-muted-foreground">
                                            Preavizul trebuie dat până la{' '}
                                            <b>{dmy(contract.notice_on)}</b>.
                                        </span>
                                    )}
                                </div>
                            </form>
                        </CardContent>
                    </Card>

                    <div className="flex flex-col gap-4">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Fișiere
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-2">
                                {adding && (
                                    <div className="grid gap-2 rounded-md border bg-muted/30 p-3">
                                        <div className="grid gap-1.5">
                                            <Label>Ce încarci</Label>
                                            <Select
                                                value={version.data.kind}
                                                onValueChange={(value) =>
                                                    version.setData(
                                                        'kind',
                                                        value,
                                                    )
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="addendum">
                                                        Act adițional
                                                    </SelectItem>
                                                    <SelectItem value="annex">
                                                        Anexă
                                                    </SelectItem>
                                                    <SelectItem value="contract">
                                                        Altă versiune a
                                                        contractului
                                                    </SelectItem>
                                                    <SelectItem value="other">
                                                        Alt document
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <Button
                                            onClick={() =>
                                                uploader.current?.click()
                                            }
                                            disabled={version.processing}
                                        >
                                            <Upload className="size-4" />
                                            Alege fișierele
                                        </Button>
                                        <p className="text-xs text-muted-foreground">
                                            Numărul și data semnării se citesc
                                            din fiecare document — nu le scrie
                                            nimeni cu mâna. Se pot alege mai
                                            multe deodată. Actul adițional nu
                                            înlocuiește contractul: stă lângă
                                            el, se caută odată cu el, iar ce
                                            schimbă (termen, valoare) se scrie
                                            în jurnal, ca propunere.
                                        </p>
                                    </div>
                                )}
                                {contract.files.map((file) => (
                                    <div
                                        key={file.id}
                                        className="flex items-start gap-2 rounded-md border p-2 text-sm"
                                    >
                                        <FileText className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                        <div className="min-w-0 flex-1">
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setPreviewing(file)
                                                }
                                                className="block w-full truncate text-left font-medium hover:underline"
                                                title="Deschide documentul"
                                            >
                                                {file.title} · {file.name}
                                            </button>
                                            <div className="text-xs text-muted-foreground">
                                                {file.signed_at
                                                    ? `semnat ${new Date(file.signed_at).toLocaleDateString('ro-RO')} · `
                                                    : ''}
                                                {Math.round(file.size / 1024)}{' '}
                                                KB
                                                {file.pages
                                                    ? ` · ${file.pages} pagini`
                                                    : ''}
                                                {' · '}
                                                {file.ocr_status === 'done' &&
                                                    `citit cu ${file.ocr_engine}`}
                                                {file.ocr_status ===
                                                    'pending' && 'se citește…'}
                                                {file.ocr_status === 'failed' &&
                                                    `necitit: ${file.ocr_error ?? 'eroare'}`}
                                                {file.ocr_status ===
                                                    'skipped' &&
                                                    'citire oprită'}
                                            </div>
                                        </div>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="size-7"
                                            onClick={() => setPreviewing(file)}
                                            title="Vezi"
                                        >
                                            <Eye className="size-4" />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="size-7"
                                            asChild
                                            title="Descarcă"
                                        >
                                            <a
                                                href={
                                                    downloadRoute({
                                                        contract: contract.id,
                                                        file: file.id,
                                                    }).url
                                                }
                                            >
                                                <Download className="size-4" />
                                            </a>
                                        </Button>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Trimite contractul
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <form
                                    onSubmit={sendShare}
                                    className="grid gap-2"
                                >
                                    <Input
                                        placeholder="adrese, separate prin virgulă"
                                        value={share.data.emails}
                                        onChange={(event) =>
                                            share.setData(
                                                'emails',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <Textarea
                                        rows={2}
                                        placeholder="un rând de însoțire, dacă vrei"
                                        value={share.data.note}
                                        onChange={(event) =>
                                            share.setData(
                                                'note',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <div className="flex flex-wrap items-end gap-2">
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="share-permission">
                                                Ce poate face
                                            </Label>
                                            <Select
                                                value={share.data.permission}
                                                onValueChange={(value) =>
                                                    share.setData(
                                                        'permission',
                                                        value,
                                                    )
                                                }
                                            >
                                                <SelectTrigger
                                                    id="share-permission"
                                                    className="w-40"
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="view">
                                                        Doar citire
                                                    </SelectItem>
                                                    <SelectItem value="comment">
                                                        Comentariu
                                                    </SelectItem>
                                                    <SelectItem value="edit">
                                                        Editare
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="share-days">
                                                Legătura ține (zile)
                                            </Label>
                                            <Input
                                                id="share-days"
                                                className="w-28"
                                                inputMode="numeric"
                                                value={share.data.days}
                                                onChange={(event) =>
                                                    share.setData(
                                                        'days',
                                                        event.target.value,
                                                    )
                                                }
                                                title="După atâtea zile, legătura din mail nu mai deschide contractul."
                                            />
                                        </div>
                                        <Button
                                            type="submit"
                                            disabled={share.processing}
                                        >
                                            <Send className="size-4" />
                                            Trimite
                                        </Button>
                                    </div>
                                </form>
                                <p className="mt-2 text-xs text-muted-foreground">
                                    Pleacă un e-mail cu o legătură către
                                    contract — fișierul nu se atașează. Se vede
                                    cine a deschis și când, iar după zilele puse
                                    mai sus legătura nu mai deschide nimic.
                                </p>
                                {contract.shares.length > 0 && (
                                    <ul className="mt-3 grid gap-1 text-xs text-muted-foreground">
                                        {contract.shares.map((entry) => (
                                            <li key={entry.id}>
                                                {entry.email} ·{' '}
                                                {entry.permission} ·{' '}
                                                {entry.opened_at
                                                    ? `deschis de ${entry.opens} ori`
                                                    : 'nedeschis încă'}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Întreabă contractul
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <form
                                    onSubmit={askContract}
                                    className="flex gap-2"
                                >
                                    <Input
                                        value={question}
                                        onChange={(event) =>
                                            setQuestion(event.target.value)
                                        }
                                        placeholder="ce scrie despre penalități? cum se reziliază?"
                                    />
                                    <Button type="submit" disabled={asking}>
                                        {asking ? (
                                            <Loader2 className="size-4 animate-spin" />
                                        ) : (
                                            <Search className="size-4" />
                                        )}
                                    </Button>
                                </form>

                                {answers !== null && answers.length === 0 && (
                                    <p className="mt-3 text-sm text-muted-foreground">
                                        Nu am găsit nimic despre asta în textul
                                        contractului.
                                    </p>
                                )}

                                {answers !== null && answers.length > 0 && (
                                    <ul className="mt-3 grid gap-2">
                                        {answers.map((answer, index) => (
                                            <li
                                                key={index}
                                                className="rounded-md border bg-muted/30 p-2 text-sm"
                                            >
                                                <div className="mb-1 text-xs text-muted-foreground">
                                                    {answer.document}
                                                </div>
                                                {answer.text}
                                            </li>
                                        ))}
                                    </ul>
                                )}

                                <p className="mt-3 text-xs text-muted-foreground">
                                    Răspunsul e chiar textul contractului —
                                    clauzele care vorbesc despre ce ai întrebat.
                                    Nimic nu pleacă de pe server.
                                </p>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Jurnal
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <ul className="grid gap-2 text-sm">
                                    {contract.events.map((event) => (
                                        <li key={event.id}>
                                            <div className="text-xs text-muted-foreground">
                                                {when(event.at)}
                                                {event.user
                                                    ? ` · ${event.user}`
                                                    : ''}
                                            </div>
                                            <div>
                                                <b>{event.type}</b>
                                                {event.body
                                                    ? ` — ${event.body}`
                                                    : ''}
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>

            <Dialog
                open={previewing !== null}
                onOpenChange={(open) => !open && setPreviewing(null)}
            >
                <DialogContent className="flex h-[90vh] flex-col gap-3 sm:max-w-5xl">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-3 pr-8 text-base">
                            <span className="truncate">{previewing?.name}</span>
                            {previewing && (
                                <Button variant="outline" size="sm" asChild>
                                    <a
                                        href={
                                            downloadRoute({
                                                contract: contract.id,
                                                file: previewing.id,
                                            }).url
                                        }
                                    >
                                        <Download className="size-4" />
                                        Descarcă
                                    </a>
                                </Button>
                            )}
                        </DialogTitle>
                    </DialogHeader>
                    {previewing && (
                        <Preview contract={contract.id} file={previewing} />
                    )}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

/**
 * Ce se poate arăta în fereastră: PDF-urile și pozele, pe loc. Un Word nu se
 * poate deschide în browser, așa că se arată textul citit din el — tot ce
 * trebuie ca să vezi despre ce e vorba fără să-l descarci.
 */
function Preview({
    contract,
    file,
}: {
    contract: number;
    file: ContractFileRow;
}) {
    const url = previewRoute({ contract, file: file.id }).url;
    const name = file.name.toLowerCase();

    if (name.endsWith('.pdf')) {
        return (
            <iframe
                src={url}
                title={file.name}
                className="min-h-0 flex-1 rounded-md border"
            />
        );
    }

    if (/\.(png|jpe?g|gif|webp|tiff?)$/.test(name)) {
        return (
            <div className="min-h-0 flex-1 overflow-auto rounded-md border bg-muted/30 p-2">
                <img src={url} alt={file.name} className="mx-auto" />
            </div>
        );
    }

    return (
        <div className="min-h-0 flex-1 overflow-auto rounded-md border p-4">
            {file.has_text ? (
                <pre className="font-sans text-sm whitespace-pre-wrap">
                    {file.text ?? ''}
                </pre>
            ) : (
                <p className="text-sm text-muted-foreground">
                    Fișierul nu se poate arăta în browser și n-are încă text
                    citit. Descarcă-l ca să-l vezi.
                </p>
            )}
        </div>
    );
}
