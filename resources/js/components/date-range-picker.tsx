import {
    endOfMonth,
    endOfQuarter,
    endOfYear,
    format,
    parse,
    startOfMonth,
    startOfQuarter,
    startOfYear,
    subMonths,
    subQuarters,
    subYears,
} from 'date-fns';
import { ro } from 'date-fns/locale';
import { CalendarIcon, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { DateRange } from 'react-day-picker';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';

export type DateRangeValue = {
    from: string | null;
    to: string | null;
};

type PresetKey =
    | 'current_month'
    | 'last_month'
    | 'current_quarter'
    | 'last_quarter'
    | 'current_year'
    | 'last_year';

const PRESETS: { key: PresetKey; label: string; compute: () => { from: Date; to: Date } }[] = [
    {
        key: 'current_month',
        label: 'Luna curentă',
        compute: () => ({ from: startOfMonth(new Date()), to: endOfMonth(new Date()) }),
    },
    {
        key: 'last_month',
        label: 'Luna trecută',
        compute: () => {
            const prev = subMonths(new Date(), 1);
            return { from: startOfMonth(prev), to: endOfMonth(prev) };
        },
    },
    {
        key: 'current_quarter',
        label: 'Trimestrul curent',
        compute: () => ({ from: startOfQuarter(new Date()), to: endOfQuarter(new Date()) }),
    },
    {
        key: 'last_quarter',
        label: 'Trimestrul trecut',
        compute: () => {
            const prev = subQuarters(new Date(), 1);
            return { from: startOfQuarter(prev), to: endOfQuarter(prev) };
        },
    },
    {
        key: 'current_year',
        label: 'Anul curent',
        compute: () => ({ from: startOfYear(new Date()), to: endOfYear(new Date()) }),
    },
    {
        key: 'last_year',
        label: 'Anul trecut',
        compute: () => {
            const prev = subYears(new Date(), 1);
            return { from: startOfYear(prev), to: endOfYear(prev) };
        },
    },
];

function parseISO(value: string | null): Date | undefined {
    if (!value) {
        return undefined;
    }
    const d = parse(value, 'yyyy-MM-dd', new Date());
    return Number.isNaN(d.getTime()) ? undefined : d;
}

function sameDay(a: Date, b: Date): boolean {
    return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
}

function matchPreset(from: Date | undefined, to: Date | undefined): PresetKey | null {
    if (!from || !to) {
        return null;
    }
    for (const preset of PRESETS) {
        const { from: pFrom, to: pTo } = preset.compute();
        if (sameDay(from, pFrom) && sameDay(to, pTo)) {
            return preset.key;
        }
    }
    return null;
}

type Props = {
    value: DateRangeValue;
    onChange: (value: DateRangeValue) => void;
    placeholder?: string;
    className?: string;
    disabled?: boolean;
    allowClear?: boolean;
};

export default function DateRangePicker({
    value,
    onChange,
    placeholder = 'Alege perioada',
    className,
    disabled,
    allowClear = true,
}: Props) {
    const fromDate = parseISO(value.from);
    const toDate = parseISO(value.to);
    const [open, setOpen] = useState(false);

    const activePreset = useMemo(() => matchPreset(fromDate, toDate), [fromDate, toDate]);

    const label = useMemo(() => {
        if (activePreset) {
            return PRESETS.find((p) => p.key === activePreset)?.label ?? placeholder;
        }
        if (fromDate && toDate) {
            return `${format(fromDate, 'd MMM yyyy', { locale: ro })} – ${format(toDate, 'd MMM yyyy', { locale: ro })}`;
        }
        if (fromDate) {
            return `${format(fromDate, 'd MMM yyyy', { locale: ro })} – ...`;
        }
        return placeholder;
    }, [activePreset, fromDate, toDate, placeholder]);

    const applyPreset = (preset: (typeof PRESETS)[number]) => {
        const { from, to } = preset.compute();
        onChange({ from: format(from, 'yyyy-MM-dd'), to: format(to, 'yyyy-MM-dd') });
        setOpen(false);
    };

    const clear = () => {
        onChange({ from: null, to: null });
        setOpen(false);
    };

    const handleSelect = (range: DateRange | undefined) => {
        onChange({
            from: range?.from ? format(range.from, 'yyyy-MM-dd') : null,
            to: range?.to ? format(range.to, 'yyyy-MM-dd') : null,
        });
    };

    const hasValue = fromDate !== undefined || toDate !== undefined;

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    disabled={disabled}
                    className={cn(
                        'min-h-11 justify-start font-normal',
                        !hasValue && 'text-muted-foreground',
                        className,
                    )}
                >
                    <CalendarIcon className="mr-2 size-4 shrink-0" />
                    <span className="truncate">{label}</span>
                    {allowClear && hasValue && (
                        <span
                            role="button"
                            tabIndex={0}
                            aria-label="Șterge perioada"
                            onClick={(e) => {
                                e.stopPropagation();
                                clear();
                            }}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter' || e.key === ' ') {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    clear();
                                }
                            }}
                            className="ml-auto inline-flex size-5 cursor-pointer items-center justify-center rounded text-muted-foreground hover:bg-muted hover:text-foreground"
                        >
                            <X className="size-3.5" />
                        </span>
                    )}
                </Button>
            </PopoverTrigger>
            <PopoverContent align="start" className="flex w-auto flex-col gap-0 p-0 sm:flex-row">
                <div className="flex shrink-0 flex-col gap-1 border-b p-2 sm:w-[180px] sm:border-r sm:border-b-0">
                    {PRESETS.map((preset) => {
                        const isActive = activePreset === preset.key;
                        return (
                            <Button
                                key={preset.key}
                                type="button"
                                variant={isActive ? 'secondary' : 'ghost'}
                                size="sm"
                                className="justify-start font-normal"
                                onClick={() => applyPreset(preset)}
                            >
                                {preset.label}
                            </Button>
                        );
                    })}
                    <div className="my-1 border-t" />
                    <Button
                        type="button"
                        variant={!activePreset && hasValue ? 'secondary' : 'ghost'}
                        size="sm"
                        className="justify-start font-normal"
                        onClick={() => {
                            /* user picks via calendar */
                        }}
                    >
                        Personalizat
                    </Button>
                    {allowClear && hasValue && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="justify-start font-normal text-muted-foreground"
                            onClick={clear}
                        >
                            Șterge
                        </Button>
                    )}
                </div>
                <Calendar
                    mode="range"
                    locale={ro}
                    numberOfMonths={2}
                    selected={fromDate || toDate ? { from: fromDate, to: toDate } : undefined}
                    onSelect={handleSelect}
                    defaultMonth={fromDate ?? new Date()}
                    captionLayout="dropdown"
                    className="p-3"
                />
            </PopoverContent>
        </Popover>
    );
}
