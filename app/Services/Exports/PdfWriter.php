<?php

namespace App\Services\Exports;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\View;

/**
 * Face PDF-ul dintr-un `ReportDocument`, prin dompdf.
 *
 * Un raport de 52 de săptămâni nu încape pe o filă A4, oricât de mică ar fi
 * litera, așa că se taie în blocuri de coloane: prima coloană (numele liniei)
 * se repetă pe fiecare filă, iar săptămânile curg mai departe. E singurul mod
 * în care un tabel lat rămâne de citit pe hârtie.
 */
class PdfWriter
{
    /** Câte coloane de cifre încap pe o filă A4 culcată, la corpul de literă folosit. */
    private const COLUMNS_PER_PAGE = 13;

    public function render(ReportDocument $document): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        // Fonturile cu diacritice sunt ale bibliotecii; nimic din afară.
        $options->set('chroot', base_path());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($document), 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    private function html(ReportDocument $document): string
    {
        return View::make('exports.report', [
            'document' => $document,
            'chunks' => $this->chunks($document),
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

    /**
     * Coloanele tăiate pe file, cu prima repetată pe fiecare.
     *
     * @return list<array{columns: list<array{label: string, width?: float, align?: string}>, indexes: list<int>, from: int, to: int}>
     */
    private function chunks(ReportDocument $document): array
    {
        $columns = $document->columns;
        $data = array_slice($columns, 1);

        if ($data === []) {
            return [['columns' => $columns, 'indexes' => [0], 'from' => 1, 'to' => 1]];
        }

        $chunks = [];
        $pages = (int) ceil(count($data) / self::COLUMNS_PER_PAGE);

        for ($page = 0; $page < $pages; $page++) {
            $offset = $page * self::COLUMNS_PER_PAGE;
            $slice = array_slice($data, $offset, self::COLUMNS_PER_PAGE);
            $indexes = range($offset + 1, $offset + count($slice));

            $chunks[] = [
                'columns' => [$columns[0], ...$slice],
                'indexes' => [0, ...$indexes],
                'from' => $page + 1,
                'to' => $pages,
            ];
        }

        return $chunks;
    }
}
