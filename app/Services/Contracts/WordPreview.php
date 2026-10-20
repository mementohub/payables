<?php

namespace App\Services\Contracts;

use App\Models\ContractFile;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZipArchive;

/**
 * Un document Word, arătat în fereastră așa cum e scris.
 *
 * Browserul nu știe să deschidă nici .docx, nici .doc, așa că până acum omul
 * vedea doar textul citit de mașină — un morman fără titluri, fără tabele,
 * fără aldine — sau nimic, și trebuia să descarce fișierul ca să-l citească.
 *
 * Aici se face din el o pagină simplă: paragrafe, titluri, îngroșări, liste
 * și tabele. Nimic nu pleacă de pe server și nu se folosește nicio unealtă
 * din afară — un .docx e un zip cu XML înăuntru, iar noi îl citim singuri.
 *
 * Se scrie numai ce punem noi, dintr-o listă scurtă de etichete; din
 * document nu trece niciun cod, oricât ar fi el de meșteșugit.
 */
class WordPreview
{
    /** Cât text se arată, ca o fereastră să nu încerce să țină o carte. */
    private const LIMIT = 400000;

    public function supports(ContractFile $file): bool
    {
        return in_array($this->kind($file), ['docx', 'doc'], true);
    }

    /** Pagina de arătat, sau null dacă nu e un document Word. */
    public function html(ContractFile $file): ?string
    {
        return match ($this->kind($file)) {
            'docx' => $this->fromDocx($file),
            'doc' => $this->fromText($file),
            default => null,
        };
    }

    private function kind(ContractFile $file): ?string
    {
        $name = mb_strtolower((string) $file->original_name);

        return match (true) {
            str_ends_with($name, '.docx') => 'docx',
            str_ends_with($name, '.doc') => 'doc',
            default => null,
        };
    }

    /**
     * Word-ul vechi n-are XML, doar text: se arată ce s-a putut citi din el,
     * pe paragrafe.
     */
    private function fromText(ContractFile $file): string
    {
        $text = trim((string) $file->text);

        if ($text === '') {
            return $this->page('<p class="gol">Din documentul ăsta nu s-a putut citi text. Descarcă-l ca să-l vezi.</p>');
        }

        $body = collect(preg_split('~\n{1,}~u', mb_substr($text, 0, self::LIMIT)) ?: [])
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->map(fn (string $line) => '<p>'.e($line).'</p>')
            ->implode('');

        return $this->page($body);
    }

    private function fromDocx(ContractFile $file): string
    {
        $path = Storage::disk((string) config('contracts.disk', 'contracts'))->path($file->path);
        $zip = new ZipArchive;

        if (! is_file($path) || $zip->open($path) !== true) {
            return $this->fromText($file);
        }

        $xml = (string) $zip->getFromName('word/document.xml');
        $images = $this->images($zip, (string) $zip->getFromName('word/_rels/document.xml.rels'));
        $zip->close();

        if (trim($xml) === '') {
            return $this->fromText($file);
        }

        try {
            return $this->page($this->render($xml, $images));
        } catch (Throwable) {
            return $this->fromText($file);
        }
    }

    /**
     * Pozele din document, puse chiar în pagină: altfel ar trebui dat afară
     * încă un drum pentru fiecare, iar fișierul stă pe discul privat.
     *
     * @return array<string, string>
     */
    private function images(ZipArchive $zip, string $rels): array
    {
        if (trim($rels) === '') {
            return [];
        }

        $dom = new DOMDocument;

        if (! @$dom->loadXML($rels, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            return [];
        }

        $found = [];

        foreach ($dom->getElementsByTagName('Relationship') as $rel) {
            if (! $rel instanceof DOMElement) {
                continue;
            }

            $target = ltrim((string) $rel->getAttribute('Target'), '/');

            if (! str_contains($target, 'media/')) {
                continue;
            }

            $bytes = (string) $zip->getFromName('word/'.ltrim(str_replace('../', '', $target), '/'));

            // Pozele mari nu-și merită locul într-o privire: ar umfla pagina
            // de câteva ori peste ce ține o fereastră.
            if ($bytes === '' || strlen($bytes) > 2_000_000) {
                continue;
            }

            $found[(string) $rel->getAttribute('Id')] = 'data:'.match (mb_strtolower(pathinfo($target, PATHINFO_EXTENSION))) {
                'png' => 'image/png',
                'gif' => 'image/gif',
                'bmp' => 'image/bmp',
                default => 'image/jpeg',
            }.';base64,'.base64_encode($bytes);
        }

        return $found;
    }

    /**
     * @param  array<string, string>  $images
     */
    private function render(string $xml, array $images): string
    {
        $dom = new DOMDocument;
        $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        $body = $xpath->query('//w:body')->item(0);

        return $body instanceof DOMElement ? $this->block($body, $xpath, $images) : '';
    }

    /**
     * @param  array<string, string>  $images
     */
    private function block(DOMElement $node, DOMXPath $xpath, array $images): string
    {
        $out = '';

        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $out .= match ($child->localName) {
                'p' => $this->paragraph($child, $xpath, $images),
                'tbl' => $this->table($child, $xpath, $images),
                default => '',
            };

            if (mb_strlen($out) > self::LIMIT) {
                return $out.'<p class="gol">… documentul e mai lung; descarcă-l ca să-l vezi întreg.</p>';
            }
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $images
     */
    private function paragraph(DOMElement $node, DOMXPath $xpath, array $images): string
    {
        $style = mb_strtolower((string) $xpath->evaluate('string(w:pPr/w:pStyle/@w:val)', $node));
        $numbered = $xpath->query('w:pPr/w:numPr', $node)->length > 0;
        $inner = '';

        foreach ($xpath->query('.//w:r|.//w:drawing//a:blip', $node) as $run) {
            $inner .= $run instanceof DOMElement ? $this->run($run, $xpath, $images) : '';
        }

        if (trim(strip_tags($inner)) === '' && ! str_contains($inner, '<img')) {
            return '<p class="gol">&nbsp;</p>';
        }

        $tag = match (true) {
            str_contains($style, 'heading1'), str_contains($style, 'title') => 'h2',
            str_contains($style, 'heading2') => 'h3',
            str_contains($style, 'heading') => 'h4',
            default => 'p',
        };

        return $numbered && $tag === 'p'
            ? '<p class="lista">'.$inner.'</p>'
            : '<'.$tag.'>'.$inner.'</'.$tag.'>';
    }

    /**
     * @param  array<string, string>  $images
     */
    private function run(DOMElement $node, DOMXPath $xpath, array $images): string
    {
        if ($node->localName === 'blip') {
            return $this->image($node, $images);
        }

        $text = '';

        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $text .= match ($child->localName) {
                't' => e($child->textContent),
                'tab' => ' &nbsp; ',
                'br', 'cr' => '<br>',
                'drawing', 'pict' => $this->firstImage($child, $xpath, $images),
                default => '',
            };
        }

        if ($text === '') {
            return '';
        }

        if ((bool) $xpath->query('w:rPr/w:b', $node)->length) {
            $text = '<strong>'.$text.'</strong>';
        }

        if ((bool) $xpath->query('w:rPr/w:i', $node)->length) {
            $text = '<em>'.$text.'</em>';
        }

        if ((bool) $xpath->query('w:rPr/w:u', $node)->length) {
            $text = '<u>'.$text.'</u>';
        }

        return $text;
    }

    /**
     * @param  array<string, string>  $images
     */
    private function firstImage(DOMElement $node, DOMXPath $xpath, array $images): string
    {
        $blip = $xpath->query('.//a:blip', $node)->item(0);

        return $blip instanceof DOMElement ? $this->image($blip, $images) : '';
    }

    /**
     * @param  array<string, string>  $images
     */
    private function image(DOMElement $blip, array $images): string
    {
        // Fiecare poză își are legătura ei; fără caietul de relații am pune
        // peste tot aceeași poză, ceea ce ar fi mai rău decât niciuna.
        $id = $blip->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'embed');
        $source = $images[$id] ?? null;

        return $source === null ? '' : '<img src="'.$source.'" alt="">';
    }

    /**
     * @param  array<string, string>  $images
     */
    private function table(DOMElement $node, DOMXPath $xpath, array $images): string
    {
        $rows = '';

        foreach ($xpath->query('w:tr', $node) as $tr) {
            if (! $tr instanceof DOMElement) {
                continue;
            }

            $cells = '';

            foreach ($xpath->query('w:tc', $tr) as $tc) {
                $cells .= $tc instanceof DOMElement
                    ? '<td>'.$this->block($tc, $xpath, $images).'</td>'
                    : '';
            }

            $rows .= '<tr>'.$cells.'</tr>';
        }

        return $rows === '' ? '' : '<table>'.$rows.'</table>';
    }

    private function page(string $body): string
    {
        return <<<HTML
            <!DOCTYPE html>
            <html lang="ro"><head><meta charset="utf-8">
            <style>
              body { margin: 0; padding: 28px 34px; background: #fff; color: #111827;
                     font: 15px/1.65 'Nunito Sans', 'Segoe UI', Helvetica, Arial, sans-serif; }
              h2, h3, h4 { font-family: 'Nunito', 'Segoe UI', sans-serif; color: #011f5b; margin: 1.4em 0 .5em; }
              h2 { font-size: 20px; } h3 { font-size: 17px; } h4 { font-size: 15px; }
              p { margin: 0 0 .65em; } p.lista { padding-left: 1.6em; }
              p.gol { color: #9aa3b2; }
              table { border-collapse: collapse; margin: 1em 0; width: 100%; }
              td { border: 1px solid #d7dde9; padding: 6px 9px; vertical-align: top; }
              td p { margin: 0; }
              img { max-width: 100%; height: auto; margin: .6em 0; }
              u { text-underline-offset: 2px; }
            </style></head><body>{$body}</body></html>
            HTML;
    }
}
