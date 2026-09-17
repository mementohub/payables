import { useForm } from '@inertiajs/react';
import { Save } from 'lucide-react';
import type { FormEvent } from 'react';
import CashFlowReportController from '@/actions/App/Http/Controllers/CashFlowReportController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { Switch } from '@/components/ui/switch';
import type { OpexCategory, Parameters } from '@/types/cash-flow';
import { fmtRon } from './report-math';

const CURRENCIES = ['RON', 'EUR', 'USD'] as const;

function Field({
    id,
    label,
    hint,
    error,
    children,
}: {
    id: string;
    label: string;
    hint?: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            {children}
            {hint && (
                <span className="text-xs text-muted-foreground">{hint}</span>
            )}
            <InputError message={error} />
        </div>
    );
}

/**
 * Every assumption of the report, saved with one button; saving starts a
 * rebuild in the background so the numbers follow the parameters.
 */
export default function ParametersForm({
    parameters,
    opex,
    connections,
    seasons,
}: {
    parameters: Parameters;
    opex: OpexCategory[];
    connections: { key: string; label: string }[];
    seasons: string[];
}) {
    const form = useForm<Parameters>({
        ...parameters,
        opening: {
            mode: parameters.opening.mode ?? 'auto',
            date: parameters.opening.date ?? '',
            bank: { ...parameters.opening.bank },
            cash: { ...parameters.opening.cash },
            deposits: { ...parameters.opening.deposits },
        },
        opex: { ...parameters.opex },
    });

    const errors = form.errors as Record<string, string | undefined>;

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.put(CashFlowReportController.parameters.url(), {
            preserveScroll: true,
        });
    }

    const number = (value: number | null | undefined) =>
        value === null || value === undefined ? '' : String(value);

    return (
        <form onSubmit={submit} className="grid gap-4">
            <div className="grid gap-4 xl:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Sold inițial de trezorerie</CardTitle>
                        <CardDescription>
                            Automat: soldurile de sfârșit de lună din OMC
                            (bănci, casierii, depozite 5081) rulate cu
                            documentele de bancă și casă până azi. Manual:
                            soldurile de mai jos la data lor, rulate la fel.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-4">
                        <Field
                            id="opening-mode"
                            label="Sursa soldului inițial"
                            error={errors['opening.mode']}
                        >
                            <NativeSelect
                                id="opening-mode"
                                value={form.data.opening.mode}
                                onChange={(e) =>
                                    form.setData('opening', {
                                        ...form.data.opening,
                                        mode: e.target.value as
                                            | 'auto'
                                            | 'manual',
                                    })
                                }
                            >
                                <NativeSelectOption value="auto">
                                    automat din OMC (ultima lună închisă +
                                    documente până azi)
                                </NativeSelectOption>
                                <NativeSelectOption value="manual">
                                    manual (soldurile de mai jos)
                                </NativeSelectOption>
                            </NativeSelect>
                        </Field>
                        <Field
                            id="opening-date"
                            label="Data soldurilor (doar manual)"
                            error={errors['opening.date']}
                            hint="Ex. 2026-08-31. Fără dată, raportul pornește de la zero."
                        >
                            <Input
                                id="opening-date"
                                type="date"
                                value={form.data.opening.date ?? ''}
                                onChange={(e) =>
                                    form.setData('opening', {
                                        ...form.data.opening,
                                        date: e.target.value || null,
                                    })
                                }
                            />
                        </Field>
                        <div className="overflow-auto">
                            <table className="w-full text-sm">
                                <thead className="text-xs text-muted-foreground uppercase">
                                    <tr>
                                        <th className="py-1 text-left">
                                            Element
                                        </th>
                                        {CURRENCIES.map((currency) => (
                                            <th
                                                key={currency}
                                                className="py-1 text-right"
                                            >
                                                {currency}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {(
                                        [
                                            ['bank', 'Conturi curente bănci'],
                                            ['cash', 'Numerar în casierii'],
                                            [
                                                'deposits',
                                                'Depozite bancare / plasamente (5081)',
                                            ],
                                        ] as const
                                    ).map(([key, label]) => (
                                        <tr key={key}>
                                            <td className="py-1 pr-2">
                                                {label}
                                            </td>
                                            {CURRENCIES.map((currency) => (
                                                <td
                                                    key={currency}
                                                    className="py-1 pl-2"
                                                >
                                                    <Input
                                                        type="number"
                                                        step="0.01"
                                                        className="text-right"
                                                        aria-label={`${label} ${currency}`}
                                                        value={number(
                                                            form.data.opening[
                                                                key
                                                            ][currency],
                                                        )}
                                                        onChange={(e) =>
                                                            form.setData(
                                                                'opening',
                                                                {
                                                                    ...form.data
                                                                        .opening,
                                                                    [key]: {
                                                                        ...form
                                                                            .data
                                                                            .opening[
                                                                            key
                                                                        ],
                                                                        [currency]:
                                                                            e
                                                                                .target
                                                                                .value ===
                                                                            ''
                                                                                ? 0
                                                                                : Number(
                                                                                      e
                                                                                          .target
                                                                                          .value,
                                                                                  ),
                                                                    },
                                                                },
                                                            )
                                                        }
                                                    />
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Surse și curs</CardTitle>
                        <CardDescription>
                            Bazele eTrip citite și cursul folosit pentru
                            fluxurile în valută.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-4">
                        <div className="grid gap-2">
                            <Label>Baze eTrip</Label>
                            {connections.map((connection) => {
                                const checked =
                                    form.data.etrip_connections.includes(
                                        connection.key,
                                    );

                                return (
                                    <label
                                        key={connection.key}
                                        className="flex items-center gap-2 text-sm"
                                    >
                                        <Checkbox
                                            checked={checked}
                                            onCheckedChange={(value) =>
                                                form.setData(
                                                    'etrip_connections',
                                                    value
                                                        ? [
                                                              ...form.data
                                                                  .etrip_connections,
                                                              connection.key,
                                                          ]
                                                        : form.data.etrip_connections.filter(
                                                              (key) =>
                                                                  key !==
                                                                  connection.key,
                                                          ),
                                                )
                                            }
                                        />
                                        {connection.label}
                                    </label>
                                );
                            })}
                            <InputError
                                message={
                                    errors['etrip_connections'] ??
                                    errors['etrip_connections.0']
                                }
                            />
                        </div>
                        <div className="grid gap-3 sm:grid-cols-3">
                            <Field
                                id="fx-mode"
                                label="Curs valutar"
                                error={errors['fx.mode']}
                            >
                                <NativeSelect
                                    id="fx-mode"
                                    value={form.data.fx.mode}
                                    onChange={(e) =>
                                        form.setData('fx', {
                                            ...form.data.fx,
                                            mode: e.target.value as
                                                | 'auto'
                                                | 'manual',
                                        })
                                    }
                                >
                                    <NativeSelectOption value="auto">
                                        BNR din eTrip (automat)
                                    </NativeSelectOption>
                                    <NativeSelectOption value="manual">
                                        Manual
                                    </NativeSelectOption>
                                </NativeSelect>
                            </Field>
                            <Field
                                id="fx-eur"
                                label="EUR/RON"
                                error={errors['fx.EUR']}
                            >
                                <Input
                                    id="fx-eur"
                                    type="number"
                                    step="0.0001"
                                    value={number(form.data.fx.EUR)}
                                    onChange={(e) =>
                                        form.setData('fx', {
                                            ...form.data.fx,
                                            EUR: Number(e.target.value),
                                        })
                                    }
                                />
                            </Field>
                            <Field
                                id="fx-usd"
                                label="USD/RON"
                                error={errors['fx.USD']}
                            >
                                <Input
                                    id="fx-usd"
                                    type="number"
                                    step="0.0001"
                                    value={number(form.data.fx.USD)}
                                    onChange={(e) =>
                                        form.setData('fx', {
                                            ...form.data.fx,
                                            USD: Number(e.target.value),
                                        })
                                    }
                                />
                            </Field>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field
                                id="th-min"
                                label="Sold minim de siguranță (RON)"
                                error={errors['thresholds.minimum']}
                            >
                                <Input
                                    id="th-min"
                                    type="number"
                                    step="1"
                                    value={number(form.data.thresholds.minimum)}
                                    onChange={(e) =>
                                        form.setData('thresholds', {
                                            ...form.data.thresholds,
                                            minimum: Number(e.target.value),
                                        })
                                    }
                                />
                            </Field>
                            <Field
                                id="th-comfort"
                                label="Sold de confort (RON)"
                                error={errors['thresholds.comfort']}
                            >
                                <Input
                                    id="th-comfort"
                                    type="number"
                                    step="1"
                                    value={number(form.data.thresholds.comfort)}
                                    onChange={(e) =>
                                        form.setData('thresholds', {
                                            ...form.data.thresholds,
                                            comfort: Number(e.target.value),
                                        })
                                    }
                                />
                            </Field>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Încasări</CardTitle>
                        <CardDescription>
                            Cum se tratează soldurile cu scadența depășită din
                            eTrip.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-3 sm:grid-cols-2">
                        <Field
                            id="ov-days"
                            label="Restanțe recente: până la (zile)"
                            error={errors['overdue.recent_days']}
                        >
                            <Input
                                id="ov-days"
                                type="number"
                                value={number(form.data.overdue.recent_days)}
                                onChange={(e) =>
                                    form.setData('overdue', {
                                        ...form.data.overdue,
                                        recent_days: Number(e.target.value),
                                    })
                                }
                            />
                        </Field>
                        <Field
                            id="ov-pct"
                            label="% recuperat din restanțele recente"
                            error={errors['overdue.recent_pct']}
                        >
                            <Input
                                id="ov-pct"
                                type="number"
                                step="1"
                                value={number(form.data.overdue.recent_pct)}
                                onChange={(e) =>
                                    form.setData('overdue', {
                                        ...form.data.overdue,
                                        recent_pct: Number(e.target.value),
                                    })
                                }
                            />
                        </Field>
                        <Field
                            id="ov-weeks"
                            label="Recuperare egală pe (săptămâni)"
                            error={errors['overdue.recent_weeks']}
                        >
                            <Input
                                id="ov-weeks"
                                type="number"
                                value={number(form.data.overdue.recent_weeks)}
                                onChange={(e) =>
                                    form.setData('overdue', {
                                        ...form.data.overdue,
                                        recent_weeks: Number(e.target.value),
                                    })
                                }
                            />
                        </Field>
                        <Field
                            id="ov-old"
                            label="% recuperat din restanțele vechi"
                            error={errors['overdue.old_pct']}
                        >
                            <Input
                                id="ov-old"
                                type="number"
                                step="1"
                                value={number(form.data.overdue.old_pct)}
                                onChange={(e) =>
                                    form.setData('overdue', {
                                        ...form.data.overdue,
                                        old_pct: Number(e.target.value),
                                    })
                                }
                            />
                        </Field>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Plăți furnizori</CardTitle>
                        <CardDescription>
                            Regulile de plată ale serviciilor din eTrip și
                            soldul furnizorilor din OMC.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-3 sm:grid-cols-2">
                        <Field
                            id="pay-days"
                            label="Zile înainte de check-in (cazare, servicii la sol)"
                            error={errors['payables.days_before_checkin']}
                        >
                            <Input
                                id="pay-days"
                                type="number"
                                value={number(
                                    form.data.payables.days_before_checkin,
                                )}
                                onChange={(e) =>
                                    form.setData('payables', {
                                        ...form.data.payables,
                                        days_before_checkin: Number(
                                            e.target.value,
                                        ),
                                    })
                                }
                            />
                        </Field>
                        <Field
                            id="pay-tickets"
                            label="Bilete avion: comenzi din ultimele (zile)"
                            error={errors['payables.ticket_days']}
                        >
                            <Input
                                id="pay-tickets"
                                type="number"
                                value={number(form.data.payables.ticket_days)}
                                onChange={(e) =>
                                    form.setData('payables', {
                                        ...form.data.payables,
                                        ticket_days: Number(e.target.value),
                                    })
                                }
                            />
                        </Field>
                        <Field
                            id="pay-prepaid"
                            label="% din cazare deja plătit în avans (4092)"
                            error={errors['payables.prepaid_pct']}
                        >
                            <Input
                                id="pay-prepaid"
                                type="number"
                                step="1"
                                value={number(form.data.payables.prepaid_pct)}
                                onChange={(e) =>
                                    form.setData('payables', {
                                        ...form.data.payables,
                                        prepaid_pct: Number(e.target.value),
                                    })
                                }
                            />
                        </Field>
                        <Field
                            id="pay-balance"
                            label="Sold furnizori neachitat (RON)"
                            hint="Gol = facturile furnizor deschise din OMC, pe scadență."
                            error={errors['payables.supplier_balance']}
                        >
                            <Input
                                id="pay-balance"
                                type="number"
                                step="1"
                                placeholder="automat din OMC"
                                value={number(
                                    form.data.payables.supplier_balance,
                                )}
                                onChange={(e) =>
                                    form.setData('payables', {
                                        ...form.data.payables,
                                        supplier_balance:
                                            e.target.value === ''
                                                ? null
                                                : Number(e.target.value),
                                    })
                                }
                            />
                        </Field>
                        <Field
                            id="pay-weeks"
                            label="Soldul restant se achită pe (săptămâni)"
                            error={errors['payables.supplier_balance_weeks']}
                        >
                            <Input
                                id="pay-weeks"
                                type="number"
                                value={number(
                                    form.data.payables.supplier_balance_weeks,
                                )}
                                onChange={(e) =>
                                    form.setData('payables', {
                                        ...form.data.payables,
                                        supplier_balance_weeks: Number(
                                            e.target.value,
                                        ),
                                    })
                                }
                            />
                        </Field>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Scenariu vânzări noi</CardTitle>
                        <CardDescription>
                            Dosarele create în aceeași săptămână a anului
                            anterior, cu încasările și costurile lor efective,
                            decalate 52 de săptămâni și înmulțite cu factorul.
                            Charterul sezonului următor se estimează din
                            programul sezonului de bază decalat un an.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-3 sm:grid-cols-2">
                        <label className="flex items-center gap-3 text-sm sm:col-span-2">
                            <Switch
                                checked={form.data.scenario.enabled}
                                onCheckedChange={(value) =>
                                    form.setData('scenario', {
                                        ...form.data.scenario,
                                        enabled: value,
                                    })
                                }
                            />
                            Include scenariul de vânzări noi în totaluri
                        </label>
                        <Field
                            id="sc-factor"
                            label="Factor vânzări noi vs an anterior"
                            error={errors['scenario.factor']}
                        >
                            <Input
                                id="sc-factor"
                                type="number"
                                step="0.01"
                                value={number(form.data.scenario.factor)}
                                onChange={(e) =>
                                    form.setData('scenario', {
                                        ...form.data.scenario,
                                        factor: Number(e.target.value),
                                    })
                                }
                            />
                        </Field>
                        <Field
                            id="sc-charter-factor"
                            label="Factor charter sezon următor"
                            error={errors['scenario.charter_factor']}
                        >
                            <Input
                                id="sc-charter-factor"
                                type="number"
                                step="0.01"
                                value={number(
                                    form.data.scenario.charter_factor,
                                )}
                                onChange={(e) =>
                                    form.setData('scenario', {
                                        ...form.data.scenario,
                                        charter_factor: Number(e.target.value),
                                    })
                                }
                            />
                        </Field>
                        <Field
                            id="sc-base"
                            label="Sezon charter de bază"
                            hint="Programul acestui sezon, decalat un an, estimează sezonul următor."
                            error={errors['scenario.charter_base_season']}
                        >
                            <NativeSelect
                                id="sc-base"
                                value={
                                    form.data.scenario.charter_base_season ?? ''
                                }
                                onChange={(e) =>
                                    form.setData('scenario', {
                                        ...form.data.scenario,
                                        charter_base_season:
                                            e.target.value || null,
                                    })
                                }
                            >
                                <NativeSelectOption value="">
                                    – fără estimare –
                                </NativeSelectOption>
                                {seasons.map((season) => (
                                    <NativeSelectOption
                                        key={season}
                                        value={season}
                                    >
                                        {season}
                                    </NativeSelectOption>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field
                            id="sc-target"
                            label="Sezonul estimat (etichetă)"
                            hint="Când există un contract pe acest sezon, estimarea se oprește."
                            error={errors['scenario.charter_target_season']}
                        >
                            <Input
                                id="sc-target"
                                placeholder="S27"
                                value={
                                    form.data.scenario.charter_target_season ??
                                    ''
                                }
                                onChange={(e) =>
                                    form.setData('scenario', {
                                        ...form.data.scenario,
                                        charter_target_season:
                                            e.target.value || null,
                                    })
                                }
                            />
                        </Field>
                    </CardContent>
                </Card>

                <Card className="xl:col-span-2">
                    <CardHeader>
                        <CardTitle>OPEX – medii lunare (RON)</CardTitle>
                        <CardDescription>
                            Categoriile cu conturi se calculează din facturile
                            furnizor OMC din ultimele 12 luni; lasă câmpul gol
                            ca să folosești media calculată. Salariile,
                            contribuțiile, impozitul, comisioanele și
                            dividendele se introduc aici.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-auto">
                            <table className="w-full text-sm">
                                <thead className="text-xs text-muted-foreground uppercase">
                                    <tr>
                                        <th className="py-1 text-left">
                                            Categorie
                                        </th>
                                        <th className="py-1 text-left">
                                            Moment plată
                                        </th>
                                        <th className="py-1 text-right">
                                            Medie OMC
                                        </th>
                                        <th className="py-1 text-right">
                                            Valoare folosită
                                        </th>
                                        <th className="w-44 py-1 text-right">
                                            Suprascriere
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-sidebar-border/70">
                                    {opex.map((category) => (
                                        <tr key={category.key}>
                                            <td className="py-1.5 pr-2">
                                                <div>{category.label}</div>
                                                <div className="text-xs text-muted-foreground">
                                                    {category.source}
                                                </div>
                                            </td>
                                            <td className="py-1.5 pr-2 text-xs text-muted-foreground">
                                                {category.rule.type ===
                                                'monthly'
                                                    ? `ziua ${category.rule.day} a lunii`
                                                    : category.rule.type ===
                                                        'quarterly'
                                                      ? `trimestrial, ziua ${category.rule.day}`
                                                      : 'uniform pe săptămâni'}
                                            </td>
                                            <td className="py-1.5 pr-2 text-right tabular-nums">
                                                {category.computed !== null
                                                    ? fmtRon(category.computed)
                                                    : '–'}
                                            </td>
                                            <td className="py-1.5 pr-2 text-right font-medium tabular-nums">
                                                {fmtRon(
                                                    form.data.opex[
                                                        category.key
                                                    ] ??
                                                        category.computed ??
                                                        category.monthly,
                                                )}
                                            </td>
                                            <td className="py-1.5 pl-2">
                                                <Input
                                                    type="number"
                                                    step="1"
                                                    className="text-right"
                                                    aria-label={category.label}
                                                    placeholder={
                                                        category.computed !==
                                                        null
                                                            ? 'automat'
                                                            : '0'
                                                    }
                                                    value={number(
                                                        form.data.opex[
                                                            category.key
                                                        ],
                                                    )}
                                                    onChange={(e) =>
                                                        form.setData('opex', {
                                                            ...form.data.opex,
                                                            [category.key]:
                                                                e.target
                                                                    .value ===
                                                                ''
                                                                    ? null
                                                                    : Number(
                                                                          e
                                                                              .target
                                                                              .value,
                                                                      ),
                                                        })
                                                    }
                                                />
                                                <InputError
                                                    message={
                                                        errors[
                                                            `opex.${category.key}`
                                                        ]
                                                    }
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>
            </div>
            <div className="flex items-center gap-3">
                <Button type="submit" disabled={form.processing}>
                    <Save />
                    Salvează parametrii și recalculează
                </Button>
                {form.recentlySuccessful && (
                    <span className="text-sm text-muted-foreground">
                        Salvat.
                    </span>
                )}
            </div>
        </form>
    );
}
