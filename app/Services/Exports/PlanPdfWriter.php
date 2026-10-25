<?php

namespace App\Services\Exports;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\View;

/**
 * Planul pe hârtie: o filă cu ipotezele scrise în cuvinte, apoi câte o filă
 * pentru fiecare secțiune.
 *
 * Nu merge prin `PdfWriter`, care taie un singur tabel lat pe file de
 * coloane; aici sunt mai multe tabele, fiecare îngust și de sine stătător,
 * iar prima filă nu e tabel deloc.
 */
class PlanPdfWriter
{
    /**
     * @param  array{title: string, subtitle: string, sections: list<array<string, mixed>>, assumptions: list<string>}  $deck
     * @param  list<array{label: string, value: string, hint: string}>  $summary
     */
    public function render(array $deck, array $summary): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', base_path());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($deck, $summary), 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * @param  array<string, mixed>  $deck
     * @param  list<array{label: string, value: string, hint: string}>  $summary
     */
    private function html(array $deck, array $summary): string
    {
        return View::make('exports.plan', [
            'deck' => $deck,
            'summary' => $summary,
            'logo' => Brand::logoDataUri(),
            'brand' => [
                'ink' => Brand::INK,
                'accent' => Brand::ACCENT,
                'muted' => Brand::MUTED,
                'subtle' => Brand::SUBTLE,
                'border' => Brand::BORDER,
                'negative' => Brand::NEGATIVE,
            ],
            'company' => Brand::company(),
            'printedAt' => now()->format('d.m.Y H:i'),
        ])->render();
    }
}
