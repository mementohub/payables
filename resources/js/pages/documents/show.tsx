import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Archive,
    ArrowLeft,
    BellRing,
    CheckCheck,
    Download,
    Eye,
    FileSpreadsheet,
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
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import {
    archive as archiveRoute,
    ask as askRoute,
    consents as consentsRoute,
    destroy as destroyRoute,
    index as documentsIndex,
    publish as publishRoute,
    remind as remindRoute,
    show as documentShow,
    update as updateRoute,
} from '@/routes/documents';
import {
    download as downloadRoute,
    preview as previewRoute,
    store as fileStore,
} from '@/routes/documents/files';
import { STATUS_LABELS } from './types';
import type {
    CompanyDocument,
    DocumentFile,
    DocumentLabels,
    DocumentSlug,
    OcrField,
} from './types';

type Cost = {
    lei: number | null;
    usd: number | null;
    tokens_in: number;
    tokens_out: number;
    model: string | null;
    month_lei: number;
};

type Props = {
    type: DocumentSlug;
    labels: DocumentLabels;
    can: { manage: boolean; delete: boolean };
    ai: boolean;
    consentGroup: string;
    document: CompanyDocument;
    departments: { id: number; name: string }[];
    people: { id: number; name: string; email: string }[];
    others: { id: number; number: string; title: string }[];
};

const lei = (value: number) =>
    value < 0.1
        ? value.toLocaleString('ro-RO', { maximumFractionDigits: 4 })
        : value.toLocaleString('ro-RO', {
              minimumFractionDigits: 2,
              maximumFractionDigits: 2,
          });

const bani = (value: number) => value.toLocaleString('ro-RO');

const dmy = (value: string | null) =>
    value === null ? '—' : new Date(value).toLocaleDateString('ro-RO');

const when = (value: string | null) =>
    value === null
        ? '—'
        : new Date(value).toLocaleString('ro-RO', {
              dateStyle: 'short',
              timeStyle: 'short',
          });

/** Cât de sigură e mașina pe câmpul ăsta. */
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

export default function DocumentShow({
    type,
    labels,
    can,
    ai,
    consentGroup,
    document: paper,
    departments,
    people,
    others,
}: Props) {
    const uploader = useRef<HTMLInputElement>(null);
    const [previewing, setPreviewing] = useState<DocumentFile | null>(null);

    const form = useForm({
        number: paper.number,
        title: paper.title,
        object: paper.object ?? '',
        issuer: paper.issuer ?? '',
        department_id: paper.department_id ? String(paper.department_id) : '',
        owner_id: paper.owner_id ? String(paper.owner_id) : '',
        issued_at: paper.issued_at ?? '',
        effective_at: paper.effective_at ?? '',
        expires_at: paper.expires_at ?? '',
        status: paper.status,
        supersedes_id: paper.supersedes_id ? String(paper.supersedes_id) : '',
        notes: paper.notes ?? '',
    });

    const publish = useForm({
        // Adresele de grup ale companiei vin completate la amândouă; se pot
        // șterge, dacă hârtia asta pleacă numai către cineva anume.
        emails: consentGroup,
        note: '',
        due: paper.consent_due_at ?? '',
    });

    const reminder = useForm({});

    const version = useForm<{ files: File[]; kind: string; label: string }>({
        files: [],
        kind: 'annex',
        label: '',
    });
    const [adding, setAdding] = useState(false);

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.put(updateRoute({ type, document: paper.id }).url, {
            preserveScroll: true,
        });
    };

    const sendToCompany = (event: FormEvent) => {
        event.preventDefault();
        publish.post(publishRoute({ type, document: paper.id }).url, {
            preserveScroll: true,
            onSuccess: () => publish.reset('note'),
        });
    };

    /*
     * Programul trimite singur aducerea aminte cu o zi înainte de termen;
     * butonul ăsta e pentru hârtiile la care trebuie insistat, oricând
     * altcândva. Pleacă pe aceleași adrese ca hârtia.
     */
    const remindCompany = () => {
        reminder.post(remindRoute({ type, document: paper.id }).url, {
            preserveScroll: true,
        });
    };

    const sendVersion = (files: FileList | null) => {
        if (!files || files.length === 0) {
            return;
        }

        version.transform((data) => ({ ...data, files: Array.from(files) }));
        version.post(fileStore({ type, document: paper.id }).url, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                version.reset();
                setAdding(false);
            },
        });
    };

    const ocr = paper.ocr_fields;

    // Întrebare despre documentul ăsta: răspunsul vine din textul lui.
    const [question, setQuestion] = useState('');
    const [asking, setAsking] = useState(false);
    const [said, setSaid] = useState<string | null>(null);
    const [cost, setCost] = useState<Cost | null>(null);
    const [answers, setAnswers] = useState<
        | { text: string; score: number; words: string[]; document: string }[]
        | null
    >(null);

    const askDocument = async (event: FormEvent) => {
        event.preventDefault();

        if (question.trim().length < 3) {
            return;
        }

        setAsking(true);

        try {
            const response = await fetch(
                askRoute({ type, document: paper.id }).url,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': decodeURIComponent(
                            window.document.cookie
                                .split('; ')
                                .find((part) => part.startsWith('XSRF-TOKEN='))
                                ?.split('=')[1] ?? '',
                        ),
                    },
                    body: JSON.stringify({ question }),
                },
            );

            const data = await response.json();
            setSaid(data.answer ?? null);
            setCost(data.cost ?? null);
            setAnswers(data.answers ?? []);
        } catch {
            setSaid(null);
            setCost(null);
            setAnswers([]);
        } finally {
            setAsking(false);
        }
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: labels.many, href: documentsIndex(type).url },
                {
                    title: paper.number,
                    href: documentShow({ type, document: paper.id }).url,
                },
            ]}
        >
            <Head title={`${paper.number} · ${paper.title}`} />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center gap-3">
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={documentsIndex(type).url}>
                            <ArrowLeft className="size-4" />
                            Înapoi la {labels.many.toLowerCase()}
                        </Link>
                    </Button>
                    <div>
                        <div className="font-mono text-xs text-muted-foreground">
                            {paper.number}
                        </div>
                        <h1 className="text-lg font-semibold">{paper.title}</h1>
                    </div>
                    <Badge variant="outline">
                        {STATUS_LABELS[paper.status]}
                    </Badge>
                    {paper.archived && <Badge variant="outline">Arhivat</Badge>}
                    {paper.published_at !== null && (
                        <Badge variant="outline">
                            trimis {dmy(paper.published_at)}
                        </Badge>
                    )}
                    {can.manage && (
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
                                Adaugă fișier
                            </Button>
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        archiveRoute({
                                            type,
                                            document: paper.id,
                                        }).url,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <Archive className="size-4" />
                                {paper.archived
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
                                                `Ștergi ${paper.number} cu totul, împreună cu fișierele? Nu se mai poate scoate înapoi.`,
                                            )
                                        ) {
                                            router.delete(
                                                destroyRoute({
                                                    type,
                                                    document: paper.id,
                                                }).url,
                                            );
                                        }
                                    }}
                                >
                                    <Trash2 className="size-4" />
                                    Șterge
                                </Button>
                            )}
                        </div>
                    )}
                </div>

                {/*
                 * Pentru cel care doar citește: butonul cu care ia hârtia la
                 * cunoștință, chiar aici, fără să mai caute mailul.
                 */}
                {paper.needs_consent && (
                    <Card
                        className={cn(
                            'border-2',
                            paper.i_consented
                                ? 'border-emerald-600/40'
                                : 'border-amber-500/60',
                        )}
                    >
                        <CardContent className="flex flex-wrap items-center gap-4 p-4">
                            {paper.i_consented ? (
                                <>
                                    <CheckCheck className="size-6 text-emerald-600" />
                                    <div className="text-sm">
                                        <div className="font-medium">
                                            Ai luat{' '}
                                            {labels.article.toLowerCase()} la
                                            cunoștință.
                                        </div>
                                        <div className="text-muted-foreground">
                                            Confirmarea ți-a plecat pe mail și
                                            stă în registrul de mai jos.
                                        </div>
                                    </div>
                                </>
                            ) : (
                                <>
                                    <div className="text-sm">
                                        <div className="font-medium">
                                            {labels.article} asta trebuie citită
                                            și luată la cunoștință.
                                        </div>
                                        <div className="text-muted-foreground">
                                            {paper.consent_due_at
                                                ? `Termenul e ${dmy(paper.consent_due_at)}.`
                                                : 'Citește-o și confirmă.'}
                                        </div>
                                    </div>
                                    {paper.token !== null && (
                                        <Button className="ml-auto" asChild>
                                            <a href={paper.read_url ?? '#'}>
                                                Citește și confirmă
                                            </a>
                                        </Button>
                                    )}
                                </>
                            )}
                        </CardContent>
                    </Card>
                )}

                <div className="grid gap-4 lg:grid-cols-[1.4fr_1fr]">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Datele documentului
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={save} className="grid gap-3">
                                {Object.values(form.errors).length > 0 && (
                                    <p className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300">
                                        {Object.values(form.errors).join(' · ')}
                                    </p>
                                )}

                                <fieldset
                                    disabled={!can.manage}
                                    className="grid gap-3"
                                >
                                    <div className="grid gap-3 sm:grid-cols-[180px_1fr]">
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="number">
                                                Număr
                                                <Confidence
                                                    field={ocr.number}
                                                />
                                            </Label>
                                            <Input
                                                id="number"
                                                value={form.data.number}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'number',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="title">
                                                Titlu
                                                <Confidence field={ocr.title} />
                                            </Label>
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
                                    </div>

                                    <div className="grid gap-1.5">
                                        <Label htmlFor="object">
                                            Obiectul
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
                                            <Label htmlFor="issuer">
                                                Emitent
                                                <Confidence
                                                    field={ocr.issuer}
                                                />
                                            </Label>
                                            <Input
                                                id="issuer"
                                                value={form.data.issuer}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'issuer',
                                                        event.target.value,
                                                    )
                                                }
                                            />
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
                                                                key={
                                                                    department.id
                                                                }
                                                                value={String(
                                                                    department.id,
                                                                )}
                                                            >
                                                                {
                                                                    department.name
                                                                }
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label>Responsabil</Label>
                                            <Select
                                                value={
                                                    form.data.owner_id || 'none'
                                                }
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

                                    <div className="grid gap-3 sm:grid-cols-4">
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="issued_at">
                                                Emisă la
                                                <Confidence
                                                    field={ocr.issued_at}
                                                />
                                            </Label>
                                            <Input
                                                id="issued_at"
                                                type="date"
                                                value={form.data.issued_at}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'issued_at',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="effective_at">
                                                În vigoare din
                                                <Confidence
                                                    field={ocr.effective_at}
                                                />
                                            </Label>
                                            <Input
                                                id="effective_at"
                                                type="date"
                                                value={form.data.effective_at}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'effective_at',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="expires_at">
                                                Valabilă până la
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
                                            <Label>Stare</Label>
                                            <Select
                                                value={form.data.status}
                                                onValueChange={(value) =>
                                                    form.setData(
                                                        'status',
                                                        value as CompanyDocument['status'],
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

                                    <div className="grid gap-1.5">
                                        <Label>
                                            Înlocuiește
                                            <span className="ml-2 text-xs font-normal text-muted-foreground">
                                                hârtia veche trece singură în
                                                „înlocuită”
                                            </span>
                                        </Label>
                                        <Select
                                            value={
                                                form.data.supersedes_id ||
                                                'none'
                                            }
                                            onValueChange={(value) =>
                                                form.setData(
                                                    'supersedes_id',
                                                    value === 'none'
                                                        ? ''
                                                        : value,
                                                )
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="—" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="none">
                                                    —
                                                </SelectItem>
                                                {others.map((other) => (
                                                    <SelectItem
                                                        key={other.id}
                                                        value={String(other.id)}
                                                    >
                                                        {other.number} ·{' '}
                                                        {other.title}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div className="grid gap-1.5">
                                        <Label htmlFor="notes">
                                            Observații
                                        </Label>
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
                                </fieldset>

                                {can.manage ? (
                                    <div>
                                        <Button
                                            type="submit"
                                            disabled={form.processing}
                                        >
                                            {form.processing && (
                                                <Loader2 className="size-4 animate-spin" />
                                            )}
                                            Salvează
                                        </Button>
                                    </div>
                                ) : (
                                    <p className="text-xs text-muted-foreground">
                                        Registrul se citește de toată lumea;
                                        datele le schimbă cine ține documentele.
                                    </p>
                                )}
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
                                {adding && can.manage && (
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
                                                    <SelectItem value="annex">
                                                        Anexă
                                                    </SelectItem>
                                                    <SelectItem value="document">
                                                        Altă versiune a
                                                        documentului
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        {version.data.kind === 'annex' && (
                                            <div className="grid gap-1.5">
                                                <Label htmlFor="annex-label">
                                                    Cum se cheamă anexa
                                                </Label>
                                                <Input
                                                    id="annex-label"
                                                    placeholder="nr. 1 — formular de predare"
                                                    value={version.data.label}
                                                    onChange={(event) =>
                                                        version.setData(
                                                            'label',
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                            </div>
                                        )}
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
                                            Numărul, data și emitentul se citesc
                                            din document — nu le scrie nimeni cu
                                            mâna.
                                        </p>
                                    </div>
                                )}
                                {paper.files.length === 0 && (
                                    <p className="text-sm text-muted-foreground">
                                        Niciun fișier încă.
                                    </p>
                                )}
                                {paper.files.map((file) => (
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
                                                        type,
                                                        document: paper.id,
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

                        {can.manage && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Trimite spre luare la cunoștință
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <form
                                        onSubmit={sendToCompany}
                                        className="grid gap-2"
                                    >
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="publish-emails">
                                                Către
                                            </Label>
                                            <Input
                                                id="publish-emails"
                                                placeholder="adrese, despărțite prin virgulă"
                                                value={publish.data.emails}
                                                onChange={(event) =>
                                                    publish.setData(
                                                        'emails',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="publish-due">
                                                De citit până la
                                            </Label>
                                            <Input
                                                id="publish-due"
                                                type="date"
                                                className="w-44"
                                                value={publish.data.due}
                                                onChange={(event) =>
                                                    publish.setData(
                                                        'due',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <Textarea
                                            rows={2}
                                            placeholder="un rând de însoțire, dacă vrei"
                                            value={publish.data.note}
                                            onChange={(event) =>
                                                publish.setData(
                                                    'note',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <Button
                                            type="submit"
                                            disabled={publish.processing}
                                        >
                                            {publish.processing ? (
                                                <Loader2 className="size-4 animate-spin" />
                                            ) : (
                                                <Send className="size-4" />
                                            )}
                                            {paper.published_at === null
                                                ? 'Trimite'
                                                : 'Trimite din nou'}
                                        </Button>
                                        {Object.values(publish.errors).length >
                                            0 && (
                                            <p className="text-xs text-red-600">
                                                {Object.values(
                                                    publish.errors,
                                                ).join(' · ')}
                                            </p>
                                        )}
                                    </form>
                                    <p className="mt-2 text-xs text-muted-foreground">
                                        Pleacă un mail cu o legătură: cine o
                                        deschide citește{' '}
                                        {labels.article.toLowerCase()} și apasă
                                        „am citit și am luat la cunoștință”,
                                        scriindu-și numele și adresa de
                                        serviciu. N-are nevoie de cont aici, iar
                                        confirmarea îi vine pe mail.
                                    </p>
                                    {paper.sent_to && (
                                        <p className="mt-2 text-xs text-muted-foreground">
                                            Ultima oară a plecat către{' '}
                                            <b>{paper.sent_to}</b>, pe{' '}
                                            {when(paper.published_at)}.
                                        </p>
                                    )}
                                    {paper.needs_consent && (
                                        <div className="mt-3 border-t pt-3">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                disabled={reminder.processing}
                                                onClick={remindCompany}
                                            >
                                                {reminder.processing ? (
                                                    <Loader2 className="size-4 animate-spin" />
                                                ) : (
                                                    <BellRing className="size-4" />
                                                )}
                                                Adu aminte acum
                                            </Button>
                                            <p className="mt-2 text-xs text-muted-foreground">
                                                {paper.consent_reminded_at
                                                    ? `Ultima aducere aminte a plecat pe ${when(paper.consent_reminded_at)}.`
                                                    : 'N-a plecat încă nicio aducere aminte.'}{' '}
                                                Cu o zi înainte de termen pleacă
                                                oricum una singură.
                                            </p>
                                        </div>
                                    )}
                                    {paper.read_url && (
                                        <p className="mt-2 text-xs break-all text-muted-foreground">
                                            Legătura din mail:{' '}
                                            <a
                                                href={paper.read_url}
                                                className="underline"
                                            >
                                                {paper.read_url}
                                            </a>
                                        </p>
                                    )}
                                </CardContent>
                            </Card>
                        )}

                        <Card>
                            <CardHeader className="flex-row items-center justify-between gap-2 space-y-0">
                                <CardTitle className="text-base">
                                    Au luat la cunoștință (
                                    {paper.consents.length})
                                </CardTitle>
                                {can.manage && paper.consents.length > 0 && (
                                    <Button variant="outline" size="sm" asChild>
                                        <a
                                            href={
                                                consentsRoute({
                                                    type,
                                                    document: paper.id,
                                                }).url
                                            }
                                        >
                                            <FileSpreadsheet className="size-4" />
                                            xlsx
                                        </a>
                                    </Button>
                                )}
                            </CardHeader>
                            <CardContent>
                                {paper.consents.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        {paper.needs_consent
                                            ? 'N-a confirmat nimeni încă.'
                                            : `${labels.article} n-a plecat încă spre companie.`}
                                    </p>
                                ) : (
                                    <ul className="grid max-h-80 gap-1 overflow-auto text-sm">
                                        {paper.consents.map((one) => (
                                            <li
                                                key={one.id}
                                                className="flex flex-wrap items-baseline gap-x-2 border-b pb-1 last:border-0"
                                            >
                                                <span className="font-medium">
                                                    {one.name}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    {one.email}
                                                </span>
                                                <span className="ml-auto text-xs text-muted-foreground tabular-nums">
                                                    {when(one.at)}
                                                </span>
                                                {/*
                                                 * Nimic nu se refuză și nimic
                                                 * nu se șterge: rândul doar
                                                 * spune de ce ar fi de privit
                                                 * încă o dată, înainte ca
                                                 * numărul să fie luat de bun.
                                                 */}
                                                {(one.twin || !one.matched) && (
                                                    <span className="w-full text-xs text-amber-600">
                                                        {one.twin
                                                            ? `de verificat: același nume ca ${one.twin}`
                                                            : 'de verificat: adresă fără cont în aplicație'}
                                                    </span>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Întreabă documentul
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <form
                                    onSubmit={askDocument}
                                    className="flex gap-2"
                                >
                                    <Input
                                        value={question}
                                        onChange={(event) =>
                                            setQuestion(event.target.value)
                                        }
                                        placeholder="cine aprobă? în cât timp?"
                                    />
                                    <Button type="submit" disabled={asking}>
                                        {asking ? (
                                            <Loader2 className="size-4 animate-spin" />
                                        ) : (
                                            <Search className="size-4" />
                                        )}
                                    </Button>
                                </form>

                                {said !== null && (
                                    <p className="mt-3 rounded-md border border-primary/30 bg-primary/5 p-3 text-sm whitespace-pre-line">
                                        {said}
                                    </p>
                                )}

                                {cost !== null && (
                                    <p className="mt-2 text-xs text-muted-foreground">
                                        {cost.lei === null
                                            ? `${bani(cost.tokens_in + cost.tokens_out)} token — prețul modelului ${cost.model ?? ''} nu e trecut în config`
                                            : `Întrebarea asta: ${lei(cost.lei)} lei · luna asta, cu tot cu ceilalți agenți: ${lei(cost.month_lei)} lei`}
                                    </p>
                                )}

                                {answers !== null &&
                                    answers.length === 0 &&
                                    said === null && (
                                        <p className="mt-3 text-sm text-muted-foreground">
                                            Nu am găsit nimic despre asta în
                                            textul documentului.
                                        </p>
                                    )}

                                {answers !== null && answers.length > 0 && (
                                    <>
                                        {said !== null && (
                                            <p className="mt-3 text-xs font-medium text-muted-foreground">
                                                Rândurile pe care se sprijină
                                            </p>
                                        )}
                                        <ul className="mt-2 grid gap-2">
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
                                    </>
                                )}

                                <p className="mt-3 text-xs text-muted-foreground">
                                    {ai
                                        ? 'Răspunsul îl scrie agentul, numai din textul documentului, cu rândurile arătate dedesubt. Pentru asta, textul ajunge la furnizorul de model.'
                                        : 'Răspunsul e chiar textul documentului — rândurile care vorbesc despre ce ai întrebat. Nimic nu pleacă de pe server.'}
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
                                    {paper.events.map((event) => (
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
                                                type,
                                                document: paper.id,
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
                        <Preview
                            type={type}
                            document={paper.id}
                            file={previewing}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

/**
 * Ce se poate arăta în fereastră: PDF-urile și pozele, pe loc. Un Word nu se
 * poate deschide în browser, așa că serverul face din el o pagină.
 */
function Preview({
    type,
    document: paper,
    file,
}: {
    type: DocumentSlug;
    document: number;
    file: DocumentFile;
}) {
    const url = previewRoute({ type, document: paper, file: file.id }).url;
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

    if (/\.docx?$/.test(name)) {
        return (
            <iframe
                src={url}
                title={file.name}
                sandbox=""
                className="min-h-0 flex-1 rounded-md border bg-white"
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
