<?php

use App\Services\Reports\EtripPnlReader;
use App\Services\Reports\OmcPnlCostReader;
use App\Services\Reports\PnlBranchMap;
use App\Services\Reports\TinaPnlReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Cele trei surse ale P&L-ului, înlocuite cu ce-i dăm noi: vânzarea din
 * eTrip, cheltuielile din OMC și, la cerere, documentele din spatele lor.
 *
 * Stă aici, nu într-un fișier de teste, fiindcă o folosesc și testele
 * evoluției, și cele ale contractelor de închiriere.
 */
function fakeEvolutionSources(array $revenue, array $costs = [], array $below = [], bool $withDetails = false, array $belowDocuments = []): void
{
    test()->mock(EtripPnlReader::class, fn (MockInterface $mock) => $mock->shouldReceive('byChannelAndProduct')->andReturn($revenue));
    test()->mock(TinaPnlReader::class, fn (MockInterface $mock) => $mock->shouldReceive('byChannelAndProduct')->andReturn([]));
    test()->mock(PnlBranchMap::class, function (MockInterface $mock) {
        $mock->shouldReceive('channel')->andReturn(null);
        $mock->shouldReceive('branchFor')->andReturn(null);
        $mock->shouldReceive('branchForAnalytic')->andReturn(null);
    });
    test()->mock(OmcPnlCostReader::class, function (MockInterface $mock) use ($costs, $below, $withDetails, $belowDocuments) {
        $mock->shouldReceive('costs')->andReturn($costs);
        $mock->shouldReceive('belowEbitda')->andReturn($below);
        $mock->shouldReceive('documentsByKey')->andReturn([]);
        // Detaliul citește aceleași cheltuieli, dar numai pe lunile cerute —
        // exact ca cititorul adevărat.
        $mock->shouldReceive('details')->andReturnUsing(
            fn ($company, $year, array $months) => $withDetails
                ? array_values(array_filter($costs, fn (array $row) => in_array($row['month'], $months, true)))
                : [],
        );
        $mock->shouldReceive('revenueAndCogs')->andReturn([]);
        $mock->shouldReceive('payrollByPlace')->andReturn([]);
        // Cursurile lunare: contractele de închiriere în lei nu au nevoie de
        // ele, dar graficul le cere oricum.
        $mock->shouldReceive('monthlyRates')->andReturn([]);
        // Documentele din spatele rândurilor de sub EBITDA, pe lunile cerute.
        $mock->shouldReceive('belowDetails')->andReturnUsing(
            fn ($company, $year, array $months, string $bucket) => array_values(array_filter(
                $belowDocuments,
                fn (array $row) => $row['bucket'] === $bucket && in_array($row['month'], $months, true),
            )),
        );
    });
}

/**
 * Vânzări în ianuarie, februarie și aprilie, pe două canale; martie e goală.
 */
function evolutionRevenue(): array
{
    return [
        ['channel' => 'retail', 'branch' => 'Sun Plaza', 'product' => 'Charter', 'month' => 1, 'bookings' => 30, 'net' => 1000.0, 'margin' => 200.0],
        ['channel' => 'site', 'branch' => '', 'product' => 'Cazare', 'month' => 1, 'bookings' => 5, 'net' => 500.0, 'margin' => 100.0],
        ['channel' => 'retail', 'branch' => 'Sun Plaza', 'product' => 'Charter', 'month' => 2, 'bookings' => 20, 'net' => 600.0, 'margin' => 150.0],
        ['channel' => 'retail', 'branch' => 'Sun Plaza', 'product' => 'Charter', 'month' => 4, 'bookings' => 10, 'net' => 400.0, 'margin' => 80.0],
    ];
}
