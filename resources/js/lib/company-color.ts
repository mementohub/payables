export type CompanyColor = {
    bg: string;
    text: string;
    border: string;
    dot: string;
    stripe: string;
};

const PALETTE: CompanyColor[] = [
    {
        bg: 'bg-sky-50 dark:bg-sky-500/8',
        text: 'text-sky-700 dark:text-sky-300',
        border: 'border-sky-300/40 dark:border-sky-400/20',
        dot: 'bg-sky-400/70',
        stripe: 'bg-sky-400/50',
    },
    {
        bg: 'bg-violet-50 dark:bg-violet-500/8',
        text: 'text-violet-700 dark:text-violet-300',
        border: 'border-violet-300/40 dark:border-violet-400/20',
        dot: 'bg-violet-400/70',
        stripe: 'bg-violet-400/50',
    },
    {
        bg: 'bg-emerald-50 dark:bg-emerald-500/8',
        text: 'text-emerald-700 dark:text-emerald-300',
        border: 'border-emerald-300/40 dark:border-emerald-400/20',
        dot: 'bg-emerald-400/70',
        stripe: 'bg-emerald-400/50',
    },
    {
        bg: 'bg-amber-50 dark:bg-amber-500/8',
        text: 'text-amber-700 dark:text-amber-300',
        border: 'border-amber-300/40 dark:border-amber-400/20',
        dot: 'bg-amber-400/70',
        stripe: 'bg-amber-400/50',
    },
    {
        bg: 'bg-rose-50 dark:bg-rose-500/8',
        text: 'text-rose-700 dark:text-rose-300',
        border: 'border-rose-300/40 dark:border-rose-400/20',
        dot: 'bg-rose-400/70',
        stripe: 'bg-rose-400/50',
    },
    {
        bg: 'bg-cyan-50 dark:bg-cyan-500/8',
        text: 'text-cyan-700 dark:text-cyan-300',
        border: 'border-cyan-300/40 dark:border-cyan-400/20',
        dot: 'bg-cyan-400/70',
        stripe: 'bg-cyan-400/50',
    },
    {
        bg: 'bg-fuchsia-50 dark:bg-fuchsia-500/8',
        text: 'text-fuchsia-700 dark:text-fuchsia-300',
        border: 'border-fuchsia-300/40 dark:border-fuchsia-400/20',
        dot: 'bg-fuchsia-400/70',
        stripe: 'bg-fuchsia-400/50',
    },
    {
        bg: 'bg-lime-50 dark:bg-lime-500/8',
        text: 'text-lime-700 dark:text-lime-300',
        border: 'border-lime-300/40 dark:border-lime-400/20',
        dot: 'bg-lime-400/70',
        stripe: 'bg-lime-400/50',
    },
    {
        bg: 'bg-orange-50 dark:bg-orange-500/8',
        text: 'text-orange-700 dark:text-orange-300',
        border: 'border-orange-300/40 dark:border-orange-400/20',
        dot: 'bg-orange-400/70',
        stripe: 'bg-orange-400/50',
    },
    {
        bg: 'bg-teal-50 dark:bg-teal-500/8',
        text: 'text-teal-700 dark:text-teal-300',
        border: 'border-teal-300/40 dark:border-teal-400/20',
        dot: 'bg-teal-400/70',
        stripe: 'bg-teal-400/50',
    },
    {
        bg: 'bg-indigo-50 dark:bg-indigo-500/8',
        text: 'text-indigo-700 dark:text-indigo-300',
        border: 'border-indigo-300/40 dark:border-indigo-400/20',
        dot: 'bg-indigo-400/70',
        stripe: 'bg-indigo-400/50',
    },
    {
        bg: 'bg-pink-50 dark:bg-pink-500/8',
        text: 'text-pink-700 dark:text-pink-300',
        border: 'border-pink-300/40 dark:border-pink-400/20',
        dot: 'bg-pink-400/70',
        stripe: 'bg-pink-400/50',
    },
];

export function companyColor(id: number | null | undefined): CompanyColor {
    if (id === null || id === undefined) {
        return {
            bg: 'bg-muted/40',
            text: 'text-muted-foreground',
            border: 'border-sidebar-border/60 dark:border-sidebar-border/40',
            dot: 'bg-muted-foreground/30',
            stripe: 'bg-muted-foreground/20',
        };
    }

    return PALETTE[Math.abs(id) % PALETTE.length];
}
