<?php

namespace App\Services\Exports;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Trimite un raport spre descărcare, în formatul cerut.
 */
class ReportExporter
{
    public const FORMATS = ['xlsx', 'pdf'];

    public function __construct(
        private XlsxWriter $xlsx,
        private PdfWriter $pdf,
    ) {}

    /**
     * Raportul ca fișier în memorie: pentru mail, unde nu descarcă nimeni
     * nimic — se atașează.
     */
    public function contents(ReportDocument $document, string $format): string
    {
        if ($format === 'pdf') {
            return $this->pdf->render($document);
        }

        $path = tempnam(sys_get_temp_dir(), 'xlsx');

        try {
            $this->xlsx->write($document, $path);

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    public function download(ReportDocument $document, string $format): Response|BinaryFileResponse
    {
        if ($format === 'pdf') {
            return new Response($this->pdf->render($document), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$document->filename.'.pdf"',
            ]);
        }

        // Fișierul se scrie pe disc fiindcă ZipArchive are nevoie de o cale;
        // se șterge singur după ce pleacă spre browser.
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $this->xlsx->write($document, $path);

        return response()
            ->download($path, $document->filename.'.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }
}
