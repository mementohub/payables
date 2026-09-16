<?php

namespace App\Services\Xlsx;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Reads the cells of one sheet of an .xlsx file with nothing but ZipArchive
 * and SimpleXML: enough for the flat tables users paste into the app
 * (charter programmes, price lists), not for formulas or styles.
 */
class XlsxReader
{
    private const EPOCH = 25569; // 1970-01-01 as an Excel serial day

    /**
     * Sheet names in workbook order.
     *
     * @return list<string>
     */
    public static function sheets(string $path): array
    {
        $zip = self::open($path);

        try {
            $workbook = self::xml($zip, 'xl/workbook.xml');
            $names = [];

            foreach ($workbook->sheets->sheet ?? [] as $sheet) {
                $names[] = (string) $sheet['name'];
            }

            return $names;
        } finally {
            $zip->close();
        }
    }

    /**
     * Rows of the sheet (by name, or the first one) as lists of scalar
     * values; dates come back as Y-m-d strings when the cell is date styled,
     * numbers as int/float, everything else as string, empty cells as null.
     *
     * @return list<list<int|float|string|null>>
     */
    public static function rows(string $path, ?string $sheetName = null): array
    {
        $zip = self::open($path);

        try {
            $workbook = self::xml($zip, 'xl/workbook.xml');
            $relations = self::xml($zip, 'xl/_rels/workbook.xml.rels');
            $targets = [];

            foreach ($relations->Relationship ?? [] as $relation) {
                $targets[(string) $relation['Id']] = ltrim(str_replace('/xl/', '', (string) $relation['Target']), '/');
            }

            $sheetPath = null;

            foreach ($workbook->sheets->sheet ?? [] as $sheet) {
                $id = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];

                if ($sheetName === null || (string) $sheet['name'] === $sheetName) {
                    $sheetPath = 'xl/'.($targets[$id] ?? 'worksheets/sheet1.xml');
                    break;
                }
            }

            if ($sheetPath === null) {
                throw new RuntimeException($sheetName === null ? 'Fișierul nu conține foi.' : "Foaia „{$sheetName}” nu există.");
            }

            $strings = self::sharedStrings($zip);
            $dateStyles = self::dateStyles($zip);
            $sheet = self::xml($zip, $sheetPath);
            $rows = [];

            foreach ($sheet->sheetData->row ?? [] as $row) {
                $cells = [];

                foreach ($row->c ?? [] as $cell) {
                    $column = self::columnIndex(preg_replace('/\d+/', '', (string) $cell['r']) ?? '');
                    $cells[$column] = self::value($cell, $strings, $dateStyles);
                }

                if ($cells === []) {
                    $rows[] = [];

                    continue;
                }

                $width = max(array_keys($cells)) + 1;
                $line = array_fill(0, $width, null);

                foreach ($cells as $index => $value) {
                    $line[$index] = $value;
                }

                $rows[] = $line;
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    private static function open(string $path): ZipArchive
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Fișierul nu este un .xlsx valid.');
        }

        return $zip;
    }

    private static function xml(ZipArchive $zip, string $entry): SimpleXMLElement
    {
        $content = $zip->getFromName($entry);

        if ($content === false) {
            throw new RuntimeException("Fișierul .xlsx nu conține {$entry}.");
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            throw new RuntimeException("Fișierul .xlsx are un {$entry} invalid.");
        }

        return $xml;
    }

    /**
     * @return list<string>
     */
    private static function sharedStrings(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }

        $strings = [];

        foreach (self::xml($zip, 'xl/sharedStrings.xml')->si ?? [] as $item) {
            $text = '';

            if (isset($item->t)) {
                $text = (string) $item->t;
            } else {
                foreach ($item->r ?? [] as $run) {
                    $text .= (string) $run->t;
                }
            }

            $strings[] = $text;
        }

        return $strings;
    }

    /**
     * Indexes (in cellXfs order) of the cell styles that format a date.
     *
     * @return array<int, true>
     */
    private static function dateStyles(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/styles.xml') === false) {
            return [];
        }

        $styles = self::xml($zip, 'xl/styles.xml');
        $formats = [];

        foreach ($styles->numFmts->numFmt ?? [] as $format) {
            $formats[(int) $format['numFmtId']] = (string) $format['formatCode'];
        }

        $dates = [];
        $index = 0;

        foreach ($styles->cellXfs->xf ?? [] as $xf) {
            $id = (int) $xf['numFmtId'];
            $code = $formats[$id] ?? '';
            $builtIn = ($id >= 14 && $id <= 22) || ($id >= 45 && $id <= 47);

            if ($builtIn || ($code !== '' && preg_match('/(?<![\\\\"])[dmyh]/i', preg_replace('/\[[^\]]*\]|"[^"]*"/', '', $code) ?? '') === 1)) {
                $dates[$index] = true;
            }

            $index++;
        }

        return $dates;
    }

    /**
     * @param  list<string>  $strings
     * @param  array<int, true>  $dateStyles
     */
    private static function value(SimpleXMLElement $cell, array $strings, array $dateStyles): int|float|string|null
    {
        $type = (string) $cell['t'];
        $raw = isset($cell->v) ? (string) $cell->v : null;

        if ($type === 'inlineStr') {
            return isset($cell->is->t) ? (string) $cell->is->t : null;
        }

        if ($raw === null || $raw === '') {
            return null;
        }

        if ($type === 's') {
            return $strings[(int) $raw] ?? null;
        }

        if ($type === 'b') {
            return $raw === '1' ? 'true' : 'false';
        }

        if ($type === 'str' || $type === 'e') {
            return $raw;
        }

        if (! is_numeric($raw)) {
            return $raw;
        }

        if (isset($dateStyles[(int) $cell['s']])) {
            $days = (float) $raw;
            $seconds = (int) round(($days - self::EPOCH) * 86400);

            return $days - floor($days) > 0 ? gmdate('Y-m-d H:i:s', $seconds) : gmdate('Y-m-d', $seconds);
        }

        return str_contains($raw, '.') || str_contains($raw, 'E') ? (float) $raw : (int) $raw;
    }

    private static function columnIndex(string $letters): int
    {
        $index = 0;

        foreach (str_split(strtoupper($letters)) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }
}
