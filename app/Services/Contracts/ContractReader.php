<?php

namespace App\Services\Contracts;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

/**
 * Citește textul unui contract, pe server.
 *
 * PDF-ul făcut la calculator are text în el și se citește direct, fără
 * pierdere. Cel scanat n-are: se face imagine, pagină cu pagină, și trece prin
 * OCR cu limba română. Diferența o spune cantitatea de text găsită la prima
 * încercare — o scanare dă două-trei cuvinte răzlețe, nu un contract.
 *
 * Nimic nu pleacă de pe server: niciun document nu ajunge la vreun serviciu
 * din afară.
 */
class ContractReader
{
    /**
     * @return array{engine: string, text: string, pages: ?int, status: string, error: ?string}
     */
    public function read(string $path, ?string $mime = null): array
    {
        if (! is_file($path)) {
            return $this->failed('fișierul nu mai e pe disc');
        }

        if (! config('contracts.ocr.enabled', true)) {
            return ['engine' => 'oprit', 'text' => '', 'pages' => null, 'status' => 'skipped', 'error' => null];
        }

        try {
            return match (true) {
                $this->is($path, $mime, ['pdf']) => $this->pdf($path),
                $this->is($path, $mime, ['docx']) => $this->docx($path),
                $this->is($path, $mime, ['txt', 'md']) => ['engine' => 'text', 'text' => (string) file_get_contents($path), 'pages' => 1, 'status' => 'done', 'error' => null],
                $this->is($path, $mime, ['png', 'jpg', 'jpeg', 'tif', 'tiff', 'webp']) => $this->image($path),
                default => $this->failed('fel de fișier necitit: '.($mime ?? pathinfo($path, PATHINFO_EXTENSION))),
            };
        } catch (Throwable $e) {
            Log::warning('Contractul nu a putut fi citit: '.$e->getMessage());

            return $this->failed($e->getMessage());
        }
    }

    /**
     * Ce unelte are serverul: fără ele, repertoriul merge mai departe, dar
     * datele se completează cu mâna, iar ecranul o spune.
     *
     * @return array<string, bool>
     */
    public function available(): array
    {
        return [
            'pdftotext' => $this->exists((string) config('contracts.ocr.pdftotext', 'pdftotext')),
            'pdftoppm' => $this->exists((string) config('contracts.ocr.pdftoppm', 'pdftoppm')),
            'tesseract' => $this->exists((string) config('contracts.ocr.tesseract', 'tesseract')),
        ];
    }

    /**
     * @param  list<string>  $extensions
     */
    private function is(string $path, ?string $mime, array $extensions): bool
    {
        $extension = mb_strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, $extensions, true)) {
            return true;
        }

        return $mime !== null && array_any(
            $extensions,
            fn (string $candidate) => str_contains(mb_strtolower($mime), $candidate),
        );
    }

    /**
     * @return array{engine: string, text: string, pages: ?int, status: string, error: ?string}
     */
    private function pdf(string $path): array
    {
        $pages = $this->pdfPages($path);

        if ($this->exists((string) config('contracts.ocr.pdftotext', 'pdftotext'))) {
            $text = $this->run([(string) config('contracts.ocr.pdftotext', 'pdftotext'), '-layout', '-enc', 'UTF-8', $path, '-']);

            if (mb_strlen(trim($text)) >= (int) config('contracts.ocr.text_threshold', 400)) {
                return ['engine' => 'pdftotext', 'text' => $text, 'pages' => $pages, 'status' => 'done', 'error' => null];
            }
        }

        return $this->scanned($path, $pages);
    }

    /**
     * PDF scanat: fiecare pagină devine imagine și trece prin OCR.
     *
     * @return array{engine: string, text: string, pages: ?int, status: string, error: ?string}
     */
    private function scanned(string $path, ?int $pages): array
    {
        $pdftoppm = (string) config('contracts.ocr.pdftoppm', 'pdftoppm');
        $tesseract = (string) config('contracts.ocr.tesseract', 'tesseract');

        if (! $this->exists($pdftoppm) || ! $this->exists($tesseract)) {
            return $this->failed('serverul n-are încă pdftoppm și tesseract; textul se completează cu mâna');
        }

        $limit = min($pages ?? (int) config('contracts.ocr.max_pages', 25), (int) config('contracts.ocr.max_pages', 25));
        $folder = sys_get_temp_dir().'/contract-'.bin2hex(random_bytes(6));
        mkdir($folder, 0700);

        try {
            $this->run([$pdftoppm, '-r', (string) (int) config('contracts.ocr.dpi', 200), '-f', '1', '-l', (string) max(1, $limit), '-png', $path, $folder.'/p']);
            $text = '';

            foreach (glob($folder.'/p*.png') ?: [] as $image) {
                $text .= $this->run([$tesseract, $image, 'stdout', '-l', (string) config('contracts.ocr.languages', 'ron+eng'), '--psm', '4'])."\n";
            }

            return ['engine' => 'tesseract', 'text' => $text, 'pages' => $pages, 'status' => 'done', 'error' => null];
        } finally {
            foreach (glob($folder.'/*') ?: [] as $leftover) {
                @unlink($leftover);
            }

            @rmdir($folder);
        }
    }

    /**
     * @return array{engine: string, text: string, pages: ?int, status: string, error: ?string}
     */
    private function image(string $path): array
    {
        $tesseract = (string) config('contracts.ocr.tesseract', 'tesseract');

        if (! $this->exists($tesseract)) {
            return $this->failed('serverul n-are încă tesseract');
        }

        return [
            'engine' => 'tesseract',
            'text' => $this->run([$tesseract, $path, 'stdout', '-l', (string) config('contracts.ocr.languages', 'ron+eng'), '--psm', '4']),
            'pages' => 1,
            'status' => 'done',
            'error' => null,
        ];
    }

    /**
     * Documentul Word e un zip cu XML înăuntru: se despachetează și se scot
     * etichetele, fără nicio unealtă din afară.
     *
     * @return array{engine: string, text: string, pages: ?int, status: string, error: ?string}
     */
    private function docx(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return $this->failed('fișierul Word nu se poate deschide');
        }

        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        $xml = preg_replace('~</w:p>~u', "\n", $xml) ?? $xml;
        $xml = preg_replace('~<w:tab[^>]*/>~u', "\t", $xml) ?? $xml;

        return [
            'engine' => 'docx',
            'text' => html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8'),
            'pages' => null,
            'status' => 'done',
            'error' => null,
        ];
    }

    private function pdfPages(string $path): ?int
    {
        $content = (string) file_get_contents($path, false, null, 0, 2_000_000);

        return preg_match_all('~/Type\s*/Page[^s]~', $content) ?: null;
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command): string
    {
        $process = new Process($command, timeout: (float) config('contracts.ocr.timeout', 600));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process->getOutput();
    }

    private function exists(string $binary): bool
    {
        $process = new Process(['which', $binary]);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * @return array{engine: string, text: string, pages: ?int, status: string, error: string}
     */
    private function failed(string $error): array
    {
        return ['engine' => 'niciunul', 'text' => '', 'pages' => null, 'status' => 'failed', 'error' => $error];
    }
}
