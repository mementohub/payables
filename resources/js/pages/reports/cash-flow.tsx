import { Form, Head, router } from '@inertiajs/react';
import { RefreshCw, Square } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import CashFlowReportController from '@/actions/App/Http/Controllers/CashFlowReportController';
import MaintenanceController from '@/actions/App/Http/Controllers/MaintenanceController';
import BalanceChart from '@/components/cash-flow/balance-chart';
import CharterPanel from '@/components/cash-flow/charter-panel';
import ParametersForm from '@/components/cash-flow/parameters-form';
import {
    deriveReport,
    fmtCompact,
    fmtRon,
    weekLabel,
} from '@/components/cash-flow/report-math';
import SourcesPanel from '@/components/cash-flow/sources-panel';
import WeeklyTable from '@/components/cash-flow/weekly-table';
import YoyTable from '@/components/cash-flow/yoy-table';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { index as cashFlowIndex } from '@/routes/reports/cash-flow';
import type { CashFlowPageProps } from '@/types/cash-flow';

const STATUS_LABEL: Record<string, string> = {
    ok: 'complet',
    partial: 'parțial',
    failed: 'eșuat',
};

function Kpi({
    label,
    value,
    hint,
    tone = 'default',
}: {
    label: string;
    value: string;
    hint?: string;
    tone?: 'default' | 'good' | 'warn' | 'bad';
}) {
    return (
        <Card
            className={cn(
                'gap-1 border-t-4 py-4',
                tone === 'good' && 'border-t-emerald-600',
                tone === 'warn' && 'border-t-amber-500',
                tone === 'bad' && 'border-t-destructive',
                tone === 'default' && 'border-t-sidebar-border',
            )}
        >
            <CardContent className="px-4">
                <div className="text-xs text-muted-foreground uppercase">
                    {label}
                </div>
                <div className="mt-1 text-xl font-semibold tabular-nums">
                    {value}
                </div>
                {hint && (
                    <div className="text-xs text-muted-foreground">{hint}</div>
                )}
            </CardContent>
        </Card>
    );
}

function amounts(map: Record<string, number>): string {
    const parts = Object.entries(map)
        .filter(([, amount]) => Math.abs(amount) >= 1)
        .sort(([, a], [, b]) => b - a)
        .map(([currency, amount]) => `${fmtCompact(amount)} ${currency}`);

    return parts.length > 0 ? parts.join(' + ') : '0';
}

export default function CashFlowReport({
    snapshot,
    run,
    parameters,
    opex,
    connections,
    contracts,
    flights,
    schedule,
}: CashFlowPageProps) {
    const payload = snapshot?.payload ?? null;
    const [horizon, setHorizon] = useState<13 | 52>(13);
    const [scenarioOn, setScenarioOn] = useState(
        payload?.params.scenario?.enabled ?? parameters.scenario.enabled,
    );
    const [compare, setCompare] = useState(false);
    const [monthly, setMonthly] = useState(false);

    useEffect(() => {
        if (!run.running) {
            return;
        }

        const timer = window.setInterval(
            () =>
                router.reload({
                    only: ['snapshot', 'run', 'lastRun'],
                }),
            5000,
        );

        return () => window.clearInterval(timer);
    }, [run.running]);

    const report = useMemo(
        () => (payload ? deriveReport(payload, scenarioOn) : null),
        [payload, scenarioOn],
    );

    const seasons = useMemo(
        () => Array.from(new Set(contracts.map((c) => c.season))).sort(),
        [contracts],
    );

    const span = Math.min(horizon, report?.weeks.length ?? horizon);
    const sum = (values: number[]) =>
        values.slice(0, span).reduce((a, b) => a + b, 0);
    const minIndex = report
        ? report.closing
              .slice(0, span)
              .reduce((best, v, i, arr) => (v < arr[best] ? i : best), 0)
        : 0;

    return (
        <>
            <Head title="WCFR 52 Weeks" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold">
                            WCFR 52 Weeks
                        </h1>
                        <p className="max-w-3xl text-sm text-muted-foreground">
                            Weekly Cash Flow Report: soldul de trezorerie
                            săptămână cu săptămână, din rezervările eTrip,
                            documentele OMC și contractele charter. Se
                            reconstruiește automat în fiecare noapte la{' '}
                            {schedule.nightly} ({schedule.timezone}) și la
                            cerere.
                        </p>
                        {snapshot && (
                            <p className="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                Construit{' '}
                                {new Date(snapshot.built_at).toLocaleString(
                                    'ro-RO',
                                )}
                                {snapshot.built_by
                                    ? ` de ${snapshot.built_by}`
                                    : ''}{' '}
                                în {Math.round(snapshot.duration_ms / 1000)} s
                                <Badge
                                    variant={
                                        snapshot.status === 'ok'
                                            ? 'default'
                                            : 'destructive'
                                    }
                                >
                                    {STATUS_LABEL[snapshot.status] ??
                                        snapshot.status}
                                </Badge>
                                {payload && (
                                    <span>
                                        · date la {payload.today} · S+1 ={' '}
                                        {weekLabel(payload.week_start, true)}
                                    </span>
                                )}
                            </p>
                        )}
                    </div>
                    <div className="flex items-center gap-2">
                        {run.running && (
                            <Form
                                {...MaintenanceController.stop.form('cashflow')}
                                options={{ preserveScroll: true }}
                            >
                                <Button
                                    type="submit"
                                    variant="outline"
                                    size="sm"
                                >
                                    <Square />
                                    Oprește
                                </Button>
                            </Form>
                        )}
                        <Form
                            {...CashFlowReportController.build.form()}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    size="sm"
                                    disabled={processing || run.running}
                                >
                                    <RefreshCw
                                        className={
                                            run.running ? 'animate-spin' : ''
                                        }
                                    />
                                    {run.running
                                        ? 'Se recalculează…'
                                        : 'Recalculează'}
                                </Button>
                            )}
                        </Form>
                    </div>
                </div>

                {snapshot?.status === 'partial' && (
                    <Alert>
                        <AlertTitle>
                            O parte din surse nu au putut fi citite
                        </AlertTitle>
                        <AlertDescription className="whitespace-pre-wrap">
                            {snapshot.error}
                        </AlertDescription>
                    </Alert>
                )}
                {snapshot?.status === 'failed' && (
                    <Alert variant="destructive">
                        <AlertTitle>
                            Raportul nu a putut fi construit
                        </AlertTitle>
                        <AlertDescription className="whitespace-pre-wrap">
                            {snapshot.error}
                        </AlertDescription>
                    </Alert>
                )}

                <Tabs defaultValue={payload ? 'report' : 'parameters'}>
                    <TabsList>
                        <TabsTrigger value="report">Raport</TabsTrigger>
                        <TabsTrigger value="parameters">Parametri</TabsTrigger>
                        <TabsTrigger value="charter">
                            Charter
                            {contracts.length > 0 && (
                                <span className="ml-1 text-xs text-muted-foreground">
                                    {contracts.length}
                                </span>
                            )}
                        </TabsTrigger>
                        <TabsTrigger value="sources">
                            Surse și ipoteze
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value="report" className="grid gap-4">
                        {!payload || !report ? (
                            <Card>
                                <CardContent className="py-10 text-center text-sm text-muted-foreground">
                                    {run.running
                                        ? 'Prima construire a raportului este în curs; pagina se actualizează singură.'
                                        : 'Raportul nu a fost încă construit. Completează soldul inițial în Parametri și apasă „Recalculează”.'}
                                </CardContent>
                            </Card>
                        ) : (
                            <>
                                <div className="flex flex-wrap items-center gap-x-6 gap-y-3 rounded-xl border border-sidebar-border/70 px-4 py-3 text-sm dark:border-sidebar-border">
                                    <div className="flex items-center gap-2">
                                        <span className="text-muted-foreground">
                                            Orizont
                                        </span>
                                        <ToggleGroup
                                            type="single"
                                            variant="outline"
                                            size="sm"
                                            value={String(horizon)}
                                            onValueChange={(value) =>
                                                value &&
                                                setHorizon(
                                                    Number(value) as 13 | 52,
                                                )
                                            }
                                        >
                                            <ToggleGroupItem value="13">
                                                13 săpt.
                                            </ToggleGroupItem>
                                            <ToggleGroupItem value="52">
                                                52 săpt.
                                            </ToggleGroupItem>
                                        </ToggleGroup>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <span className="text-muted-foreground">
                                            Tabel
                                        </span>
                                        <ToggleGroup
                                            type="single"
                                            variant="outline"
                                            size="sm"
                                            value={monthly ? 'month' : 'week'}
                                            onValueChange={(value) =>
                                                value &&
                                                setMonthly(value === 'month')
                                            }
                                        >
                                            <ToggleGroupItem value="week">
                                                săptămânal
                                            </ToggleGroupItem>
                                            <ToggleGroupItem value="month">
                                                lunar
                                            </ToggleGroupItem>
                                        </ToggleGroup>
                                    </div>
                                    <label className="flex items-center gap-2">
                                        <Switch
                                            checked={scenarioOn}
                                            onCheckedChange={setScenarioOn}
                                        />
                                        Scenariu vânzări noi
                                    </label>
                                    <label className="flex items-center gap-2">
                                        <Switch
                                            checked={compare}
                                            onCheckedChange={setCompare}
                                        />
                                        Compară cu anul anterior (OMC efectiv,
                                        aceeași săptămână)
                                    </label>
                                </div>

                                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                    <Kpi
                                        label="Sold inițial"
                                        value={`${fmtCompact(report.opening[0])} RON`}
                                        hint={
                                            payload.opening.date
                                                ? `solduri la ${payload.opening.date}, rulate cu OMC`
                                                : 'lipsește soldul inițial'
                                        }
                                        tone={
                                            payload.opening.date
                                                ? 'default'
                                                : 'bad'
                                        }
                                    />
                                    <Kpi
                                        label={`Sold minim în ${span} săpt.`}
                                        value={`${fmtCompact(report.closing[minIndex])} RON`}
                                        hint={`săptămâna ${weekLabel(report.weeks[minIndex], true)}`}
                                        tone={
                                            report.closing[minIndex] <
                                            report.minimum
                                                ? 'bad'
                                                : report.closing[minIndex] <
                                                    report.comfort
                                                  ? 'warn'
                                                  : 'good'
                                        }
                                    />
                                    <Kpi
                                        label={`Sold final S+${span}`}
                                        value={`${fmtCompact(report.closing[span - 1])} RON`}
                                        hint={`${report.signal.slice(0, span).filter((s) => s !== 'OK').length} săptămâni sub confort`}
                                    />
                                    <Kpi
                                        label={`Încasări − plăți, ${span} săpt.`}
                                        value={`${fmtCompact(sum(report.inflows))} − ${fmtCompact(sum(report.outflows) + sum(report.opex))}`}
                                        hint={`flux net ${fmtCompact(sum(report.net))} RON`}
                                        tone={
                                            sum(report.net) < 0
                                                ? 'warn'
                                                : 'good'
                                        }
                                    />
                                    <Kpi
                                        label="Restanțe clienți recente"
                                        value={amounts(
                                            payload.kpis.overdue_recent,
                                        )}
                                        hint={`recuperate ${payload.params.overdue?.recent_pct ?? 0}% pe ${payload.params.overdue?.recent_weeks ?? 0} săpt.; vechi: ${amounts(payload.kpis.overdue_old)}`}
                                        tone="warn"
                                    />
                                    <Kpi
                                        label="Rezervări cu sold"
                                        value={fmtRon(payload.kpis.bookings)}
                                        hint={`încasări viitoare ${fmtCompact(payload.kpis.receivables_existing)} RON; după orizont: ${amounts(payload.kpis.beyond_horizon)}`}
                                    />
                                    <Kpi
                                        label="Plăți din rezervări și contracte"
                                        value={`${fmtCompact(payload.kpis.payables_existing)} RON`}
                                        hint={`facturi furnizor deschise OMC: ${fmtCompact(payload.kpis.suppliers_open)} RON`}
                                    />
                                    <Kpi
                                        label="Scenariu vânzări noi"
                                        value={`${fmtRon(payload.kpis.scenario_bookings)} dosare`}
                                        hint={`încasări ${fmtCompact(payload.kpis.scenario_receipts ?? 0)} RON pe segment (B10.1–B10.7); anul anterior × ${payload.params.scenario?.factor ?? 1}${scenarioOn ? '' : ' (exclus din totaluri)'}`}
                                    />
                                </div>

                                <Card>
                                    <CardHeader>
                                        <CardTitle>
                                            Soldul de trezorerie, săptămână cu
                                            săptămână
                                        </CardTitle>
                                        <CardDescription>
                                            RON. Soldul final (axa stângă) și
                                            fluxurile săptămânii – încasări,
                                            plăți produs + OPEX (axa dreaptă).
                                            Fundalul gri marchează săptămânile
                                            acoperite doar de scenariu; linia
                                            roșie punctată este pragul minim.
                                            {compare &&
                                                ' Liniile întrerupte sunt valorile efective ale anului anterior din OMC.'}
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent>
                                        <BalanceChart
                                            report={report}
                                            lastyear={payload.lastyear}
                                            coverage={payload.coverage}
                                            horizon={span}
                                            compare={compare}
                                        />
                                    </CardContent>
                                </Card>

                                {compare && (
                                    <Card>
                                        <CardHeader>
                                            <CardTitle>
                                                Comparație cu anul anterior,
                                                săptămână cu săptămână
                                            </CardTitle>
                                            <CardDescription>
                                                An curent = prognoza acestui
                                                raport; an anterior = încasările
                                                și plățile efective din OMC în
                                                aceeași săptămână, cu soldul
                                                reconstituit din soldul de azi
                                                (estimare).
                                            </CardDescription>
                                        </CardHeader>
                                        <CardContent>
                                            <YoyTable
                                                report={report}
                                                lastyear={payload.lastyear}
                                                horizon={span}
                                            />
                                        </CardContent>
                                    </Card>
                                )}

                                <Card>
                                    <CardHeader>
                                        <CardTitle>
                                            Situația fluxurilor de trezorerie
                                        </CardTitle>
                                        <CardDescription>
                                            Toate liniile raportului, în RON,
                                            {monthly
                                                ? ' grupate pe luni.'
                                                : ' pe săptămâni.'}{' '}
                                            Liniile marcate „scenariu” intră în
                                            totaluri doar cu scenariul pornit.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent>
                                        <WeeklyTable
                                            report={report}
                                            horizon={span}
                                            monthly={monthly}
                                            scenarioOn={scenarioOn}
                                        />
                                    </CardContent>
                                </Card>

                                <div className="grid gap-4 xl:grid-cols-2">
                                    <Card>
                                        <CardHeader>
                                            <CardTitle>
                                                Încasări din rezervări existente
                                            </CardTitle>
                                            <CardDescription>
                                                Pe segment, tip de scadență,
                                                canal și moneda dosarului
                                                (eTrip).
                                            </CardDescription>
                                        </CardHeader>
                                        <CardContent>
                                            <StructureTable
                                                rows={payload.structure.receivables.map(
                                                    (row) => ({
                                                        key: `${row.segment}-${row.bucket}-${row.channel}-${row.currency}`,
                                                        cells: [
                                                            row.label,
                                                            row.bucket,
                                                            row.channel,
                                                            row.currency,
                                                        ],
                                                        amount: row.amount,
                                                        lei: row.lei,
                                                        count: row.tranches,
                                                    }),
                                                )}
                                                headers={[
                                                    'Segment',
                                                    'Tip',
                                                    'Canal',
                                                    'Monedă',
                                                ]}
                                                countLabel="Scadențe"
                                            />
                                        </CardContent>
                                    </Card>
                                    <Card>
                                        <CardHeader>
                                            <CardTitle>
                                                Plăți furnizori din rezervări
                                                existente
                                            </CardTitle>
                                            <CardDescription>
                                                Pe categorie și moneda
                                                furnizorului (eTrip), fără
                                                charter.
                                            </CardDescription>
                                        </CardHeader>
                                        <CardContent>
                                            <StructureTable
                                                rows={payload.structure.payables.map(
                                                    (row) => ({
                                                        key: `${row.category}-${row.currency}`,
                                                        cells: [
                                                            row.label,
                                                            row.currency,
                                                        ],
                                                        amount: row.amount,
                                                        lei: row.lei,
                                                        count: row.items,
                                                    }),
                                                )}
                                                headers={[
                                                    'Categorie',
                                                    'Monedă',
                                                ]}
                                                countLabel="Servicii"
                                            />
                                        </CardContent>
                                    </Card>
                                </div>

                                <div className="grid gap-4 xl:grid-cols-2">
                                    <Card>
                                        <CardHeader>
                                            <CardTitle>
                                                Încasări din vânzări noi
                                                (scenariu)
                                            </CardTitle>
                                            <CardDescription>
                                                Pe segmentul dosarului și moneda
                                                încasării: ce au încasat
                                                dosarele create în aceleași
                                                săptămâni ale anului anterior,
                                                decalat 52 de săptămâni × factor
                                                (liniile B10.1–B10.7).
                                            </CardDescription>
                                        </CardHeader>
                                        <CardContent>
                                            <StructureTable
                                                rows={(
                                                    payload.structure
                                                        .new_sales_receipts ??
                                                    []
                                                ).map((row) => ({
                                                    key: `${row.segment}-${row.currency}`,
                                                    cells: [
                                                        row.label,
                                                        row.currency,
                                                    ],
                                                    amount: row.amount,
                                                    lei: row.lei,
                                                    count: row.receipts,
                                                }))}
                                                headers={['Segment', 'Monedă']}
                                                countLabel="Încasări"
                                            />
                                        </CardContent>
                                    </Card>
                                    <Card>
                                        <CardHeader>
                                            <CardTitle>
                                                Plăți furnizori pentru vânzări
                                                noi (scenariu)
                                            </CardTitle>
                                            <CardDescription>
                                                Pe categorie și moneda
                                                furnizorului, din aceleași
                                                dosare ale anului anterior, fără
                                                charter (linia C11).
                                            </CardDescription>
                                        </CardHeader>
                                        <CardContent>
                                            <StructureTable
                                                rows={(
                                                    payload.structure
                                                        .new_sales_costs ?? []
                                                ).map((row) => ({
                                                    key: `${row.category}-${row.currency}`,
                                                    cells: [
                                                        row.label,
                                                        row.currency,
                                                    ],
                                                    amount: row.amount,
                                                    lei: row.lei,
                                                    count: row.items,
                                                }))}
                                                headers={[
                                                    'Categorie',
                                                    'Monedă',
                                                ]}
                                                countLabel="Servicii"
                                            />
                                        </CardContent>
                                    </Card>
                                </div>
                            </>
                        )}
                    </TabsContent>

                    <TabsContent value="parameters">
                        <ParametersForm
                            parameters={parameters}
                            opex={opex}
                            connections={connections}
                            seasons={seasons}
                        />
                    </TabsContent>

                    <TabsContent value="charter">
                        <CharterPanel contracts={contracts} flights={flights} />
                    </TabsContent>

                    <TabsContent value="sources">
                        <SourcesPanel
                            sources={snapshot?.sources ?? []}
                            opening={payload?.opening ?? null}
                            run={run}
                            fx={payload?.fx ?? {}}
                        />
                    </TabsContent>
                </Tabs>
            </div>
        </>
    );
}

function StructureTable({
    rows,
    headers,
    countLabel,
}: {
    rows: {
        key: string;
        cells: string[];
        amount: number;
        lei: number;
        count: number;
    }[];
    headers: string[];
    countLabel: string;
}) {
    const sorted = [...rows].sort((a, b) => b.lei - a.lei);
    const total = sorted.reduce((sum, row) => sum + row.lei, 0);

    return (
        <div className="max-h-[360px] overflow-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
            <table className="w-full text-sm">
                <thead className="sticky top-0 bg-muted/50 text-left text-xs text-muted-foreground uppercase backdrop-blur">
                    <tr>
                        {headers.map((header) => (
                            <th key={header} className="px-3 py-2">
                                {header}
                            </th>
                        ))}
                        <th className="px-3 py-2 text-right">{countLabel}</th>
                        <th className="px-3 py-2 text-right">Sumă</th>
                        <th className="px-3 py-2 text-right">RON</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                    {sorted.length === 0 && (
                        <tr>
                            <td
                                colSpan={headers.length + 3}
                                className="px-3 py-6 text-center text-muted-foreground"
                            >
                                Nimic.
                            </td>
                        </tr>
                    )}
                    {sorted.map((row) => (
                        <tr key={row.key}>
                            {row.cells.map((cell, i) => (
                                <td
                                    key={i}
                                    className="px-3 py-1.5 whitespace-nowrap"
                                >
                                    {cell}
                                </td>
                            ))}
                            <td className="px-3 py-1.5 text-right tabular-nums">
                                {row.count}
                            </td>
                            <td className="px-3 py-1.5 text-right whitespace-nowrap tabular-nums">
                                {fmtRon(row.amount)}
                            </td>
                            <td className="px-3 py-1.5 text-right whitespace-nowrap tabular-nums">
                                {fmtRon(row.lei)}
                            </td>
                        </tr>
                    ))}
                </tbody>
                {sorted.length > 0 && (
                    <tfoot className="bg-muted/50 font-semibold">
                        <tr>
                            <td
                                className="px-3 py-2"
                                colSpan={headers.length + 2}
                            >
                                Total
                            </td>
                            <td className="px-3 py-2 text-right tabular-nums">
                                {fmtRon(total)}
                            </td>
                        </tr>
                    </tfoot>
                )}
            </table>
        </div>
    );
}

CashFlowReport.layout = (page: React.ReactNode) => (
    <AppLayout
        breadcrumbs={[
            { title: 'Rapoarte', href: cashFlowIndex() },
            { title: 'WCFR 52 Weeks', href: cashFlowIndex() },
        ]}
    >
        {page}
    </AppLayout>
);
