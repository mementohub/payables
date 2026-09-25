<?php

namespace App\Services\Exports;

use RuntimeException;
use ZipArchive;

/**
 * Scrie un `.xlsx` din `ReportDocument`, direct: un fișier xlsx e o arhivă zip
 * cu câteva fișiere XML, iar PHP are și zip, și XML în el.
 *
 * S-a ales asta în locul unei biblioteci fiindcă raportul are o singură formă —
 * un tabel cu antet, grupe și totaluri — iar o bibliotecă de calcul tabelar ar
 * fi adus un consum de memorie pe care 52 de săptămâni × zeci de linii nu-l
 * merită. Ce iese sunt numere adevărate, nu text: se pot aduna în Excel.
 */
class XlsxWriter
{
    /** Indecșii de stil din `styles.xml`, în ordinea în care sunt scriși acolo. */
    private const STYLE_DEFAULT = 0;

    private const STYLE_TITLE = 1;

    private const STYLE_SUBTITLE = 2;

    private const STYLE_HEADER = 3;

    private const STYLE_HEADER_RIGHT = 4;

    private const STYLE_LABEL = 5;

    private const STYLE_LABEL_STRONG = 6;

    private const STYLE_NUMBER = 7;

    private const STYLE_NUMBER_STRONG = 8;

    private const STYLE_PERCENT = 9;

    private const STYLE_SECTION = 10;

    private const STYLE_SECTION_NUMBER = 11;

    private const STYLE_NOTE = 12;

    /** Rândul de la care încep datele: logo, titlu, subtitlu, un rând liber. */
    private const FIRST_ROW = 5;

    public function write(ReportDocument $document, string $path): string
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Nu pot scrie fișierul Excel la „{$path}”.");
        }

        $logo = Brand::logo();

        $zip->addFromString('[Content_Types].xml', $this->contentTypes($logo !== null));
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('docProps/core.xml', $this->core($document));
        $zip->addFromString('xl/workbook.xml', $this->workbook($document));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet($document, $logo !== null));

        if ($logo !== null) {
            $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', $this->sheetRels());
            $zip->addFromString('xl/drawings/drawing1.xml', $this->drawing());
            $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', $this->drawingRels());
            $zip->addFromString('xl/media/logo.png', $logo);
        }

        $zip->close();

        return $path;
    }

    private function contentTypes(bool $withLogo): string
    {
        $drawing = $withLogo
            ? '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>'
            : '';
        $png = $withLogo ? '<Default Extension="png" ContentType="image/png"/>' : '';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .$png
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            .$drawing
            .'</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            .'</Relationships>';
    }

    private function core(ReportDocument $document): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            .'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<dc:title>'.$this->escape($document->title).'</dc:title>'
            .'<dc:creator>'.$this->escape(Brand::company()).'</dc:creator>'
            .'<dcterms:created xsi:type="dcterms:W3CDTF">'.now()->toIso8601String().'</dcterms:created>'
            .'</cp:coreProperties>';
    }

    private function workbook(ReportDocument $document): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.$this->escape($this->sheetName($document->sheet)).'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    private function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    /**
     * Culorile temei, în ordinea în care `cellXfs` le folosește mai jos. ARGB,
     * cum le cere formatul.
     */
    private function styles(): string
    {
        $navy = 'FF'.ltrim(Brand::INK, '#');
        $orange = 'FF'.ltrim(Brand::ACCENT, '#');
        $muted = 'FF'.ltrim(Brand::MUTED, '#');
        $grey = 'FF'.ltrim(Brand::SUBTLE, '#');
        $border = 'FF'.ltrim(Brand::BORDER, '#');

        $fonts = '<fonts count="6">'
            .'<font><sz val="10"/><color rgb="'.$navy.'"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="16"/><color rgb="'.$navy.'"/><name val="Calibri"/></font>'
            .'<font><sz val="10"/><color rgb="'.$grey.'"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="10"/><color rgb="'.$navy.'"/><name val="Calibri"/></font>'
            .'<font><i/><sz val="9"/><color rgb="'.$grey.'"/><name val="Calibri"/></font>'
            .'</fonts>';

        $fills = '<fills count="4">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="'.$navy.'"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="'.$muted.'"/><bgColor indexed="64"/></patternFill></fill>'
            .'</fills>';

        $borders = '<borders count="3">'
            .'<border><left/><right/><top/><bottom/><diagonal/></border>'
            .'<border><left/><right/><top/><bottom style="thin"><color rgb="'.$border.'"/></bottom><diagonal/></border>'
            .'<border><left/><right/><top style="thin"><color rgb="'.$orange.'"/></top><bottom/><diagonal/></border>'
            .'</borders>';

        // Ordinea de aici trebuie să fie exact cea a constantelor STYLE_*.
        $xfs = '<cellXfs count="13">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="0" fontId="3" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="3" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="right" vertical="center" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'
            .'<xf numFmtId="0" fontId="4" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
            .'<xf numFmtId="164" fontId="4" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>'
            .'<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
            .'<xf numFmtId="0" fontId="4" fillId="0" borderId="2" xfId="0" applyFont="1" applyBorder="1"/>'
            .'<xf numFmtId="164" fontId="4" fillId="0" borderId="2" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1"/>'
            .'<xf numFmtId="0" fontId="5" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'</cellXfs>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="2">'
            .'<numFmt numFmtId="164" formatCode="#,##0"/>'
            .'<numFmt numFmtId="165" formatCode="0.0%"/>'
            .'</numFmts>'
            .$fonts.$fills.$borders
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .$xfs
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    private function sheet(ReportDocument $document, bool $withLogo): string
    {
        $columns = $document->columns;
        $last = $this->column(count($columns));

        $cols = '<cols>';

        foreach ($columns as $index => $column) {
            $cols .= sprintf(
                '<col min="%d" max="%d" width="%s" customWidth="1"/>',
                $index + 1,
                $index + 1,
                number_format((float) ($column['width'] ?? 14), 2, '.', ''),
            );
        }

        $cols .= '</cols>';

        $rows = '';
        $number = 1;

        // Antetul paginii: titlul pe rândul 2, ca logo-ul de pe 1 să aibă loc.
        $rows .= '<row r="'.(++$number).'" ht="22" customHeight="1">'
            .$this->cell('A'.$number, $document->title, 'text', self::STYLE_TITLE).'</row>';
        $rows .= '<row r="'.(++$number).'">'
            .$this->cell('A'.$number, $document->subtitle, 'text', self::STYLE_SUBTITLE).'</row>';
        $number = self::FIRST_ROW - 1;

        $rows .= '<row r="'.(++$number).'" ht="30" customHeight="1">';

        foreach ($columns as $index => $column) {
            $style = ($column['align'] ?? 'left') === 'right' ? self::STYLE_HEADER_RIGHT : self::STYLE_HEADER;
            $rows .= $this->cell($this->column($index + 1).$number, (string) $column['label'], 'text', $style);
        }

        $rows .= '</row>';

        foreach ($document->rows as $row) {
            $style = $row['style'] ?? ReportDocument::STYLE_NORMAL;
            $rows .= '<row r="'.(++$number).'">';

            foreach ($row['cells'] as $index => $cell) {
                $rows .= $this->cell(
                    $this->column($index + 1).$number,
                    is_array($cell) ? $cell['value'] : $cell,
                    is_array($cell) ? ($cell['type'] ?? 'text') : 'text',
                    $this->styleFor($style, $index === 0, is_array($cell) ? ($cell['type'] ?? 'text') : 'text'),
                );
            }

            $rows .= '</row>';
        }

        foreach ($document->notes as $note) {
            $number += 1;
            $rows .= '<row r="'.(++$number).'">'.$this->cell('A'.$number, $note, 'text', self::STYLE_NOTE).'</row>';
        }

        // Prima coloană și antetul rămân pe ecran cât timp se defilează.
        $freeze = '<sheetViews><sheetView workbookViewId="0" showGridLines="0">'
            .'<pane xSplit="1" ySplit="'.self::FIRST_ROW.'" topLeftCell="B'.(self::FIRST_ROW + 1).'" activePane="bottomRight" state="frozen"/>'
            .'</sheetView></sheetViews>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<dimension ref="A1:'.$last.$number.'"/>'
            .$freeze
            .'<sheetFormatPr defaultRowHeight="15"/>'
            .$cols
            .'<sheetData>'.$rows.'</sheetData>'
            .'<pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>'
            .'<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/>'
            .($withLogo ? '<drawing r:id="rId1"/>' : '')
            .'</worksheet>';
    }

    private function styleFor(string $rowStyle, bool $first, string $type): int
    {
        $numeric = $type !== 'text';

        return match (true) {
            $rowStyle === ReportDocument::STYLE_SECTION || $rowStyle === ReportDocument::STYLE_TOTAL => $numeric
                ? self::STYLE_SECTION_NUMBER
                : self::STYLE_SECTION,
            $rowStyle === ReportDocument::STYLE_GROUP => $numeric ? self::STYLE_NUMBER_STRONG : self::STYLE_LABEL_STRONG,
            $type === 'percent' => self::STYLE_PERCENT,
            $numeric => self::STYLE_NUMBER,
            $first => self::STYLE_LABEL,
            default => self::STYLE_LABEL,
        };
    }

    private function cell(string $reference, mixed $value, string $type, int $style): string
    {
        if ($value === null || $value === '') {
            return '<c r="'.$reference.'" s="'.$style.'"/>';
        }

        if ($type === 'text') {
            return '<c r="'.$reference.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'
                .$this->escape((string) $value).'</t></is></c>';
        }

        return '<c r="'.$reference.'" s="'.$style.'"><v>'.$this->numeric((float) $value).'</v></c>';
    }

    private function numeric(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.') ?: '0';
    }

    private function column(int $index): string
    {
        $name = '';

        while ($index > 0) {
            $index -= 1;
            $name = chr(65 + $index % 26).$name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    /** Excel refuză numele de filă mai lungi de 31 de caractere sau cu `[]:*?/\`. */
    private function sheetName(string $name): string
    {
        return mb_substr(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $name), 0, 31);
    }

    private function sheetRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/>'
            .'</Relationships>';
    }

    /** Logo-ul, ancorat în colțul din stânga sus, cu mărimea în EMU (1 px ≈ 9525). */
    private function drawing(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" '
            .'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            .'<xdr:oneCellAnchor>'
            .'<xdr:from><xdr:col>0</xdr:col><xdr:colOff>38100</xdr:colOff><xdr:row>0</xdr:row><xdr:rowOff>19050</xdr:rowOff></xdr:from>'
            .'<xdr:ext cx="400050" cy="414020"/>'
            .'<xdr:pic>'
            .'<xdr:nvPicPr><xdr:cNvPr id="1" name="Logo"/><xdr:cNvPicPr/></xdr:nvPicPr>'
            .'<xdr:blipFill><a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
            .'<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="400050" cy="414020"/></a:xfrm>'
            .'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr>'
            .'</xdr:pic>'
            .'<xdr:clientData/>'
            .'</xdr:oneCellAnchor>'
            .'</xdr:wsDr>';
    }

    private function drawingRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/logo.png"/>'
            .'</Relationships>';
    }

    private function escape(string $value): string
    {
        // Caracterele de control nu au voie în XML și pică fișierul la deschidere.
        $clean = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);

        return htmlspecialchars($clean, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
