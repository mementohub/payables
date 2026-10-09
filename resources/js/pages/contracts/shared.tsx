import { Head } from '@inertiajs/react';
import { Download, FileText } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

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
        file: { name: string; size: number; text: string | null } | null;
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
    const file = `/contracte/${token}/fisier`;
    const name = contract.file?.name.toLowerCase() ?? '';
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
                    {contract.file && (
                        <Button variant="outline" className="ml-auto" asChild>
                            <a href={`${file}?download=1`}>
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

                {contract.file === null ? (
                    <p className="text-sm text-muted-foreground">
                        Contractul n-are încă niciun fișier încărcat.
                    </p>
                ) : isPdf ? (
                    <iframe
                        src={file}
                        title={contract.file.name}
                        className="h-[75vh] w-full rounded-md border bg-background"
                    />
                ) : isImage ? (
                    <img
                        src={file}
                        alt={contract.file.name}
                        className="mx-auto rounded-md border bg-background"
                    />
                ) : (
                    <Card>
                        <CardContent className="p-4">
                            <div className="mb-2 flex items-center gap-2 text-sm font-medium">
                                <FileText className="size-4" />
                                {contract.file.name}
                            </div>
                            {contract.file.text ? (
                                <pre className="max-h-[70vh] overflow-auto font-sans text-sm whitespace-pre-wrap">
                                    {contract.file.text}
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
