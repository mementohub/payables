import { Monitor, Moon, Sun } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useAppearance, type Appearance } from '@/hooks/use-appearance';

const NEXT: Record<Appearance, Appearance> = {
    light: 'dark',
    dark: 'system',
    system: 'light',
};

const LABEL: Record<Appearance, string> = {
    light: 'Luminos',
    dark: 'Întunecat',
    system: 'Sistem',
};

export function AppearanceToggle() {
    const { appearance, updateAppearance } = useAppearance();
    const Icon = appearance === 'light' ? Sun : appearance === 'dark' ? Moon : Monitor;

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon-sm"
                    aria-label={`Temă: ${LABEL[appearance]}. Apasă pentru a schimba.`}
                    onClick={() => updateAppearance(NEXT[appearance])}
                >
                    <Icon className="size-4" />
                </Button>
            </TooltipTrigger>
            <TooltipContent>Temă: {LABEL[appearance]}</TooltipContent>
        </Tooltip>
    );
}
