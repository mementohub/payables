<?php

use App\Services\Etrip\EtripReader;
use App\Services\Reports\EtripPnlReader;
use Illuminate\Database\ConnectionInterface;
use Mockery\MockInterface;

/**
 * Interogarea de venit nu se poate rula aici — eTrip e o replică de producție.
 * Ce se poate verifica e ce pleacă spre ea: regulile de canal și legăturile.
 *
 * @param  list<object>  $rows
 * @return array{sql: string, bindings: list<mixed>}
 */
function capturePnlRevenueQuery(array $rows = []): array
{
    $captured = ['sql' => '', 'bindings' => []];

    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('statement')->andReturnTrue();
    $connection->shouldReceive('select')->andReturnUsing(function (string $sql, array $bindings) use (&$captured, $rows) {
        $captured = ['sql' => $sql, 'bindings' => $bindings];

        return $rows;
    });

    test()->mock(EtripReader::class, fn (MockInterface $mock) => $mock->shouldReceive('connection')->andReturn($connection));

    app(EtripPnlReader::class)->byChannelAndProduct(2025);

    return $captured;
}

test('bookings made in a franchise office are a channel of their own', function () {
    config(['pnl.segmentation.franchise_branches' => ['Franciza']]);

    $query = capturePnlRevenueQuery();

    // Fără regula asta, francizele cad pe „retail”, ramura implicită, și
    // umflă magazinele proprii cu vânzarea altcuiva.
    expect($query['sql'])->toContain("THEN 'franciza'")
        ->and($query['bindings'])->toContain('franciza');
});

test('the franchise rule comes after the site one', function () {
    $sql = capturePnlRevenueQuery()['sql'];

    // Precedența e regula: o comandă de site lucrată de un agent de franciză
    // rămâne a site-ului.
    expect(strpos($sql, "THEN 'franciza'"))
        ->toBeGreaterThan(strpos($sql, "WHEN s.is_site THEN 'site'"));
});

test('the rows come back the way the report reads them', function () {
    $rows = [(object) [
        'channel' => 'franciza',
        'branch_label' => 'Franciza Brasov',
        'product' => 'Charter',
        'month' => '3',
        'bookings' => '12',
        'net' => '1000.004',
        'margin' => '120.006',
    ]];

    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('statement')->andReturnTrue();
    $connection->shouldReceive('select')->andReturn($rows);
    test()->mock(EtripReader::class, fn (MockInterface $mock) => $mock->shouldReceive('connection')->andReturn($connection));

    expect(app(EtripPnlReader::class)->byChannelAndProduct(2025))->toBe([[
        'channel' => 'franciza',
        'branch' => 'Franciza Brasov',
        'product' => 'Charter',
        'month' => 3,
        'bookings' => 12,
        'net' => 1000.0,
        'margin' => 120.01,
    ]]);
});

test('a booking made outside the shops is its own channel', function () {
    config(['pnl.segmentation.other_branches' => ['Curs ghid', 'Corporate']]);

    $query = capturePnlRevenueQuery();

    // Pe numele întreg, nu pe fragment: lista scoate sucursale din retail.
    expect($query['sql'])->toContain("THEN 'other'")
        ->and($query['sql'])->toContain('branch_name IN (?,?)')
        ->and($query['bindings'])->toContain('cursghid')
        ->and($query['bindings'])->toContain('corporate');
});
