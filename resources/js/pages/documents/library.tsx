import { Head } from '@inertiajs/react';
import { CheckCheck, FileText, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import type { DocumentStatus } from './types';
import { STATUS_LABELS } from './types';

type Paper = {
    id: number;
    type: 'decizie' | 'procedura';
    label: string;
    number: string;
    title: string;
    object: string | null;
    issuer: string | null;
    issued_at: string | null;
    effective_at: string | null;
    consent_due_at: string | null;
    status: DocumentStatus;
    token: string;
    url: string;
    /** A apăsat deja omul, după contul lui. */
    mine: boolean;
};

type Props = {
    token: string;
    opened: { number: string; title: string };
    papers: Paper[];
};

/** Cheia sub care browserul ține minte ce hârtii au fost confirmate de aici. */
const DONE = 'luare-la-cunostinta:hartii';

/**
 * Semnele lăsate în browser de cel fără cont.
 *
 * Aceeași cheie pe care o scrie pagina de citit: raftul doar o citește, ca
 * omul să vadă dintr-o privire ce i-a mai rămas de luat la cunoștință.
 */
const signedHere = (): Set<string> => {
    try {
        const saved = JSON.parse(
            window.localStorage.getItem(DONE) ?? '{}',
        ) as Record<string, unknown> | null;

        return new Set(
            saved && typeof saved === 'object' ? Object.keys(saved) : [],
        );
    } catch {
        // Browser cu memoria închisă: raftul rămâne doar un raft.
        return new Set<string>();
    }
};

const dmy = (date: string | null) =>
    date ? new Date(date).toLocaleDateString('ro-RO') : '—';

/** Fără diacritice și fără majuscule: „hotarare” găsește „Hotărâre”. */
const plain = (text: string) =>
    text
        .toLowerCase()
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[șş]/g, 's')
        .replace(/[țţ]/g, 't');

function StatusBadge({ status }: { status: DocumentStatus }) {
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

/**
 * Raftul hârtiilor casei, deschis cu legătura primită pe mail.
 *
 * Nu e registrul din aplicație: aici nu se umblă la nimic. Se caută, se
 * deschide, se citește — iar la procedurile necitite încă se apasă „am luat
 * la cunoștință” pe pagina hârtiei, ca de obicei.
 */
export default function DocumentLibrary({ opened, papers }: Props) {
    const [query, setQuery] = useState('');
    const [kind, setKind] = useState<'toate' | 'decizie' | 'procedura'>('toate');
    const [here] = useState<Set<string>>(() => signedHere());

    const found = useMemo(() => {
        const needle = plain(query.trim());

        return papers.filter((paper) => {
            if (kind !== 'toate' && paper.type !== kind) {
                return false;
            }

            if (needle === '') {
                return true;
            }

            return plain(
                [paper.number, paper.title, paper.object ?? '', paper.issuer ?? ''].join(' '),
            ).includes(needle);
        });
    }, [papers, query, kind]);

    const counts = {
        toate: papers.length,
        decizie: papers.filter((paper) => paper.type === 'decizie').length,
        procedura: papers.filter((paper) => paper.type === 'procedura').length,
    };

    return (
        <div className="min-h-screen bg-muted/30 p-4">
            <Head title="Decizii și proceduri" />

            <div className="mx-auto flex max-w-5xl flex-col gap-4">
                <div className="flex flex-wrap items-center gap-3">
                    <img
                        src="/img/logo.png"
                        alt="Christian Tour"
                        className="size-9 rounded-md"
                    />
                    <div>
                        <div className="text-xs text-muted-foreground uppercase">
                            Christian Tour · Documente interne
                        </div>
                        <h1 className="text-lg font-semibold">
                            Deciziile și procedurile companiei
                        </h1>
                    </div>
                </div>

                <Card>
                    <CardContent className="flex flex-col gap-3 p-4">
                        <div className="relative">
                            <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                value={query}
                                onChange={(event) => setQuery(event.target.value)}
                                placeholder="Caută după număr, titlu, obiect sau emitent"
                                className="pl-9"
                            />
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {(
                                [
                                    ['toate', 'Toate'],
                                    ['decizie', 'Decizii'],
                                    ['procedura', 'Proceduri'],
                                ] as const
                            ).map(([value, label]) => (
                                <Button
                                    key={value}
                                    type="button"
                                    size="sm"
                                    variant={kind === value ? 'default' : 'outline'}
                                    onClick={() => setKind(value)}
                                >
                                    {label}
                                    <span className="text-xs opacity-70">
                                        {counts[value]}
                                    </span>
                                </Button>
                            ))}
                        </div>
                    </CardContent>
                </Card>

                {found.length === 0 ? (
                    <Card>
                        <CardContent className="p-8 text-center text-sm text-muted-foreground">
                            {papers.length === 0
                                ? 'Deocamdată n-a plecat nicio hârtie în companie.'
                                : 'Nimic pe numele ăsta. Încearcă altfel — sau fără niciun cuvânt, ca să le vezi pe toate.'}
                        </CardContent>
                    </Card>
                ) : (
                    <div className="flex flex-col gap-2">
                        {found.map((paper) => {
                            const read = paper.mine || here.has(paper.token);

                            return (
                                <a
                                    key={paper.id}
                                    href={paper.url}
                                    className={cn(
                                        'rounded-xl border bg-card p-4 transition-colors hover:border-primary/40 hover:bg-accent/40',
                                    )}
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge variant="secondary">
                                            {paper.label}
                                        </Badge>
                                        <span className="font-mono text-sm">
                                            {paper.number}
                                        </span>
                                        <StatusBadge status={paper.status} />
                                        {read && (
                                            <span className="flex items-center gap-1 text-xs text-emerald-700 dark:text-emerald-300">
                                                <CheckCheck className="size-3.5" />
                                                luată la cunoștință
                                            </span>
                                        )}
                                        <span className="ml-auto text-xs text-muted-foreground">
                                            în vigoare din {dmy(paper.effective_at)}
                                        </span>
                                    </div>
                                    <div className="mt-2 font-medium">
                                        {paper.title}
                                    </div>
                                    {paper.object && (
                                        <div className="mt-1 line-clamp-2 text-sm text-muted-foreground">
                                            {paper.object}
                                        </div>
                                    )}
                                    <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                        <span className="flex items-center gap-1">
                                            <FileText className="size-3.5" />
                                            Deschide și citește
                                        </span>
                                        {paper.issuer && (
                                            <span>Emitent: {paper.issuer}</span>
                                        )}
                                        <span>Emisă la {dmy(paper.issued_at)}</span>
                                    </div>
                                </a>
                            );
                        })}
                    </div>
                )}

                <p className="px-1 pb-6 text-xs text-muted-foreground">
                    Ai ajuns aici din {opened.number} · {opened.title}. Pe raft
                    stau numai hârtiile trimise în companie, de citit și de
                    descărcat; nimic nu se schimbă de aici.
                </p>
            </div>
        </div>
    );
}
