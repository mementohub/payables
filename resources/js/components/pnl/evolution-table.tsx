import { ChevronDown, ChevronRight } from 'lucide-react';
import { Fragment, useState } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';

export type EvolutionCell = {
    total: number;
    by_channel: Record<string, number>;
    by_product: Record<string, number>;
};

export type EvolutionRow = {
    key: string;
    label: string;
    kind: 'strong' | 'cost' | 'result' | 'line';
    /** Grupa din care face parte linia de cost. */
    group?: string;
    /** Câte linii stau sub grupă. */
    lines?: number;
    /** Contul P&L al liniei de cheltuială; după el se cere detaliul. */
    saf?: string;
    /** Rândul se citește și ca procent din altul (marja din venit). */
    of?: string;
    /** Rând de cheltuială: creșterea e veste proastă, nu bună. */
    cost?: boolean;
    cells: Record<string, EvolutionCell>;
};

export type UnpostedMonth = { month: number; label: string; reasons: string[] };

export type PnlEvolution = {
    year: number;
    /** Până în a câta lună merge tabelul; 12 înseamnă anul întreg. */
    through?: number;
    channels: string[];
    products: string[];
    periods: {
        code: string;
        label: string;
        kind: 'month' | 'quarter' | 'year';
        /** Trimestrul tăiat de luna aleasă, sau anul oprit înainte de decembrie. */
        partial?: boolean;
        /** Din ce luni e făcută coloana: [prima, ultima]. */
        months?: [number, number];
        /** Lunile din perioadă pe care contabilitatea nu le-a închis încă. */
        unposted?: UnpostedMonth[];
    }[];
    rows: EvolutionRow[];
};

/**
 * Cât s-a schimbat față de anul trecut, sub cifră.
 *
 * Verde unde e bine, roșu unde nu — iar la cheltuieli „bine” înseamnă mai
 * puțin. La vârful cursorului stă și cifra de anul trecut, ca să nu fie
 * nevoie de încă un tabel alături.
 */
function Against({
    change,
    unit,
    year,
}: {
    change: { text: string; good: boolean; was: number } | null;
    unit: 'lei' | 'mii';
    /** Anul cu care se compară, pentru ce scrie la vârful cursorului. */
    year: number;
}) {
    if (change === null) {
        return null;
    }

    const scale = unit === 'mii' ? 1000 : 1;
    const was =
        Math.abs(change.was) < scale / 2
            ? '–'
            : number(change.was / scale);

    return (
        <span
            className="block text-[10px] leading-tight"
            title={`${year}, aceeași perioadă: ${number(change.was)} lei`}
        >
            <span className="text-muted-foreground">{was}</span>{' '}
            <span
                className={cn(
                    change.good
                        ? 'text-emerald-600 dark:text-emerald-500'
                        : 'text-red-600 dark:text-red-400',
                )}
            >
                {change.text}
            </span>
        </span>
    );
}

const number = (value: number, decimals = 0) =>
    new Intl.NumberFormat('ro-RO', {
        maximumFractionDigits: decimals,
        minimumFractionDigits: decimals,
    }).format(value);

/**
 * Anul întreg dintr-o privire: fiecare lună, trimestrul ei și totalul, pe
 * aceleași coloane ale vederii.
 *
 * Tabelul e lat prin fire: se citește trăgând de el în lateral, cu linia
 * lipită în stânga, ca ochiul să nu piardă rândul. Trimestrele și anul stau
 * pe fundal apăsat, ca să se vadă unde se închide socoteala.
 */
export function PnlEvolutionTable({
    evolution,
    previous,
    view,
    labelOf,
    onCost,
}: {
    evolution: PnlEvolution;
    /** Același tabel, anul trecut; lipsește dacă nu s-a cerut comparația. */
    previous?: PnlEvolution | null;
    view: 'channel' | 'product';
    labelOf: (key: string) => string;
    /**
     * Ce se întâmplă la un clic pe o cheltuială: se deschide din ce e făcută,
     * pentru perioada coloanei apăsate — ca în vederea unei singure perioade.
     */
    onCost?: (cost: {
        saf: string;
        label: string;
        period: string;
        column: string | null;
    }) => void;
}) {
    // Strâns, fiecare perioadă e o singură coloană: luna, trimestrul, anul.
    // Desfăcut, sub ea stau coloanele vederii. Se poate și una câte una, cu un
    // clic pe capul perioadei — de cele mai multe ori interesează o singură
    // lună, nu toate douăsprezece deodată.
    const [open, setOpen] = useState<Set<string>>(new Set());
    const [unit, setUnit] = useState<'lei' | 'mii'>('mii');
    // Costurile se strâng în două trepte: întâi rămân doar rândurile groase
    // (venit, marjă, cheltuieli, EBITDA, profit), apoi se desfac pe grupe, iar
    // o grupă deschisă își arată liniile ei.
    const [groups, setGroups] = useState(false);
    const [openGroups, setOpenGroups] = useState<Set<string>>(new Set());

    const toggleGroup = (group: string) =>
        setOpenGroups((current) => {
            const next = new Set(current);

            if (!next.delete(group)) {
                next.add(group);
            }

            return next;
        });

    const rows = evolution.rows.filter((row) => {
        if (row.kind === 'line') {
            return groups && openGroups.has(row.group ?? '');
        }

        return row.kind !== 'cost' || groups;
    });

    const split = (code: string) => open.has(code);
    const allOpen = open.size === evolution.periods.length;

    const toggle = (code: string) =>
        setOpen((current) => {
            const next = new Set(current);

            if (!next.delete(code)) {
                next.add(code);
            }

            return next;
        });

    const members =
        view === 'channel' ? evolution.channels : evolution.products;
    const scale = unit === 'mii' ? 1000 : 1;
    const money = (value: number) =>
        Math.abs(value) < scale / 2 ? '–' : number(value / scale);

    const valueOf = (
        cell: EvolutionCell | undefined,
        member: string | null,
    ) => {
        if (cell === undefined) {
            return 0;
        }

        if (member === null) {
            return cell.total;
        }

        return (
            (view === 'channel'
                ? cell.by_channel[member]
                : cell.by_product[member]) ?? 0
        );
    };

    /**
     * Cât face cifra din veniturile nete ale coloanei ei.
     *
     * Rândurile care-și declară baza o păstrează; toate celelalte se judecă
     * după venituri — o cheltuială de trei milioane nu spune nimic până nu
     * știi din cât.
     */
    const share = (row: EvolutionRow, code: string, member: string | null) => {
        if (row.key === 'revenue') {
            return null;
        }

        const base = evolution.rows.find(
            (other) => other.key === (row.of ?? 'revenue'),
        );
        const of = base === undefined ? 0 : valueOf(base.cells[code], member);

        return of === 0
            ? null
            : `${((valueOf(row.cells[code], member) / of) * 100).toFixed(1)}%`;
    };

    /**
     * Cât s-a schimbat față de anul trecut, pe aceeași perioadă.
     *
     * Se dă în procente, nu în lei: pe un tabel cu șaptesprezece coloane,
     * cifrele mari una lângă alta nu se mai citesc, iar ochiul caută oricum
     * dacă a crescut sau a scăzut.
     */
    const against = (
        row: EvolutionRow,
        code: string,
        member: string | null,
    ) => {
        if (!previous) {
            return null;
        }

        const before = previous.rows.find((other) => other.key === row.key);

        if (before === undefined) {
            return null;
        }

        const was = valueOf(before.cells[code], member);

        if (Math.abs(was) < 0.0005) {
            return null;
        }

        const now = valueOf(row.cells[code], member);
        const change = ((now - was) / Math.abs(was)) * 100;

        return {
            text: `${change > 0 ? '+' : ''}${change.toFixed(1)}%`,
            // La cheltuieli, creșterea e veste proastă.
            good:
                row.cost === true || row.kind === 'cost' || row.kind === 'line'
                    ? change < 0
                    : change > 0,
            was,
        };
    };

    /**
     * Cum se cheamă perioada coloanei în restul aplicației, ca detaliul să
     * poată fi cerut pentru ea.
     *
     * Trimestrul tăiat de luna aleasă n-are nume — nu e nici trimestrul
     * întreg, nici cumulul — deci pe el nu se poate apăsa.
     */
    const periodKey = (period: PnlEvolution['periods'][number]) => {
        const months = period.months;

        if (period.kind === 'month') {
            return months ? 'mtd' + months[0] : null;
        }

        // Trimestrul e luat singur, nu cumulat: „q3” ar fi însemnat
        // trimestrele I–III la un loc, iar coloana arată doar iulie–septembrie.
        if (period.kind === 'quarter') {
            return months ? 'r' + months[0] + '-' + months[1] : null;
        }

        return (evolution.through ?? 12) < 12
            ? 'ytd' + (evolution.through ?? 12)
            : 'year';
    };

    /** Se poate apăsa? Doar liniile de cheltuială, pe o coloană cu luni. */
    const clickable = (
        row: EvolutionRow,
        period: PnlEvolution['periods'][number],
    ) =>
        onCost !== undefined &&
        // Liniile de cheltuială, plus amortizarea, rezultatul financiar și
        // impozitul: ele nu stau pe o linie, dar se citesc din conturile lor.
        ((row.kind === 'line' && row.saf !== undefined) ||
            row.key.startsWith('below:')) &&
        periodKey(period) !== null;

    const openCost = (
        row: EvolutionRow,
        period: PnlEvolution['periods'][number],
        member: string | null,
    ) => {
        const key = periodKey(period);

        if (!clickable(row, period) || key === null) {
            return;
        }

        onCost?.({
            saf: row.saf ?? row.key,
            label: row.label,
            period: key,
            column: member,
        });
    };

    // Trimestrul și anul se văd de departe: ele sunt reperele cu care se
    // compară lunile dintre ele.
    const tone = (kind: string) =>
        kind === 'year'
            ? 'bg-muted/70'
            : kind === 'quarter'
              ? 'bg-muted/40'
              : '';

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-end gap-3">
                <div className="grid gap-1.5">
                    <Label>Coloane</Label>
                    <ToggleGroup
                        type="single"
                        value={allOpen ? 'split' : 'total'}
                        onValueChange={(value) =>
                            value &&
                            setOpen(
                                value === 'split'
                                    ? new Set(
                                          evolution.periods.map(
                                              (period) => period.code,
                                          ),
                                      )
                                    : new Set(),
                            )
                        }
                        variant="outline"
                    >
                        <ToggleGroupItem value="total">
                            Doar lună, trimestru, an
                        </ToggleGroupItem>
                        <ToggleGroupItem value="split">
                            {view === 'channel' ? 'Pe canale' : 'Pe produse'}
                        </ToggleGroupItem>
                    </ToggleGroup>
                </div>
                <div className="grid gap-1.5">
                    <Label>Rânduri</Label>
                    <ToggleGroup
                        type="single"
                        value={groups ? 'groups' : 'totals'}
                        onValueChange={(value) => {
                            if (!value) {
                                return;
                            }

                            setGroups(value === 'groups');

                            if (value === 'totals') {
                                setOpenGroups(new Set());
                            }
                        }}
                        variant="outline"
                    >
                        <ToggleGroupItem value="totals">
                            Doar totalurile
                        </ToggleGroupItem>
                        <ToggleGroupItem value="groups">
                            Pe grupe de cost
                        </ToggleGroupItem>
                    </ToggleGroup>
                </div>
                <div className="grid gap-1.5">
                    <Label>Unitate</Label>
                    <ToggleGroup
                        type="single"
                        value={unit}
                        onValueChange={(value) =>
                            value && setUnit(value as 'lei' | 'mii')
                        }
                        variant="outline"
                    >
                        <ToggleGroupItem value="mii">mii lei</ToggleGroupItem>
                        <ToggleGroupItem value="lei">lei</ToggleGroupItem>
                    </ToggleGroup>
                </div>
                <p className="text-sm text-muted-foreground">
                    Lunile fără nicio mișcare nu se desenează. Un clic pe numele
                    perioadei o deschide pe coloanele vederii, iar unul pe grupa
                    de cost îi arată liniile. Bulina{' '}
                    <span className="text-amber-600 dark:text-amber-400">
                        ●
                    </span>{' '}
                    arată o lună pe care contabilitatea n-a închis-o încă.
                    {(evolution.through ?? 12) < 12 &&
                        ' Tabelul se oprește la luna aleasă: trimestrul tăiat la jumătate poartă o stea, iar ultima coloană e cumulul până acolo.'}
                    {onCost !== undefined &&
                        ' Un clic pe cifra unei cheltuieli arată din ce e făcută în perioada aia.'}
                </p>
            </div>

            <Card>
                <CardContent className="overflow-x-auto p-0">
                    <Table className="text-xs">
                        <TableHeader>
                            <TableRow>
                                <TableHead
                                    rowSpan={2}
                                    className="sticky left-0 z-20 w-64 max-w-64 min-w-64 bg-card align-bottom"
                                >
                                    Linie
                                </TableHead>
                                {evolution.periods.map((period) => (
                                    <TableHead
                                        key={period.code}
                                        colSpan={
                                            split(period.code)
                                                ? members.length + 1
                                                : 1
                                        }
                                        className={cn(
                                            'border-l text-center font-semibold',
                                            tone(period.kind),
                                            period.kind !== 'month' &&
                                                'text-foreground',
                                        )}
                                    >
                                        <button
                                            type="button"
                                            onClick={() => toggle(period.code)}
                                            className="inline-flex items-center gap-0.5 hover:text-foreground"
                                            title={
                                                split(period.code)
                                                    ? 'Strânge perioada'
                                                    : view === 'channel'
                                                      ? 'Desfă pe canale'
                                                      : 'Desfă pe produse'
                                            }
                                        >
                                            {(period.unposted?.length ?? 0) >
                                                0 && (
                                                <span
                                                    className="mr-0.5 text-amber-600 dark:text-amber-400"
                                                    title={`Contabilitatea n-a închis încă: ${period.unposted
                                                        ?.map(
                                                            (month) =>
                                                                `${month.label} (${month.reasons.join('; ')})`,
                                                        )
                                                        .join(', ')}`}
                                                >
                                                    ●
                                                </span>
                                            )}
                                            {period.label}
                                            {split(period.code) ? (
                                                <ChevronDown className="size-3" />
                                            ) : (
                                                <ChevronRight className="size-3 opacity-50" />
                                            )}
                                        </button>
                                    </TableHead>
                                ))}
                            </TableRow>
                            <TableRow>
                                {evolution.periods.map((period) => (
                                    <Fragment key={period.code}>
                                        <TableHead
                                            className={cn(
                                                'border-l text-right',
                                                tone(period.kind),
                                            )}
                                        >
                                            Total
                                        </TableHead>
                                        {split(period.code) &&
                                            members.map((member) => (
                                                <TableHead
                                                    key={member}
                                                    className={cn(
                                                        'text-right font-normal whitespace-nowrap text-muted-foreground',
                                                        tone(period.kind),
                                                    )}
                                                >
                                                    {labelOf(member)}
                                                </TableHead>
                                            ))}
                                    </Fragment>
                                ))}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((row) => (
                                <TableRow
                                    key={row.key}
                                    className={cn(
                                        row.kind === 'result' &&
                                            'bg-muted/30 font-semibold',
                                        row.kind === 'strong' && 'font-medium',
                                    )}
                                >
                                    <TableCell
                                        className={cn(
                                            'sticky left-0 z-10 w-64 max-w-64 min-w-64 truncate bg-card',
                                            row.kind === 'cost' &&
                                                'text-muted-foreground',
                                            row.kind === 'line' &&
                                                'pl-7 text-muted-foreground',
                                        )}
                                        title={row.label}
                                    >
                                        {row.key.startsWith('group:') ? (
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    toggleGroup(row.label)
                                                }
                                                className="inline-flex max-w-full items-center gap-1 hover:text-foreground"
                                            >
                                                {openGroups.has(row.label) ? (
                                                    <ChevronDown className="size-3 shrink-0" />
                                                ) : (
                                                    <ChevronRight className="size-3 shrink-0 opacity-50" />
                                                )}
                                                <span className="truncate">
                                                    {row.label}
                                                </span>
                                                {row.lines !== undefined &&
                                                    row.lines > 1 && (
                                                        <span className="text-[10px] opacity-60">
                                                            ({row.lines})
                                                        </span>
                                                    )}
                                            </button>
                                        ) : (
                                            row.label
                                        )}
                                    </TableCell>
                                    {evolution.periods.map((period) => (
                                        <Fragment key={period.code}>
                                            <TableCell
                                                className={cn(
                                                    'border-l text-right whitespace-nowrap tabular-nums',
                                                    tone(period.kind),
                                                    clickable(row, period) &&
                                                        'cursor-pointer hover:bg-accent/60',
                                                )}
                                                title={
                                                    clickable(row, period)
                                                        ? 'Vezi din ce e făcută cheltuiala în ' +
                                                          period.label
                                                        : undefined
                                                }
                                                onClick={() =>
                                                    openCost(row, period, null)
                                                }
                                            >
                                                {money(
                                                    valueOf(
                                                        row.cells[period.code],
                                                        null,
                                                    ),
                                                )}
                                                {share(
                                                    row,
                                                    period.code,
                                                    null,
                                                ) !== null && (
                                                    <span className="ml-1 text-[10px] text-muted-foreground">
                                                        {share(
                                                            row,
                                                            period.code,
                                                            null,
                                                        )}
                                                    </span>
                                                )}
                                                <Against
                                                    change={against(
                                                        row,
                                                        period.code,
                                                        null,
                                                    )}
                                                    unit={unit}
                                                    year={evolution.year - 1}
                                                />
                                            </TableCell>
                                            {split(period.code) &&
                                                members.map((member) => (
                                                    <TableCell
                                                        key={member}
                                                        className={cn(
                                                            'text-right whitespace-nowrap tabular-nums',
                                                            tone(period.kind),
                                                            clickable(
                                                                row,
                                                                period,
                                                            ) &&
                                                                'cursor-pointer hover:bg-accent/60',
                                                        )}
                                                        title={
                                                            clickable(
                                                                row,
                                                                period,
                                                            )
                                                                ? 'Vezi din ce e făcută cheltuiala în ' +
                                                                  period.label +
                                                                  ' · ' +
                                                                  labelOf(
                                                                      member,
                                                                  )
                                                                : undefined
                                                        }
                                                        onClick={() =>
                                                            openCost(
                                                                row,
                                                                period,
                                                                member,
                                                            )
                                                        }
                                                    >
                                                        {money(
                                                            valueOf(
                                                                row.cells[
                                                                    period.code
                                                                ],
                                                                member,
                                                            ),
                                                        )}
                                                        {share(
                                                            row,
                                                            period.code,
                                                            member,
                                                        ) !== null && (
                                                            <span className="ml-1 text-[10px] text-muted-foreground">
                                                                {share(
                                                                    row,
                                                                    period.code,
                                                                    member,
                                                                )}
                                                            </span>
                                                        )}
                                                        <Against
                                                            change={against(
                                                                row,
                                                                period.code,
                                                                member,
                                                            )}
                                                            unit={unit}
                                                            year={
                                                                evolution.year -
                                                                1
                                                            }
                                                        />
                                                    </TableCell>
                                                ))}
                                        </Fragment>
                                    ))}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
        </div>
    );
}
