import { Form, Head, router } from '@inertiajs/react';
import {
    CircleAlert,
    CircleCheck,
    Play,
    RefreshCw,
    Square,
} from 'lucide-react';
import { useEffect, useRef } from 'react';
import MaintenanceController from '@/actions/App/Http/Controllers/MaintenanceController';
import SyncController from '@/actions/App/Http/Controllers/SyncController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { index as maintenanceIndex } from '@/routes/maintenance';

type RunStatus = {
    running: boolean;
    stale: boolean;
    mode: 'background' | 'inline' | null;
    started_at: string | null;
    started_by: string | null;
    arguments: string[];
    exit_code: number | null;
    log: string;
};

type Props = {
    pendingMigrations: string[];
    upgrade: RunStatus;
    syncRun: RunStatus;
    scheduler: { last_beat: string | null; alive: boolean };
    sync: { at: string; ok: boolean; summary: string } | null;
    companies: {
        id: number;
        name: string;
        source: string;
        last_synced_at: string | null;
    }[];
};

const OK = 'text-emerald-700 dark:text-emerald-500';

function dateTime(value: string | null): string {
    return value ? new Date(value).toLocaleString('ro-RO') : 'niciodată';
}

function RunBadge({ upgrade }: { upgrade: RunStatus }) {
    if (upgrade.running) {
        return (
            <Badge variant="secondary">
                <RefreshCw className="animate-spin" />
                rulează de la {dateTime(upgrade.started_at)}
                {upgrade.started_by ? `, pornită de ${upgrade.started_by}` : ''}
            </Badge>
        );
    }

    if (upgrade.stale) {
        return (
            <Badge variant="destructive">
                <CircleAlert />
                pornită la {dateTime(upgrade.started_at)} și neîncheiată;
                verifică jurnalul
            </Badge>
        );
    }

    if (upgrade.exit_code === 0) {
        return (
            <Badge variant="outline" className={OK}>
                <CircleCheck />
                ultima rulare reușită · {dateTime(upgrade.started_at)}
                {upgrade.mode === 'inline' ? ' (doar migrările)' : ''}
            </Badge>
        );
    }

    if (upgrade.exit_code !== null) {
        return (
            <Badge variant="destructive">
                <CircleAlert />
                ultima rulare a eșuat (cod {upgrade.exit_code}) ·{' '}
                {dateTime(upgrade.started_at)}
            </Badge>
        );
    }

    return <Badge variant="outline">nu a rulat încă din aplicație</Badge>;
}

function StopButton({
    run,
    status,
}: {
    run: 'upgrade' | 'sync';
    status: RunStatus;
}) {
    if (!status.running && !status.stale) {
        return null;
    }

    return (
        <Form
            {...MaintenanceController.stop.form({ run })}
            options={{ preserveScroll: true }}
            onBefore={() =>
                confirm(
                    'Oprești procesul? Ce a apucat să aducă rămâne; restul se reia la următoarea sincronizare.',
                )
            }
        >
            {({ processing }) => (
                <Button
                    type="submit"
                    variant="destructive"
                    size="sm"
                    disabled={processing}
                >
                    <Square />
                    Oprește
                </Button>
            )}
        </Form>
    );
}

function RunLog({ log, empty }: { log: string; empty: string }) {
    const ref = useRef<HTMLPreElement>(null);

    useEffect(() => {
        ref.current?.scrollTo({ top: ref.current.scrollHeight });
    }, [log]);

    return (
        <pre
            ref={ref}
            className="max-h-96 min-h-24 overflow-auto rounded-md bg-muted/50 p-3 font-mono text-xs whitespace-pre-wrap"
        >
            {log || empty}
        </pre>
    );
}

export default function MaintenanceIndex({
    pendingMigrations,
    upgrade,
    syncRun,
    scheduler,
    sync,
    companies,
}: Props) {
    const polling = upgrade.running || syncRun.running;

    useEffect(() => {
        if (!polling) {
            return;
        }

        const timer = window.setInterval(() => {
            router.reload({
                only: [
                    'upgrade',
                    'syncRun',
                    'pendingMigrations',
                    'companies',
                    'sync',
                ],
            });
        }, 3000);

        return () => window.clearInterval(timer);
    }, [polling]);

    return (
        <>
            <Head title="Întreținere" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">Întreținere</h1>
                    <p className="max-w-3xl text-sm text-muted-foreground">
                        Ce s-ar face altfel din consola serverului: actualizarea
                        aplicației după un deploy și starea sincronizării cu
                        OMC.
                    </p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>
                            Actualizare aplicație (app:upgrade)
                        </CardTitle>
                        <CardDescription>
                            Rulează migrările în așteptare, aduce ultimele zile
                            de documente din OMC și reîmprospătează furnizorii
                            eTrip. Pornește în fundal; jurnalul de mai jos se
                            actualizează singur cât timp rulează.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="flex flex-wrap items-center gap-3">
                            <RunBadge upgrade={upgrade} />
                            <StopButton run="upgrade" status={upgrade} />
                            <Badge
                                variant={
                                    pendingMigrations.length > 0
                                        ? 'secondary'
                                        : 'outline'
                                }
                                className={
                                    pendingMigrations.length > 0
                                        ? 'text-amber-600 dark:text-amber-400'
                                        : OK
                                }
                            >
                                {pendingMigrations.length > 0
                                    ? `${pendingMigrations.length} migrări în așteptare`
                                    : 'baza de date este la zi'}
                            </Badge>
                        </div>

                        {pendingMigrations.length > 0 && (
                            <ul className="rounded-md bg-muted/50 p-3 font-mono text-xs">
                                {pendingMigrations.map((migration) => (
                                    <li key={migration}>{migration}</li>
                                ))}
                            </ul>
                        )}

                        <div className="flex flex-wrap items-center gap-2">
                            <Form
                                {...MaintenanceController.upgrade.form()}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        disabled={processing || upgrade.running}
                                    >
                                        <Play />
                                        Rulează app:upgrade
                                    </Button>
                                )}
                            </Form>
                            <Form
                                {...MaintenanceController.migrate.form()}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing || upgrade.running}
                                    >
                                        {processing
                                            ? 'Se migrează…'
                                            : 'Doar migrările (pe loc)'}
                                    </Button>
                                )}
                            </Form>
                            <span className="text-xs text-muted-foreground">
                                „Doar migrările” rulează în cererea curentă,
                                fără proces în fundal; folosește-l dacă
                                app:upgrade nu poate porni.
                            </span>
                        </div>

                        <RunLog
                            log={upgrade.log}
                            empty="Jurnalul apare aici după prima rulare."
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-wrap items-start justify-between gap-3">
                        <div className="space-y-1.5">
                            <CardTitle>Sincronizare OMC</CardTitle>
                            <CardDescription>
                                Documentele ERP se aduc automat la 10 minute
                                (ultimele 3 zile) și noaptea (ultimele 45 de
                                zile) prin scheduler-ul Laravel; fiecare rulare
                                actualizează și facturile încă deschise. Pornite
                                de aici, rulează în fundal, iar paginile se
                                actualizează pe măsură ce intră datele.
                            </CardDescription>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Form
                                {...SyncController.storeAll.form()}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing || syncRun.running}
                                    >
                                        <RefreshCw
                                            className={
                                                syncRun.running
                                                    ? 'animate-spin'
                                                    : undefined
                                            }
                                        />
                                        Sincronizează acum (ultimele zile)
                                    </Button>
                                )}
                            </Form>
                            <Form
                                {...SyncController.storeAll.form()}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <>
                                        <input
                                            type="hidden"
                                            name="days"
                                            value="45"
                                        />
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            disabled={
                                                processing || syncRun.running
                                            }
                                        >
                                            Adu ultimele 45 de zile
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="flex flex-wrap items-center gap-3">
                            <RunBadge upgrade={syncRun} />
                            <StopButton run="sync" status={syncRun} />
                            {syncRun.arguments.length > 0 && (
                                <span className="font-mono text-xs text-muted-foreground">
                                    erp:sync {syncRun.arguments.join(' ')}
                                </span>
                            )}
                        </div>

                        <RunLog
                            log={syncRun.log}
                            empty="Jurnalul sincronizării pornite din aplicație apare aici."
                        />

                        <div className="flex flex-wrap items-center gap-3 text-sm">
                            {scheduler.alive ? (
                                <Badge variant="outline" className={OK}>
                                    <CircleCheck />
                                    scheduler activ · ultimul semnal{' '}
                                    {dateTime(scheduler.last_beat)}
                                </Badge>
                            ) : (
                                <Badge variant="destructive">
                                    <CircleAlert />
                                    scheduler inactiv
                                    {scheduler.last_beat
                                        ? ` · ultimul semnal ${dateTime(scheduler.last_beat)}`
                                        : ''}
                                </Badge>
                            )}
                            <span className="text-muted-foreground">
                                Ultima rulare erp:sync:{' '}
                                {sync
                                    ? `${sync.ok ? 'reușită' : 'cu erori'} · ${dateTime(sync.at)} · ${sync.summary}`
                                    : 'niciuna încă'}
                            </span>
                        </div>

                        {!scheduler.alive && (
                            <p className="rounded-md bg-amber-500/10 p-3 text-sm text-amber-700 dark:text-amber-400">
                                Fără scheduler, datele nu se aduc singure.
                                Cron-ul Laravel trebuie activat pe server (
                                <code>php artisan schedule:run</code> la fiecare
                                minut, opțiunea „Laravel scheduler” în Ploi);
                                până atunci apasă „Sincronizează acum”.
                            </p>
                        )}

                        <div className="overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left text-xs text-muted-foreground uppercase">
                                    <tr>
                                        <th className="px-3 py-2">Companie</th>
                                        <th className="px-3 py-2">Sursă</th>
                                        <th className="px-3 py-2">
                                            Date OMC la
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                                    {companies.map((company) => (
                                        <tr key={company.id}>
                                            <td className="px-3 py-2 font-medium">
                                                {company.name}
                                            </td>
                                            <td className="px-3 py-2 text-muted-foreground">
                                                {company.source}
                                            </td>
                                            <td className="px-3 py-2 text-muted-foreground">
                                                {dateTime(
                                                    company.last_synced_at,
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

MaintenanceIndex.layout = (page: React.ReactNode) => (
    <AppLayout
        breadcrumbs={[{ title: 'Întreținere', href: maintenanceIndex() }]}
    >
        {page}
    </AppLayout>
);
