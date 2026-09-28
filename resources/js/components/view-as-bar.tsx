import { Link, usePage } from '@inertiajs/react';
import { Eye } from 'lucide-react';
import { Button } from '@/components/ui/button';
import type { Auth } from '@/types/auth';

/**
 * Banda care spune prin ochii cui se uită contul. Cât e pornită, meniul și
 * paginile arată ce vede omul privit, deci trebuie spus limpede — și trebuie
 * să existe mereu o ieșire, de pe orice pagină.
 */
export function ViewAsBar() {
    const { auth } = usePage<{ auth: Auth }>().props;
    const preview = auth?.preview;

    if (!preview) {
        return null;
    }

    return (
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
            <Eye className="size-4 shrink-0" />
            <span>
                Te uiți prin ochii lui <strong>{preview.name}</strong>
                {preview.roles.length > 0 && ` (${preview.roles.join(', ')})`}.
                Meniul și paginile arată ce vede el; deciziile rămân ale
                contului tău.
            </span>
            <Button
                asChild
                size="sm"
                variant="outline"
                className="ml-auto h-7 border-amber-300 bg-white/70 text-amber-900 hover:bg-white dark:border-amber-800 dark:bg-transparent dark:text-amber-200"
            >
                <Link href="/dashboard?as=0">Ieși din vizualizare</Link>
            </Button>
        </div>
    );
}
