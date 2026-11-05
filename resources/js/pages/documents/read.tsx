import { Head, useForm } from '@inertiajs/react';
import { CheckCheck, Download, FileText, Loader2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { consent as consentRoute } from '@/routes/documents';
import type { DocumentLabels } from './types';

type Props = {
    token: string;
    labels: DocumentLabels;
    needsConsent: boolean;
    document: {
        number: string;
        title: string;
        object: string | null;
        issuer: string | null;
        issued_at: string | null;
        effective_at: string | null;
        consent_due_at: string | null;
        files: {
            id: number;
            title: string;
            kind_label: string;
            name: string;
            size: number;
            text: string | null;
        }[];
    };
    /** Omul, dacă are cont și e autentificat: nu-și mai scrie numele. */
    me: { name: string; email: string } | null;
    /** Luarea lui la cunoștință, dacă a apăsat deja. */
    mine: { name: string; email: string; at: string | null } | null;
    consents: number;
};

/** Cheia sub care browserul ține minte cine a apăsat, pe telefonul lui. */
const WHO = 'luare-la-cunostinta:cine';

/**
 * Cine are cont e știut; cine n-are și-a scris numele data trecută, iar
 * procedurile vin una după alta. Îi ținem minte numele și adresa chiar în
 * browserul lui — la noi nu ajung decât atunci când apasă.
 */
const who = (me: { name: string; email: string } | null) => {
    if (me) {
        return { name: me.name, email: me.email };
    }

    try {
        const saved = JSON.parse(
            window.localStorage.getItem(WHO) ?? 'null',
        ) as { name?: string; email?: string } | null;

        return {
            name: typeof saved?.name === 'string' ? saved.name : '',
            email: typeof saved?.email === 'string' ? saved.email : '',
        };
    } catch {
        // Browser cu memoria închisă: scrie de mână, ca prima oară.
        return { name: '', email: '' };
    }
};

const remember = (name: string, email: string) => {
    try {
        window.localStorage.setItem(WHO, JSON.stringify({ name, email }));
    } catch {
        // N-are unde: nu-i nimic de făcut și nici de spus.
    }
};

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
 * Documentul deschis din legătura primită pe mail.
 *
 * Pagina stă singură, fără meniul aplicației: cel care o deschide poate să nu
 * aibă cont aici. La proceduri, dedesubt stă chiar formularul de luare la
 * cunoștință.
 */
export default function DocumentRead({
    token,
    labels,
    needsConsent,
    document: paper,
    me,
    mine,
    consents,
}: Props) {
    const [openId, setOpenId] = useState<number | null>(
        paper.files[0]?.id ?? null,
    );
    const [done, setDone] = useState(mine !== null);

    const form = useForm({
        ...who(me),
        agreed: false as boolean,
    });

    const open = paper.files.find((one) => one.id === openId) ?? null;
    const url = (id: number) => `/document/${token}/fisier?file=${id}`;
    const name = open?.name.toLowerCase() ?? '';
    const isPdf = name.endsWith('.pdf');
    const isImage = /\.(png|jpe?g|gif|webp|tiff?)$/.test(name);
    const isWord = /\.docx?$/.test(name);

    const confirm = (event: FormEvent) => {
        event.preventDefault();

        form.post(consentRoute(token).url, {
            preserveScroll: true,
            onSuccess: () => {
                remember(form.data.name, form.data.email);
                setDone(true);
            },
        });
    };

    return (
        <div className="min-h-screen bg-muted/30 p-4">
            <Head title={`${paper.number} · ${paper.title}`} />

            <div className="mx-auto flex max-w-5xl flex-col gap-4">
                <div className="flex flex-wrap items-center gap-3">
                    <img
                        src="/img/logo.png"
                        alt="Christian Tour"
                        className="size-9 rounded-md"
                    />
                    <div>
                        <div className="text-xs text-muted-foreground uppercase">
                            Christian Tour · {labels.many}
                        </div>
                        <h1 className="text-lg font-semibold">{paper.title}</h1>
                    </div>
                    {open && (
                        <Button variant="outline" className="ml-auto" asChild>
                            <a href={`${url(open.id)}&download=1`}>
                                <Download className="size-4" />
                                Descarcă
                            </a>
                        </Button>
                    )}
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            {labels.article} {paper.number}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-2 text-sm sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <span className="text-muted-foreground">
                                Obiect:{' '}
                            </span>
                            {paper.object ?? '—'}
                        </div>
                        <div>
                            <span className="text-muted-foreground">
                                Emitent:{' '}
                            </span>
                            {paper.issuer ?? '—'}
                        </div>
                        <div>
                            <span className="text-muted-foreground">
                                Emisă la:{' '}
                            </span>
                            {dmy(paper.issued_at)}
                        </div>
                        <div>
                            <span className="text-muted-foreground">
                                În vigoare din:{' '}
                            </span>
                            {dmy(paper.effective_at)}
                        </div>
                        {needsConsent && paper.consent_due_at && (
                            <div>
                                <span className="text-muted-foreground">
                                    De citit până la:{' '}
                                </span>
                                <b>{dmy(paper.consent_due_at)}</b>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/*
                 * Butonul stă înaintea documentului, nu după el: cine deschide
                 * legătura de pe telefon vede din prima ce i se cere, fără să
                 * deruleze zece pagini de procedură. Documentul e chiar
                 * dedesubt, iar rândul de sub titlu o spune.
                 */}
                {needsConsent &&
                    (done || mine !== null ? (
                        <Card className="border-2 border-emerald-600/40">
                            <CardContent className="flex flex-wrap items-center gap-4 p-5">
                                <CheckCheck className="size-7 text-emerald-600" />
                                <div className="text-sm">
                                    <div className="font-medium">
                                        Am înregistrat luarea la cunoștință.
                                    </div>
                                    <div className="text-muted-foreground">
                                        {mine
                                            ? `${mine.name} · ${mine.email} · ${when(mine.at)}`
                                            : 'Confirmarea ți-a plecat pe mail.'}
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    ) : (
                        <Card className="border-2 border-amber-500/60">
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Am citit și am luat la cunoștință
                                </CardTitle>
                                <p className="text-sm text-muted-foreground">
                                    {labels.article} e mai jos. Citește-o, apoi
                                    bifează și apasă — se înregistrează numele
                                    și ceasul.
                                </p>
                            </CardHeader>
                            <CardContent>
                                <form onSubmit={confirm} className="grid gap-3">
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="name">
                                                Numele tău
                                            </Label>
                                            <Input
                                                id="name"
                                                value={form.data.name}
                                                readOnly={me !== null}
                                                placeholder="Nume și prenume"
                                                onChange={(event) =>
                                                    form.setData(
                                                        'name',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                            {form.errors.name && (
                                                <p className="text-xs text-red-600">
                                                    {form.errors.name}
                                                </p>
                                            )}
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="email">
                                                Adresa ta de serviciu
                                            </Label>
                                            <Input
                                                id="email"
                                                type="email"
                                                value={form.data.email}
                                                readOnly={me !== null}
                                                placeholder="nume@christiantour.ro"
                                                onChange={(event) =>
                                                    form.setData(
                                                        'email',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                            {form.errors.email && (
                                                <p className="text-xs text-red-600">
                                                    {form.errors.email}
                                                </p>
                                            )}
                                        </div>
                                    </div>

                                    <label className="flex items-start gap-2 text-sm">
                                        <Checkbox
                                            checked={form.data.agreed}
                                            onCheckedChange={(value) =>
                                                form.setData(
                                                    'agreed',
                                                    value === true,
                                                )
                                            }
                                            className="mt-0.5"
                                        />
                                        <span>
                                            Declar că am citit{' '}
                                            {labels.article.toLowerCase()}{' '}
                                            <b>{paper.number}</b> —{' '}
                                            {paper.title} — și am luat la
                                            cunoștință conținutul ei.
                                        </span>
                                    </label>
                                    {form.errors.agreed && (
                                        <p className="text-xs text-red-600">
                                            {form.errors.agreed}
                                        </p>
                                    )}

                                    <div className="flex flex-wrap items-center gap-3">
                                        <Button
                                            type="submit"
                                            disabled={
                                                form.processing ||
                                                !form.data.agreed
                                            }
                                        >
                                            {form.processing && (
                                                <Loader2 className="size-4 animate-spin" />
                                            )}
                                            Confirm
                                        </Button>
                                        <span className="text-xs text-muted-foreground">
                                            Se înregistrează numele, adresa și
                                            ceasul. Confirmarea îți vine pe
                                            mail.
                                            {consents > 0 &&
                                                ` Până acum au confirmat ${consents}.`}
                                        </span>
                                    </div>
                                </form>
                            </CardContent>
                        </Card>
                    ))}

                {paper.files.length > 1 && (
                    <Card>
                        <CardContent className="grid gap-1 p-2">
                            {paper.files.map((one) => (
                                <button
                                    key={one.id}
                                    type="button"
                                    onClick={() => setOpenId(one.id)}
                                    className={cn(
                                        'flex items-center gap-3 rounded-md px-3 py-2 text-left text-sm hover:bg-muted',
                                        one.id === openId && 'bg-muted',
                                    )}
                                >
                                    <FileText className="size-4 shrink-0 text-muted-foreground" />
                                    <span className="min-w-0 flex-1">
                                        <span className="font-medium">
                                            {one.title}
                                        </span>
                                        <span className="block truncate text-xs text-muted-foreground">
                                            {one.name}
                                        </span>
                                    </span>
                                    <a
                                        href={`${url(one.id)}&download=1`}
                                        onClick={(event) =>
                                            event.stopPropagation()
                                        }
                                        className="shrink-0 text-muted-foreground hover:text-foreground"
                                        title={`Descarcă ${one.name}`}
                                    >
                                        <Download className="size-4" />
                                    </a>
                                </button>
                            ))}
                        </CardContent>
                    </Card>
                )}

                {open === null ? (
                    <p className="text-sm text-muted-foreground">
                        Documentul n-are încă niciun fișier încărcat.
                    </p>
                ) : isPdf || isWord ? (
                    <iframe
                        src={url(open.id)}
                        title={open.name}
                        className="h-[75vh] w-full rounded-md border bg-background"
                    />
                ) : isImage ? (
                    <img
                        src={url(open.id)}
                        alt={open.name}
                        className="mx-auto rounded-md border bg-background"
                    />
                ) : (
                    <Card>
                        <CardContent className="p-4">
                            <div className="mb-2 flex items-center gap-2 text-sm font-medium">
                                <FileText className="size-4" />
                                {open.name}
                            </div>
                            {open.text ? (
                                <pre className="max-h-[70vh] overflow-auto font-sans text-sm whitespace-pre-wrap">
                                    {open.text}
                                </pre>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    Fișierul nu se poate arăta în browser.
                                    Descarcă-l ca să-l citești.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                )}
            </div>
        </div>
    );
}
