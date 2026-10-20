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
    /** @var array<string, ?string> unealta → calea ei, odată găsită */
    private array $binaries = [];

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
                $this->is($path, $mime, ['doc']) => $this->doc($path),
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
            'pdftotext' => $this->binary('pdftotext') !== null,
            'pdftoppm' => $this->binary('pdftoppm') !== null,
            'tesseract' => $this->binary('tesseract') !== null,
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

        $pdftotext = $this->binary('pdftotext');

        if ($pdftotext !== null) {
            $text = $this->run([$pdftotext, '-layout', '-enc', 'UTF-8', $path, '-']);

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
        $pdftoppm = $this->binary('pdftoppm');
        $tesseract = $this->binary('tesseract');

        if ($pdftoppm === null || $tesseract === null) {
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
        $tesseract = $this->binary('tesseract');

        if ($tesseract === null) {
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

    /**
     * Word-ul vechi (.doc) n-are XML, ci un teanc de fluxuri binare.
     *
     * Nicio unealtă de pe server nu-l citește — nici măcar prin micromamba nu
     * există una —, așa că textul se scoate de aici. Nu e o citire în toată
     * regula: se caută bucățile de text, și în UTF-16, și în opt biți, iar
     * dintre ele se păstrează cele care seamănă a frază. Pentru o privire și
     * pentru căutat în contract e destul; cine vrea documentul întreg îl
     * descarcă.
     *
     * @return array{engine: string, text: string, pages: ?int, status: string, error: ?string}
     */
    private function doc(string $path): array
    {
        $raw = (string) file_get_contents($path);

        if ($raw === '') {
            return $this->failed('fișierul Word e gol');
        }

        $best = '';

        // Textul stă aliniat pe doi octeți, dar nu se știe de la care capăt;
        // se încearcă amândouă și rămâne cea care scoate mai mult.
        foreach ([0, 1] as $offset) {
            $text = $this->readable((string) @iconv('UTF-16LE', 'UTF-8//IGNORE', substr($raw, $offset)));

            if (mb_strlen($text) > mb_strlen($best)) {
                $best = $text;
            }
        }

        // Documentele mai vechi țin textul pe un octet, în pagina de cod a
        // Windows-ului.
        $single = $this->readable((string) @iconv('WINDOWS-1252', 'UTF-8//IGNORE', $raw));

        if (mb_strlen($single) > mb_strlen($best)) {
            $best = $single;
        }

        return $best === ''
            ? $this->failed('din documentul Word vechi nu s-a putut citi text')
            : ['engine' => 'doc', 'text' => $best, 'pages' => null, 'status' => 'done', 'error' => null];
    }

    /**
     * Frazele dintr-un morman de octeți: bucăți destul de lungi, cu litere
     * destule între ele, ca să nu rămână gunoiul binar.
     */
    private function readable(string $text): string
    {
        preg_match_all('~[\p{L}\p{N}][\p{L}\p{N} \t,.;:!?()\[\]{}«»„”"\'’\-–—/%&@+°\n]{24,}~u', $text, $found);

        $lines = [];

        foreach ($found[0] as $piece) {
            // Word își ține numele de stiluri în aceleași fluxuri; ele apar
            // la început și n-au nicio treabă cu contractul.
            if (preg_match('~font de paragraf|default paragraph font|table normal|list paragraph|^ww-|subiect comentariu|comment reference|balloon text|footnote text|header char|footer char~iu', $piece) === 1) {
                continue;
            }

            $piece = trim(preg_replace('~[ \t]{2,}~u', ' ', $piece) ?? $piece);

            // Un șir de același semn („ÿÿÿÿ…”) trece drept litere în opt biți,
            // dar nu e text: o frază are multe semne deosebite în ea.
            if (count(array_unique(preg_split('~~u', $piece, -1, PREG_SPLIT_NO_EMPTY) ?: [])) < 8) {
                continue;
            }

            $letters = preg_match_all('~\p{L}~u', $piece);

            // Sub jumătate litere e zgomot, nu frază.
            if ($letters > 12 && $letters > mb_strlen($piece) * 0.5) {
                $lines[] = $piece;
            }
        }

        return trim(implode("\n", $lines));
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
        // Tesseract își caută limbile acolo unde i se spune: pe serverul ăsta
        // stau lângă el, în directorul utilizatorului.
        $tessdata = (string) config('contracts.ocr.tessdata', '');
        $env = $tessdata !== '' && is_dir($tessdata) ? ['TESSDATA_PREFIX' => $tessdata] : [];

        $process = new Process($command, env: $env, timeout: (float) config('contracts.ocr.timeout', 600));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process->getOutput();
    }

    /**
     * Unde e unealta: întâi cum e scrisă în config (poate fi o cale întreagă),
     * apoi în locurile știute, apoi în PATH.
     */
    private function binary(string $name): ?string
    {
        if (array_key_exists($name, $this->binaries)) {
            return $this->binaries[$name];
        }

        $configured = (string) config('contracts.ocr.'.$name, $name);

        if (str_contains($configured, '/')) {
            return $this->binaries[$name] = is_executable($configured) ? $configured : null;
        }

        foreach ((array) config('contracts.ocr.paths', []) as $folder) {
            $candidate = rtrim((string) $folder, '/').'/'.$configured;

            if (is_executable($candidate)) {
                return $this->binaries[$name] = $candidate;
            }
        }

        $which = new Process(['which', $configured]);
        $which->run();

        return $this->binaries[$name] = $which->isSuccessful() ? trim($which->getOutput()) : null;
    }

    /**
     * @return array{engine: string, text: string, pages: ?int, status: string, error: string}
     */
    private function failed(string $error): array
    {
        return ['engine' => 'niciunul', 'text' => '', 'pages' => null, 'status' => 'failed', 'error' => $error];
    }
}
