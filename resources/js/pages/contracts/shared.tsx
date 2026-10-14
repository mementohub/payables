import { Head } from '@inertiajs/react';
import { Download, FileText } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';

type Props = {
    share: {
        permission: string;
        expires_at: string | null;
        sender: string | null;
    };
    contract: {
        number: string;
        title: string;
        partner_name: string;
        object: string | null;
        value: number | null;
        currency: string | null;
        signed_at: string | null;
        expires_at: string | null;
        files: {
            id: number;
            title: string;
            kind_label: string;
            name: string;
            size: number;
            signed_at: string | null;
            text: string | null;
        }[];
    };
    token: string;
};

const dmy = (value: string | null) =>
    value === null ? '—' : new Date(value).toLocaleDateString('ro-RO');

const money = (value: number | null, currency: string | null) =>
    value === null
        ? '—'
        : `${new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 0 }).format(value)} ${currency ?? 'RON'}`;

/**
 * Contractul deschis din legătura primită pe mail: se citește, se descarcă,
 * atât. Pagina stă singură, fără meniul aplicației, fiindcă cel care o
 * deschide poate să nu aibă cont.
 */
export default function ContractShared({ share, contract, token }: Props) {
    // Teancul vine întreg; se citește o hârtie pe rând, cu contractul deschis
    // din capul locului.
    const [openId, setOpenId] = useState<number | null>(
        contract.files[0]?.id ?? null,
    );

    const open = contract.files.find((one) => one.id === openId) ?? null;
    const url = (id: number) => `/contracte/${token}/fisier?file=${id}`;
    const name = open?.name.toLowerCase() ?? '';
    const isPdf = name.endsWith('.pdf');
    const isImage = /\.(png|jpe?g|gif|webp|tiff?)$/.test(name);

    return (
        <div className="min-h-screen bg-muted/30 p-4">
            <Head title={`${contract.number} · ${contract.partner_name}`} />

            <div className="mx-auto flex max-w-5xl flex-col gap-4">
                <div className="flex flex-wrap items-center gap-3">
                    <img
                        src="/img/logo.png"
                        alt="Christian Tour"
                        className="size-9 rounded-md"
                    />
                    <div>
                        <div className="text-xs text-muted-foreground uppercase">
                            Christian Tour · Contract Management
                        </div>
                        <h1 className="text-lg font-semibold">
                            {contract.title}
                        </h1>
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
                            {contract.number} · {contract.partner_name}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-2 text-sm sm:grid-cols-2">
                        <div>
                            <span className="text-muted-foreground">
                                Obiect:{' '}
                            </span>
                            {contract.object ?? '—'}
                        </div>
                        <div>
                            <span className="text-muted-foreground">
                                Valoare:{' '}
                            </span>
                            {money(contract.value, contract.currency)}
                        </div>
                        <div>
                            <span className="text-muted-foreground">
                                Semnat:{' '}
                            </span>
                            {dmy(contract.signed_at)}
                        </div>
                        <div>
                            <span className="text-muted-foreground">
                                Expiră:{' '}
                            </span>
                            {dmy(contract.expires_at)}
                        </div>
                        <p className="text-xs text-muted-foreground sm:col-span-2">
                            Trimis de {share.sender ?? 'Christian Tour'}
                            {share.expires_at
                                ? ` · legătura e bună până la ${dmy(share.expires_at)}`
                                : ''}
                            . Deschiderile se văd în jurnalul contractului.
                        </p>
                    </CardContent>
                </Card>

                {contract.files.length > 1 && (
                    <Card>
                        <CardContent className="grid gap-1 p-2">
                            {contract.files.map((one) => (
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
                                            {one.signed_at
                                                ? ` · ${dmy(one.signed_at)}`
                                                : ''}
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
                        Contractul n-are încă niciun fișier încărcat.
                    </p>
                ) : isPdf ? (
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
