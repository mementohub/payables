import { CircleAlert, CircleCheck, CircleDashed } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type {
    OpeningDetail,
    RunStatus,
    SourceStatus,
    SupplierAdvance,
} from '@/types/cash-flow';
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
/**
 * Poziția de trezorerie, pe monede.
 *
 * Coloana unei monede apare doar dacă are ceva în ea: altfel unsprezece
 * coloane, din care opt de zerouri, strâng cifrele una în alta și nu se mai
 * citește nimic. Mărunțișul (sub un leu echivalent) se spune în cuvinte, sub
 * tabel.
 */
function OpeningTable({ opening }: { opening: OpeningDetail }) {
    const used = opening.currencies.filter((currency) =>
        opening.rows.some(
            (row) => Math.abs(row.values[currency] ?? 0) >= 1000,
        ),
    );
    const shown = used.length > 0 ? used : opening.currencies.slice(0, 1);
    const rest = opening.currencies.filter(
        (currency) =>
            !shown.includes(currency) &&
            opening.rows.some((row) => Math.abs(row.values[currency] ?? 0) >= 1),
    );
    const position = opening.rows.find((row) => row.key === 'position');
    const rates = Object.entries(opening.rates ?? {}).filter(
        ([currency]) => currency !== 'RON' && shown.includes(currency),
    );

    return (
        <div className="space-y-3">
            <div className="overflow-x-auto">
                <table className="w-full min-w-[520px] text-sm">
                    <thead className="text-xs text-muted-foreground uppercase">
                        <tr>
                            <th className="py-1.5 pr-4 text-left font-medium">
                                Element
                            </th>
                            {shown.map((currency) => (
                                <th
                                    key={currency}
                                    className="w-[150px] py-1.5 pl-4 text-right font-medium"
                                >
                                    {currency}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {opening.rows.map((row) => {
                            const total =
                                row.key.endsWith('_now') ||
                                row.key === 'position';

                            return (
                                <tr
                                    key={row.key}
                                    className={
                                        total
                                            ? 'border-t border-sidebar-border/70 font-semibold'
                                            : 'text-muted-foreground'
                                    }
                                >
                                    <td className="py-1.5 pr-4">{row.label}</td>
                                    {shown.map((currency) => (
                                        <td
                                            key={currency}
                                            className="py-1.5 pl-4 text-right whitespace-nowrap tabular-nums"
                                        >
                                            {Math.abs(
                                                row.values[currency] ?? 0,
                                            ) >= 0.005
                                                ? fmtRon(
                                                      row.values[currency] ?? 0,
                                                  )
                                                : '—'}
                                        </td>
                                    ))}
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>

            <div className="flex flex-wrap items-baseline justify-between gap-2 rounded-md bg-muted/40 px-3 py-2">
                <span className="text-sm font-semibold">Total în lei</span>
                <span className="text-lg font-semibold tabular-nums">
                    {fmtRon(opening.total)}
                </span>
            </div>

            {(opening.deposits?.length || opening.negative?.length) && (
                <div className="grid gap-3 sm:grid-cols-2">
                    {opening.deposits && opening.deposits.length > 0 && (
                        <div className="rounded-md border border-sidebar-border/70 p-3 dark:border-sidebar-border">
                            <div className="text-xs font-semibold text-muted-foreground uppercase">
                                Depozite 5081, pe conturi
                            </div>
                            <ul className="mt-1.5 space-y-0.5 text-sm">
                                {opening.deposits.map((row) => (
                                    <li
                                        key={`${row.account}-${row.currency}`}
                                        className="flex items-baseline justify-between gap-3"
                                    >
                                        <span className="text-muted-foreground">
                                            {row.account}
                                        </span>
                                        <span className="tabular-nums">
                                            {fmtRon(row.amount)}{' '}
                                            <span className="text-xs text-muted-foreground">
                                                {row.currency}
                                            </span>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {opening.negative && opening.negative.length > 0 && (
                        <div className="rounded-md border border-sidebar-border/70 p-3 dark:border-sidebar-border">
                            <div className="text-xs font-semibold text-muted-foreground uppercase">
                                Conturi pe minus, scăzute din poziție
                            </div>
                            <ul className="mt-1.5 space-y-0.5 text-sm">
                                {opening.negative.map((row) => (
                                    <li
                                        key={row.account}
                                        className="flex items-baseline justify-between gap-3"
                                    >
                                        <span className="truncate text-muted-foreground">
                                            {row.bank}
                                        </span>
                                        <span className="tabular-nums text-red-600 dark:text-red-400">
                                            {fmtRon(row.amount)}{' '}
                                            <span className="text-xs text-muted-foreground">
                                                {row.currency}
                                            </span>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                            <p className="mt-1.5 text-xs text-muted-foreground">
                                Linii de credit trase sau conturi din care s-a
                                constituit un depozit: banii se regăsesc în
                                depozite.
                            </p>
                        </div>
                    )}
                </div>
            )}

            <p className="text-xs text-muted-foreground">
                {rates.length > 0 && (
                    <>
                        Curs BNR din OMC:{' '}
                        {rates
                            .map(([currency, rate]) => `${currency} ${rate}`)
                            .join(', ')}
                        .{' '}
                    </>
                )}
                {rest.length > 0 && position && (
                    <>
                        Sume mărunte, cuprinse în total:{' '}
                        {rest
                            .map(
                                (currency) =>
                                    `${fmtRon(position.values[currency] ?? 0)} ${currency}`,
                            )
                            .join(', ')}
                        .
                    </>
                )}
            </p>
        </div>
    );
}

export default function SourcesPanel({
    sources,
    opening,
    run,
    fx,
    advances = [],
}: {
    sources: SourceStatus[];
    opening: OpeningDetail | null;
    run: RunStatus;
    fx: Record<string, number>;
    advances?: SupplierAdvance[];
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
                        {opening?.date ? (
                            <>
                                Banii din conturi la {opening.date}, sfârșitul
                                zilei de ieri.
                                <span className="mt-1 block">
                                    Pornește din ultimele solduri din OMC —
                                    bănci {opening.base?.bank ?? '–'}, casierii{' '}
                                    {opening.base?.cash ?? '–'}, depozite 5081{' '}
                                    {opening.base?.deposits ?? '–'} — rulate cu
                                    documentele de bancă și casă până în acea zi
                                    inclusiv.
                                    {opening.fallback
                                        ? ' OMC nu are solduri înainte de ieri; s-a folosit cea mai recentă dată disponibilă.'
                                        : ''}
                                </span>
                            </>
                        ) : (
                            'Nu există încă o poziție: OMC nu a răspuns sau lipsesc soldurile de sfârșit de lună.'
                        )}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {opening && opening.rows.length > 0 ? (
                        <OpeningTable opening={opening} />
                    ) : null}
                </CardContent>
            </Card>

            {advances.length > 0 && (
                <Card className="xl:col-span-2">
                    <CardHeader>
                        <CardTitle>
                            Avansuri plătite, scăzute din prognoză
                        </CardTitle>
                        <CardDescription>
                            Bani dați deja furnizorilor, ca să nu fie plătiți a
                            doua oară. Plățile pe care OMC nu le-a legat de
                            nicio factură sting întâi facturile deschise ale
                            furnizorului (C10); ce rămâne din ele sau, dacă e
                            mai mare, avansul de pe 409 acoperă plățile
                            viitoare: contractele charter ale contrapărții
                            (întâi depozitele neplătite, apoi rotațiile în
                            ordinea datei), apoi serviciile lui din eTrip. Un
                            depozit marcat plătit în contract, pe care
                            contractul îl scade deja din ultimele rotații, nu se
                            mai scade o dată.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="max-h-[420px] overflow-auto rounded-lg border border-sidebar-border/70 dark:border-sidebar-border">
                            <table className="w-full text-xs">
                                <thead className="sticky top-0 bg-muted text-left text-muted-foreground">
                                    <tr>
                                        <th className="px-2 py-1.5">
                                            Furnizor
                                        </th>
                                        <th className="px-2 py-1.5 text-right">
                                            Plătit fără factură
                                        </th>
                                        <th className="px-2 py-1.5 text-right">
                                            Avans pe 409
                                        </th>
                                        <th className="px-2 py-1.5">
                                            Scăzut din prognoză
                                        </th>
                                        <th className="px-2 py-1.5 text-right">
                                            Nefolosit
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                                    {advances.map((row) => (
                                        <tr
                                            key={row.partner}
                                            className="align-top"
                                        >
                                            <td className="px-2 py-1.5 font-medium">
                                                {row.partner}
                                            </td>
                                            <td className="px-2 py-1.5 text-right tabular-nums">
                                                {row.unmatched_lei > 0
                                                    ? fmtRon(row.unmatched_lei)
                                                    : '–'}
                                                {row.unmatched_payments > 0 && (
                                                    <div className="text-muted-foreground">
                                                        {row.unmatched_payments}{' '}
                                                        plăți
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-2 py-1.5 text-right tabular-nums">
                                                {row.advance_lei > 0
                                                    ? fmtRon(row.advance_lei)
                                                    : '–'}
                                                {Object.keys(row.advance)
                                                    .length > 0 && (
                                                    <div className="text-muted-foreground">
                                                        {Object.entries(
                                                            row.advance,
                                                        )
                                                            .map(
                                                                ([
                                                                    currency,
                                                                    amount,
                                                                ]) =>
                                                                    `${fmtRon(amount)} ${currency}`,
                                                            )
                                                            .join(' + ')}
                                                    </div>
                                                )}
                                                {row.deposit_in_contract_lei >
                                                    0 && (
                                                    <div className="text-muted-foreground">
                                                        din care depozit deja în
                                                        contract{' '}
                                                        {fmtRon(
                                                            row.deposit_in_contract_lei,
                                                        )}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-2 py-1.5">
                                                <span className="font-semibold tabular-nums">
                                                    {fmtRon(row.applied_lei)}
                                                </span>
                                                {Object.keys(row.applied)
                                                    .length > 0 && (
                                                    <span className="ml-1 text-muted-foreground">
                                                        (
                                                        {Object.entries(
                                                            row.applied,
                                                        )
                                                            .map(
                                                                ([line, lei]) =>
                                                                    `${line} ${fmtRon(lei)}`,
                                                            )
                                                            .join(', ')}
                                                        )
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-2 py-1.5 text-right text-muted-foreground tabular-nums">
                                                {row.left_lei > 0
                                                    ? fmtRon(row.left_lei)
                                                    : '–'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <p className="mt-2 text-xs text-muted-foreground">
                            „Nefolosit” rămâne un avans fără plăți de acoperit
                            în prognoză (de exemplu un furnizor fără contract
                            charter sau servicii eTrip legate sigur de el).
                            Fiecare sumă scăzută apare, cu minus, în celula ei
                            din tabel.
                        </p>
                    </CardContent>
                </Card>
            )}

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
