import { Download, FileSpreadsheet, FileText } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/**
 * Scoate raportul din aplicație: Excel de lucrat mai departe, PDF de trimis.
 *
 * Legăturile sunt `<a>` obișnuite, nu cereri Inertia: răspunsul e un fișier,
 * iar Inertia ar încerca să-l citească drept pagină.
 */
export function ExportMenu({
    href,
    disabled,
}: {
    href: (format: 'xlsx' | 'pdf') => string;
    disabled?: boolean;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline" size="sm" disabled={disabled}>
                    <Download />
                    Descarcă
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem asChild>
                    <a href={href('xlsx')}>
                        <FileSpreadsheet />
                        Excel (.xlsx)
                    </a>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <a href={href('pdf')}>
                        <FileText />
                        PDF
                    </a>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
