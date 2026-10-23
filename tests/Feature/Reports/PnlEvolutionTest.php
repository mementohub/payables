<?php

use App\Models\Company;
use App\Models\User;
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
        // Documentele din spatele rândurilor de sub EBITDA, pe lunile cerute.
        $mock->shouldReceive('belowDetails')->andReturnUsing(
            fn ($company, $year, array $months, string $bucket) => array_values(array_filter(
                $belowDocuments,
                fn (array $row) => $row['bucket'] === $bucket && in_array($row['month'], $months, true),
            )),
        );
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
        // Detaliul citește aceleași cheltuieli, dar numai pe lunile cerute —
        // exact ca cititorul adevărat.
        $mock->shouldReceive('details')->andReturnUsing(
            fn ($company, $year, array $months) => $withDetails
                ? array_values(array_filter($costs, fn (array $row) => in_array($row['month'], $months, true)))
                : [],
        );
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

test('choosing a month stops the evolution there, and the last column is the cumulative to it', function () {
    fakeEvolutionSources([
        ...evolutionRevenue(),
        // Mai și iulie există, dar nu trebuie să se vadă la februarie.
        ['channel' => 'retail', 'branch' => 'Sun Plaza', 'product' => 'Charter', 'month' => 5, 'bookings' => 7, 'net' => 300.0, 'margin' => 60.0],
        ['channel' => 'retail', 'branch' => 'Sun Plaza', 'product' => 'Charter', 'month' => 7, 'bookings' => 9, 'net' => 900.0, 'margin' => 90.0],
    ]);

    $service = app(PnlReportService::class);
    $report = $service->report(test()->company, 2026, forceRefresh: true);

    $evolution = $service->evolution($report, through: 2);

    expect(array_column($evolution['periods'], 'code'))->toBe(['m1', 'm2', 'q1', 'year'])
        // Trimestrul tăiat de luna aleasă o spune, ca să nu pară întreg.
        ->and(collect($evolution['periods'])->firstWhere('code', 'q1'))
        ->toMatchArray(['label' => 'T1*', 'partial' => true])
        ->and(collect($evolution['periods'])->firstWhere('code', 'year'))
        ->toMatchArray(['label' => 'ian.–feb.', 'partial' => true])
        ->and($evolution['through'])->toBe(2);

    $revenue = collect($evolution['rows'])->firstWhere('key', 'revenue');

    // Cumulul e până în februarie, nu anul întreg.
    expect($revenue['cells']['year']['total'])->toEqual(2100.0)
        ->and($revenue['cells'])->not->toHaveKey('m4');

    // Anul întreg rămâne întreg când nu se alege nicio lună.
    $whole = $service->evolution($report);

    expect(collect($whole['periods'])->firstWhere('code', 'year'))
        ->toMatchArray(['label' => '2026', 'partial' => false])
        ->and(collect($whole['rows'])->firstWhere('key', 'revenue')['cells']['year']['total'])->toEqual(3700.0);
});

test('the page cuts both years at the same month, so the comparison is like for like', function () {
    fakeEvolutionSources(evolutionRevenue());

    $boss = User::factory()->withRoles('top_management')->create();

    $this->mock(PnlReportService::class, function (MockInterface $mock) {
        $mock->shouldReceive('cached')->andReturn(['year' => 2026]);
        $mock->shouldReceive('view')->andReturn([
            'year' => 2026, 'period' => 'ytd9', 'channels' => [], 'products' => [],
            'revenue' => ['total' => ['net' => 0.0, 'margin' => 0.0], 'by_channel' => [], 'by_product' => []],
            'lines' => [], 'totals' => ['total' => 0.0], 'below' => [], 'meta' => [],
        ]);
        $mock->shouldReceive('evolution')
            ->twice()
            ->withArgs(fn (...$args) => ($args[5] ?? null) === 9)
            ->andReturn(['year' => 2026, 'through' => 9, 'periods' => [], 'rows' => [], 'channels' => [], 'products' => []]);
    });

    $this->actingAs($boss)
        ->get('/reports/pnl?company_id='.test()->company->id.'&year=2026&layout=evolution&period=ytd9&compare=1')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('evolution.through', 9)
            ->where('previousEvolution.through', 9));
});

test('a quarter column asks for its own three months, not the year up to it', function () {
    // „q3” înseamnă în restul aplicației trimestrele I–III cumulate, iar
    // coloana T3 din evoluție e doar iulie–septembrie: de aceea detaliul se
    // cere pe lunile ei.
    expect(PnlReportService::months('r7-9'))->toBe([7, 8, 9])
        ->and(PnlReportService::months('q3'))->toBe([1, 2, 3, 4, 5, 6, 7, 8, 9])
        ->and(PnlReportService::months('r4-5'))->toBe([4, 5])
        ->and(PnlReportService::months('mtd9'))->toBe([9])
        ->and(PnlReportService::months('ytd9'))->toBe([1, 2, 3, 4, 5, 6, 7, 8, 9])
        // O formă strâmbă nu taie nimic: rămâne anul întreg.
        ->and(PnlReportService::months('r9-4'))->toBe(range(1, 12))
        ->and(PnlReportService::months('r0-13'))->toBe(range(1, 12));
});

test('every column says which months it is made of, so a click can ask for them', function () {
    fakeEvolutionSources(evolutionRevenue());

    $service = app(PnlReportService::class);
    $evolution = $service->evolution($service->report(test()->company, 2026, forceRefresh: true), through: 5);

    $months = collect($evolution['periods'])->pluck('months', 'code');

    expect($months['m1'])->toBe([1, 1])
        ->and($months['q1'])->toBe([1, 3])
        // T2 tăiat la mai: aprilie–mai, nu aprilie–iunie.
        ->and($months['q2'])->toBe([4, 5])
        ->and($months['year'])->toBe([1, 5]);
});

test('the cost detail of a quarter column adds up to what the column shows', function () {
    fakeEvolutionSources(
        evolutionRevenue(),
        [
            ['account' => '6021', 'note' => 'Combustibil', 'sediu' => '', 'partner' => 'OMV', 'month' => 7, 'lei' => 700.0, 'data_doc' => '2026-07-10', 'tip_doc' => 'FactFI', 'nr_doc' => 'A1'],
            ['account' => '6021', 'note' => 'Combustibil', 'sediu' => '', 'partner' => 'OMV', 'month' => 9, 'lei' => 300.0, 'data_doc' => '2026-09-10', 'tip_doc' => 'FactFI', 'nr_doc' => 'A2'],
            ['account' => '6021', 'note' => 'Combustibil', 'sediu' => '', 'partner' => 'OMV', 'month' => 2, 'lei' => 500.0, 'data_doc' => '2026-02-10', 'tip_doc' => 'FactFI', 'nr_doc' => 'A3'],
        ],
        withDetails: true,
    );

    $service = app(PnlReportService::class);
    $report = $service->report(test()->company, 2026, forceRefresh: true);
    $evolution = $service->evolution($report);

    $line = collect($evolution['rows'])->first(fn (array $row) => ($row['kind'] ?? '') === 'line');
    $cell = $line['cells']['q3']['total'];

    $details = $service->costDetails(test()->company, 2026, $line['saf'], 'r7-9');

    expect($cell)->toEqual(1000.0)->and($details['total'])->toEqual($cell);
});

test('the rows under EBITDA open on the entries behind them, like any other cost', function () {
    fakeEvolutionSources(
        evolutionRevenue(),
        [],
        [
            ['bucket' => 'impozit', 'account' => '6911', 'month' => 3, 'lei' => 400.0],
            ['bucket' => 'impozit', 'account' => '6911', 'month' => 7, 'lei' => 600.0],
        ],
        belowDocuments: [
            ['bucket' => 'impozit', 'account' => '6911', 'sediu' => '', 'partner' => 'ANAF', 'note' => 'Impozit T1', 'month' => 3, 'lei' => 400.0, 'data_doc' => '2026-03-31', 'tip_doc' => 'NC', 'nr_doc' => 'I1'],
            ['bucket' => 'impozit', 'account' => '6911', 'sediu' => '', 'partner' => 'ANAF', 'note' => 'Impozit T2', 'month' => 7, 'lei' => 600.0, 'data_doc' => '2026-07-31', 'tip_doc' => 'NC', 'nr_doc' => 'I2'],
        ],
    );

    $service = app(PnlReportService::class);
    $service->report(test()->company, 2026, forceRefresh: true);

    // Luna lui: numai nota ei.
    $march = $service->belowDetails(test()->company, 2026, 'impozit', 'mtd3');

    expect($march['total'])->toEqual(400.0)
        ->and($march['label'])->toBe('Impozit pe profit')
        ->and($march['documents'])->toHaveCount(1)
        ->and($march['documents'][0]['nr_doc'])->toBe('I1');

    // Cumulul le ia pe amândouă, iar poziția adună pe cont și partener.
    $year = $service->belowDetails(test()->company, 2026, 'impozit', 'year');

    expect($year['total'])->toEqual(1000.0)
        ->and($year['items'])->toHaveCount(1)
        ->and($year['items'][0]['partner'])->toBe('ANAF')
        ->and($year['items'][0]['documents'])->toBe(2);

    // Și prin rută, ca din ecran.
    $boss = User::factory()->withRoles('top_management')->create();

    $this->actingAs($boss)
        ->getJson('/reports/pnl/'.test()->company->id.'/details?year=2026&saf=below:impozit&period=year')
        ->assertOk()
        ->assertJsonPath('saf', 'below:impozit')
        ->assertJsonPath('total', 1000);
});
