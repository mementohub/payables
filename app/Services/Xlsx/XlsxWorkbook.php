<?php

namespace App\Services\Xlsx;

use DateTimeInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * Un xlsx cu mai multe foi și, pe foile de sinteză, grafice adevărate —
 * nu poze, ci diagrame Excel legate de celule: cine schimbă un cost în foaie
 * vede bara mișcându-se. Scrisă de mână, ca și restul exporturilor, ca să nu
 * aducem o bibliotecă întreagă pentru patru bare.
 *
 * O foaie se dă ca:
 *   ['name' => 'Pe zi', 'headers' => [...], 'rows' => iterable,
 *    'widths' => [12, 40, ...],
 *    'chart' => ['title' => '…', 'kind' => 'bar'|'col'|'line',
 *                'categories' => 0, 'values' => 2, 'limit' => 20,
 *                'anchor' => ['col' => 5, 'row' => 1, 'cols' => 10, 'rows' => 22]]]
 */
class XlsxWorkbook
{
    /** Culoarea barelor: albastrul aplicației. */
    private const INK = '3B5BA5';

    /**
     * @param  list<array<string, mixed>>  $sheets
     */
    public static function streamDownload(string $filename, array $sheets): StreamedResponse
    {
        return new StreamedResponse(
            fn () => self::write('php://output', $sheets),
            200,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.addslashes($filename).'"',
                'Cache-Control' => 'max-age=0',
            ],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $sheets
     */
    public static function write(string $target, array $sheets): void
    {
        if ($sheets === []) {
            throw new RuntimeException('An xlsx needs at least one sheet.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        if ($tmp === false) {
            throw new RuntimeException('Could not allocate temp file for xlsx export.');
        }

        $zip = new ZipArchive;
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not open zip archive for xlsx export.');
        }

        $names = [];
        foreach ($sheets as $index => $sheet) {
            $names[$index] = self::sheetName((string) ($sheet['name'] ?? 'Sheet'.($index + 1)), $names);
        }

        $charts = [];

        $zip->addFromString('_rels/.rels', self::rootRelsXml());
        $zip->addFromString('xl/styles.xml', self::stylesXml());

        foreach ($sheets as $index => $sheet) {
            $number = $index + 1;
            $chart = isset($sheet['chart']) ? (array) $sheet['chart'] : null;
            $rows = $sheet['rows'] ?? [];

            if ($chart !== null) {
                $rows = is_array($rows) ? $rows : iterator_to_array($rows);

                // O foaie goală nu capătă grafic: un grafic fără niciun punct
                // face Excelul să se plângă că fișierul e stricat.
                if ($rows === []) {
                    $chart = null;
                } else {
                    $charts[] = $number;
                }
            }

            $zip->addFromString(
                'xl/worksheets/sheet'.$number.'.xml',
                self::sheetXml((array) ($sheet['headers'] ?? []), $rows, (array) ($sheet['widths'] ?? []), $chart !== null),
            );

            if ($chart === null) {
                continue;
            }

            $zip->addFromString('xl/worksheets/_rels/sheet'.$number.'.xml.rels', self::sheetRelsXml($number));
            $zip->addFromString('xl/drawings/drawing'.$number.'.xml', self::drawingXml($chart));
            $zip->addFromString('xl/drawings/_rels/drawing'.$number.'.xml.rels', self::drawingRelsXml($number));
            $zip->addFromString(
                'xl/charts/chart'.$number.'.xml',
                self::chartXml($chart, $names[$index], (array) ($sheet['headers'] ?? []), $rows),
            );
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypesXml(count($sheets), $charts));
        $zip->addFromString('xl/workbook.xml', self::workbookXml($names));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRelsXml(count($sheets)));
        $zip->close();

        if ($target === 'php://output') {
            readfile($tmp);
        } else {
            copy($tmp, $target);
        }

        @unlink($tmp);
    }

    /**
     * @param  array<int, string>  $headers
     * @param  iterable<int, array<int, mixed>>  $rows
     * @param  array<int, int>  $widths
     */
    private static function sheetXml(array $headers, iterable $rows, array $widths, bool $withDrawing): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';

        if ($widths !== []) {
            $xml .= '<cols>';
            foreach ($widths as $i => $width) {
                $xml .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.(float) $width.'" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';

        $rowIndex = 1;
        if ($headers !== []) {
            $xml .= self::rowXml($headers, $rowIndex++, header: true);
        }

        foreach ($rows as $row) {
            $xml .= self::rowXml(array_values((array) $row), $rowIndex++);
        }

        $xml .= '</sheetData>';

        if ($headers !== []) {
            $xml .= '<autoFilter ref="A1:'.self::columnLetter(count($headers) - 1).max(1, $rowIndex - 1).'"/>';
        }

        if ($withDrawing) {
            $xml .= '<drawing r:id="rId1"/>';
        }

        return $xml.'</worksheet>';
    }

    /**
     * @param  array<int, mixed>  $cells
     */
    private static function rowXml(array $cells, int $rowIndex, bool $header = false): string
    {
        $xml = '<row r="'.$rowIndex.'">';
        $colIndex = 0;
        foreach ($cells as $cell) {
            $xml .= self::cellXml(self::columnLetter($colIndex++).$rowIndex, $cell, $header);
        }

        return $xml.'</row>';
    }

    private static function cellXml(string $ref, mixed $value, bool $header): string
    {
        if ($value instanceof DateTimeInterface) {
            $value = $value->format('Y-m-d H:i:s');
        }

        if ($value === null || $value === '') {
            return '<c r="'.$ref.'"'.($header ? ' s="1"' : '').'/>';
        }

        if (is_int($value)) {
            return '<c r="'.$ref.'"'.($header ? ' s="1"' : '').'><v>'.$value.'</v></c>';
        }

        // Zecimalele capătă formatul cu mie și doi zecimali: altfel costurile
        // se citesc „1234.5” și omul le mai numără o dată pe hârtie.
        if (is_float($value) && is_finite($value)) {
            return '<c r="'.$ref.'" s="'.($header ? 1 : 2).'"><v>'.$value.'</v></c>';
        }

        $escaped = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<c r="'.$ref.'" t="inlineStr"'.($header ? ' s="1"' : '').'><is><t xml:space="preserve">'.$escaped.'</t></is></c>';
    }

    /**
     * Graficul foii: o serie, categoriile dintr-o coloană și valorile din alta.
     *
     * @param  array<string, mixed>  $chart
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    private static function chartXml(array $chart, string $sheetName, array $headers, array $rows): string
    {
        $kind = (string) ($chart['kind'] ?? 'bar');
        $catCol = (int) ($chart['categories'] ?? 0);
        $valCol = (int) ($chart['values'] ?? 1);
        $limit = (int) ($chart['limit'] ?? 20);
        $count = min(count($rows), max(1, $limit));

        // Numele foii intră într-o formulă, iar formula intră în XML: apostroful
        // se dublează pentru Excel, iar ce iese se trece prin escape pentru
        // XML. O foaie numită „P&L plan” strica altfel tot fișierul.
        $sheet = str_replace(
            ['&', '<', '>'],
            ['&amp;', '&lt;', '&gt;'],
            "'".str_replace("'", "''", $sheetName)."'",
        );
        $catRef = $sheet.'!$'.self::columnLetter($catCol).'$2:$'.self::columnLetter($catCol).'$'.($count + 1);
        $valRef = $sheet.'!$'.self::columnLetter($valCol).'$2:$'.self::columnLetter($valCol).'$'.($count + 1);
        $titleRef = $sheet.'!$'.self::columnLetter($valCol).'$1';

        $cats = '';
        $vals = '';
        for ($i = 0; $i < $count; $i++) {
            $row = array_values((array) ($rows[$i] ?? []));
            $cats .= '<c:pt idx="'.$i.'"><c:v>'.self::escape((string) ($row[$catCol] ?? '')).'</c:v></c:pt>';
            $vals .= '<c:pt idx="'.$i.'"><c:v>'.(float) ($row[$valCol] ?? 0).'</c:v></c:pt>';
        }

        $series = '<c:ser><c:idx val="0"/><c:order val="0"/>'
            .'<c:tx><c:strRef><c:f>'.$titleRef.'</c:f><c:strCache><c:ptCount val="1"/><c:pt idx="0"><c:v>'
            .self::escape((string) ($headers[$valCol] ?? 'Valoare')).'</c:v></c:pt></c:strCache></c:strRef></c:tx>'
            .'<c:spPr><a:solidFill><a:srgbClr val="'.self::INK.'"/></a:solidFill></c:spPr>'
            .($kind === 'line' ? '<c:marker><c:symbol val="circle"/><c:size val="5"/></c:marker>' : '<c:invertIfNegative val="0"/>')
            .'<c:cat><c:strRef><c:f>'.$catRef.'</c:f><c:strCache><c:ptCount val="'.$count.'"/>'.$cats.'</c:strCache></c:strRef></c:cat>'
            .'<c:val><c:numRef><c:f>'.$valRef.'</c:f><c:numCache><c:formatCode>#,##0.00</c:formatCode><c:ptCount val="'.$count.'"/>'.$vals.'</c:numCache></c:numRef></c:val>'
            .($kind === 'line' ? '<c:smooth val="0"/>' : '')
            .'</c:ser>';

        $plot = $kind === 'line'
            ? '<c:lineChart><c:grouping val="standard"/><c:varyColors val="0"/>'.$series.'<c:marker val="1"/><c:axId val="111111111"/><c:axId val="222222222"/></c:lineChart>'
            : '<c:barChart><c:barDir val="'.($kind === 'col' ? 'col' : 'bar').'"/><c:grouping val="clustered"/><c:varyColors val="0"/>'
                .$series.'<c:gapWidth val="60"/><c:axId val="111111111"/><c:axId val="222222222"/></c:barChart>';

        // La barele orizontale categoriile stau de sus în jos: prima linie a
        // foii trebuie să fie și prima bară, nu ultima.
        $catAx = '<c:catAx><c:axId val="111111111"/><c:scaling><c:orientation val="'.($kind === 'bar' ? 'maxMin' : 'minMax').'"/></c:scaling>'
            .'<c:delete val="0"/><c:axPos val="'.($kind === 'bar' ? 'l' : 'b').'"/><c:tickLblPos val="nextTo"/>'
            .'<c:crossAx val="222222222"/></c:catAx>';

        $valAx = '<c:valAx><c:axId val="222222222"/><c:scaling><c:orientation val="minMax"/></c:scaling>'
            .'<c:delete val="0"/><c:axPos val="'.($kind === 'bar' ? 'b' : 'l').'"/><c:majorGridlines/>'
            .'<c:numFmt formatCode="#,##0" sourceLinked="0"/><c:tickLblPos val="nextTo"/>'
            .'<c:crossAx val="111111111"/></c:valAx>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<c:chartSpace xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<c:chart>'
            .'<c:title><c:tx><c:rich><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:defRPr sz="1200" b="1"/></a:pPr><a:r><a:rPr lang="ro-RO" sz="1200" b="1"/><a:t>'
            .self::escape((string) ($chart['title'] ?? '')).'</a:t></a:r></a:p></c:rich></c:tx><c:overlay val="0"/></c:title>'
            .'<c:autoTitleDeleted val="0"/>'
            .'<c:plotArea><c:layout/>'.$plot.$catAx.$valAx.'</c:plotArea>'
            .'<c:plotVisOnly val="1"/><c:dispBlanksAs val="gap"/>'
            .'</c:chart></c:chartSpace>';
    }

    /**
     * @param  array<string, mixed>  $chart
     */
    private static function drawingXml(array $chart): string
    {
        $anchor = (array) ($chart['anchor'] ?? []);
        $col = (int) ($anchor['col'] ?? 6);
        $row = (int) ($anchor['row'] ?? 1);
        $cols = (int) ($anchor['cols'] ?? 9);
        $rows = (int) ($anchor['rows'] ?? 24);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            .'<xdr:twoCellAnchor>'
            .'<xdr:from><xdr:col>'.$col.'</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>'.$row.'</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from>'
            .'<xdr:to><xdr:col>'.($col + $cols).'</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>'.($row + $rows).'</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:to>'
            .'<xdr:graphicFrame macro=""><xdr:nvGraphicFramePr><xdr:cNvPr id="2" name="Grafic"/><xdr:cNvGraphicFramePr/></xdr:nvGraphicFramePr>'
            .'<xdr:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></xdr:xfrm>'
            .'<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/chart">'
            .'<c:chart xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:id="rId1"/>'
            .'</a:graphicData></a:graphic></xdr:graphicFrame>'
            .'<xdr:clientData/></xdr:twoCellAnchor></xdr:wsDr>';
    }

    private static function sheetRelsXml(int $number): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing'.$number.'.xml"/>'
            .'</Relationships>';
    }

    private static function drawingRelsXml(int $number): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart" Target="../charts/chart'.$number.'.xml"/>'
            .'</Relationships>';
    }

    private static function columnLetter(int $index): string
    {
        $letter = '';
        $index++;
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letter = chr(65 + $mod).$letter;
            $index = (int) (($index - $mod) / 26);
        }

        return $letter;
    }

    /**
     * @param  array<int, string>  $taken
     */
    private static function sheetName(string $name, array $taken): string
    {
        $clean = preg_replace('/[\\\\\/\*\?\[\]:]/u', ' ', trim($name)) ?: 'Sheet';
        $clean = mb_substr($clean, 0, 31);

        $candidate = $clean;
        $i = 2;
        while (in_array($candidate, $taken, true)) {
            $suffix = ' ('.$i++.')';
            $candidate = mb_substr($clean, 0, 31 - mb_strlen($suffix)).$suffix;
        }

        return $candidate;
    }

    /**
     * @param  list<int>  $charts
     */
    private static function contentTypesXml(int $sheets, array $charts): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';

        for ($i = 1; $i <= $sheets; $i++) {
            $xml .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        foreach ($charts as $i) {
            $xml .= '<Override PartName="/xl/drawings/drawing'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>'
                .'<Override PartName="/xl/charts/chart'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/>';
        }

        return $xml.'</Types>';
    }

    private static function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    /**
     * @param  array<int, string>  $names
     */
    private static function workbookXml(array $names): string
    {
        $sheets = '';
        foreach (array_values($names) as $i => $name) {
            $sheets .= '<sheet name="'.self::escape($name).'" sheetId="'.($i + 1).'" r:id="rId'.($i + 1).'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$sheets.'</sheets></workbook>';
    }

    private static function workbookRelsXml(int $sheets): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';

        for ($i = 1; $i <= $sheets; $i++) {
            $xml .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
        }

        return $xml.'<Relationship Id="rId'.($sheets + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    }

    private static function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF'.self::INK.'"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="1"><border/></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="3">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'<dxfs count="0"/></styleSheet>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
