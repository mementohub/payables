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
    /** Rândul se citește și ca procent din altul (marja din venit). */
    of?: string;
    cells: Record<string, EvolutionCell>;
};

export type PnlEvolution = {
    year: number;
    channels: string[];
    products: string[];
    periods: {
        code: string;
        label: string;
        kind: 'month' | 'quarter' | 'year';
    }[];
    rows: EvolutionRow[];
};

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
    view,
    labelOf,
}: {
    evolution: PnlEvolution;
    view: 'channel' | 'product';
    labelOf: (key: string) => string;
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

    const share = (row: EvolutionRow, code: string, member: string | null) => {
        if (row.of === undefined) {
            return null;
        }

        const base = evolution.rows.find((other) => other.key === row.of);
        const of = base === undefined ? 0 : valueOf(base.cells[code], member);

        return of === 0
            ? null
            : `${((valueOf(row.cells[code], member) / of) * 100).toFixed(1)}%`;
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
                    de cost îi arată liniile.
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
                                                )}
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
                                            </TableCell>
                                            {split(period.code) &&
                                                members.map((member) => (
                                                    <TableCell
                                                        key={member}
                                                        className={cn(
                                                            'text-right whitespace-nowrap tabular-nums',
                                                            tone(period.kind),
                                                        )}
                                                    >
                                                        {money(
                                                            valueOf(
                                                                row.cells[
                                                                    period.code
                                                                ],
                                                                member,
                                                            ),
                                                        )}
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
