import { CornerUpRight, Package, Share2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

export type PnlLines = Record<string, { group: string; label: string }>;

export type MovePayload = {
    scope: 'line' | 'item' | 'document';
    match_key: string;
    label?: string;
};

export const CHANNEL_LABELS: Record<string, string> = {
    b2b: 'B2B (agenții partenere)',
    retail: 'Retail (sucursale proprii)',
    site: 'Site',
    cc: 'Call Center (Online B2C)',
    franciza: 'Franciză',
    other: 'Altele',
};

/**
 * Mută cheltuiala pe alt canal. Fără asta, o cheltuială fără punct de lucru se
 * împarte pe cheia de venit, chiar când se știe al cui e: o campanie a
 * site-ului ajunge, pe cheie, mai ales pe retail.
 */
export function MoveChannelButton({
    payload,
    onMove,
}: {
    payload: MovePayload;
    onMove: (payload: MovePayload, channel: string) => void;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    variant="outline"
                    size="sm"
                    className="h-6 gap-1 px-2 text-xs"
                    onClick={(event) => event.stopPropagation()}
                >
                    <Share2 className="size-3" />
                    Canal
                </Button>
            </PopoverTrigger>
            <PopoverContent
                className="w-72 p-0"
                align="end"
                onClick={(event) => event.stopPropagation()}
            >
                <Command>
                    <CommandList>
                        <CommandGroup heading="Mută toată cheltuiala pe canalul">
                            {Object.entries(CHANNEL_LABELS).map(
                                ([value, label]) => (
                                    <CommandItem
                                        key={value}
                                        value={label}
                                        onSelect={() => {
                                            setOpen(false);
                                            onMove(payload, value);
                                        }}
                                    >
                                        {label}
                                    </CommandItem>
                                ),
                            )}
                        </CommandGroup>
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}

/**
 * Mută cheltuiala pe altă categorie de produs. Pe axa de produs cheltuiala se
 * împarte, implicit, pe ce a vândut canalul în luna aia; când se știe al cui e
 * — o campanie pentru croaziere, un comision de charter — cheia n-are ce
 * căuta acolo.
 */
export function MoveProductButton({
    products,
    payload,
    onMove,
}: {
    products: string[];
    payload: MovePayload;
    onMove: (payload: MovePayload, product: string) => void;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    variant="outline"
                    size="sm"
                    className="h-6 gap-1 px-2 text-xs"
                    onClick={(event) => event.stopPropagation()}
                >
                    <Package className="size-3" />
                    Categorie
                </Button>
            </PopoverTrigger>
            <PopoverContent
                className="w-72 p-0"
                align="end"
                onClick={(event) => event.stopPropagation()}
            >
                <Command>
                    <CommandInput placeholder="Caută categoria…" />
                    <CommandList className="max-h-72">
                        <CommandEmpty>Nicio categorie găsită.</CommandEmpty>
                        <CommandGroup heading="Mută toată cheltuiala pe categoria">
                            {products.map((product) => (
                                <CommandItem
                                    key={product}
                                    value={product}
                                    onSelect={() => {
                                        setOpen(false);
                                        onMove(payload, product);
                                    }}
                                >
                                    {product === '- -'
                                        ? 'Fără categorie'
                                        : product}
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}

/**
 * Mută o cheltuială pe altă linie, alegând-o dintr-o listă căutabilă.
 *
 * Trasul cu mouse-ul merge, dar numai când vezi și sursa, și ținta pe ecran;
 * pe un raport de o sută de linii, cu grupe strânse, alegerea din listă e
 * singura care funcționează de fiecare dată.
 */
export function MoveCostButton({
    lines,
    currentSaf,
    payload,
    onMove,
    label = 'Mută',
}: {
    lines: PnlLines;
    currentSaf: string;
    payload: MovePayload;
    onMove: (payload: MovePayload, saf: string) => void;
    label?: string;
}) {
    const [open, setOpen] = useState(false);

    const groups = useMemo(() => {
        const byGroup = new Map<string, { saf: string; label: string }[]>();

        for (const [saf, line] of Object.entries(lines)) {
            byGroup.set(line.group, [
                ...(byGroup.get(line.group) ?? []),
                { saf, label: line.label },
            ]);
        }

        return [...byGroup.entries()].sort((a, b) => a[0].localeCompare(b[0]));
    }, [lines]);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    variant="outline"
                    size="sm"
                    className="h-6 gap-1 px-2 text-xs"
                    onClick={(event) => event.stopPropagation()}
                >
                    <CornerUpRight className="size-3" />
                    {label}
                </Button>
            </PopoverTrigger>
            <PopoverContent
                className="w-96 p-0"
                align="end"
                onClick={(event) => event.stopPropagation()}
            >
                <Command>
                    <CommandInput placeholder="Caută linia de cost…" />
                    <CommandList className="max-h-80">
                        <CommandEmpty>Nicio linie găsită.</CommandEmpty>
                        {groups.map(([group, items]) => (
                            <CommandGroup key={group} heading={group}>
                                {items.map((item) => (
                                    <CommandItem
                                        key={item.saf}
                                        value={`${group} ${item.label} ${item.saf}`}
                                        disabled={item.saf === currentSaf}
                                        onSelect={() => {
                                            setOpen(false);
                                            onMove(payload, item.saf);
                                        }}
                                    >
                                        <span className="mr-2 font-mono text-xs text-muted-foreground">
                                            {item.saf}
                                        </span>
                                        <span className="truncate">
                                            {item.label}
                                        </span>
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        ))}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
