import { Deferred, Head, router } from '@inertiajs/react';
import { CircleCheck, CircleX, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import AppLayout from '@/layouts/app-layout';
import { index as databaseStatusIndex } from '@/routes/database-status';
import type { ConnectionStatus, Props } from './types';

type ConnectionGroup = {
    name: string;
    connections: ConnectionStatus[];
};

function groupConnections(connections: ConnectionStatus[]): ConnectionGroup[] {
    const groups: ConnectionGroup[] = [];

    for (const connection of connections) {
        const group = groups.find((item) => item.name === connection.group);

        if (group) {
            group.connections.push(connection);
        } else {
            groups.push({ name: connection.group, connections: [connection] });
        }
    }

    return groups;
}

function formatServer(connection: ConnectionStatus): string {
    if (!connection.host) {
        return '—';
    }

    return connection.port
        ? `${connection.host}:${connection.port}`
        : connection.host;
}

function formatLatency(latency: number | null): string {
    return latency === null ? '—' : `${latency} ms`;
}

function formatCheckedAt(checkedAt: string): string {
    return new Date(checkedAt).toLocaleTimeString('ro-RO');
}

function StatusBadge({ connected }: { connected: boolean }) {
    return connected ? (
        <Badge variant="secondary">
            <CircleCheck className="text-emerald-600 dark:text-emerald-500" />
            Conectat
        </Badge>
    ) : (
        <Badge variant="destructive">
            <CircleX />
            Eroare
        </Badge>
    );
}

function ConnectionRows({ connection }: { connection: ConnectionStatus }) {
    return (
        <>
            <tr className={connection.error ? 'border-b-0' : undefined}>
                <td className="px-4 py-3">
                    <div className="font-medium">{connection.label}</div>
                    <div className="font-mono text-xs text-muted-foreground">
                        {connection.name}
                        {connection.driver ? ` · ${connection.driver}` : ''}
                    </div>
                </td>
                <td className="px-4 py-3 text-muted-foreground">
                    {formatServer(connection)}
                </td>
                <td className="px-4 py-3 text-muted-foreground">
                    {connection.database ?? '—'}
                    {connection.schema ? (
                        <span className="text-xs"> ({connection.schema})</span>
                    ) : null}
                </td>
                <td className="px-4 py-3 text-muted-foreground">
                    {connection.username ?? '—'}
                </td>
                <td className="px-4 py-3 text-right text-muted-foreground">
                    {formatLatency(connection.latency_ms)}
                </td>
                <td className="px-4 py-3 text-right">
                    <StatusBadge connected={connection.connected} />
                </td>
            </tr>
            {connection.error && (
                <tr>
                    <td className="px-4 pb-3" colSpan={6}>
                        <div className="rounded-md bg-destructive/10 p-3">
                            {connection.error_class && (
                                <div className="mb-1 font-mono text-xs text-destructive/80">
                                    {connection.error_class}
                                </div>
                            )}
                            <pre className="font-mono text-xs whitespace-pre-wrap text-destructive">
                                {connection.error}
                            </pre>
                        </div>
                    </td>
                </tr>
            )}
        </>
    );
}

function ConnectionTable({ group }: { group: ConnectionGroup }) {
    return (
        <div className="overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-left text-xs text-muted-foreground uppercase">
                    <tr>
                        <th className="px-4 py-3">{group.name}</th>
                        <th className="px-4 py-3">Server</th>
                        <th className="px-4 py-3">Bază de date</th>
                        <th className="px-4 py-3">Utilizator</th>
                        <th className="px-4 py-3 text-right">Latență</th>
                        <th className="px-4 py-3 text-right">Stare</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                    {group.connections.map((connection) => (
                        <ConnectionRows
                            key={connection.name}
                            connection={connection}
                        />
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function StatusSkeleton() {
    return (
        <div className="space-y-4">
            {[0, 1].map((index) => (
                <div
                    key={index}
                    className="space-y-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
                >
                    <Skeleton className="h-4 w-40 animate-pulse" />
                    <Skeleton className="h-8 w-full animate-pulse" />
                    <Skeleton className="h-8 w-full animate-pulse" />
                </div>
            ))}
        </div>
    );
}

export default function DatabaseStatusIndex({ status }: Props) {
    const [reloading, setReloading] = useState(false);

    const connections = status?.connections ?? [];
    const connectedCount = connections.filter(
        (connection) => connection.connected,
    ).length;

    function recheck() {
        router.reload({
            only: ['status'],
            onStart: () => setReloading(true),
            onFinish: () => setReloading(false),
        });
    }

    return (
        <>
            <Head title="Stare baze de date" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold">
                            Stare baze de date
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Fiecare conexiune definită este testată printr-un
                            `select 1`. Când o conexiune eșuează, eroarea
                            driverului este afișată mai jos.
                        </p>
                    </div>
                    <Button
                        variant="outline"
                        onClick={recheck}
                        disabled={reloading}
                    >
                        <RefreshCw
                            className={reloading ? 'animate-spin' : ''}
                        />
                        {reloading ? 'Se verifică…' : 'Reverifică'}
                    </Button>
                </div>

                <Deferred data="status" fallback={<StatusSkeleton />}>
                    <div className="space-y-4">
                        <p className="text-sm text-muted-foreground">
                            {connectedCount} din {connections.length} conexiuni
                            funcționează
                            {status
                                ? ` · verificat la ${formatCheckedAt(status.checked_at)}`
                                : ''}
                        </p>

                        {groupConnections(connections).map((group) => (
                            <ConnectionTable key={group.name} group={group} />
                        ))}
                    </div>
                </Deferred>
            </div>
        </>
    );
}

DatabaseStatusIndex.layout = (page: React.ReactNode) => (
    <AppLayout
        breadcrumbs={[
            { title: 'Stare baze de date', href: databaseStatusIndex() },
        ]}
    >
        {page}
    </AppLayout>
);
