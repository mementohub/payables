import { format, parse } from 'date-fns';
import { ro } from 'date-fns/locale';
import { CalendarIcon } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';

type Props = {
    value: string | null;
    onChange: (value: string) => void;
    placeholder?: string;
    name?: string;
    id?: string;
    required?: boolean;
    className?: string;
    disabled?: boolean;
    fromYear?: number;
    toYear?: number;
};

function parseISO(value: string | null): Date | undefined {
    if (!value) {
        return undefined;
    }

    const d = parse(value, 'yyyy-MM-dd', new Date());

    return Number.isNaN(d.getTime()) ? undefined : d;
}

export default function DatePicker({
    value,
    onChange,
    placeholder = 'Alege data',
    name,
    id,
    required,
    className,
    disabled,
    fromYear,
    toYear,
}: Props) {
    const selected = parseISO(value);
    const [open, setOpen] = useState(false);

    return (
        <>
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                    <Button
                        id={id}
                        type="button"
                        variant="outline"
                        disabled={disabled}
                        className={cn(
                            'w-full justify-start font-normal',
                            !selected && 'text-muted-foreground',
                            className,
                        )}
                    >
                        <CalendarIcon className="mr-2 size-4" />
                        {selected
                            ? format(selected, 'dd MMM yyyy', { locale: ro })
                            : placeholder}
                    </Button>
                </PopoverTrigger>
                <PopoverContent align="start" className="w-auto p-0">
                    <Calendar
                        mode="single"
                        locale={ro}
                        captionLayout="dropdown"
                        selected={selected}
                        onSelect={(date) => {
                            if (date) {
                                onChange(format(date, 'yyyy-MM-dd'));
                                setOpen(false);
                            } else {
                                onChange('');
                            }
                        }}
                        defaultMonth={selected}
                        startMonth={
                            fromYear ? new Date(fromYear, 0) : undefined
                        }
                        endMonth={toYear ? new Date(toYear, 11) : undefined}
                    />
                </PopoverContent>
            </Popover>
            {name && (
                <input
                    type="hidden"
                    name={name}
                    value={value ?? ''}
                    required={required}
                />
            )}
        </>
    );
}
