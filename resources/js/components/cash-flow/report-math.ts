import type { ReportLine, ReportPayload } from '@/types/cash-flow';

export type DerivedReport = {
    weeks: string[];
    opening: number[];
    inflows: number[];
    outflows: number[];
    opex: number[];
    net: number[];
    closing: number[];
    minimum: number;
    comfort: number;
    signal: string[];
    lines: ReportLine[];
};

const nums = (line: ReportLine | undefined, weeks: number): number[] =>
    line
        ? line.values.map((value) => (typeof value === 'number' ? value : 0))
        : new Array(weeks).fill(0);

export function lineByCode(
    lines: ReportLine[],
    code: string,
): ReportLine | undefined {
    return lines.find((line) => line.code === code);
}

/**
 * Recomputes the totals, the net flow and the balance chain from the
 * component lines, with or without the scenario lines, so the toggles on
 * the page never wait for the server.
 */
export function deriveReport(
    payload: ReportPayload,
    scenarioOn: boolean,
): DerivedReport {
    const weeks = payload.weeks;
    const count = weeks.length;
    const zero = () => new Array<number>(count).fill(0);
    const include = (line: ReportLine) =>
        line.kind === 'value' && (scenarioOn || !line.scenario);

    const sumSection = (section: ReportLine['section']) =>
        payload.lines
            .filter((line) => line.section === section && include(line))
            .reduce((acc, line) => {
                nums(line, count).forEach((value, i) => (acc[i] += value));

                return acc;
            }, zero());

    const inflows = sumSection('B');
    const outflows = sumSection('C');
    const opex = sumSection('D');
    const minimum = Number(payload.params.thresholds?.minimum ?? 0);
    const comfort = Number(payload.params.thresholds?.comfort ?? 0);
    const openingStart = nums(lineByCode(payload.lines, 'A'), count)[0] ?? 0;

    const opening = zero();
    const net = zero();
    const closing = zero();
    let balance = openingStart;

    for (let i = 0; i < count; i++) {
        opening[i] = balance;
        net[i] = inflows[i] - outflows[i] - opex[i];
        balance += net[i];
        closing[i] = balance;
    }

    const signal = closing.map((value) =>
        value < minimum ? 'DEFICIT' : value < comfort ? 'ATENȚIE' : 'OK',
    );

    const replace: Record<string, number[] | string[]> = {
        A: opening,
        B: inflows,
        C: outflows,
        D: opex,
        E1: net,
        E2: closing,
        E4: closing.map((value) => value - minimum),
        E5: signal,
    };

    const lines = payload.lines.map((line) =>
        replace[line.code]
            ? {
                  ...line,
                  values: replace[line.code],
                  total:
                      line.kind === 'total'
                          ? (replace[line.code] as number[]).reduce(
                                (a, b) => a + b,
                                0,
                            )
                          : line.total,
              }
            : line,
    );

    return {
        weeks,
        opening,
        inflows,
        outflows,
        opex,
        net,
        closing,
        minimum,
        comfort,
        signal,
        lines,
    };
}

export type MonthGroup = { key: string; label: string; indexes: number[] };

const MONTHS = [
    'ian',
    'feb',
    'mar',
    'apr',
    'mai',
    'iun',
    'iul',
    'aug',
    'sep',
    'oct',
    'nov',
    'dec',
];

export function monthGroups(weeks: string[]): MonthGroup[] {
    const groups: MonthGroup[] = [];

    weeks.forEach((week, index) => {
        const key = week.slice(0, 7);
        let group = groups[groups.length - 1];

        if (!group || group.key !== key) {
            const [year, month] = key.split('-');
            group = {
                key,
                label: `${MONTHS[Number(month) - 1]} ${year.slice(2)}`,
                indexes: [],
            };
            groups.push(group);
        }

        group.indexes.push(index);
    });

    return groups;
}

export function weekLabel(monday: string, withYear = false): string {
    const [year, month, day] = monday.split('-');

    return withYear ? `${day}.${month}.${year.slice(2)}` : `${day}.${month}`;
}

export function fmtRon(value: number | null | undefined, decimals = 0): string {
    if (value === null || value === undefined || Number.isNaN(value)) {
        return '–';
    }

    return new Intl.NumberFormat('ro-RO', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }).format(value);
}

export function fmtCompact(value: number): string {
    const abs = Math.abs(value);

    if (abs >= 1_000_000) {
        return `${(value / 1_000_000).toLocaleString('ro-RO', { maximumFractionDigits: 1 })} M`;
    }

    if (abs >= 1_000) {
        return `${(value / 1_000).toLocaleString('ro-RO', { maximumFractionDigits: 0 })} k`;
    }

    return value.toLocaleString('ro-RO', { maximumFractionDigits: 0 });
}

export function fmtDelta(current: number, previous: number | null): string {
    if (previous === null || previous === undefined) {
        return '–';
    }

    const delta = current - previous;
    const pct = previous !== 0 ? (delta / Math.abs(previous)) * 100 : null;

    return `${delta > 0 ? '+' : ''}${fmtCompact(delta)}${pct !== null ? ` (${pct > 0 ? '+' : ''}${pct.toFixed(0)}%)` : ''}`;
}
