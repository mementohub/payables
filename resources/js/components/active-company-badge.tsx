import { router, usePage } from '@inertiajs/react';
import { Building2, Check, ChevronDown } from 'lucide-react';
import { useState } from 'react';
import ActiveCompanyController from '@/actions/App/Http/Controllers/ActiveCompanyController';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
    CommandSeparator,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { companyColor } from '@/lib/company-color';
import { cn } from '@/lib/utils';

type CompanyRef = { id: number; name: string };

type SharedProps = {
    companies?: CompanyRef[];
    activeCompany?: CompanyRef | null;
};

export default function ActiveCompanyBadge() {
    const { companies = [], activeCompany = null } =
        usePage<SharedProps>().props;
    const [open, setOpen] = useState(false);

    if (companies.length === 0) {
        return null;
    }

    const color = companyColor(activeCompany?.id ?? null);

    const select = (companyId: number | null) => {
        setOpen(false);
        router.post(
            ActiveCompanyController.update().url,
            { company_id: companyId ?? '' },
            { preserveScroll: true },
        );
    };

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    className={cn(
                        'inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs font-medium transition-colors hover:opacity-80',
                        color.bg,
                        color.text,
                        color.border,
                    )}
                    aria-label="Schimbă compania activă"
                >
                    {activeCompany ? (
                        <>
                            <span
                                className={cn(
                                    'size-1.5 shrink-0 rounded-full',
                                    color.dot,
                                )}
                            />
                            <span className="max-w-[180px] truncate">
                                {activeCompany.name}
                            </span>
                        </>
                    ) : (
                        <>
                            <Building2 className="size-3" />
                            <span>Toate companiile</span>
                        </>
                    )}
                    <ChevronDown className="size-3 opacity-60" />
                </button>
            </PopoverTrigger>
            <PopoverContent className="w-[260px] p-0" align="start">
                <Command>
                    <CommandInput placeholder="Caută companie…" />
                    <CommandList>
                        <CommandEmpty>Nicio companie găsită.</CommandEmpty>
                        <CommandGroup>
                            <CommandItem
                                value="__all__"
                                onSelect={() => select(null)}
                            >
                                <Building2 className="mr-2 size-4" />
                                <span>Toate companiile</span>
                                {!activeCompany && (
                                    <Check className="ml-auto size-4" />
                                )}
                            </CommandItem>
                        </CommandGroup>
                        <CommandSeparator />
                        <CommandGroup heading="Companii">
                            {companies.map((c) => {
                                const cColor = companyColor(c.id);
                                const isActive = activeCompany?.id === c.id;

                                return (
                                    <CommandItem
                                        key={c.id}
                                        value={c.name}
                                        onSelect={() => select(c.id)}
                                    >
                                        <span
                                            className={cn(
                                                'mr-2 size-2.5 shrink-0 rounded-full',
                                                cColor.dot,
                                            )}
                                        />
                                        <span className="truncate">
                                            {c.name}
                                        </span>
                                        {isActive && (
                                            <Check className="ml-auto size-4" />
                                        )}
                                    </CommandItem>
                                );
                            })}
                        </CommandGroup>
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
