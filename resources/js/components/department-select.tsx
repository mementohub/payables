import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { DepartmentRef } from '@/types/approvals';

const groups: { value: string; label: string }[] = [
    { value: 'product', label: 'Produse' },
    { value: 'channel', label: 'Canale de vânzare' },
    { value: 'support', label: 'Suport' },
];

/** The departments, grouped as the taxonomy is: products, channels, support. */
export default function DepartmentSelect({
    departments,
    value,
    onChange,
    placeholder = 'Alege departamentul…',
    exclude = [],
    className,
    size = 'default',
}: {
    departments: DepartmentRef[];
    value: string;
    onChange: (value: string) => void;
    placeholder?: string;
    exclude?: number[];
    className?: string;
    size?: 'sm' | 'default';
}) {
    const available = departments.filter((d) => !exclude.includes(d.id));

    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger size={size} className={cn('w-full', className)}>
                <SelectValue placeholder={placeholder} />
            </SelectTrigger>
            <SelectContent>
                {groups.map((group) => {
                    const items = available.filter(
                        (d) => d.group === group.value,
                    );

                    return items.length === 0 ? null : (
                        <SelectGroup key={group.value}>
                            <SelectLabel>{group.label}</SelectLabel>
                            {items.map((d) => (
                                <SelectItem key={d.id} value={String(d.id)}>
                                    {d.parent_id ? `— ${d.name}` : d.name}
                                </SelectItem>
                            ))}
                        </SelectGroup>
                    );
                })}
            </SelectContent>
        </Select>
    );
}
