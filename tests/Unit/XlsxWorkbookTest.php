<?php

use App\Services\Xlsx\XlsxWorkbook;

function workbookParts(string $path): array
{
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();

    $parts = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $parts[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
    }
    $zip->close();

    return $parts;
}

test('a workbook carries every sheet, and a chart only where one was asked for', function () {
    $path = tempnam(sys_get_temp_dir(), 'wb_').'.xlsx';

    XlsxWorkbook::write($path, [
        ['name' => 'Sinteză', 'headers' => ['Indicator', 'Valoare'], 'rows' => [['Furnizor', 'Memento Türkiye'], ['EUR', 1234.5]]],
        [
            'name' => 'Pe zi de check-in',
            'headers' => ['Check-in', 'Monedă', 'Cost'],
            'rows' => [['2026-07-01', 'EUR', 1200.5], ['2026-07-02', 'EUR', 830.0]],
            'chart' => ['title' => 'Cost pe zi', 'kind' => 'col', 'categories' => 0, 'values' => 2],
        ],
        ['name' => 'Gol', 'headers' => ['A'], 'rows' => [], 'chart' => ['title' => 'nimic', 'kind' => 'bar']],
    ]);

    $parts = workbookParts($path);

    expect($parts)->toHaveKeys([
        'xl/workbook.xml', 'xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml', 'xl/worksheets/sheet3.xml',
        'xl/charts/chart2.xml', 'xl/drawings/drawing2.xml',
    ])
        // Foaia goală nu capătă grafic: un grafic fără puncte strică fișierul.
        ->and($parts)->not->toHaveKey('xl/charts/chart3.xml')
        ->and($parts['xl/workbook.xml'])->toContain('Pe zi de check-in')
        ->and($parts['[Content_Types].xml'])->toContain('/xl/charts/chart2.xml');

    foreach ($parts as $name => $xml) {
        if (str_ends_with($name, '.xml') || str_ends_with($name, '.rels')) {
            expect(simplexml_load_string($xml))->not->toBeFalse("{$name} nu e XML valid");
        }
    }

    @unlink($path);
});

test('the chart points at the cells of its own sheet', function () {
    $path = tempnam(sys_get_temp_dir(), 'wb_').'.xlsx';

    XlsxWorkbook::write($path, [[
        'name' => 'Pe hotel',
        'headers' => ['Hotel', 'Monedă', 'Cost'],
        'rows' => [['ETHNO BELEK', 'EUR', 6836.0], ['LAGO HOTEL', 'EUR', 5228.0], ['ADALYA', 'EUR', 2848.0]],
        'chart' => ['title' => 'Cost pe hotel', 'kind' => 'bar', 'categories' => 0, 'values' => 2, 'limit' => 2],
    ]]);

    $chart = workbookParts($path)['xl/charts/chart1.xml'];

    // Graficul ia primele două linii, cât i s-a cerut, și le citește din foaie.
    expect($chart)->toContain("'Pe hotel'!\$A\$2:\$A\$3")
        ->toContain("'Pe hotel'!\$C\$2:\$C\$3")
        ->toContain('<c:v>ETHNO BELEK</c:v>')
        ->toContain('<c:v>6836</c:v>')
        ->not->toContain('ADALYA');

    @unlink($path);
});

test('a sheet whose name carries an ampersand still writes a readable file', function () {
    $path = tempnam(sys_get_temp_dir(), 'xlsx');

    XlsxWorkbook::write($path, [[
        'name' => 'P&L plan',
        'headers' => ['Linie', 'Sumă'],
        'rows' => [['Venit net', 1000.0], ['Marjă brută', 100.0]],
        'chart' => ['title' => 'Venit & marjă', 'kind' => 'bar', 'categories' => 0, 'values' => 1],
    ]]);

    $zip = new ZipArchive;
    $zip->open($path);

    $chart = (string) $zip->getFromName('xl/charts/chart1.xml');
    $zip->close();
    @unlink($path);

    // Numele foii intră în formula graficului, iar formula în XML: fără
    // escape, un singur „&” strica tot fișierul.
    expect($chart)->toContain('\'P&amp;L plan\'!$B$2')
        ->and(simplexml_load_string($chart))->not->toBeFalse();
});
