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
                    <CardTitle>Poziția de trezorerie (sold inițial)</CardTitle>
                    <CardDescription>
                        {opening?.date
                            ? `Poziția de trezorerie la ${opening.date}, sfârșitul zilei de ieri: soldurile contabile de bază (bănci ${opening.base?.bank ?? '–'}, casierii ${opening.base?.cash ?? '–'}, depozite 5081 ${opening.base?.deposits ?? '–'}) rulate cu documentele de bancă și casă până în acea zi inclusiv, la cursul BNR din OMC de la acea dată.${opening.fallback ? ' OMC nu are solduri înainte de ieri; s-a folosit cea mai recentă dată disponibilă.' : ''}`
                            : 'Nu există încă o poziție: OMC nu a răspuns sau lipsesc soldurile de sfârșit de lună.'}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {opening && opening.rows.length > 0 ? (
                        <div className="overflow-auto">
                            <table className="w-full text-sm">
                                <thead className="text-xs text-muted-foreground uppercase">
                                    <tr>
                                        <th className="py-1 text-left">
                                            Element
                                        </th>
                                        {opening.currencies.map((currency) => (
                                            <th
                                                key={currency}
                                                className="py-1 text-right"
                                            >
                                                {currency}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-sidebar-border/70">
                                    {opening.rows.map((row) => (
                                        <tr
                                            key={row.key}
                                            className={
                                                row.key.endsWith('_now') ||
                                                row.key === 'position'
                                                    ? 'font-semibold'
                                                    : ''
                                            }
                                        >
                                            <td className="py-1 pr-2">
                                                {row.label}
                                            </td>
                                            {opening.currencies.map(
                                                (currency) => (
                                                    <td
                                                        key={currency}
                                                        className="py-1 text-right whitespace-nowrap tabular-nums"
                                                    >
                                                        {fmtRon(
                                                            row.values[
                                                                currency
                                                            ] ?? 0,
                                                        )}
                                                    </td>
                                                ),
                                            )}
                                        </tr>
                                    ))}
                                    <tr className="font-semibold">
                                        <td className="py-1 pr-2">
                                            Total RON
                                            {opening.rates &&
                                            Object.keys(opening.rates).length >
                                                0
                                                ? ` (BNR ${Object.entries(
                                                      opening.rates,
                                                  )
                                                      .filter(
                                                          ([currency]) =>
                                                              currency !==
                                                              'RON',
                                                      )
                                                      .map(
                                                          ([currency, rate]) =>
                                                              `${currency} ${rate}`,
                                                      )
                                                      .join(', ')})`
                                                : ''}
                                        </td>
                                        <td
                                            className="py-1 text-right tabular-nums"
                                            colSpan={opening.currencies.length}
                                        >
                                            {fmtRon(opening.total)}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
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
                        <b className="text-foreground">Sold inițial.</b> Poziția
                        de trezorerie din OMC la sfârșitul zilei de ieri:
                        ultimul sold contabil închis al băncilor, casieriilor și
                        depozitelor 5081, rulat cu documentele de încasare și
                        plată de după el, până ieri inclusiv, la cursul BNR al
                        acelei zile.
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
                        facturile furnizor deschise din OMC pe scadență.
                    </p>
                    <p>
                        <b className="text-foreground">Charter.</b> Fiecare
                        contract se decontează pe termenii lui, din tabul
                        Charter: rotațiile contractelor semnate la C6, ale celor
                        draft la C7, depozitul la C8 la scadența lui și
                        regularizat la ultimele rotații, taxele de aeroport la
                        C9 pe regula contractului (reconciliere lunară în prima
                        săptămână a lunii următoare la CTR 317, la N zile după
                        zbor la CTR 281). Taxele pe care contractul le
                        decontează odată cu rotația intră în linia rotației.
                        Contractele în care CHR vinde locuri sunt încasare și
                        intră la B10. Contractele Memento Air cu companiile
                        aeriene sunt păstrate doar ca termeni, fără efect de
                        cash, până se confirmă cine plătește efectiv carrierii.
                    </p>
                    <p>
                        <b className="text-foreground">OPEX.</b> Medii lunare pe
                        ultimele 12 luni închise: facturile furnizor OMC pe
                        conturi de cheltuieli (chirii, marketing, servicii…) și
                        registrul jurnal pentru ce se plătește direct din bancă
                        (salarii, contribuții, impozit, comisioane, dividende,
                        CAPEX); valorile din parametri au prioritate. Fiecare
                        sumă intră în săptămâna zilei de plată.
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
