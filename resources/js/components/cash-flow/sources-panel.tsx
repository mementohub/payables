import { CircleAlert, CircleCheck, CircleDashed } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { OpeningDetail, RunStatus, SourceStatus } from '@/types/cash-flow';
import { fmtRon } from './report-math';

function StatusIcon({ status }: { status: SourceStatus['status'] }) {
    if (status === 'ok') {
        return <CircleCheck className="size-4 text-emerald-600" />;
    }

    if (status === 'error') {
        return <CircleAlert className="size-4 text-destructive" />;
    }

    return <CircleDashed className="size-4 text-muted-foreground" />;
}

/**
 * Where each line comes from, whether the last build could read it, the
 * opening balance detail and the rules the report applies.
 */
export default function SourcesPanel({
    sources,
    opening,
    run,
    fx,
}: {
    sources: SourceStatus[];
    opening: OpeningDetail | null;
    run: RunStatus;
    fx: Record<string, number>;
}) {
    return (
        <div className="grid gap-4 xl:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>Surse la ultima construire</CardTitle>
                    <CardDescription>
                        Fiecare sursă se citește separat: dacă una cade,
                        celelalte rămân în raport și linia ei apare cu zero.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {sources.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Raportul nu a fost încă construit.
                        </p>
                    ) : (
                        <ul className="divide-y divide-sidebar-border/70 text-sm">
                            {sources.map((source) => (
                                <li
                                    key={source.key}
                                    className="flex items-start gap-3 py-2"
                                >
                                    <StatusIcon status={source.status} />
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium">
                                                {source.label}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {source.ms} ms
                                                {source.rows > 0
                                                    ? ` · ${source.rows} rânduri`
                                                    : ''}
                                            </span>
                                        </div>
                                        {source.message && (
                                            <p
                                                className={
                                                    source.status === 'error'
                                                        ? 'text-destructive'
                                                        : 'text-muted-foreground'
                                                }
                                            >
                                                {source.message}
                                            </p>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                    {Object.keys(fx).length > 0 && (
                        <p className="mt-3 text-xs text-muted-foreground">
                            Cursuri folosite:{' '}
                            {Object.entries(fx)
                                .filter(([currency]) => currency !== 'RON')
                                .map(
                                    ([currency, rate]) =>
                                        `${currency} ${rate.toLocaleString('ro-RO', { maximumFractionDigits: 4 })}`,
                                )
                                .join(' · ')}
                        </p>
                    )}
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Sold inițial</CardTitle>
                    <CardDescription>
                        {opening?.date
                            ? `Solduri la ${opening.date}, rulate cu OMC până la ${opening.as_of}.`
                            : 'Introdu soldurile și data lor în parametri.'}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {opening?.date ? (
                        <table className="w-full text-sm">
                            <thead className="text-xs text-muted-foreground uppercase">
                                <tr>
                                    <th className="py-1 text-left">Element</th>
                                    <th className="py-1 text-right">RON</th>
                                    <th className="py-1 text-right">EUR</th>
                                    <th className="py-1 text-right">USD</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-sidebar-border/70">
                                {Object.entries(opening.components).map(
                                    ([key, component]) => (
                                        <tr key={key}>
                                            <td className="py-1">
                                                {String(component.label)}
                                            </td>
                                            {['RON', 'EUR', 'USD'].map(
                                                (currency) => (
                                                    <td
                                                        key={currency}
                                                        className="py-1 text-right tabular-nums"
                                                    >
                                                        {fmtRon(
                                                            Number(
                                                                component[
                                                                    currency
                                                                ] ?? 0,
                                                            ),
                                                        )}
                                                    </td>
                                                ),
                                            )}
                                        </tr>
                                    ),
                                )}
                                <tr>
                                    <td className="py-1">
                                        Mișcări OMC de la data soldurilor
                                    </td>
                                    {['RON', 'EUR', 'USD'].map((currency) => (
                                        <td
                                            key={currency}
                                            className="py-1 text-right tabular-nums"
                                        >
                                            {fmtRon(
                                                opening.rolled[currency] ?? 0,
                                            )}
                                        </td>
                                    ))}
                                </tr>
                                <tr className="font-semibold">
                                    <td className="py-1">Poziție azi</td>
                                    {['RON', 'EUR', 'USD'].map((currency) => (
                                        <td
                                            key={currency}
                                            className="py-1 text-right tabular-nums"
                                        >
                                            {fmtRon(
                                                opening.by_currency[currency] ??
                                                    0,
                                            )}
                                        </td>
                                    ))}
                                </tr>
                                <tr className="font-semibold">
                                    <td className="py-1">Total RON</td>
                                    <td
                                        className="py-1 text-right tabular-nums"
                                        colSpan={3}
                                    >
                                        {fmtRon(opening.total)}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    ) : null}
                </CardContent>
            </Card>

            <Card className="xl:col-span-2">
                <CardHeader>
                    <CardTitle>Cum este construit</CardTitle>
                </CardHeader>
                <CardContent className="grid gap-2 text-sm text-muted-foreground md:grid-cols-2">
                    <p>
                        <b className="text-foreground">Orizont.</b> 52 de
                        săptămâni calendaristice luni–duminică, S+1 fiind
                        săptămâna curentă; se reconstruiește în fiecare noapte
                        după sincronizarea OMC și la cerere.
                    </p>
                    <p>
                        <b className="text-foreground">Sold inițial.</b>{' '}
                        Soldurile de bănci, casierii și depozite introduse în
                        parametri la data lor, rulate cu documentele de încasare
                        și plată din OMC până azi.
                    </p>
                    <p>
                        <b className="text-foreground">Încasări.</b> Dosare
                        eTrip confirmate cu sold de încasat: scadențarul
                        (avansuri din due_dates + soldul la balance_due_date),
                        din care se scade ce s-a încasat, în ordine cronologică.
                        Scadențele depășite: recuperare parțială (B8) sau memo
                        (B9).
                    </p>
                    <p>
                        <b className="text-foreground">Plăți produs.</b> Costul
                        furnizor net al serviciilor confirmate, plătit cu N zile
                        înainte de check-in; biletele de linie la comandă;
                        charterul după contractele din aplicație; facturile
                        furnizor deschise din OMC pe scadență.
                    </p>
                    <p>
                        <b className="text-foreground">OPEX.</b> Medii lunare
                        din facturile furnizor OMC (12 luni) sau valorile din
                        parametri, puse în săptămâna zilei de plată.
                    </p>
                    <p>
                        <b className="text-foreground">Scenariu.</b> Dosarele
                        create în aceeași săptămână a anului trecut, cu
                        încasările și costurile lor efective, decalate 52 de
                        săptămâni × factor; charterul sezonului următor din
                        programul sezonului de bază decalat un an.
                    </p>
                    <p>
                        <b className="text-foreground">An anterior.</b>{' '}
                        Încasările și plățile efective din OMC pentru aceeași
                        săptămână a anului trecut; soldul anului trecut este
                        reconstituit din soldul de azi, deci este o estimare.
                    </p>
                    <p>
                        <b className="text-foreground">De confirmat.</b>{' '}
                        Avansurile plătite furnizorilor (4092), CAPEX și
                        dividendele planificate, termenii contractelor draft,
                        transferurile între conturi proprii din OMC.
                    </p>
                </CardContent>
            </Card>

            {(run.log || run.started_at) && (
                <Card className="xl:col-span-2">
                    <CardHeader>
                        <CardTitle>Jurnalul ultimei rulări</CardTitle>
                        <CardDescription>
                            {run.started_at
                                ? `Pornit ${new Date(run.started_at).toLocaleString('ro-RO')}${run.started_by ? ` de ${run.started_by}` : ''}${run.exit_code !== null ? ` · cod ieșire ${run.exit_code}` : run.running ? ' · în curs' : ''}`
                                : ''}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <pre className="max-h-64 overflow-auto rounded-lg bg-muted p-3 font-mono text-xs whitespace-pre-wrap">
                            {run.log || '(nimic încă)'}
                        </pre>
                        {run.stale && (
                            <Badge variant="destructive" className="mt-2">
                                rulare pierdută
                            </Badge>
                        )}
                    </CardContent>
                </Card>
            )}
        </div>
    );
}
