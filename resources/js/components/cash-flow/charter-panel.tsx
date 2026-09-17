import { Form } from '@inertiajs/react';
import { Plus, Trash2, Upload } from 'lucide-react';
import { useMemo, useState } from 'react';
import CharterContractController from '@/actions/App/Http/Controllers/CharterContractController';
import CharterFlightController from '@/actions/App/Http/Controllers/CharterFlightController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import type { Contract, Flight } from '@/types/cash-flow';
import { fmtRon } from './report-math';

/** How the contract settles a rotation, in one line. */
function rotationTerm(contract: Contract): string {
    const days = contract.days_before_flight;

    switch (contract.payment_basis) {
        case 'week_start':
            return `rotația: cu ${days} zile înainte de luni`;
        case 'signing':
            return 'rotația: integral la semnare';
        default:
            return `rotația: OP cu ${days} zile înainte de zbor`;
    }
}

/** How the contract settles the airport taxes, in one line. */
function taxTerm(contract: Contract): string {
    switch (contract.taxes_rule) {
        case 'with_rotation':
            return 'taxe: odată cu rotația';
        case 'days_after_flight':
            return `taxe: la ${contract.taxes_days ?? 3} zile după zbor`;
        case 'days_before_flight':
            return `taxe: în avans, cu ${contract.taxes_days ?? 14} zile înainte`;
        default:
            return `taxe: lunar, ziua ${contract.taxes_month_day} a lunii următoare`;
    }
}

function ContractFields({
    contract,
    errors,
}: {
    contract?: Contract;
    errors: Record<string, string | undefined>;
}) {
    const field = (
        name: keyof Contract,
        label: string,
        props: React.ComponentProps<typeof Input> = {},
        hint?: string,
    ) => (
        <div className="grid gap-1.5">
            <Label htmlFor={`c-${name}`}>{label}</Label>
            <Input
                id={`c-${name}`}
                name={name}
                defaultValue={
                    contract?.[name] === null || contract?.[name] === undefined
                        ? ''
                        : String(contract[name])
                }
                {...props}
            />
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            <InputError message={errors[name]} />
        </div>
    );

    const select = (
        name: keyof Contract,
        label: string,
        options: [string, string][],
        fallback: string,
        hint?: string,
    ) => (
        <div className="grid gap-1.5">
            <Label htmlFor={`c-${name}`}>{label}</Label>
            <NativeSelect
                id={`c-${name}`}
                name={name}
                defaultValue={
                    contract?.[name] === null || contract?.[name] === undefined
                        ? fallback
                        : String(contract[name])
                }
            >
                {options.map(([value, text]) => (
                    <NativeSelectOption key={value} value={value}>
                        {text}
                    </NativeSelectOption>
                ))}
            </NativeSelect>
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            <InputError message={errors[name]} />
        </div>
    );

    const section = (title: string) => (
        <p className="mt-2 border-t border-sidebar-border/70 pt-3 text-xs font-semibold text-muted-foreground uppercase sm:col-span-2 dark:border-sidebar-border">
            {title}
        </p>
    );

    return (
        <div className="grid gap-3 sm:grid-cols-2">
            <div className="sm:col-span-2">
                {field('name', 'Contract', {
                    placeholder: 'CTR 317 Memento Air – S26',
                    required: true,
                })}
            </div>
            {field('counterparty', 'Contraparte', {
                placeholder: 'Memento Air S.R.L.',
            })}
            {field('buyer', 'Cumpărător', {
                placeholder: "Christian'76 Tour",
            })}
            {field('contract_no', 'Număr contract', {
                placeholder: '317/11.11.2025',
            })}
            {field('signed_date', 'Data semnării', { type: 'date' })}
            {field('season', 'Sezon', { placeholder: 'S26', required: true })}
            {field('operator', 'Operator', { placeholder: 'Memento Air' })}
            {field('period_from', 'Primul zbor', { type: 'date' })}
            {field('period_to', 'Ultimul zbor', { type: 'date' })}

            {section('Încadrare în flux')}
            {select(
                'direction',
                'Direcție',
                [
                    ['out', 'plată CHR – rotațiile intră la C6/C7'],
                    ['in', 'încasare CHR – rotațiile intră la B10'],
                ],
                'out',
            )}
            {select(
                'in_cash_flow',
                'Intră în raport',
                [
                    ['1', 'da – generează plăți/încasări'],
                    ['0', 'nu – doar termenii, fără efect de cash'],
                ],
                '1',
                'Contractele Memento Air cu companiile aeriene stau pe „nu” până se confirmă cine plătește efectiv carrierii.',
            )}
            {select(
                'status',
                'Status',
                [
                    ['signed', 'semnat – rotațiile intră la C6'],
                    ['draft', 'draft – rotațiile intră la C7, net de depozit'],
                ],
                'signed',
            )}
            {field('currency', 'Monedă', {
                defaultValue: contract?.currency ?? 'EUR',
                maxLength: 3,
            })}

            {section('Plata rotației')}
            {select(
                'payment_basis',
                'Regula de plată',
                [
                    ['flight', 'cu N zile înainte de fiecare zbor'],
                    [
                        'week_start',
                        'cu N zile înainte de luni, pe săptămâna de operare',
                    ],
                    ['signing', 'integral la semnare'],
                ],
                'flight',
            )}
            {field('days_before_flight', 'Zile (N)', {
                type: 'number',
                defaultValue: String(contract?.days_before_flight ?? 10),
                required: true,
            })}

            {section('Taxe de aeroport')}
            {select(
                'taxes_rule',
                'Regula taxelor',
                [
                    [
                        'monthly_first_week',
                        'reconciliere lunară, prima săptămână a lunii următoare (C9)',
                    ],
                    [
                        'with_rotation',
                        'odată cu rotația (intră în C6/C7 sau B10)',
                    ],
                    ['days_after_flight', 'la N zile după zbor (C9)'],
                    ['days_before_flight', 'în avans, cu N zile înainte (C9)'],
                ],
                'monthly_first_week',
            )}
            <div className="grid gap-3 sm:grid-cols-2">
                {field('taxes_month_day', 'Ziua lunii (reconciliere)', {
                    type: 'number',
                    min: 1,
                    max: 28,
                    defaultValue: String(contract?.taxes_month_day ?? 5),
                    required: true,
                })}
                {field('taxes_days', 'Zile (după/înainte)', {
                    type: 'number',
                    min: 0,
                })}
            </div>

            {section('Depozit')}
            {field('deposit_percent', 'Depozit (% din valoare)', {
                type: 'number',
                step: '0.01',
            })}
            {field('deposit_amount', 'Depozit (sumă, dacă diferă)', {
                type: 'number',
                step: '0.01',
            })}
            {field('deposit_due_date', 'Scadența depozitului', {
                type: 'date',
            })}
            {select(
                'deposit_paid',
                'Stare depozit',
                [
                    ['0', 'neachitat – intră la C8 la scadență'],
                    ['1', 'achitat deja'],
                ],
                '0',
            )}
            <div className="sm:col-span-2">
                {field('deposit_settlement', 'Regularizare', {
                    placeholder:
                        'regularizare la plata ultimei rotații (art. 3.5)',
                    maxLength: 60,
                })}
            </div>

            {section('Valori și clauze')}
            {field('contract_value', 'Valoare netă (monedă)', {
                type: 'number',
                step: '0.01',
            })}
            {field('contract_value_with_taxes', 'Valoare cu taxe pax', {
                type: 'number',
                step: '0.01',
            })}
            {field(
                'fx_markup_pct',
                'Adaos curs la plata în RON (%)',
                {
                    type: 'number',
                    step: '0.01',
                    defaultValue: String(contract?.fx_markup_pct ?? 0),
                    required: true,
                },
                'Contractele Memento Air: RON la cursul BNR din ziua plății + 2%.',
            )}
            {field('late_penalty_pct_per_day', 'Penalitate întârziere (%/zi)', {
                type: 'number',
                step: '0.001',
            })}
            <div className="sm:col-span-2">
                {field('invoicing', 'Facturare', {
                    placeholder:
                        'factură per rotație; taxele pe reconcilierea lunară',
                    maxLength: 255,
                })}
            </div>
            <div className="grid gap-1.5 sm:col-span-2">
                <Label htmlFor="c-fuel_rule">Combustibil / curs</Label>
                <Textarea
                    id="c-fuel_rule"
                    name="fuel_rule"
                    rows={2}
                    defaultValue={contract?.fuel_rule ?? ''}
                    placeholder="Platts FOB MED + SAF 70 EUR/t, bază 750 USD/t; recalcul pe 15 și 30…"
                />
                <InputError message={errors.fuel_rule} />
            </div>
            <div className="grid gap-1.5 sm:col-span-2">
                <Label htmlFor="c-cancellation_terms">Anulare</Label>
                <Textarea
                    id="c-cancellation_terms"
                    name="cancellation_terms"
                    rows={2}
                    defaultValue={contract?.cancellation_terms ?? ''}
                    placeholder="100% din valoarea lanțului după semnare…"
                />
                <InputError message={errors.cancellation_terms} />
            </div>

            {section('Sursă')}
            <div className="sm:col-span-2">
                {field('source', 'Document sursă', {
                    placeholder: 'CTR 317/11.11.2025 + anexa AA30/ADD7',
                    maxLength: 255,
                })}
            </div>
            {select(
                'confidence',
                'Încredere',
                [
                    ['R', 'R – clauză citită clar'],
                    ['M', 'M – medie'],
                    ['S', 'S – scăzută, dedusă'],
                ],
                'R',
            )}
            <div className="grid gap-1.5 sm:col-span-2">
                <Label htmlFor="c-notes">Note</Label>
                <Textarea
                    id="c-notes"
                    name="notes"
                    rows={3}
                    defaultValue={contract?.notes ?? ''}
                    placeholder="ipoteze de model, lipsuri din anexe, ce rămâne de confirmat cu Financiarul…"
                />
                <InputError message={errors.notes} />
            </div>
        </div>
    );
}

function ContractDialog({
    contract,
    trigger,
}: {
    contract?: Contract;
    trigger: React.ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const formProps = contract
        ? CharterContractController.update.form(contract.id)
        : CharterContractController.store.form();

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {contract
                            ? 'Modifică contractul'
                            : 'Contract charter nou'}
                    </DialogTitle>
                    <DialogDescription>
                        Termenii pe care se decontează contractul: când se
                        plătește rotația, cum se decontează taxele de aeroport,
                        depozitul, clauza de curs și direcția banilor. Raportul
                        aplică exact ce se salvează aici.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...formProps}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <ContractFields
                                contract={contract}
                                errors={errors as Record<string, string>}
                            />
                            <DialogFooter>
                                <Button type="submit" disabled={processing}>
                                    Salvează
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function FlightDialog({
    contracts,
    defaultContractId,
}: {
    contracts: Contract[];
    defaultContractId: number | null;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant="outline"
                    size="sm"
                    disabled={contracts.length === 0}
                >
                    <Plus />
                    Rotație
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Rotație nouă</DialogTitle>
                    <DialogDescription>
                        Valoarea netă a rotației în moneda contractului; data
                        plății se calculează din termenii contractului dacă o
                        lași goală.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...CharterFlightController.store.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="grid gap-1.5 sm:col-span-2">
                                    <Label htmlFor="f-contract">Contract</Label>
                                    <NativeSelect
                                        id="f-contract"
                                        name="charter_contract_id"
                                        defaultValue={String(
                                            defaultContractId ??
                                                contracts[0]?.id ??
                                                '',
                                        )}
                                    >
                                        {contracts.map((contract) => (
                                            <NativeSelectOption
                                                key={contract.id}
                                                value={String(contract.id)}
                                            >
                                                {contract.season} ·{' '}
                                                {contract.name}
                                            </NativeSelectOption>
                                        ))}
                                    </NativeSelect>
                                    <InputError
                                        message={errors.charter_contract_id}
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="f-operator">Operator</Label>
                                    <Input
                                        id="f-operator"
                                        name="operator"
                                        placeholder="ANIMA WINGS"
                                    />
                                    <InputError message={errors.operator} />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="f-route">Rută</Label>
                                    <Input
                                        id="f-route"
                                        name="route"
                                        placeholder="OTP AYT OTP"
                                        required
                                    />
                                    <InputError message={errors.route} />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="f-no">Nr. zbor</Label>
                                    <Input
                                        id="f-no"
                                        name="flight_no"
                                        placeholder="A2 4238/4239"
                                    />
                                    <InputError message={errors.flight_no} />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="f-date">
                                        Data zborului
                                    </Label>
                                    <Input
                                        id="f-date"
                                        name="flight_date"
                                        type="date"
                                        required
                                    />
                                    <InputError message={errors.flight_date} />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="f-seats">Locuri</Label>
                                    <Input
                                        id="f-seats"
                                        name="seats"
                                        type="number"
                                    />
                                    <InputError message={errors.seats} />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="f-price">Preț / loc</Label>
                                    <Input
                                        id="f-price"
                                        name="price_per_seat"
                                        type="number"
                                        step="0.0001"
                                    />
                                    <InputError
                                        message={errors.price_per_seat}
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="f-net">Valoare netă</Label>
                                    <Input
                                        id="f-net"
                                        name="net_value"
                                        type="number"
                                        step="0.01"
                                        required
                                    />
                                    <InputError message={errors.net_value} />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="f-taxes">
                                        Taxe aeroport (estimare)
                                    </Label>
                                    <Input
                                        id="f-taxes"
                                        name="taxes"
                                        type="number"
                                        step="0.01"
                                        defaultValue="0"
                                    />
                                    <InputError message={errors.taxes} />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="f-pay">
                                        Data plății rotației
                                    </Label>
                                    <Input
                                        id="f-pay"
                                        name="pay_date"
                                        type="date"
                                    />
                                    <InputError message={errors.pay_date} />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="f-taxpay">
                                        Data plății taxelor
                                    </Label>
                                    <Input
                                        id="f-taxpay"
                                        name="taxes_pay_date"
                                        type="date"
                                    />
                                    <InputError
                                        message={errors.taxes_pay_date}
                                    />
                                </div>
                            </div>
                            <DialogFooter>
                                <Button type="submit" disabled={processing}>
                                    Adaugă
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * The charter contracts and their flight programmes: the only input the
 * report needs by hand, because the contracts live on SharePoint.
 */
export default function CharterPanel({
    contracts,
    flights,
}: {
    contracts: Contract[];
    flights: Flight[];
}) {
    const [contractId, setContractId] = useState<number | null>(
        contracts[0]?.id ?? null,
    );
    const [showPast, setShowPast] = useState(false);
    const today = new Date().toISOString().slice(0, 10);

    const visible = useMemo(
        () =>
            flights.filter(
                (flight) =>
                    (contractId === null ||
                        flight.charter_contract_id === contractId) &&
                    (showPast || flight.flight_date >= today),
            ),
        [flights, contractId, showPast, today],
    );

    const byOperator = useMemo(() => {
        const groups = new Map<
            string,
            {
                season: string;
                operator: string;
                flights: number;
                net: number;
                taxes: number;
                first: string;
                last: string;
            }
        >();

        for (const flight of visible) {
            const operator = flight.operator ?? '–';
            const key = `${flight.season}|${operator}`;
            const group = groups.get(key) ?? {
                season: flight.season,
                operator,
                flights: 0,
                net: 0,
                taxes: 0,
                first: flight.flight_date,
                last: flight.flight_date,
            };

            group.flights += 1;
            group.net += flight.net_value;
            group.taxes += flight.taxes;
            group.first =
                group.first < flight.flight_date
                    ? group.first
                    : flight.flight_date;
            group.last =
                group.last > flight.flight_date
                    ? group.last
                    : flight.flight_date;
            groups.set(key, group);
        }

        return Array.from(groups.values()).sort((a, b) => b.net - a.net);
    }, [visible]);

    const totals = useMemo(
        () =>
            visible.reduce(
                (acc, flight) => ({
                    net: acc.net + flight.net_value,
                    taxes: acc.taxes + flight.taxes,
                    seats: acc.seats + (flight.seats ?? 0),
                }),
                { net: 0, taxes: 0, seats: 0 },
            ),
        [visible],
    );

    return (
        <div className="grid gap-4">
            <Card>
                <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3 space-y-0">
                    <div>
                        <CardTitle>Contracte charter</CardTitle>
                        <CardDescription>
                            Fiecare contract are propriile reguli: rotațiile
                            semnate intră la C6, cele draft la C7 net de
                            depozit, depozitul la C8 la scadența lui, taxele de
                            aeroport la C9 pe regula contractului. Contractele
                            în care CHR vinde locuri sunt încasare și intră la
                            B10; cele fără efect de cash sunt păstrate doar ca
                            termeni.
                        </CardDescription>
                    </div>
                    <ContractDialog
                        trigger={
                            <Button size="sm">
                                <Plus />
                                Contract
                            </Button>
                        }
                    />
                </CardHeader>
                <CardContent>
                    {contracts.length === 0 ? (
                        <p className="py-6 text-center text-sm text-muted-foreground">
                            Niciun contract. Adaugă contractul sezonului (ex.
                            CTR 317/11.11.2025 Memento Air, S26) și importă
                            programul de zbor din anexă.
                        </p>
                    ) : (
                        <div className="overflow-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left text-xs text-muted-foreground uppercase">
                                    <tr>
                                        <th className="px-3 py-2">Contract</th>
                                        <th className="px-3 py-2">Sezon</th>
                                        <th className="px-3 py-2">Status</th>
                                        <th className="px-3 py-2">Termeni</th>
                                        <th className="px-3 py-2 text-right">
                                            Plată
                                        </th>
                                        <th className="px-3 py-2 text-right">
                                            Depozit
                                        </th>
                                        <th className="px-3 py-2 text-right">
                                            Rotații
                                        </th>
                                        <th className="px-3 py-2 text-right">
                                            Valoare netă
                                        </th>
                                        <th className="px-3 py-2">Perioadă</th>
                                        <th className="px-3 py-2 text-right">
                                            Acțiuni
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                                    {contracts.map((contract) => (
                                        <tr
                                            key={contract.id}
                                            className={
                                                contract.id === contractId
                                                    ? 'bg-muted/30'
                                                    : ''
                                            }
                                        >
                                            <td className="px-3 py-2">
                                                <button
                                                    type="button"
                                                    className="text-left font-medium hover:underline"
                                                    onClick={() =>
                                                        setContractId(
                                                            contract.id,
                                                        )
                                                    }
                                                >
                                                    {contract.name}
                                                </button>
                                                <div className="text-xs text-muted-foreground">
                                                    {[
                                                        contract.counterparty ??
                                                            contract.operator,
                                                        contract.contract_no,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ')}
                                                </div>
                                            </td>
                                            <td className="px-3 py-2 font-mono text-xs">
                                                {contract.season}
                                            </td>
                                            <td className="px-3 py-2">
                                                <Badge
                                                    variant={
                                                        contract.status ===
                                                        'signed'
                                                            ? 'default'
                                                            : 'outline'
                                                    }
                                                >
                                                    {contract.status ===
                                                    'signed'
                                                        ? 'semnat'
                                                        : 'draft'}
                                                </Badge>
                                                {contract.direction ===
                                                    'in' && (
                                                    <div className="mt-1 text-xs font-medium text-emerald-700 dark:text-emerald-400">
                                                        încasare
                                                    </div>
                                                )}
                                                {!contract.in_cash_flow && (
                                                    <div className="mt-1 text-xs text-muted-foreground">
                                                        fără efect de cash
                                                    </div>
                                                )}
                                            </td>
                                            <td className="max-w-[320px] px-3 py-2 text-xs text-muted-foreground">
                                                <div>
                                                    {rotationTerm(contract)}
                                                </div>
                                                <div>{taxTerm(contract)}</div>
                                            </td>
                                            <td className="px-3 py-2 text-right whitespace-nowrap tabular-nums">
                                                {contract.deposit_percent !==
                                                null
                                                    ? `${contract.deposit_percent}%${contract.deposit_paid ? ' plătit' : contract.deposit_due_date ? ` la ${contract.deposit_due_date}` : ''}`
                                                    : '–'}
                                            </td>
                                            <td className="px-3 py-2 text-right tabular-nums">
                                                {contract.flights_count}
                                            </td>
                                            <td className="px-3 py-2 text-right whitespace-nowrap tabular-nums">
                                                {fmtRon(contract.flights_net)}{' '}
                                                {contract.currency}
                                            </td>
                                            <td className="px-3 py-2 text-xs whitespace-nowrap text-muted-foreground">
                                                {contract.first_flight
                                                    ? `${contract.first_flight} → ${contract.last_flight}`
                                                    : '–'}
                                            </td>
                                            <td className="px-3 py-2">
                                                <div className="flex items-center justify-end gap-1">
                                                    <ContractDialog
                                                        contract={contract}
                                                        trigger={
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                            >
                                                                modifică
                                                            </Button>
                                                        }
                                                    />
                                                    <Form
                                                        {...CharterContractController.destroy.form(
                                                            contract.id,
                                                        )}
                                                        options={{
                                                            preserveScroll: true,
                                                        }}
                                                        onBefore={() =>
                                                            confirm(
                                                                `Ștergi contractul ${contract.name} și cele ${contract.flights_count} rotații?`,
                                                            )
                                                        }
                                                    >
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            type="submit"
                                                            title="Șterge"
                                                        >
                                                            <Trash2 />
                                                        </Button>
                                                    </Form>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </CardContent>
            </Card>

            <Card>
                <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3 space-y-0">
                    <div>
                        <CardTitle>Program de zbor</CardTitle>
                        <CardDescription>
                            Rotațiile contractului selectat. Importă anexa
                            contractului așa cum vine de la Memento Air (foaia
                            cu „Companie Aeriana, Ruta Zbor, Primul Zbor,
                            Ultimul Zbor, Numar Total Zboruri, Zi de Operare,
                            Numar locuri, PRET / LOC /RT, Taxe RO, Taxe
                            Destinatie” – se desfășoară în rotații săptămânale)
                            sau un fișier .xlsx/.csv cu o rotație pe rând;
                            rândurile cu alt sezon merg în contractul acelui
                            sezon.
                        </CardDescription>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <NativeSelect
                            aria-label="Contract"
                            value={
                                contractId !== null ? String(contractId) : ''
                            }
                            onChange={(e) =>
                                setContractId(
                                    e.target.value
                                        ? Number(e.target.value)
                                        : null,
                                )
                            }
                        >
                            <NativeSelectOption value="">
                                toate contractele
                            </NativeSelectOption>
                            {contracts.map((contract) => (
                                <NativeSelectOption
                                    key={contract.id}
                                    value={String(contract.id)}
                                >
                                    {contract.season} · {contract.name}
                                </NativeSelectOption>
                            ))}
                        </NativeSelect>
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={showPast}
                                onChange={(e) => setShowPast(e.target.checked)}
                            />
                            și zborurile trecute
                        </label>
                        <FlightDialog
                            contracts={contracts}
                            defaultContractId={contractId}
                        />
                    </div>
                </CardHeader>
                <CardContent className="grid gap-4">
                    {byOperator.length > 1 && (
                        <div className="overflow-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left text-xs text-muted-foreground uppercase">
                                    <tr>
                                        <th className="px-3 py-2">Sezon</th>
                                        <th className="px-3 py-2">Operator</th>
                                        <th className="px-3 py-2 text-right">
                                            Rotații
                                        </th>
                                        <th className="px-3 py-2 text-right">
                                            Net
                                        </th>
                                        <th className="px-3 py-2 text-right">
                                            Taxe est.
                                        </th>
                                        <th className="px-3 py-2">Perioadă</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                                    {byOperator.map((group) => (
                                        <tr
                                            key={`${group.season}-${group.operator}`}
                                        >
                                            <td className="px-3 py-1.5 font-mono text-xs">
                                                {group.season}
                                            </td>
                                            <td className="px-3 py-1.5">
                                                {group.operator}
                                            </td>
                                            <td className="px-3 py-1.5 text-right tabular-nums">
                                                {group.flights}
                                            </td>
                                            <td className="px-3 py-1.5 text-right whitespace-nowrap tabular-nums">
                                                {fmtRon(group.net)}
                                            </td>
                                            <td className="px-3 py-1.5 text-right whitespace-nowrap tabular-nums">
                                                {fmtRon(group.taxes)}
                                            </td>
                                            <td className="px-3 py-1.5 text-xs whitespace-nowrap text-muted-foreground">
                                                {group.first} → {group.last}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    {contracts.length > 0 && (
                        <Form
                            {...CharterFlightController.import.form()}
                            options={{ preserveScroll: true }}
                            className="flex flex-wrap items-end gap-3 rounded-xl border border-dashed border-sidebar-border p-3"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="imp-contract">
                                            Importă în contractul
                                        </Label>
                                        <NativeSelect
                                            id="imp-contract"
                                            name="charter_contract_id"
                                            defaultValue={String(
                                                contractId ?? contracts[0].id,
                                            )}
                                        >
                                            {contracts.map((contract) => (
                                                <NativeSelectOption
                                                    key={contract.id}
                                                    value={String(contract.id)}
                                                >
                                                    {contract.season} ·{' '}
                                                    {contract.name}
                                                </NativeSelectOption>
                                            ))}
                                        </NativeSelect>
                                        <InputError
                                            message={errors.charter_contract_id}
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="imp-file">
                                            Fișier (.xlsx / .csv)
                                        </Label>
                                        <Input
                                            id="imp-file"
                                            name="file"
                                            type="file"
                                            accept=".xlsx,.csv"
                                            required
                                        />
                                        <InputError message={errors.file} />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="imp-sheet">
                                            Foaie (opțional)
                                        </Label>
                                        <Input
                                            id="imp-sheet"
                                            name="sheet"
                                            placeholder="Detaliu_Charter"
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="imp-replace">
                                            Rotațiile existente
                                        </Label>
                                        <NativeSelect
                                            id="imp-replace"
                                            name="replace"
                                            defaultValue="1"
                                        >
                                            <NativeSelectOption value="1">
                                                se înlocuiesc
                                            </NativeSelectOption>
                                            <NativeSelectOption value="0">
                                                se păstrează (adaugă)
                                            </NativeSelectOption>
                                        </NativeSelect>
                                    </div>
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        <Upload />
                                        Importă programul
                                    </Button>
                                </>
                            )}
                        </Form>
                    )}
                    <div className="max-h-[480px] overflow-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <table className="w-full text-sm">
                            <thead className="sticky top-0 bg-muted/50 text-left text-xs text-muted-foreground uppercase backdrop-blur">
                                <tr>
                                    <th className="px-3 py-2">Sezon</th>
                                    <th className="px-3 py-2">Operator</th>
                                    <th className="px-3 py-2">Rută</th>
                                    <th className="px-3 py-2">Zbor</th>
                                    <th className="px-3 py-2">Data zbor</th>
                                    <th className="px-3 py-2 text-right">
                                        Locuri
                                    </th>
                                    <th className="px-3 py-2 text-right">
                                        Valoare netă
                                    </th>
                                    <th className="px-3 py-2 text-right">
                                        Taxe
                                    </th>
                                    <th className="px-3 py-2">Plată rotație</th>
                                    <th className="px-3 py-2">Plată taxe</th>
                                    <th className="px-3 py-2" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                                {visible.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={11}
                                            className="px-3 py-6 text-center text-muted-foreground"
                                        >
                                            Nicio rotație.
                                        </td>
                                    </tr>
                                )}
                                {visible.map((flight) => (
                                    <tr key={flight.id}>
                                        <td className="px-3 py-1.5 font-mono text-xs">
                                            {flight.season}
                                        </td>
                                        <td className="px-3 py-1.5 text-xs whitespace-nowrap">
                                            {flight.operator ?? ''}
                                        </td>
                                        <td className="px-3 py-1.5 whitespace-nowrap">
                                            {flight.route}
                                        </td>
                                        <td className="px-3 py-1.5 text-xs whitespace-nowrap text-muted-foreground">
                                            {flight.flight_no ?? ''}
                                        </td>
                                        <td className="px-3 py-1.5 whitespace-nowrap">
                                            {flight.flight_date}
                                        </td>
                                        <td className="px-3 py-1.5 text-right tabular-nums">
                                            {flight.seats ?? ''}
                                        </td>
                                        <td className="px-3 py-1.5 text-right whitespace-nowrap tabular-nums">
                                            {fmtRon(flight.net_value, 2)}
                                        </td>
                                        <td className="px-3 py-1.5 text-right whitespace-nowrap tabular-nums">
                                            {fmtRon(flight.taxes, 2)}
                                        </td>
                                        <td className="px-3 py-1.5 whitespace-nowrap">
                                            {flight.payment_date}
                                            {flight.pay_date && (
                                                <span className="ml-1 text-xs text-muted-foreground">
                                                    fix
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-1.5 whitespace-nowrap">
                                            {flight.taxes > 0
                                                ? flight.taxes_payment_date
                                                : ''}
                                        </td>
                                        <td className="px-3 py-1.5">
                                            <Form
                                                {...CharterFlightController.destroy.form(
                                                    flight.id,
                                                )}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                                onBefore={() =>
                                                    confirm(
                                                        `Ștergi rotația ${flight.route} din ${flight.flight_date}?`,
                                                    )
                                                }
                                            >
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    type="submit"
                                                    title="Șterge"
                                                >
                                                    <Trash2 />
                                                </Button>
                                            </Form>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                            {visible.length > 0 && (
                                <tfoot className="bg-muted/50 font-semibold">
                                    <tr>
                                        <td className="px-3 py-2" colSpan={5}>
                                            {visible.length} rotații
                                        </td>
                                        <td className="px-3 py-2 text-right tabular-nums">
                                            {totals.seats}
                                        </td>
                                        <td className="px-3 py-2 text-right tabular-nums">
                                            {fmtRon(totals.net, 2)}
                                        </td>
                                        <td className="px-3 py-2 text-right tabular-nums">
                                            {fmtRon(totals.taxes, 2)}
                                        </td>
                                        <td colSpan={3} />
                                    </tr>
                                </tfoot>
                            )}
                        </table>
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}
