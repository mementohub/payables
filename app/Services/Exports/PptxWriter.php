<?php

namespace App\Services\Exports;

use RuntimeException;
use ZipArchive;

/**
 * O prezentare PowerPoint scrisă de mână, ca și celelalte exporturi.
 *
 * Un .pptx e un zip cu XML-uri: prezentarea, un șablon, o temă și câte un
 * fișier pentru fiecare filă. Se scriu aici, cu mâna, ca să nu aducem o
 * bibliotecă întreagă pentru șase file de tabel — la fel cum se scriu și
 * fișierele Excel ale aplicației.
 *
 * O filă se dă ca:
 *   ['title' => '…', 'subtitle' => '…',
 *    'table' => ['columns' => [['label' => '…', 'width' => 20, 'align' => 'right']],
 *                'rows' => [['cells' => […], 'style' => 'total']]],
 *    'bullets' => ['…', '…']]
 */
class PptxWriter
{
    /** Filă 16:9, în EMU. */
    private const WIDTH = 12192000;

    private const HEIGHT = 6858000;

    private const MARGIN = 540000;

    /** Câte rânduri de tabel încap pe o filă. */
    public const ROWS_PER_SLIDE = 13;

    /**
     * @param  list<array<string, mixed>>  $slides
     */
    public function render(array $slides, string $title): string
    {
        if ($slides === []) {
            throw new RuntimeException('A deck needs at least one slide.');
        }

        $path = tempnam(sys_get_temp_dir(), 'pptx_');

        if ($path === false) {
            throw new RuntimeException('Could not allocate temp file for pptx export.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not open zip archive for pptx export.');
        }

        $count = count($slides);

        $zip->addFromString('[Content_Types].xml', $this->contentTypes($count));
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('docProps/core.xml', $this->core($title));
        $zip->addFromString('ppt/presentation.xml', $this->presentation($count));
        $zip->addFromString('ppt/_rels/presentation.xml.rels', $this->presentationRels($count));
        $zip->addFromString('ppt/slideMasters/slideMaster1.xml', $this->master());
        $zip->addFromString('ppt/slideMasters/_rels/slideMaster1.xml.rels', $this->masterRels());
        $zip->addFromString('ppt/slideLayouts/slideLayout1.xml', $this->layout());
        $zip->addFromString('ppt/slideLayouts/_rels/slideLayout1.xml.rels', $this->layoutRels());
        $zip->addFromString('ppt/theme/theme1.xml', $this->theme());

        foreach ($slides as $index => $slide) {
            $number = $index + 1;
            $zip->addFromString('ppt/slides/slide'.$number.'.xml', $this->slide($slide));
            $zip->addFromString('ppt/slides/_rels/slide'.$number.'.xml.rels', $this->slideRels());
        }

        $zip->close();

        try {
            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    private function contentTypes(int $slides): string
    {
        $overrides = '';

        for ($i = 1; $i <= $slides; $i++) {
            $overrides .= '<Override PartName="/ppt/slides/slide'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'
            .'<Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>'
            .'<Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>'
            .'<Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>'
            .$overrides
            .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            .'</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            .'</Relationships>';
    }

    private function core(string $title): string
    {
        $now = now()->toIso8601ZuluString();

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            .'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
            .'xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<dcterms:created xsi:type="dcterms:W3CDTF">'.$now.'</dcterms:created>'
            .'<dc:creator>'.$this->escape(Brand::company()).'</dc:creator>'
            .'<cp:lastModifiedBy>'.$this->escape(Brand::company()).'</cp:lastModifiedBy>'
            .'<dcterms:modified xsi:type="dcterms:W3CDTF">'.$now.'</dcterms:modified>'
            .'<dc:title>'.$this->escape($title).'</dc:title>'
            .'</cp:coreProperties>';
    }

    private function presentation(int $slides): string
    {
        $ids = '';

        for ($i = 1; $i <= $slides; $i++) {
            $ids .= '<p:sldId id="'.(255 + $i).'" r:id="rId'.($i + 1).'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<p:presentation '.$this->namespaces().' saveSubsetFonts="1">'
            .'<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst>'
            .'<p:sldIdLst>'.$ids.'</p:sldIdLst>'
            .'<p:sldSz cx="'.self::WIDTH.'" cy="'.self::HEIGHT.'"/>'
            .'<p:notesSz cx="6858000" cy="9144000"/>'
            .'</p:presentation>';
    }

    private function presentationRels(int $slides): string
    {
        $rels = '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>';

        for ($i = 1; $i <= $slides; $i++) {
            $rels .= '<Relationship Id="rId'.($i + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide'.$i.'.xml"/>';
        }

        $rels .= '<Relationship Id="rId'.($slides + 2).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="theme/theme1.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>';
    }

    private function master(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<p:sldMaster '.$this->namespaces().'>'
            .'<p:cSld>'.$this->emptyTree().'</p:cSld>'
            .'<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" '
            .'accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>'
            .'<p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/></p:sldLayoutIdLst>'
            .'</p:sldMaster>';
    }

    private function masterRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme1.xml"/>'
            .'</Relationships>';
    }

    private function layout(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<p:sldLayout '.$this->namespaces().' type="blank" preserve="1">'
            .'<p:cSld name="Filă goală">'.$this->emptyTree().'</p:cSld>'
            .'<p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr>'
            .'</p:sldLayout>';
    }

    private function layoutRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/>'
            .'</Relationships>';
    }

    private function slideRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'
            .'</Relationships>';
    }

    private function emptyTree(): string
    {
        return '<p:spTree>'
            .'<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            .'<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/>'
            .'<a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
            .'</p:spTree>';
    }

    private function namespaces(): string
    {
        return 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            .'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"';
    }

    /**
     * O filă: titlu, subtitlu, și sub ele un tabel sau o listă.
     *
     * @param  array<string, mixed>  $slide
     */
    private function slide(array $slide): string
    {
        $shapes = '';
        $id = 2;
        $top = self::MARGIN;

        $shapes .= $this->text(
            $id++, 'Titlu', self::MARGIN, $top, self::WIDTH - 2 * self::MARGIN, 620000,
            [['text' => (string) ($slide['title'] ?? ''), 'size' => 2600, 'bold' => true, 'color' => ltrim(Brand::INK, '#')]],
        );
        $top += 620000;

        if (($slide['subtitle'] ?? '') !== '') {
            $shapes .= $this->text(
                $id++, 'Subtitlu', self::MARGIN, $top, self::WIDTH - 2 * self::MARGIN, 460000,
                [['text' => (string) $slide['subtitle'], 'size' => 1200, 'bold' => false, 'color' => ltrim(Brand::SUBTLE, '#')]],
            );
            $top += 400000;
        }

        // Dunga portocalie de sub titlu, ca pe hârtie.
        $shapes .= $this->rule($id++, self::MARGIN, $top, self::WIDTH - 2 * self::MARGIN);
        $top += 180000;

        if (isset($slide['bullets']) && $slide['bullets'] !== []) {
            $runs = array_map(fn (string $line) => [
                'text' => $line, 'size' => 1200, 'bold' => false, 'color' => ltrim(Brand::INK, '#'), 'bullet' => true,
            ], $slide['bullets']);

            $shapes .= $this->text($id++, 'Listă', self::MARGIN, $top, self::WIDTH - 2 * self::MARGIN, self::HEIGHT - $top - self::MARGIN, $runs);
        }

        if (isset($slide['table'])) {
            $shapes .= $this->table($id++, self::MARGIN, $top, self::WIDTH - 2 * self::MARGIN, $slide['table']);
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<p:sld '.$this->namespaces().'><p:cSld><p:spTree>'
            .'<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            .'<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/>'
            .'<a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
            .$shapes
            .'</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>';
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     */
    private function text(int $id, string $name, int $x, int $y, int $cx, int $cy, array $runs): string
    {
        $paragraphs = '';

        foreach ($runs as $run) {
            $bullet = ($run['bullet'] ?? false)
                ? '<a:pPr marL="200000" indent="-200000"><a:buChar char="•"/></a:pPr>'
                : '<a:pPr/>';

            $paragraphs .= '<a:p>'.$bullet.'<a:r><a:rPr lang="ro-RO" sz="'.(int) $run['size'].'" b="'.(($run['bold'] ?? false) ? '1' : '0').'" dirty="0">'
                .'<a:solidFill><a:srgbClr val="'.strtoupper((string) $run['color']).'"/></a:solidFill>'
                .'</a:rPr><a:t>'.$this->escape((string) $run['text']).'</a:t></a:r></a:p>';
        }

        return '<p:sp><p:nvSpPr><p:cNvPr id="'.$id.'" name="'.$this->escape($name).'"/>'
            .'<p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>'
            .'<p:spPr><a:xfrm><a:off x="'.$x.'" y="'.$y.'"/><a:ext cx="'.$cx.'" cy="'.$cy.'"/></a:xfrm>'
            .'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/></p:spPr>'
            .'<p:txBody><a:bodyPr wrap="square" lIns="0" tIns="0" rIns="0" bIns="0"><a:normAutofit/></a:bodyPr>'
            .'<a:lstStyle/>'.$paragraphs.'</p:txBody></p:sp>';
    }

    private function rule(int $id, int $x, int $y, int $cx): string
    {
        return '<p:sp><p:nvSpPr><p:cNvPr id="'.$id.'" name="Dungă"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>'
            .'<p:spPr><a:xfrm><a:off x="'.$x.'" y="'.$y.'"/><a:ext cx="'.$cx.'" cy="27000"/></a:xfrm>'
            .'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom>'
            .'<a:solidFill><a:srgbClr val="'.strtoupper(ltrim(Brand::ACCENT, '#')).'"/></a:solidFill>'
            .'<a:ln><a:noFill/></a:ln></p:spPr>'
            .'<p:txBody><a:bodyPr/><a:lstStyle/><a:p/></p:txBody></p:sp>';
    }

    /**
     * @param  array{columns: list<array<string, mixed>>, rows: list<array<string, mixed>>}  $table
     */
    private function table(int $id, int $x, int $y, int $cx, array $table): string
    {
        $columns = $table['columns'];
        $total = array_sum(array_map(fn (array $column) => (float) ($column['width'] ?? 12), $columns)) ?: 1;

        $grid = '';
        $widths = [];

        foreach ($columns as $index => $column) {
            $width = (int) round($cx * ((float) ($column['width'] ?? 12) / $total));
            $widths[$index] = $width;
            $grid .= '<a:gridCol w="'.$width.'"/>';
        }

        $header = '';

        foreach ($columns as $index => $column) {
            $header .= $this->cell(
                (string) $column['label'],
                $index === 0 ? 'l' : 'r',
                ltrim(Brand::INK, '#'),
                'FFFFFF',
                true,
            );
        }

        $body = '<a:tr h="300000">'.$header.'</a:tr>';

        foreach ($table['rows'] as $row) {
            $style = (string) ($row['style'] ?? 'normal');
            $fill = match ($style) {
                'total' => strtoupper(ltrim(Brand::MUTED, '#')),
                'group' => 'F5F7FB',
                default => 'FFFFFF',
            };
            $cells = '';

            foreach ($columns as $index => $column) {
                $value = $row['cells'][$index] ?? null;
                $text = $this->format($value);
                $negative = str_starts_with($text, '-');

                $cells .= $this->cell(
                    $text,
                    $index === 0 ? 'l' : 'r',
                    $fill,
                    $negative ? strtoupper(ltrim(Brand::NEGATIVE, '#')) : strtoupper(ltrim(Brand::INK, '#')),
                    in_array($style, ['total', 'section', 'group'], true),
                );
            }

            $body .= '<a:tr h="280000">'.$cells.'</a:tr>';
        }

        $height = 300000 + 280000 * count($table['rows']);

        return '<p:graphicFrame><p:nvGraphicFramePr><p:cNvPr id="'.$id.'" name="Tabel"/>'
            .'<p:cNvGraphicFramePr><a:graphicFrameLocks noGrp="1"/></p:cNvGraphicFramePr><p:nvPr/></p:nvGraphicFramePr>'
            .'<p:xfrm><a:off x="'.$x.'" y="'.$y.'"/><a:ext cx="'.$cx.'" cy="'.$height.'"/></p:xfrm>'
            .'<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/table">'
            .'<a:tbl><a:tblPr firstRow="1" bandRow="1"/><a:tblGrid>'.$grid.'</a:tblGrid>'.$body.'</a:tbl>'
            .'</a:graphicData></a:graphic></p:graphicFrame>';
    }

    private function cell(string $text, string $align, string $fill, string $color, bool $bold): string
    {
        return '<a:tc><a:txBody><a:bodyPr/><a:lstStyle/>'
            .'<a:p><a:pPr algn="'.$align.'"/><a:r><a:rPr lang="ro-RO" sz="1000" b="'.($bold ? '1' : '0').'" dirty="0">'
            .'<a:solidFill><a:srgbClr val="'.$color.'"/></a:solidFill></a:rPr>'
            .'<a:t>'.$this->escape($text).'</a:t></a:r></a:p></a:txBody>'
            .'<a:tcPr marL="45720" marR="45720" marT="27432" marB="27432" anchor="ctr">'
            .'<a:solidFill><a:srgbClr val="'.$fill.'"/></a:solidFill></a:tcPr></a:tc>';
    }

    private function format(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_string($value)) {
            return $value;
        }

        return number_format((float) $value, 0, ',', '.');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Tema: culorile și fonturile cu care PowerPoint desenează ce nu e scris
     * pe față. Minimă, dar întreagă — fără ea fișierul nu se deschide.
     */
    private function theme(): string
    {
        $scheme = '';

        foreach ([
            'dk1' => '000000', 'lt1' => 'FFFFFF', 'dk2' => ltrim(Brand::INK, '#'), 'lt2' => 'EEF1F7',
            'accent1' => ltrim(Brand::INK, '#'), 'accent2' => ltrim(Brand::ACCENT, '#'), 'accent3' => '74809A',
            'accent4' => 'A5B4D4', 'accent5' => 'C8102E', 'accent6' => '2E8B57',
            'hlink' => ltrim(Brand::ACCENT, '#'), 'folHlink' => '74809A',
        ] as $name => $value) {
            $scheme .= in_array($name, ['dk1', 'lt1'], true)
                ? '<a:'.$name.'><a:sysClr val="'.($name === 'dk1' ? 'windowText' : 'window').'" lastClr="'.$value.'"/></a:'.$name.'>'
                : '<a:'.$name.'><a:srgbClr val="'.strtoupper($value).'"/></a:'.$name.'>';
        }

        $fill = '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>';
        $line = '<a:ln w="9525" cap="flat" cmpd="sng" algn="ctr"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:prstDash val="solid"/></a:ln>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="Christian Tour">'
            .'<a:themeElements>'
            .'<a:clrScheme name="Christian Tour">'.$scheme.'</a:clrScheme>'
            .'<a:fontScheme name="Christian Tour">'
            .'<a:majorFont><a:latin typeface="Calibri"/><a:ea typeface=""/><a:cs typeface=""/></a:majorFont>'
            .'<a:minorFont><a:latin typeface="Calibri"/><a:ea typeface=""/><a:cs typeface=""/></a:minorFont>'
            .'</a:fontScheme>'
            .'<a:fmtScheme name="Christian Tour">'
            .'<a:fillStyleLst>'.$fill.$fill.$fill.'</a:fillStyleLst>'
            .'<a:lnStyleLst>'.$line.$line.$line.'</a:lnStyleLst>'
            .'<a:effectStyleLst><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle></a:effectStyleLst>'
            .'<a:bgFillStyleLst>'.$fill.$fill.$fill.'</a:bgFillStyleLst>'
            .'</a:fmtScheme>'
            .'</a:themeElements><a:objectDefaults/><a:extraClrSchemeLst/></a:theme>';
    }
}
