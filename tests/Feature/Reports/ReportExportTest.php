<?php

use App\Models\CashFlowSnapshot;
use App\Models\Company;
use App\Models\User;
use App\Services\Exports\CashFlowExport;
use App\Services\Exports\PnlExport;
use App\Services\Exports\XlsxWriter;
use App\Services\Reports\PnlReportService;
use Mockery\MockInterface;

beforeEach(function () {
    $this->company = Company::factory()->create(['name' => 'Christian Tour']);
    $this->user = User::factory()->create(['roles' => [User::ROLE_TOP_MANAGEMENT]]);
});

/**
 * @return array<string, mixed>
 */
function pnlViewFixture(): array
{
    return [
        'year' => 2026,
        'period' => 'ytd8',
        'basis' => PnlReportService::BASIS_RAS,
        'mode' => PnlReportService::MODE_OPERATIONAL,
        'channels' => ['b2b', 'retail'],
        'products' => ['Charter'],
        'revenue' => [
            'by_channel' => [
                'b2b' => ['net' => 600.0, 'margin' => 60.0, 'bookings' => 3],
                'retail' => ['net' => 400.0, 'margin' => 40.0, 'bookings' => 2],
            ],
            'by_product' => ['Charter' => ['net' => 1000.0, 'margin' => 100.0, 'bookings' => 5]],
            'total' => ['net' => 1000.0, 'margin' => 100.0, 'bookings' => 5],
            'fx_commission' => ['label' => 'Alte venituri — comision de curs', 'total' => 25.0, 'by_channel' => ['b2b' => 15.0, 'retail' => 10.0], 'by_product' => ['Charter' => 25.0]],
        ],
        'lines' => [
            ['saf' => '20200', 'group' => 'Salarii și Contribuții', 'label' => 'Cheltuieli salarii angajați', 'total' => 40.0, 'direct' => 0.0, 'by_channel' => ['b2b' => 24.0, 'retail' => 16.0], 'by_product' => ['Charter' => 40.0]],
            ['saf' => '16950', 'group' => 'Marketing și promovare', 'label' => 'Cheltuieli promovare Google', 'total' => 10.0, 'direct' => 0.0, 'by_channel' => ['b2b' => 6.0, 'retail' => 4.0], 'by_product' => ['Charter' => 10.0]],
        ],
        'groups' => [],
        'totals' => ['total' => 50.0, 'by_channel' => ['b2b' => 30.0, 'retail' => 20.0], 'by_product' => ['Charter' => 50.0], 'by_month' => []],
        'below' => [
            'amortizare' => ['total' => 5.0, 'by_channel' => ['b2b' => 3.0, 'retail' => 2.0], 'by_product' => ['Charter' => 5.0]],
            'financiar' => ['total' => -2.0, 'by_channel' => ['b2b' => -1.2, 'retail' => -0.8], 'by_product' => ['Charter' => -2.0]],
            'impozit' => ['total' => 1.0, 'by_channel' => ['b2b' => 0.6, 'retail' => 0.4], 'by_product' => ['Charter' => 1.0]],
        ],
        'meta' => ['generated_at' => '2026-09-22T08:00:00+03:00', 'direct_lei' => 0.0, 'allocated_lei' => 50.0, 'excluded_accounts' => ['628'], 'reconciles' => true],
    ];
}

test('the P&L comes down as a workbook with real numbers behind it', function () {
    $this->mock(PnlReportService::class, function (MockInterface $mock) {
        $mock->shouldReceive('cached')->andReturn(['year' => 2026]);
        $mock->shouldReceive('view')->andReturn(pnlViewFixture());
    });

    $response = $this->actingAs($this->user)->get("/reports/pnl/{$this->company->id}/export?format=xlsx&year=2026&view=channel&period=ytd8");

    $response->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    expect($response->headers->get('content-disposition'))->toContain('pnl-2026-ytd8-channel.xlsx');

    // Un xlsx e o arhivă: dacă se deschide și are foaia înăuntru, fișierul e bun.
    $path = tempnam(sys_get_temp_dir(), 'test');
    file_put_contents($path, $response->streamedContent() !== '' ? $response->streamedContent() : $response->getFile()->getContent());

    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();

    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    unlink($path);

    expect($sheet)->toContain('Venit net')
        // Cifrele sunt numere, nu text: se pot aduna în Excel.
        ->and($sheet)->toContain('<v>1000</v>')
        ->and($sheet)->toContain('Cheltuieli promovare Google');
});

test('the P&L comes down as a PDF', function () {
    $this->mock(PnlReportService::class, function (MockInterface $mock) {
        $mock->shouldReceive('cached')->andReturn(['year' => 2026]);
        $mock->shouldReceive('view')->andReturn(pnlViewFixture());
    });

    $response = $this->actingAs($this->user)->get("/reports/pnl/{$this->company->id}/export?format=pdf&year=2026&view=channel&period=ytd8");

    $response->assertOk()->assertHeader('content-type', 'application/pdf');

    expect(substr($response->getContent(), 0, 5))->toBe('%PDF-');
});

test('a report that is not built yet sends you back instead of an empty file', function () {
    $this->mock(PnlReportService::class, fn (MockInterface $mock) => $mock->shouldReceive('cached')->andReturn(null));

    $this->actingAs($this->user)
        ->from('/reports/pnl')
        ->get("/reports/pnl/{$this->company->id}/export?format=xlsx&year=2019")
        ->assertRedirect('/reports/pnl');
});

test('an unknown format is refused', function () {
    $this->actingAs($this->user)
        ->get("/reports/pnl/{$this->company->id}/export?format=docx&year=2026")
        ->assertSessionHasErrors('format');
});

test('the WCFR snapshot comes down with all its weeks', function () {
    CashFlowSnapshot::create([
        'week_start' => '2026-09-21',
        'built_at' => now(),
        'built_by' => 'test',
        'status' => 'ok',
        'duration_ms' => 1000,
        'sources' => [],
        'payload' => [
            'weeks' => ['2026-09-21', '2026-09-28'],
            'currency' => 'RON',
            'opening' => ['as_of' => '2026-09-21'],
            'lines' => [
                ['code' => 'A', 'label' => 'Sold inițial', 'kind' => 'balance', 'values' => [100.0, 200.0]],
                ['code' => 'B1', 'label' => 'Încasări pachete', 'kind' => 'value', 'values' => [10.0, 20.0]],
                ['code' => 'E5', 'label' => 'Comentariu', 'kind' => 'text', 'values' => ['ok', 'ok']],
            ],
        ],
    ]);

    $document = app(CashFlowExport::class)->document(CashFlowSnapshot::latest());

    expect($document->columns)->toHaveCount(3)
        ->and($document->columns[1]['label'])->toBe("S+1\n21.09")
        ->and($document->rows[0]['style'])->toBe('total')
        ->and($document->rows[1]['cells'][1])->toBe(['value' => 10.0, 'type' => 'number'])
        // O linie de text rămâne text, altfel ar ajunge zero în Excel.
        ->and($document->rows[2]['cells'][1])->toBe(['value' => 'ok', 'type' => 'text']);

    $this->actingAs($this->user)
        ->get('/reports/cash-flow/export?format=xlsx')
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

test('without a snapshot the WCFR export sends you back', function () {
    $this->actingAs($this->user)
        ->from('/reports/cash-flow')
        ->get('/reports/cash-flow/export?format=pdf')
        ->assertRedirect('/reports/cash-flow');
});

test('the workbook carries the logo and keeps the header on screen', function () {
    $document = app(PnlExport::class)->document($this->company, pnlViewFixture(), 'channel');
    $path = tempnam(sys_get_temp_dir(), 'test');
    app(XlsxWriter::class)->write($document, $path);

    $zip = new ZipArchive;
    $zip->open($path);
    $names = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }

    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    unlink($path);

    expect($names)->toContain('xl/media/logo.png')
        ->and($names)->toContain('xl/drawings/drawing1.xml')
        ->and($sheet)->toContain('state="frozen"')
        ->and($sheet)->toContain('Cont de profit și pierdere');
});
