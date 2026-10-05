<?php

use App\Models\Company;
use App\Services\Reports\EtripPnlReader;
use App\Services\Reports\OmcPnlCostReader;
use App\Services\Reports\PnlBranchMap;
use App\Services\Reports\PnlReportService;
use App\Services\Reports\TinaPnlReader;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;

/**
 * Cele trei izvoare ale raportului, mute sau pline, după caz: veniturile din
 * eTrip și Tina, costurile din OMC și harta sucursalelor.
 *
 * @param  list<array<string, mixed>>  $revenue
 * @param  list<array<string, mixed>>  $costs
 * @param  list<array<string, mixed>>  $below
 */
function fakeEvolutionSources(array $revenue, array $costs = [], array $below = []): void
{
    test()->mock(EtripPnlReader::class, fn (MockInterface $mock) => $mock->shouldReceive('byChannelAndProduct')->andReturn($revenue));
    test()->mock(TinaPnlReader::class, fn (MockInterface $mock) => $mock->shouldReceive('byChannelAndProduct')->andReturn([]));
    test()->mock(PnlBranchMap::class, function (MockInterface $mock) {
        $mock->shouldReceive('channel')->andReturn(null);
        $mock->shouldReceive('branchFor')->andReturn(null);
        $mock->shouldReceive('branchForAnalytic')->andReturn(null);
    });
    test()->mock(OmcPnlCostReader::class, function (MockInterface $mock) use ($costs, $below) {
        $mock->shouldReceive('costs')->andReturn($costs);
        $mock->shouldReceive('belowEbitda')->andReturn($below);
        $mock->shouldReceive('documentsByKey')->andReturn([]);
        $mock->shouldReceive('details')->andReturn([]);
        $mock->shouldReceive('revenueAndCogs')->andReturn([]);
        $mock->shouldReceive('payrollByPlace')->andReturn([]);
    });
}

beforeEach(function () {
    Cache::flush();
    $this->company = Company::factory()->create(['name' => 'Christian Tour']);
});

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

function pnlEvolution(): array
{
    $service = app(PnlReportService::class);

    return $service->evolution($service->report(test()->company, 2026, forceRefresh: true));
}

test('the year is laid out month by month, with each quarter and the total', function () {
    fakeEvolutionSources(evolutionRevenue());

    $evolution = pnlEvolution();

    // Martie n-are nicio mișcare, deci nu se desenează; T1 rămâne, fiindcă
    // ianuarie și februarie l-au umplut.
    expect(array_column($evolution['periods'], 'code'))->toBe(['m1', 'm2', 'q1', 'm4', 'q2', 'year'])
        ->and(collect($evolution['periods'])->firstWhere('code', 'm1')['label'])->toBe('ian.')
        ->and(collect($evolution['periods'])->firstWhere('code', 'q1')['kind'])->toBe('quarter')
        ->and(collect($evolution['periods'])->firstWhere('code', 'year')['label'])->toBe('2026');
});

test('a quarter is its own three months, not the year up to then', function () {
    fakeEvolutionSources(evolutionRevenue());

    $revenue = collect(pnlEvolution()['rows'])->firstWhere('key', 'revenue');

    expect($revenue['cells']['m1']['total'])->toEqual(1500.0)
        ->and($revenue['cells']['m2']['total'])->toEqual(600.0)
        ->and($revenue['cells']['q1']['total'])->toEqual(2100.0)
        // Trimestrul al doilea e doar aprilie, nu anul până în aprilie.
        ->and($revenue['cells']['q2']['total'])->toEqual(400.0)
        ->and($revenue['cells']['year']['total'])->toEqual(2500.0);
});

test('each period is split on both views, and they close on the same total', function () {
    fakeEvolutionSources(evolutionRevenue());

    $evolution = pnlEvolution();
    $revenue = collect($evolution['rows'])->firstWhere('key', 'revenue');

    expect($evolution['channels'])->toContain('retail', 'site')
        ->and($evolution['products'])->toContain('Charter', 'Cazare')
        ->and($revenue['cells']['m1']['by_channel']['retail'])->toEqual(1000.0)
        ->and($revenue['cells']['m1']['by_channel']['site'])->toEqual(500.0)
        ->and($revenue['cells']['m1']['by_product']['Charter'])->toEqual(1000.0)
        ->and(array_sum($revenue['cells']['year']['by_channel']))
        ->toEqual(array_sum($revenue['cells']['year']['by_product']));
});

test('the rows are the P&L itself, from revenue down to net profit', function () {
    // Chiria lunii ianuarie: 100 lei, împărțiți pe canale după venit.
    fakeEvolutionSources(
        evolutionRevenue(),
        [['account' => '612', 'sediu' => 'SEDIUL CENTRAL', 'partner' => 'Landlord SRL', 'note' => 'Chirie', 'month' => 1, 'lei' => 100.0]],
        [['bucket' => 'amortizare', 'account' => '681', 'month' => 1, 'lei' => 30.0]],
    );

    $rows = collect(pnlEvolution()['rows'])->keyBy('key');

    expect($rows->keys())->toContain('revenue', 'margin', 'costs', 'ebitda', 'ebit', 'net', 'group:Chirie sedii')
        ->and($rows['margin']['cells']['m1']['total'])->toEqual(300.0)
        ->and($rows['costs']['cells']['m1']['total'])->toEqual(100.0)
        // EBITDA e marja minus cheltuielile, pe fiecare perioadă și pe fiecare coloană.
        ->and($rows['ebitda']['cells']['m1']['total'])->toEqual(200.0)
        ->and($rows['ebitda']['cells']['m1']['by_channel']['retail'])
        ->toEqual(round($rows['margin']['cells']['m1']['by_channel']['retail'] - $rows['costs']['cells']['m1']['by_channel']['retail'], 2))
        // Amortizarea coboară EBITDA la EBIT.
        ->and($rows['below:amortizare']['cells']['m1']['total'])->toEqual(30.0)
        ->and($rows['ebit']['cells']['m1']['total'])->toEqual(170.0)
        ->and($rows['net']['cells']['year']['total'])->toEqual($rows['ebit']['cells']['year']['total']);
});

test('a cost group keeps its own lines under it, for a second look', function () {
    fakeEvolutionSources(
        evolutionRevenue(),
        [
            ['account' => '612', 'sediu' => 'SEDIUL CENTRAL', 'partner' => 'Landlord SRL', 'note' => 'Chirie sediu', 'month' => 1, 'lei' => 100.0],
            ['account' => '605', 'sediu' => 'SEDIUL CENTRAL', 'partner' => 'Enel', 'note' => 'Curent', 'month' => 1, 'lei' => 40.0],
        ],
    );

    $rows = collect(pnlEvolution()['rows']);
    $group = $rows->firstWhere('key', 'group:Chirie sedii');
    $lines = $rows->where('kind', 'line')->where('group', 'Chirie sedii');

    expect($group['lines'])->toBe($lines->count())
        // Grupa e exact suma liniilor ei, pe fiecare perioadă și pe fiecare coloană.
        ->and($lines->sum(fn (array $line) => $line['cells']['m1']['total']))
        ->toEqual($group['cells']['m1']['total'])
        ->and($lines->first()['saf'])->not->toBeEmpty()
        // Liniile vin după grupa lor, ca pagina să le poată desface pe loc.
        ->and($rows->search(fn (array $row) => $row['key'] === $lines->first()['key']))
        ->toBeGreaterThan($rows->search(fn (array $row) => $row['key'] === 'group:Chirie sedii'));
});

test('a column with nothing on it is not sent at all', function () {
    fakeEvolutionSources(evolutionRevenue());

    $revenue = collect(pnlEvolution()['rows'])->firstWhere('key', 'revenue');

    // În februarie vinde doar retailul, deci site-ul nici nu apare.
    expect($revenue['cells']['m2']['by_channel'])->toBe(['retail' => 600.0]);
});

test('the evolution of a company with nothing built says so instead of breaking', function () {
    $service = app(PnlReportService::class);

    expect($service->evolution(['error' => 'nimic construit']))->toBe(['error' => 'nimic construit']);
});

/**
 * O lună pe care contabilitatea n-a închis-o încă: vânzarea e în eTrip, dar
 * registrul n-are nici facturile, nici statul de plată.
 */
function unpostedSources(): void
{
    fakeEvolutionSources(
        [
            ['channel' => 'retail', 'branch' => '', 'product' => 'Charter', 'month' => 1, 'bookings' => 10, 'net' => 1000.0, 'margin' => 200.0],
            ['channel' => 'retail', 'branch' => '', 'product' => 'Charter', 'month' => 2, 'bookings' => 10, 'net' => 1000.0, 'margin' => 200.0],
            ['channel' => 'retail', 'branch' => '', 'product' => 'Charter', 'month' => 3, 'bookings' => 10, 'net' => 1000.0, 'margin' => 200.0],
        ],
        [
            ['account' => '641', 'sediu' => '', 'partner' => 'Salarii', 'note' => 'Salarii', 'month' => 1, 'lei' => 500.0],
            ['account' => '641', 'sediu' => '', 'partner' => 'Salarii', 'note' => 'Salarii', 'month' => 2, 'lei' => 500.0],
            // Luna a treia n-are salarii puse.
        ],
    );

    test()->mock(OmcPnlCostReader::class, function (MockInterface $mock) {
        $mock->shouldReceive('costs')->andReturn([
            ['account' => '641', 'sediu' => '', 'partner' => 'Salarii', 'note' => 'Salarii', 'month' => 1, 'lei' => 500.0, 'trip_share' => 0.0],
            ['account' => '641', 'sediu' => '', 'partner' => 'Salarii', 'note' => 'Salarii', 'month' => 2, 'lei' => 500.0, 'trip_share' => 0.0],
        ]);
        $mock->shouldReceive('belowEbitda')->andReturn([]);
        $mock->shouldReceive('documentsByKey')->andReturn([]);
        $mock->shouldReceive('details')->andReturn([]);
        $mock->shouldReceive('payrollByPlace')->andReturn([]);
        // Registrul: ianuarie și februarie facturate, martie nu.
        $mock->shouldReceive('revenueAndCogs')->andReturn([
            ['bucket' => 'revenue', 'month' => 1, 'lei' => 900.0],
            ['bucket' => 'cogs', 'month' => 1, 'lei' => 700.0],
            ['bucket' => 'revenue', 'month' => 2, 'lei' => 900.0],
            ['bucket' => 'cogs', 'month' => 2, 'lei' => 700.0],
            // Martie: abia câteva facturi, restul nepuse.
            ['bucket' => 'revenue', 'month' => 3, 'lei' => 10.0],
            ['bucket' => 'cogs', 'month' => 3, 'lei' => 5.0],
        ]);
    });
}

test('a month the accountants have not closed is marked, with the reason', function () {
    unpostedSources();

    $evolution = pnlEvolution();
    $march = collect($evolution['periods'])->firstWhere('code', 'm3');
    $february = collect($evolution['periods'])->firstWhere('code', 'm2');

    expect($march['unposted'])->toHaveCount(1)
        ->and($march['unposted'][0]['label'])->toBe('mar.')
        ->and($march['unposted'][0]['reasons'])->toBe([
            'veniturile nu sunt facturate în contabilitate',
            'salariile lunii nu sunt înregistrate',
        ])
        // O lună întreagă nu poartă niciun semn.
        ->and($february['unposted'])->toBe([])
        // Trimestrul care o cuprinde spune și el ce lună îi lipsește.
        ->and(collect($evolution['periods'])->firstWhere('code', 'q1')['unposted'])->toHaveCount(1);
});
