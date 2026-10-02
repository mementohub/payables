<?php

use App\Models\Company;
use App\Models\PnlCostOverride;
use App\Services\Reports\EtripPnlReader;
use App\Services\Reports\OmcPnlCostReader;
use App\Services\Reports\PnlBranchMap;
use App\Services\Reports\PnlReportService;
use App\Services\Reports\TinaPnlReader;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;

/**
 * Venitul: retailul vinde de 3 ori cât site-ul, iar în retail Charter face
 * 3/4 din vânzare. Cheile de repartizare ies din cifrele astea.
 *
 * @param  list<array<string, mixed>>  $rows
 */
function fakePnlRevenue(array $rows, array $tina = []): void
{
    test()->mock(EtripPnlReader::class, fn (MockInterface $mock) => $mock->shouldReceive('byChannelAndProduct')->andReturn($rows));
    // Tina e al doilea ERP de venituri; testele care n-o privesc o lasă mută.
    test()->mock(TinaPnlReader::class, fn (MockInterface $mock) => $mock->shouldReceive('byChannelAndProduct')->andReturn($tina));
}

/**
 * @param  list<array<string, mixed>>  $rows
 * @param  list<array<string, mixed>>  $below
 * @param  list<array<string, mixed>>  $documents
 */
function fakePnlCosts(array $rows, array $below = [], array $documents = [], array $payroll = []): void
{
    test()->mock(OmcPnlCostReader::class, function (MockInterface $mock) use ($rows, $below, $documents, $payroll) {
        $mock->shouldReceive('costs')->andReturn($rows);
        $mock->shouldReceive('belowEbitda')->andReturn($below)->byDefault();
        $mock->shouldReceive('documentsByKey')->andReturn($documents)->byDefault();
        // `details` citește aceleași documente: pe ele se sprijină detaliul unei celule.
        $mock->shouldReceive('details')->andReturn($documents)->byDefault();
        $mock->shouldReceive('revenueAndCogs')->andReturn([])->byDefault();
        // Masa salarială pe magazin: cheia alternativă de repartizare.
        $mock->shouldReceive('payrollByPlace')->andReturn($payroll)->byDefault();
    });
}

/**
 * @param  array<string, string>  $branches
 */
function fakePnlBranches(array $branches, array $shops = []): void
{
    test()->mock(PnlBranchMap::class, function (MockInterface $mock) use ($branches, $shops) {
        $mock->shouldReceive('channel')->andReturnUsing(fn (string $sediu) => $branches[$sediu] ?? null);
        $mock->shouldReceive('branchFor')->andReturnUsing(fn (string $sediu) => isset($branches[$sediu]) ? $sediu : null);
        // Magazinul de pe analiticul unui stat de plată: „.1.Magheru” e Magheru.
        $mock->shouldReceive('branchForAnalytic')->andReturnUsing(function (string $analytic) use ($shops) {
            $key = preg_replace('/^\d+/', '', preg_replace('/[^a-z0-9]/', '', mb_strtolower($analytic)));

            foreach ($shops as $shop) {
                if ($key === preg_replace('/[^a-z0-9]/', '', mb_strtolower($shop))) {
                    return $shop;
                }
            }

            return null;
        });
    });
}

function pnlRevenueRows(): array
{
    return [
        ['channel' => 'retail', 'branch' => 'Sun Plaza', 'product' => 'Charter', 'month' => 1, 'bookings' => 30, 'net' => 750.0, 'margin' => 100.0],
        ['channel' => 'retail', 'branch' => 'Magheru', 'product' => 'Cazare', 'month' => 1, 'bookings' => 10, 'net' => 250.0, 'margin' => 50.0],
        ['channel' => 'site', 'branch' => '', 'product' => 'Cazare', 'month' => 1, 'bookings' => 5, 'net' => 250.0, 'margin' => 40.0],
    ];
}

beforeEach(function () {
    Cache::flush();
    $this->company = Company::factory()->create(['name' => 'Christian Tour']);
});

/**
 * Raportul construit și tăiat pe o perioadă, cum îl vede pagina.
 *
 * @return array<string, mixed>
 */
function pnlView(string $period = 'year'): array
{
    $service = app(PnlReportService::class);

    return $service->view($service->report(test()->company, 2026, forceRefresh: true), $period);
}

test('a cost tied to a branch is charged to that branch\'s channel alone', function () {
    fakePnlRevenue(pnlRevenueRows());
    fakePnlBranches(['Sun Plaza' => 'retail']);
    // Chiria unui magazin: 100 lei, direct pe retail.
    fakePnlCosts([['account' => '612', 'sediu' => 'Sun Plaza', 'partner' => 'Sun Plaza SRL', 'note' => 'Chirie', 'month' => 1, 'lei' => 100.0]]);

    $report = pnlView();
    $line = collect($report['lines'])->firstWhere('saf', '4000');

    expect($line['by_channel']['retail'])->toEqual(100.0)
        ->and($line['by_channel']['site'])->toEqual(0.0)
        ->and($line['direct'])->toEqual(100.0)
        ->and($report['meta']['direct_lei'])->toEqual(100.0)
        ->and($report['meta']['allocated_lei'])->toEqual(0.0);

    // În retail, Charter face 750 din 1.000, deci ia 75 din cei 100 de lei.
    expect($line['by_product']['Charter'])->toEqual(75.0)
        ->and($line['by_product']['Cazare'])->toEqual(25.0);
});

test('a general cost is split between channels on their share of net revenue', function () {
    fakePnlRevenue(pnlRevenueRows());
    fakePnlBranches([]);
    // Chiria sediului central: 120 lei, fără sucursală.
    fakePnlCosts([['account' => '612', 'sediu' => 'SEDIUL CENTRAL', 'partner' => 'Landlord SRL', 'note' => 'Chirie sediu central', 'month' => 3, 'lei' => 120.0]]);

    $report = pnlView();
    $line = collect($report['lines'])->firstWhere('saf', '4000');

    // Retail 1.000 din 1.250 = 80 %, site 250 din 1.250 = 20 %.
    expect($line['by_channel']['retail'])->toEqual(96.0)
        ->and($line['by_channel']['site'])->toEqual(24.0)
        ->and($report['meta']['allocated_lei'])->toEqual(120.0)
        ->and($report['meta']['direct_lei'])->toEqual(0.0);

    // Pe produs: 96 × 3/4 din retail, plus nimic de la site, care vinde doar cazare.
    expect($line['by_product']['Charter'])->toEqual(72.0)
        ->and($line['by_product']['Cazare'])->toEqual(48.0);
});

test('salaries ignore the work point they are booked on and follow revenue', function () {
    fakePnlRevenue(pnlRevenueRows());
    // Statul de plată e înregistrat pe „CLUJ”, care e o sucursală adevărată.
    fakePnlBranches(['CLUJ' => 'retail']);
    fakePnlCosts([['account' => '641', 'sediu' => 'CLUJ', 'partner' => '', 'note' => 'Salarii', 'month' => 2, 'lei' => 1000.0]]);

    $report = pnlView();
    $line = collect($report['lines'])->firstWhere('saf', '20200');

    expect($line['by_channel']['retail'])->toEqual(800.0)
        ->and($line['by_channel']['site'])->toEqual(200.0)
        ->and($line['direct'])->toEqual(0.0);
});

test('the two views of the same costs add up to the same total', function () {
    fakePnlRevenue(pnlRevenueRows());
    fakePnlBranches(['Sun Plaza' => 'retail', 'Online B2C' => 'cc']);
    fakePnlCosts([
        ['account' => '612', 'sediu' => 'Sun Plaza', 'partner' => '', 'note' => 'Chirie', 'month' => 1, 'lei' => 100.0],
        ['account' => '641', 'sediu' => 'CLUJ', 'partner' => '', 'note' => 'Salarii', 'month' => 2, 'lei' => 1000.0],
        ['account' => '6232', 'sediu' => '', 'partner' => 'Google', 'note' => 'Promovare Google', 'month' => 3, 'lei' => 333.33],
        ['account' => '9999', 'sediu' => '', 'partner' => 'Cineva', 'note' => 'Ceva nerecunoscut', 'month' => 4, 'lei' => 77.0],
    ]);

    $report = pnlView();
    $totals = $report['totals'];

    expect($report['meta']['reconciles'])->toBeTrue()
        ->and(array_sum($totals['by_channel']))->toEqualWithDelta($totals['total'], 0.05)
        ->and(array_sum($totals['by_product']))->toEqualWithDelta($totals['total'], 0.05)
        ->and($totals['total'])->toEqualWithDelta(1510.33, 0.01)
        ->and(array_sum($totals['by_month']))->toEqualWithDelta($totals['total'], 0.05);

    // Ce nu se recunoaște rămâne în raport, pe linia lui, ca totalul să închidă.
    expect($report['meta']['unmapped_lei'])->toEqual(77.0)
        ->and(collect($report['lines'])->firstWhere('saf', 'nemapat')['total'])->toEqual(77.0);
});

test('a cost of a channel that sold nothing is still kept, split evenly', function () {
    fakePnlRevenue([]);
    fakePnlBranches([]);
    fakePnlCosts([['account' => '612', 'sediu' => '', 'partner' => '', 'note' => 'Chirie', 'month' => 1, 'lei' => 500.0]]);

    $report = pnlView();

    expect($report['totals']['total'])->toEqual(500.0)
        ->and(array_sum($report['totals']['by_channel']))->toEqualWithDelta(500.0, 0.05);
});

test('every period is cumulative from January, quarters and months alike', function () {
    fakePnlRevenue(pnlRevenueRows());
    fakePnlBranches([]);
    fakePnlCosts([
        ['account' => '612', 'sediu' => '', 'partner' => '', 'note' => 'Chirie', 'month' => 1, 'lei' => 100.0],
        ['account' => '612', 'sediu' => '', 'partner' => '', 'note' => 'Chirie', 'month' => 3, 'lei' => 200.0],
        ['account' => '612', 'sediu' => '', 'partner' => '', 'note' => 'Chirie', 'month' => 7, 'lei' => 400.0],
    ]);

    $service = app(PnlReportService::class);
    $report = $service->report($this->company, 2026, forceRefresh: true);

    // T1 = ian–mar, T2 = ian–iun, T3 = ian–sep: cumulate, cum se citește un P&L.
    expect($service->view($report, 'year')['totals']['total'])->toEqual(700.0)
        ->and($service->view($report, 'q1')['totals']['total'])->toEqual(300.0)
        ->and($service->view($report, 'q2')['totals']['total'])->toEqual(300.0)
        ->and($service->view($report, 'q3')['totals']['total'])->toEqual(700.0)
        ->and($service->view($report, 'q4')['totals']['total'])->toEqual(700.0)
        // Și lunile: „iulie” înseamnă ianuarie–iulie.
        ->and($service->view($report, 'm1')['totals']['total'])->toEqual(100.0)
        ->and($service->view($report, 'm2')['totals']['total'])->toEqual(100.0)
        ->and($service->view($report, 'm3')['totals']['total'])->toEqual(300.0)
        ->and($service->view($report, 'm7')['totals']['total'])->toEqual(700.0);

    // Cumulat, seria nu mai are voie să scadă, iar decembrie trebuie să fie
    // anul întreg. (Suma lunilor nu mai înseamnă nimic: fiecare lună le
    // conține pe cele dinaintea ei.)
    $series = collect(range(1, 12))->map(fn (int $m) => $service->view($report, 'm'.$m)['totals']['total']);

    expect($series->last())->toEqualWithDelta($service->view($report, 'year')['totals']['total'], 0.05)
        ->and($series->sliding(2)->every(fn ($pair) => $pair->last() >= $pair->first() - 0.005))->toBeTrue();

    // Și fiecare tăietură închide pe ea însăși.
    foreach (['year', 'q1', 'q3', 'm1'] as $period) {
        expect($service->view($report, $period)['meta']['reconciles'])->toBeTrue();
    }
});

test('each month\'s cost is split on that month\'s own revenue, then accumulated', function () {
    // În ianuarie vinde doar retailul, în februarie doar site-ul.
    fakePnlRevenue([
        ['channel' => 'retail', 'branch' => 'Sun Plaza', 'product' => 'Charter', 'month' => 1, 'bookings' => 1, 'net' => 1000.0, 'margin' => 100.0],
        ['channel' => 'site', 'branch' => '', 'product' => 'Cazare', 'month' => 2, 'bookings' => 1, 'net' => 1000.0, 'margin' => 100.0],
    ]);
    fakePnlBranches([]);
    fakePnlCosts([
        ['account' => '612', 'sediu' => '', 'partner' => '', 'note' => 'Chirie', 'month' => 1, 'lei' => 100.0],
        ['account' => '612', 'sediu' => '', 'partner' => '', 'note' => 'Chirie', 'month' => 2, 'lei' => 100.0],
    ]);

    $service = app(PnlReportService::class);
    $report = $service->report($this->company, 2026, forceRefresh: true);

    $january = $service->view($report, 'm1');
    $throughFebruary = $service->view($report, 'm2');

    // Ianuarie: doar retailul a vândut, deci el duce toată chiria lunii.
    expect($january['totals']['by_channel']['retail'])->toEqual(100.0)
        ->and($january['totals']['by_channel']['site'])->toEqual(0.0);

    // Cumulat la februarie: fiecare lună și-a păstrat cheia ei, iar totalurile
    // s-au adunat — nu s-a reîmpărțit nimic pe cheia cumulată.
    expect($throughFebruary['totals']['by_channel']['retail'])->toEqual(100.0)
        ->and($throughFebruary['totals']['by_channel']['site'])->toEqual(100.0);

    // Pe an, fiecare canal a purtat chiria lunii lui.
    $year = $service->view($report, 'year');
    expect($year['totals']['by_channel']['retail'])->toEqual(100.0)
        ->and($year['totals']['by_channel']['site'])->toEqual(100.0);
});

test('a cost moved by hand goes to the line it was dropped on', function () {
    fakePnlRevenue(pnlRevenueRows());
    fakePnlBranches([]);
    fakePnlCosts([['account' => '628', 'sediu' => '', 'partner' => 'Soft Mediatel', 'note' => 'Servicii', 'month' => 1, 'lei' => 500.0]]);

    // Regulile o pun la software; omul o mută la consultanță.
    expect(collect(pnlView()['lines'])->firstWhere('saf', '18930')['total'])->toEqual(500.0);

    PnlCostOverride::factory()->create([
        'company_id' => $this->company->id,
        'scope' => PnlCostOverride::SCOPE_ITEM,
        'match_key' => PnlCostOverride::itemKey('628', '', 'Soft Mediatel'),
        'saf' => '18920',
    ]);

    $moved = pnlView();

    expect(collect($moved['lines'])->firstWhere('saf', '18930'))->toBeNull()
        ->and(collect($moved['lines'])->firstWhere('saf', '18920')['total'])->toEqual(500.0)
        ->and($moved['totals']['total'])->toEqual(500.0);
});

test('a whole line can be moved, and a single cost still wins over it', function () {
    fakePnlRevenue(pnlRevenueRows());
    fakePnlBranches([]);
    fakePnlCosts([
        ['account' => '628', 'sediu' => '', 'partner' => 'Soft Mediatel', 'note' => 'Servicii', 'month' => 1, 'lei' => 500.0],
        ['account' => '628', 'sediu' => '', 'partner' => 'Irix', 'note' => 'Servicii', 'month' => 1, 'lei' => 300.0],
    ]);

    PnlCostOverride::factory()->create([
        'company_id' => $this->company->id,
        'scope' => PnlCostOverride::SCOPE_LINE,
        'match_key' => '18930',
        'saf' => '18920',
    ]);
    PnlCostOverride::factory()->create([
        'company_id' => $this->company->id,
        'scope' => PnlCostOverride::SCOPE_ITEM,
        'match_key' => PnlCostOverride::itemKey('628', '', 'Irix'),
        'saf' => '18200',
    ]);

    $report = pnlView();

    expect(collect($report['lines'])->firstWhere('saf', '18920')['total'])->toEqual(500.0)
        ->and(collect($report['lines'])->firstWhere('saf', '18200')['total'])->toEqual(300.0)
        ->and(collect($report['lines'])->firstWhere('saf', '18930'))->toBeNull();
});

test('the periods still close when the amounts do not divide cleanly', function () {
    // Chei care dau zecimale fără capăt: 1/3 din venit pe fiecare canal.
    fakePnlRevenue([
        ['channel' => 'retail', 'branch' => 'Sun Plaza', 'product' => 'Charter', 'month' => 1, 'bookings' => 1, 'net' => 100.0, 'margin' => 10.0],
        ['channel' => 'site', 'branch' => '', 'product' => 'Cazare', 'month' => 1, 'bookings' => 1, 'net' => 100.0, 'margin' => 10.0],
        ['channel' => 'cc', 'branch' => 'Online B2C', 'product' => 'Ticketing', 'month' => 1, 'bookings' => 1, 'net' => 100.0, 'margin' => 10.0],
    ]);
    fakePnlBranches([]);

    // O mie de cheltuieli mici, fiecare împărțită în trei.
    $costs = [];

    for ($i = 0; $i < 1000; $i++) {
        $costs[] = ['account' => '612', 'sediu' => '', 'partner' => '', 'note' => 'Chirie', 'month' => ($i % 12) + 1, 'lei' => 10.01];
    }

    fakePnlCosts($costs);

    $service = app(PnlReportService::class);
    $report = $service->report($this->company, 2026, forceRefresh: true);

    foreach (['year', 'q1', 'q2', 'q3', 'q4', 'm1', 'm7'] as $period) {
        $view = $service->view($report, $period);

        expect($view['meta']['reconciles'])->toBeTrue()
            ->and(array_sum($view['totals']['by_channel']))->toEqualWithDelta($view['totals']['total'], 0.05)
            ->and(array_sum($view['totals']['by_product']))->toEqualWithDelta($view['totals']['total'], 0.05);
    }

    expect($service->view($report, 'year')['totals']['total'])->toEqualWithDelta(10010.0, 0.01);
});

test('the built report stays in cache long enough to survive the day', function () {
    fakePnlRevenue(pnlRevenueRows());
    fakePnlBranches([]);
    fakePnlCosts([['account' => '612', 'sediu' => '', 'partner' => '', 'note' => 'Chirie', 'month' => 1, 'lei' => 100.0]]);

    $service = app(PnlReportService::class);
    $service->report($this->company, 2026, forceRefresh: true);

    // Construcția durează peste un minut; o oră de cache ar trimite pagina
    // înapoi la „se construiește” de câteva ori pe zi.
    $this->travel(6)->hours();
    expect($service->cached($this->company, 2026))->not->toBeNull();

    $this->travel(8)->days();
    expect($service->cached($this->company, 2026))->toBeNull();
});

test('the report goes on below EBITDA down to net profit', function () {
    fakePnlRevenue(pnlRevenueRows());
    fakePnlBranches([]);
    fakePnlCosts(
        [['account' => '612', 'sediu' => '', 'partner' => '', 'note' => 'Chirie', 'month' => 1, 'lei' => 100.0]],
        [
            ['bucket' => 'amortizare', 'account' => '681', 'month' => 1, 'lei' => 40.0],
            // Reluările de provizioane vin cu semn negativ: scad amortizarea.
            ['bucket' => 'amortizare', 'account' => '781', 'month' => 1, 'lei' => -10.0],
            ['bucket' => 'financiar', 'account' => '665', 'month' => 2, 'lei' => 25.0],
            ['bucket' => 'financiar', 'account' => '765', 'month' => 2, 'lei' => -5.0],
            ['bucket' => 'impozit', 'account' => '691', 'month' => 3, 'lei' => 12.0],
        ],
    );

    $view = pnlView();

    // Marja 190 − cheltuieli 100 = EBITDA 90; − 30 amortizare = EBIT 60;
    // − 20 financiar = 40 înainte de impozit; − 12 impozit = 28 profit net.
    expect($view['below']['amortizare']['total'])->toEqual(30.0)
        ->and($view['below']['financiar']['total'])->toEqual(20.0)
        ->and($view['below']['impozit']['total'])->toEqual(12.0);

    // Și pe canale, pe aceeași cheie ca restul cheltuielilor generale.
    expect(array_sum($view['below']['amortizare']['by_channel']))->toEqualWithDelta(30.0, 0.01)
        ->and(array_sum($view['below']['impozit']['by_product']))->toEqualWithDelta(12.0, 0.01);
});

test('below-EBITDA lines follow the period like everything else', function () {
    fakePnlRevenue(pnlRevenueRows());
    fakePnlBranches([]);
    fakePnlCosts(
        [],
        [
            ['bucket' => 'amortizare', 'account' => '681', 'month' => 2, 'lei' => 100.0],
            ['bucket' => 'amortizare', 'account' => '681', 'month' => 8, 'lei' => 300.0],
        ],
    );

    $service = app(PnlReportService::class);
    $report = $service->report($this->company, 2026, forceRefresh: true);

    expect($service->view($report, 'year')['below']['amortizare']['total'])->toEqual(400.0)
        ->and($service->view($report, 'q1')['below']['amortizare']['total'])->toEqual(100.0)
        ->and($service->view($report, 'q2')['below']['amortizare']['total'])->toEqual(100.0)
        ->and($service->view($report, 'q3')['below']['amortizare']['total'])->toEqual(400.0);
});

test('one invoice can be moved without moving everything from that supplier', function () {
    fakePnlRevenue(pnlRevenueRows());
    fakePnlBranches([]);

    // Două facturi de la același furnizor, una dintre ele pusă greșit.
    fakePnlCosts(
        [['account' => '628', 'sediu' => '', 'partner' => 'Soft Mediatel', 'note' => 'Servicii', 'month' => 1, 'lei' => 500.0]],
        [],
        [['data_doc' => '2026-01-20', 'tip_doc' => 'FactFI', 'nr_doc' => 'A7', 'account' => '628', 'sediu' => '', 'partner' => 'Soft Mediatel', 'note' => 'Servicii', 'month' => 1, 'lei' => 200.0]],
    );

    PnlCostOverride::factory()->create([
        'company_id' => $this->company->id,
        'scope' => PnlCostOverride::SCOPE_DOCUMENT,
        'match_key' => PnlCostOverride::documentKey('2026-01-20', 'FactFI', 'A7'),
        'saf' => '18920',
    ]);

    $view = pnlView();

    // Din cei 500 ai furnizorului, 200 pleacă; restul rămâne unde era.
    expect(collect($view['lines'])->firstWhere('saf', '18930')['total'])->toEqual(300.0)
        ->and(collect($view['lines'])->firstWhere('saf', '18920')['total'])->toEqual(200.0)
        ->and($view['totals']['total'])->toEqual(500.0)
        ->and($view['meta']['reconciles'])->toBeTrue();
});

test('IFRS 16 takes the rent out of EBITDA and puts it below the line', function () {
    fakePnlRevenue(pnlRevenueRows());
    fakePnlBranches([]);
    // 1.200 lei chirie pe an (linia 4000) plus 300 lei altceva.
    fakePnlCosts([
        ['account' => '612', 'sediu' => '', 'partner' => '', 'note' => 'Chirie', 'month' => 1, 'lei' => 1200.0],
        ['account' => '626', 'sediu' => '', 'partner' => '', 'note' => 'Telefonie', 'month' => 1, 'lei' => 300.0],
    ]);
    config(['pnl.ifrs16.lines' => ['4000'], 'pnl.ifrs16.term_years' => 5, 'pnl.ifrs16.discount_rate' => 0.08]);

    $service = app(PnlReportService::class);
    $report = $service->report($this->company, 2026, forceRefresh: true);

    $ras = $service->view($report, 'year', PnlReportService::BASIS_RAS);
    $ifrs = $service->view($report, 'year', PnlReportService::BASIS_IFRS16);

    // Statutar: toată cheltuiala e în exploatare.
    expect($ras['totals']['total'])->toEqual(1500.0)
        ->and($ras['ifrs16'])->toBeNull();

    // IFRS 16: chiria iese, deci EBITDA e mai mare fix cu ea.
    expect($ifrs['totals']['total'])->toEqual(300.0)
        ->and($ifrs['ifrs16']['lease_expense'])->toEqual(1200.0)
        ->and($ifrs['ifrs16']['effect_on_ebitda'])->toEqual(1200.0);

    // Datoria = 1.200 × anuitatea pe 5 ani la 8 % (3,9927), amortizată liniar.
    $liability = 1200 * (1 - 1.08 ** -5) / 0.08;
    expect($ifrs['ifrs16']['liability'])->toEqualWithDelta($liability, 0.01)
        ->and($ifrs['ifrs16']['depreciation'])->toEqualWithDelta($liability / 5, 0.01)
        ->and($ifrs['ifrs16']['interest'])->toEqualWithDelta($liability * 0.08, 0.01);

    // Amortizarea și dobânda ajung sub EBITDA, iar profitul net se mișcă doar
    // cu diferența dintre chirie și ele.
    expect($ifrs['below']['amortizare']['total'])->toEqualWithDelta($liability / 5, 0.01)
        ->and($ifrs['below']['financiar']['total'])->toEqualWithDelta($liability * 0.08, 0.01)
        ->and($ifrs['ifrs16']['effect_on_net'])->toEqualWithDelta(1200 - $liability / 5 - $liability * 0.08, 0.01);

    // Cele două vederi închid în amândouă bazele.
    expect($ras['meta']['reconciles'])->toBeTrue()
        ->and($ifrs['meta']['reconciles'])->toBeTrue();
});

test('the IFRS 16 restatement splits across periods without inventing rent', function () {
    fakePnlRevenue(pnlRevenueRows());
    fakePnlBranches([]);
    fakePnlCosts([
        ['account' => '612', 'sediu' => '', 'partner' => '', 'note' => 'Chirie', 'month' => 2, 'lei' => 600.0],
        ['account' => '612', 'sediu' => '', 'partner' => '', 'note' => 'Chirie', 'month' => 8, 'lei' => 600.0],
    ]);
    config(['pnl.ifrs16.lines' => ['4000']]);

    $service = app(PnlReportService::class);
    $report = $service->report($this->company, 2026, forceRefresh: true);

    $year = $service->view($report, 'year', PnlReportService::BASIS_IFRS16);
    $q1 = $service->view($report, 'q1', PnlReportService::BASIS_IFRS16);
    $q3 = $service->view($report, 'q3', PnlReportService::BASIS_IFRS16);

    // T1 prinde doar prima chirie; T3, cumulat, le prinde pe amândouă, deci
    // ajunge la anul întreg.
    expect($q1['ifrs16']['lease_expense'])->toEqual(600.0)
        ->and($q3['ifrs16']['lease_expense'])->toEqual(1200.0)
        ->and($q3['ifrs16']['depreciation'])->toEqualWithDelta($year['ifrs16']['depreciation'], 0.01)
        ->and($q1['ifrs16']['depreciation'])->toEqualWithDelta($year['ifrs16']['depreciation'] / 2, 0.01);
});

test('the financial view takes revenue and COGS from the ledger, not from eTrip', function () {
    fakePnlRevenue(pnlRevenueRows());
    fakePnlBranches([]);
    fakePnlCosts(
        [['account' => '626', 'sediu' => '', 'partner' => '', 'note' => 'Telefonie', 'month' => 1, 'lei' => 100.0]],
    );

    // Contabilitatea recunoaște altfel: 2.000 venit, 1.600 cost.
    test()->mock(OmcPnlCostReader::class, function (MockInterface $mock) {
        $mock->shouldReceive('costs')->andReturn([['account' => '626', 'sediu' => '', 'partner' => '', 'note' => 'Telefonie', 'month' => 1, 'lei' => 100.0]]);
        $mock->shouldReceive('belowEbitda')->andReturn([]);
        $mock->shouldReceive('documentsByKey')->andReturn([]);
        $mock->shouldReceive('payrollByPlace')->andReturn([]);
        $mock->shouldReceive('revenueAndCogs')->andReturn([
            ['bucket' => 'revenue', 'month' => 1, 'lei' => 2000.0],
            ['bucket' => 'cogs', 'month' => 1, 'lei' => 1600.0],
        ]);
    });

    $service = app(PnlReportService::class);
    $report = $service->report($this->company, 2026, forceRefresh: true);

    $operational = $service->view($report, 'year', PnlReportService::BASIS_RAS, PnlReportService::MODE_OPERATIONAL);
    $financial = $service->view($report, 'year', PnlReportService::BASIS_RAS, PnlReportService::MODE_FINANCIAL);

    // Operațional: 1.250 venit din eTrip, marja 190.
    expect($operational['revenue']['total']['net'])->toEqual(1250.0)
        ->and($operational['revenue']['total']['margin'])->toEqual(190.0);

    // Financiar: cifrele registrului, marja = venit − cost.
    expect($financial['revenue']['total']['net'])->toEqual(2000.0)
        ->and($financial['revenue']['total']['margin'])->toEqual(400.0)
        ->and($financial['revenue']['cogs'])->toEqual(1600.0);

    // Coloanele păstrează structura vânzării: retailul făcea 1.000 din 1.250 = 80 %.
    expect($financial['revenue']['by_channel']['retail']['net'])->toEqual(1600.0)
        ->and($financial['revenue']['by_channel']['site']['net'])->toEqual(400.0)
        ->and(
            $financial['revenue']['by_channel']['retail']['net']
            + $financial['revenue']['by_channel']['site']['net']
        )->toEqual(2000.0);

    // Cheltuielile rămân aceleași în ambele vederi: se schimbă doar vârful.
    expect($financial['totals']['total'])->toEqual($operational['totals']['total']);
});

test('the financial view follows the period too', function () {
    fakePnlRevenue(pnlRevenueRows());
    fakePnlBranches([]);
    fakePnlCosts([]);

    test()->mock(OmcPnlCostReader::class, function (MockInterface $mock) {
        $mock->shouldReceive('costs')->andReturn([]);
        $mock->shouldReceive('belowEbitda')->andReturn([]);
        $mock->shouldReceive('documentsByKey')->andReturn([]);
        $mock->shouldReceive('payrollByPlace')->andReturn([]);
        $mock->shouldReceive('revenueAndCogs')->andReturn([
            ['bucket' => 'revenue', 'month' => 2, 'lei' => 500.0],
            ['bucket' => 'revenue', 'month' => 8, 'lei' => 1500.0],
            ['bucket' => 'cogs', 'month' => 2, 'lei' => 400.0],
            ['bucket' => 'cogs', 'month' => 8, 'lei' => 1000.0],
        ]);
    });

    $service = app(PnlReportService::class);
    $report = $service->report($this->company, 2026, forceRefresh: true);

    expect($service->view($report, 'year', 'ras', 'financial')['revenue']['total']['net'])->toEqual(2000.0)
        ->and($service->view($report, 'q1', 'ras', 'financial')['revenue']['total']['net'])->toEqual(500.0)
        ->and($service->view($report, 'q3', 'ras', 'financial')['revenue']['total']['net'])->toEqual(2000.0)
        ->and($service->view($report, 'q1', 'ras', 'financial')['revenue']['total']['margin'])->toEqual(100.0);
});

test('retail opens into its own branches, and they add up to retail', function () {
    fakePnlRevenue(pnlRevenueRows());
    // Chiria de la Sun Plaza e a magazinului Sun Plaza.
    fakePnlBranches(['Sun Plaza' => 'retail']);
    fakePnlCosts([
        ['account' => '612', 'sediu' => 'Sun Plaza', 'partner' => '', 'note' => 'Chirie', 'month' => 1, 'lei' => 100.0],
        ['account' => '626', 'sediu' => '', 'partner' => '', 'note' => 'Telefonie', 'month' => 1, 'lei' => 500.0],
    ]);

    $service = app(PnlReportService::class);
    $report = $service->report($this->company, 2026, forceRefresh: true);

    $channels = $service->view($report, 'year');
    $retail = $service->view($report, 'year', 'ras', 'operational', 'retail');

    // Desfăcut, coloanele sunt magazinele, iar totalul e al retailului.
    expect($retail['channels'])->toContain('Sun Plaza')->toContain('Magheru')
        ->and($retail['expand'])->toBe('retail')
        ->and($retail['revenue']['total']['net'])->toEqual(1000.0)
        ->and($retail['revenue']['by_channel']['Sun Plaza']['net'])->toEqual(750.0)
        ->and($retail['revenue']['by_channel']['Magheru']['net'])->toEqual(250.0);

    // Cheltuiala retailului se regăsește întreagă pe magazinele lui.
    $retailCost = $channels['totals']['by_channel']['retail'];
    expect(array_sum($retail['totals']['by_channel']))->toEqualWithDelta($retailCost, 0.05)
        ->and($retail['totals']['total'])->toEqualWithDelta($retailCost, 0.05);

    // Chiria stă pe magazinul ei, nu împărțită pe cheia de vânzare.
    $rent = collect($retail['lines'])->firstWhere('saf', '4000');
    expect($rent['by_channel']['Sun Plaza'])->toEqual(100.0)
        ->and($rent['by_channel']['Magheru'])->toEqual(0.0);

    // Telefonia, generală, se împarte pe vânzare: 750/1.000 și 250/1.000 din
    // partea de retail a celor 500 de lei.
    $phone = collect($retail['lines'])->firstWhere('saf', '24200');
    expect($phone['by_channel']['Sun Plaza'] / $phone['by_channel']['Magheru'])->toEqualWithDelta(3.0, 0.01);
});

test('an unknown channel to expand is ignored rather than emptying the report', function () {
    fakePnlRevenue(pnlRevenueRows());
    fakePnlBranches([]);
    fakePnlCosts([['account' => '626', 'sediu' => '', 'partner' => '', 'note' => 'Telefonie', 'month' => 1, 'lei' => 100.0]]);

    $service = app(PnlReportService::class);
    $report = $service->report($this->company, 2026, forceRefresh: true);
    $view = $service->view($report, 'year', 'ras', 'operational', 'inexistent');

    expect($view['expand'])->toBeNull()
        ->and($view['totals']['total'])->toEqual(100.0)
        ->and($view['meta']['reconciles'])->toBeTrue();
});

test('a branch that sells in two channels is counted only once in each', function () {
    // „Plaza” vinde și pe retail, și pe franciză. Desfăcut pe retail, trebuie
    // să se vadă doar partea de retail — altfel coloanele ar aduna vânzări
    // care aparțin altui canal.
    fakePnlRevenue([
        ['channel' => 'retail', 'branch' => 'Plaza', 'product' => 'Charter', 'month' => 1, 'bookings' => 10, 'net' => 600.0, 'margin' => 60.0],
        ['channel' => 'franciza', 'branch' => 'Plaza', 'product' => 'Charter', 'month' => 1, 'bookings' => 5, 'net' => 400.0, 'margin' => 40.0],
        ['channel' => 'retail', 'branch' => 'Magheru', 'product' => 'Cazare', 'month' => 1, 'bookings' => 4, 'net' => 200.0, 'margin' => 20.0],
    ]);
    fakePnlBranches([]);
    fakePnlCosts([['account' => '626', 'sediu' => '', 'partner' => '', 'note' => 'Telefonie', 'month' => 1, 'lei' => 1200.0]]);

    $service = app(PnlReportService::class);
    $report = $service->report($this->company, 2026, forceRefresh: true);

    $channels = $service->view($report, 'year');
    $retail = $service->view($report, 'year', 'ras', 'operational', 'retail');

    // Retailul vinde 800 din cei 1.200 ai companiei.
    expect($channels['revenue']['by_channel']['retail']['net'])->toEqual(800.0)
        ->and($retail['revenue']['total']['net'])->toEqual(800.0)
        ->and($retail['revenue']['by_channel']['Plaza']['net'])->toEqual(600.0)
        ->and($retail['revenue']['by_channel']['Magheru']['net'])->toEqual(200.0);

    // Franciza deschisă își vede doar partea ei din aceeași sucursală.
    $franciza = $service->view($report, 'year', 'ras', 'operational', 'franciza');
    expect($franciza['revenue']['by_channel']['Plaza']['net'])->toEqual(400.0)
        ->and($franciza['channels'])->not->toContain('Magheru');

    // Costul desfăcut al unui canal = costul lui din vederea pe canale.
    expect($retail['totals']['total'])->toEqualWithDelta($channels['totals']['by_channel']['retail'], 0.05)
        ->and($franciza['totals']['total'])->toEqualWithDelta($channels['totals']['by_channel']['franciza'], 0.05)
        ->and($retail['meta']['reconciles'])->toBeTrue();
});

test('other operating income counts as revenue in the financial view', function () {
    fakePnlRevenue(pnlRevenueRows());
    fakePnlBranches([]);

    test()->mock(OmcPnlCostReader::class, function (MockInterface $mock) {
        $mock->shouldReceive('costs')->andReturn([]);
        $mock->shouldReceive('belowEbitda')->andReturn([]);
        $mock->shouldReceive('documentsByKey')->andReturn([]);
        $mock->shouldReceive('payrollByPlace')->andReturn([]);
        // Cifra de afaceri, plus subvenții și alte venituri din exploatare.
        $mock->shouldReceive('revenueAndCogs')->andReturn([
            ['bucket' => 'revenue', 'month' => 1, 'lei' => 1000.0],
            ['bucket' => 'other_income', 'month' => 1, 'lei' => 120.0],
            ['bucket' => 'other_income', 'month' => 2, 'lei' => -20.0],
            ['bucket' => 'cogs', 'month' => 1, 'lei' => 800.0],
        ]);
    });

    $service = app(PnlReportService::class);
    $report = $service->report($this->company, 2026, forceRefresh: true);
    $view = $service->view($report, 'year', 'ras', 'financial');

    // 1.000 cifră de afaceri + 120 − 20 alte venituri = 1.100 venituri din exploatare.
    expect($view['revenue']['total']['net'])->toEqual(1100.0)
        ->and($view['revenue']['total']['margin'])->toEqual(300.0)
        ->and($view['revenue']['cogs'])->toEqual(800.0);

    // Pe trimestre, fiecare ia doar ce e al lui.
    expect($service->view($report, 'q1', 'ras', 'financial')['revenue']['total']['net'])->toEqual(1100.0)
        // Cumulat: ianuarie (1.000 + 120) plus februarie (−20).
        ->and($service->view($report, 'm2', 'ras', 'financial')['revenue']['total']['net'])->toEqual(1100.0)
        ->and($service->view($report, 'm1', 'ras', 'financial')['revenue']['total']['net'])->toEqual(1120.0);
});

test('a quarter compares against the same cumulative quarter last year', function () {
    fakePnlRevenue(pnlRevenueRows());
    fakePnlBranches([]);
    fakePnlCosts([
        ['account' => '626', 'sediu' => '', 'partner' => '', 'note' => 'Telefonie', 'month' => 2, 'lei' => 100.0],
        ['account' => '626', 'sediu' => '', 'partner' => '', 'note' => 'Telefonie', 'month' => 5, 'lei' => 200.0],
        ['account' => '626', 'sediu' => '', 'partner' => '', 'note' => 'Telefonie', 'month' => 11, 'lei' => 400.0],
    ]);

    $service = app(PnlReportService::class);
    $report = $service->report($this->company, 2026, forceRefresh: true);

    // Aceeași tăietură pe amândoi anii înseamnă aceleași luni: ian–iun aici,
    // ian–iun și acolo. Altfel comparația ar pune un semestru lângă un an.
    expect(PnlReportService::months('q2'))->toBe([1, 2, 3, 4, 5, 6])
        ->and($service->view($report, 'q2')['totals']['total'])->toEqual(300.0)
        ->and($service->view($report, 'q4')['totals']['total'])->toEqual(700.0)
        ->and($service->view($report, 'year')['totals']['total'])->toEqual(700.0);
});

test('a period is a month plus how it is read: MTD alone, YTD from January', function () {
    expect(PnlReportService::months('mtd7'))->toBe([7])
        ->and(PnlReportService::months('ytd7'))->toBe([1, 2, 3, 4, 5, 6, 7])
        ->and(PnlReportService::months('ytd1'))->toBe([1])
        ->and(PnlReportService::months('mtd1'))->toBe([1])
        ->and(PnlReportService::months('year'))->toBe(range(1, 12))
        // Trimestrele sunt doar YTD pe luna lor, deci nu mai au opțiune proprie.
        ->and(PnlReportService::months('ytd3'))->toBe(PnlReportService::months('q1'))
        ->and(PnlReportService::months('ytd6'))->toBe(PnlReportService::months('q2'))
        ->and(PnlReportService::months('ytd12'))->toBe(PnlReportService::months('year'))
        // Linkurile vechi rămân valide.
        ->and(PnlReportService::months('m7'))->toBe(PnlReportService::months('ytd7'))
        // Orice altceva înseamnă anul întreg, nu o eroare.
        ->and(PnlReportService::months('mtd13'))->toBe(range(1, 12))
        ->and(PnlReportService::months('aiurea'))->toBe(range(1, 12));
});

test('MTD shows one month alone while YTD carries the year so far', function () {
    fakePnlRevenue(pnlRevenueRows());
    fakePnlBranches([]);
    fakePnlCosts([
        ['account' => '626', 'sediu' => '', 'partner' => '', 'note' => 'Telefonie', 'month' => 1, 'lei' => 100.0],
        ['account' => '626', 'sediu' => '', 'partner' => '', 'note' => 'Telefonie', 'month' => 5, 'lei' => 250.0],
    ]);

    $service = app(PnlReportService::class);
    $report = $service->report($this->company, 2026, forceRefresh: true);

    expect($service->view($report, 'mtd5')['totals']['total'])->toEqual(250.0)
        ->and($service->view($report, 'ytd5')['totals']['total'])->toEqual(350.0)
        ->and($service->view($report, 'mtd3')['totals']['total'])->toEqual(0.0)
        ->and($service->view($report, 'ytd3')['totals']['total'])->toEqual(100.0)
        ->and($service->view($report, 'mtd5')['meta']['reconciles'])->toBeTrue()
        ->and($service->view($report, 'ytd5')['meta']['reconciles'])->toBeTrue();
});

test('a cost moved to another channel stops following the revenue key', function () {
    fakePnlRevenue(pnlRevenueRows());
    fakePnlBranches([]);
    // O campanie a site-ului, fără punct de lucru: cheia ar trimite 80% la retail.
    fakePnlCosts([['account' => '6232', 'sediu' => '', 'partner' => 'Google', 'note' => 'Promovare Google', 'month' => 1, 'lei' => 1000.0]]);

    expect(collect(pnlView()['lines'])->firstWhere('saf', '16950')['by_channel'])
        ->toMatchArray(['retail' => 800.0, 'site' => 200.0]);

    PnlCostOverride::factory()->create([
        'company_id' => $this->company->id,
        'scope' => PnlCostOverride::SCOPE_ITEM,
        'match_key' => PnlCostOverride::itemKey('6232', '', 'Google'),
        'saf' => null,
        'channel' => 'site',
    ]);

    $moved = pnlView();
    $line = collect($moved['lines'])->firstWhere('saf', '16950');

    // Tot pe linia ei, dar integral pe site.
    expect($line['by_channel']['site'])->toEqual(1000.0)
        ->and($line['by_channel']['retail'])->toEqual(0.0)
        ->and($line['total'])->toEqual(1000.0)
        ->and($moved['totals']['total'])->toEqual(1000.0)
        ->and($moved['meta']['reconciles'])->toBeTrue();
});

test('one correction can move both the line and the channel', function () {
    fakePnlRevenue(pnlRevenueRows());
    fakePnlBranches([]);
    fakePnlCosts([['account' => '6232', 'sediu' => '', 'partner' => 'Google', 'note' => 'Promovare Google', 'month' => 1, 'lei' => 500.0]]);

    PnlCostOverride::factory()->create([
        'company_id' => $this->company->id,
        'scope' => PnlCostOverride::SCOPE_ITEM,
        'match_key' => PnlCostOverride::itemKey('6232', '', 'Google'),
        'saf' => '18920',
        'channel' => 'cc',
    ]);

    $line = collect(pnlView()['lines'])->firstWhere('saf', '18920');

    expect($line['total'])->toEqual(500.0)
        ->and($line['by_channel']['cc'])->toEqual(500.0)
        ->and(collect(pnlView()['lines'])->firstWhere('saf', '16950'))->toBeNull();
});

test('a channel correction holds in every view of the report', function () {
    fakePnlRevenue(pnlRevenueRows());
    fakePnlBranches([]);
    fakePnlCosts([['account' => '612', 'sediu' => '', 'partner' => '', 'note' => 'Chirie', 'month' => 1, 'lei' => 900.0]]);
    config(['pnl.ifrs16.lines' => ['4000']]);

    PnlCostOverride::factory()->create([
        'company_id' => $this->company->id,
        'scope' => PnlCostOverride::SCOPE_ITEM,
        'match_key' => PnlCostOverride::itemKey('612', '', ''),
        'saf' => null,
        'channel' => 'site',
    ]);

    $service = app(PnlReportService::class);
    $report = $service->report($this->company, 2026, forceRefresh: true);

    // Corectura stă în construcție, deci o poartă orice tăietură a raportului:
    // perioadă, bază contabilă, mod de recunoaștere, canal desfăcut.
    foreach ([
        ['year', 'ras', 'operational'],
        ['ytd1', 'ras', 'operational'],
        ['year', 'ifrs16', 'operational'],
        ['year', 'ras', 'financial'],
        ['year', 'ifrs16', 'financial'],
    ] as [$period, $basis, $mode]) {
        $view = $service->view($report, $period, $basis, $mode);
        $rent = collect($view['lines'])->firstWhere('saf', '4000');
        expect($rent['by_channel']['site'])->toEqual(900.0)
            ->and($rent['by_channel']['retail'])->toEqual(0.0);
    }
});

test('the financial view splits costs across the columns too, and closes', function () {
    fakePnlRevenue(pnlRevenueRows());
    fakePnlBranches([]);

    test()->mock(OmcPnlCostReader::class, function (MockInterface $mock) {
        $mock->shouldReceive('costs')->andReturn([
            ['account' => '626', 'sediu' => '', 'partner' => '', 'note' => 'Telefonie', 'month' => 1, 'lei' => 1000.0],
        ]);
        $mock->shouldReceive('belowEbitda')->andReturn([]);
        $mock->shouldReceive('documentsByKey')->andReturn([]);
        $mock->shouldReceive('payrollByPlace')->andReturn([]);
        $mock->shouldReceive('revenueAndCogs')->andReturn([
            ['bucket' => 'revenue', 'month' => 1, 'lei' => 5000.0],
            ['bucket' => 'cogs', 'month' => 1, 'lei' => 4000.0],
        ]);
    });

    $service = app(PnlReportService::class);
    $report = $service->report($this->company, 2026, forceRefresh: true);
    $view = $service->view($report, 'year', 'ras', 'financial');

    // Cheltuielile se împart pe coloane la fel ca în vederea operațională:
    // doar vârful raportului se schimbă între moduri.
    expect($view['totals']['by_channel']['retail'])->toEqual(800.0)
        ->and($view['totals']['by_channel']['site'])->toEqual(200.0)
        ->and(array_sum($view['totals']['by_channel']))->toEqualWithDelta($view['totals']['total'], 0.05)
        ->and($view['meta']['reconciles'])->toBeTrue();
});

test('only the channels where a branch means a shop can be opened', function () {
    fakePnlRevenue([
        ['channel' => 'retail', 'branch' => 'Sun Plaza', 'product' => 'Charter', 'month' => 1, 'bookings' => 1, 'net' => 600.0, 'margin' => 60.0],
        // Agentul care lucrează comanda de pe site e logat pe sucursala lui;
        // „Site · Botoșani” nu spune nimic despre canal.
        ['channel' => 'site', 'branch' => 'Botosani', 'product' => 'Cazare', 'month' => 1, 'bookings' => 1, 'net' => 400.0, 'margin' => 40.0],
    ]);
    fakePnlBranches([]);
    fakePnlCosts([['account' => '626', 'sediu' => '', 'partner' => '', 'note' => 'Telefonie', 'month' => 1, 'lei' => 100.0]]);

    $service = app(PnlReportService::class);
    $report = $service->report($this->company, 2026, forceRefresh: true);
    $view = $service->view($report, 'year');

    expect($view['expandable'])->toContain('retail')
        ->and($view['expandable'])->not->toContain('site');

    // Cerut pe site, desfacerea e ignorată: rămâi pe canale.
    $asked = $service->view($report, 'year', 'ras', 'operational', 'site');
    expect($asked['expand'])->toBeNull()
        ->and($asked['channels'])->toContain('site')
        ->and($asked['channels'])->not->toContain('Botosani');
});

test('a franchise keeps its revenue and margin but carries no cost', function () {
    fakePnlRevenue([
        ['channel' => 'retail', 'branch' => 'Sun Plaza', 'product' => 'Charter', 'month' => 1, 'bookings' => 1, 'net' => 600.0, 'margin' => 60.0],
        ['channel' => 'franciza', 'branch' => 'Franciza Brasov', 'product' => 'Charter', 'month' => 1, 'bookings' => 1, 'net' => 400.0, 'margin' => 50.0],
    ]);
    fakePnlBranches([]);
    fakePnlCosts([['account' => '626', 'sediu' => '', 'partner' => '', 'note' => 'Telefonie', 'month' => 1, 'lei' => 1000.0]]);

    $view = pnlView();

    // Venitul și marja sunt ale noastre; cheltuiala e a francizatului.
    expect($view['revenue']['by_channel']['franciza']['net'])->toEqual(400.0)
        ->and($view['revenue']['by_channel']['franciza']['margin'])->toEqual(50.0)
        ->and($view['totals']['by_channel']['franciza'])->toEqual(0.0);

    // Toată cheltuiala rămâne pe canalele care o duc, deci totalul nu scade.
    expect($view['totals']['by_channel']['retail'])->toEqual(1000.0)
        ->and($view['totals']['total'])->toEqual(1000.0)
        ->and($view['meta']['reconciles'])->toBeTrue();
});

test('a cost booked on a franchise work point is not ours either', function () {
    fakePnlRevenue([
        ['channel' => 'retail', 'branch' => 'Sun Plaza', 'product' => 'Charter', 'month' => 1, 'bookings' => 1, 'net' => 600.0, 'margin' => 60.0],
        ['channel' => 'franciza', 'branch' => 'Franciza Brasov', 'product' => 'Charter', 'month' => 1, 'bookings' => 1, 'net' => 400.0, 'margin' => 50.0],
    ]);
    // Punctul de lucru trimite spre franciză, dar cheltuiala nu rămâne acolo.
    fakePnlBranches(['Franciza Brasov' => 'franciza']);
    fakePnlCosts([['account' => '612', 'sediu' => 'Franciza Brasov', 'partner' => '', 'note' => 'Chirie', 'month' => 1, 'lei' => 500.0]]);

    $view = pnlView();

    expect($view['totals']['by_channel']['franciza'])->toEqual(0.0)
        ->and($view['totals']['by_channel']['retail'])->toEqual(500.0)
        ->and($view['totals']['total'])->toEqual(500.0)
        ->and($view['meta']['reconciles'])->toBeTrue();
});

test('below-EBITDA lines skip the franchise too', function () {
    fakePnlRevenue([
        ['channel' => 'retail', 'branch' => 'Sun Plaza', 'product' => 'Charter', 'month' => 1, 'bookings' => 1, 'net' => 600.0, 'margin' => 60.0],
        ['channel' => 'franciza', 'branch' => 'Franciza Brasov', 'product' => 'Charter', 'month' => 1, 'bookings' => 1, 'net' => 400.0, 'margin' => 50.0],
    ]);
    fakePnlBranches([]);
    fakePnlCosts([], [['bucket' => 'impozit', 'account' => '691', 'month' => 1, 'lei' => 300.0]]);

    $view = pnlView();

    expect($view['below']['impozit']['by_channel']['franciza'])->toEqual(0.0)
        ->and($view['below']['impozit']['by_channel']['retail'])->toEqual(300.0)
        ->and($view['below']['impozit']['total'])->toEqual(300.0);
});

test('a move made by hand onto the franchise is still honoured', function () {
    fakePnlRevenue([
        ['channel' => 'retail', 'branch' => 'Sun Plaza', 'product' => 'Charter', 'month' => 1, 'bookings' => 1, 'net' => 600.0, 'margin' => 60.0],
        ['channel' => 'franciza', 'branch' => 'Franciza Brasov', 'product' => 'Charter', 'month' => 1, 'bookings' => 1, 'net' => 400.0, 'margin' => 50.0],
    ]);
    fakePnlBranches([]);
    fakePnlCosts([['account' => '626', 'sediu' => '', 'partner' => 'Telekom', 'note' => 'Telefonie', 'month' => 1, 'lei' => 200.0]]);

    PnlCostOverride::factory()->create([
        'company_id' => $this->company->id,
        'scope' => PnlCostOverride::SCOPE_ITEM,
        'match_key' => PnlCostOverride::itemKey('626', '', 'Telekom'),
        'saf' => null,
        'channel' => 'franciza',
    ]);

    // Regula ține cheia de venit departe de franciză; o mutare anume e altceva.
    expect(pnlView()['totals']['by_channel']['franciza'])->toEqual(200.0);
});

test('a cost moved onto a product category goes there whole', function () {
    fakePnlRevenue([
        ['channel' => 'retail', 'branch' => 'Sun Plaza', 'product' => 'Charter', 'month' => 1, 'bookings' => 3, 'net' => 750.0, 'margin' => 75.0],
        ['channel' => 'retail', 'branch' => 'Sun Plaza', 'product' => 'Croaziere', 'month' => 1, 'bookings' => 1, 'net' => 250.0, 'margin' => 25.0],
    ]);
    fakePnlBranches([]);
    fakePnlCosts([['account' => '623', 'sediu' => '', 'partner' => 'Google Ireland', 'note' => 'Promovare', 'month' => 1, 'lei' => 400.0]]);

    // Pe cheia de venit, cheltuiala s-ar rupe 300 / 100 după ce a vândut
    // canalul; o campanie pentru croaziere nu e însă a charterului.
    PnlCostOverride::factory()->create([
        'company_id' => $this->company->id,
        'scope' => PnlCostOverride::SCOPE_ITEM,
        'match_key' => PnlCostOverride::itemKey('623', '', 'Google Ireland'),
        'saf' => null,
        'channel' => null,
        'product' => 'Croaziere',
    ]);

    $view = pnlView();

    expect($view['totals']['by_product']['Croaziere'])->toEqual(400.0)
        ->and($view['totals']['by_product']['Charter'] ?? 0.0)->toEqual(0.0)
        // Axa de canal nu se atinge: produsul spune ce s-a vândut, nu cine.
        ->and($view['totals']['by_channel']['retail'])->toEqual(400.0)
        ->and($view['meta']['reconciles'])->toBeTrue();
});

test('a product correction and a channel correction hold together', function () {
    fakePnlRevenue([
        ['channel' => 'retail', 'branch' => 'Sun Plaza', 'product' => 'Charter', 'month' => 1, 'bookings' => 3, 'net' => 750.0, 'margin' => 75.0],
        ['channel' => 'site', 'branch' => '', 'product' => 'Croaziere', 'month' => 1, 'bookings' => 1, 'net' => 250.0, 'margin' => 25.0],
    ]);
    fakePnlBranches([]);
    fakePnlCosts([['account' => '623', 'sediu' => '', 'partner' => 'Google Ireland', 'note' => 'Promovare', 'month' => 1, 'lei' => 400.0]]);

    PnlCostOverride::factory()->create([
        'company_id' => $this->company->id,
        'scope' => PnlCostOverride::SCOPE_ITEM,
        'match_key' => PnlCostOverride::itemKey('623', '', 'Google Ireland'),
        'saf' => null,
        'channel' => 'site',
        'product' => 'Croaziere',
    ]);

    $view = pnlView();

    expect($view['totals']['by_channel']['site'])->toEqual(400.0)
        ->and($view['totals']['by_product']['Croaziere'])->toEqual(400.0)
        ->and($view['meta']['reconciles'])->toBeTrue();
});

test('the FX commission is revenue, not a financial result', function () {
    fakePnlRevenue([
        ['channel' => 'retail', 'branch' => 'Sun Plaza', 'product' => 'Charter', 'month' => 1, 'bookings' => 3, 'net' => 750.0, 'margin' => 75.0],
        ['channel' => 'site', 'branch' => '', 'product' => 'Charter', 'month' => 1, 'bookings' => 1, 'net' => 250.0, 'margin' => 25.0],
    ]);
    fakePnlBranches([]);
    // În registru, un venit de sub EBITDA vine cu minus; comisionul de curs
    // trebuie să urce în marjă cu plus.
    fakePnlCosts([], [
        ['bucket' => PnlReportService::BUCKET_FX_COMMISSION, 'account' => '765', 'month' => 1, 'lei' => -400.0],
        ['bucket' => 'financiar', 'account' => '665', 'month' => 1, 'lei' => 100.0],
    ]);

    $view = pnlView();

    expect($view['revenue']['fx_commission']['total'])->toEqual(400.0)
        // 100 marjă din vânzare + 400 comision.
        ->and($view['revenue']['total']['margin'])->toEqual(500.0)
        // Pe cheia de venit: 3/4 retail, 1/4 site.
        ->and($view['revenue']['fx_commission']['by_channel']['retail'])->toEqual(300.0)
        ->and($view['revenue']['by_channel']['retail']['margin'])->toEqual(375.0)
        // Ce a rămas jos e chiar rezultat financiar, fără comision.
        ->and($view['below']['financiar']['total'])->toEqual(100.0);
});

test('the franchise gets its share of the FX commission', function () {
    fakePnlRevenue([
        ['channel' => 'retail', 'branch' => 'Sun Plaza', 'product' => 'Charter', 'month' => 1, 'bookings' => 3, 'net' => 600.0, 'margin' => 60.0],
        ['channel' => 'franciza', 'branch' => 'Franciza Brasov', 'product' => 'Charter', 'month' => 1, 'bookings' => 1, 'net' => 400.0, 'margin' => 40.0],
    ]);
    fakePnlBranches([]);
    fakePnlCosts([], [['bucket' => PnlReportService::BUCKET_FX_COMMISSION, 'account' => '765', 'month' => 1, 'lei' => -100.0]]);

    // Venitul francizei e al nostru, deci și comisionul de pe încasările ei;
    // doar cheltuielile sunt ale francizatului.
    expect(pnlView()['revenue']['fx_commission']['by_channel']['franciza'])->toEqual(40.0);
});

test('opening a cell shows what is in that cell, not the whole line', function () {
    fakePnlRevenue([
        ['channel' => 'retail', 'branch' => 'Sun Plaza', 'product' => 'Charter', 'month' => 1, 'bookings' => 3, 'net' => 750.0, 'margin' => 75.0],
        ['channel' => 'b2b', 'branch' => '', 'product' => 'Charter', 'month' => 1, 'bookings' => 1, 'net' => 250.0, 'margin' => 25.0],
    ]);
    fakePnlBranches(['Sun Plaza' => 'retail']);

    // Aceeași linie de cost, două cheltuieli: una a magazinului, una a nimănui.
    $costs = [
        ['account' => '626', 'sediu' => 'Sun Plaza', 'partner' => 'Orange', 'note' => 'Telefonie', 'month' => 1, 'lei' => 400.0],
        ['account' => '626', 'sediu' => '', 'partner' => 'Telekom', 'note' => 'Telefonie', 'month' => 1, 'lei' => 200.0],
    ];

    fakePnlCosts($costs, [], array_map(
        fn (array $cost, int $i) => [...$cost, 'data_doc' => '2026-01-1'.$i, 'tip_doc' => 'FactFI', 'nr_doc' => 'A'.$i],
        $costs,
        array_keys($costs),
    ));

    $service = app(PnlReportService::class);
    $service->report($this->company, 2026, forceRefresh: true);

    $all = $service->costDetails($this->company, 2026, '24200', 'year');
    $b2b = $service->costDetails($this->company, 2026, '24200', 'year', 'b2b');
    $retail = $service->costDetails($this->company, 2026, '24200', 'year', 'retail');

    // Toată linia, apoi cele două celule ale ei; împreună dau linia înapoi.
    expect($all['total'])->toEqual(600.0)
        ->and($b2b['total'])->toEqual(50.0)
        ->and($retail['total'])->toEqual(550.0)
        ->and(round($b2b['total'] + $retail['total'], 2))->toEqual($all['total'])
        // Telefonul magazinului nu apare deloc în B2B, fiindcă n-a ajuns acolo.
        ->and(collect($b2b['items'])->pluck('partner')->all())->toBe(['Telekom'])
        ->and(collect($retail['items'])->pluck('partner')->sort()->values()->all())->toBe(['Orange', 'Telekom']);
});

test('a cell detail adds up to the number shown in the table', function () {
    fakePnlRevenue(pnlRevenueRows());
    fakePnlBranches(['Sun Plaza' => 'retail']);

    $costs = [
        ['account' => '612', 'sediu' => 'Sun Plaza', 'partner' => 'Proprietar', 'note' => 'Chirie', 'month' => 1, 'lei' => 300.0],
        ['account' => '626', 'sediu' => '', 'partner' => 'Telekom', 'note' => 'Telefonie', 'month' => 1, 'lei' => 120.0],
    ];

    fakePnlCosts($costs, [], array_map(
        fn (array $cost, int $i) => [...$cost, 'data_doc' => '2026-01-1'.$i, 'tip_doc' => 'FactFI', 'nr_doc' => 'B'.$i],
        $costs,
        array_keys($costs),
    ));

    $service = app(PnlReportService::class);
    $report = $service->report($this->company, 2026, forceRefresh: true);
    $view = $service->view($report, 'year');

    foreach (['retail', 'site'] as $channel) {
        $cell = 0.0;

        foreach ($view['lines'] as $line) {
            $cell += $line['by_channel'][$channel] ?? 0.0;
        }

        $detail = 0.0;

        foreach ($view['lines'] as $line) {
            $detail += $service->costDetails($this->company, 2026, $line['saf'], 'year', $channel)['total'];
        }

        expect(round($detail, 2))->toEqual(round($cell, 2));
    }
});

test('on a product column the detail follows the product mix', function () {
    fakePnlRevenue([
        ['channel' => 'retail', 'branch' => '', 'product' => 'Charter', 'month' => 1, 'bookings' => 3, 'net' => 750.0, 'margin' => 75.0],
        ['channel' => 'retail', 'branch' => '', 'product' => 'Croaziere', 'month' => 1, 'bookings' => 1, 'net' => 250.0, 'margin' => 25.0],
    ]);
    fakePnlBranches([]);

    $costs = [['account' => '626', 'sediu' => '', 'partner' => 'Telekom', 'note' => 'Telefonie', 'month' => 1, 'lei' => 400.0]];
    fakePnlCosts($costs, [], [[...$costs[0], 'data_doc' => '2026-01-10', 'tip_doc' => 'FactFI', 'nr_doc' => 'C1']]);

    $service = app(PnlReportService::class);
    $service->report($this->company, 2026, forceRefresh: true);

    expect($service->costDetails($this->company, 2026, '24200', 'year', 'Croaziere', 'product')['total'])->toEqual(100.0)
        ->and($service->costDetails($this->company, 2026, '24200', 'year', 'Charter', 'product')['total'])->toEqual(300.0);
});

test('the amortisation line says which accounts it is made of', function () {
    fakePnlRevenue(pnlRevenueRows());
    fakePnlBranches([]);
    fakePnlCosts([], [
        ['bucket' => 'amortizare', 'account' => '6811', 'month' => 1, 'lei' => 1000.0],
        ['bucket' => 'amortizare', 'account' => '6814', 'month' => 1, 'lei' => 200.0],
        // Reluările vin cu minus: pe ele se sprijină „net de reluări”.
        ['bucket' => 'amortizare', 'account' => '7814', 'month' => 1, 'lei' => -1500.0],
    ]);

    $view = pnlView();
    $accounts = collect($view['below']['amortizare']['accounts']);

    // Un an în care se reiau mai multe ajustări decât se amortizează iese pe
    // minus, iar linia adaugă la profit. Defalcarea arată de ce.
    expect($view['below']['amortizare']['total'])->toEqual(-300.0)
        ->and($accounts->pluck('account')->all())->toBe(['7814', '6811', '6814'])
        ->and($accounts->firstWhere('account', '7814')['lei'])->toEqual(-1500.0)
        ->and($accounts->firstWhere('account', '6811')['label'])->toBe('Amortizarea imobilizărilor')
        ->and($accounts->firstWhere('account', '7814')['label'])->toContain('reluări');
});

test('the breakdown follows the period on screen', function () {
    fakePnlRevenue(pnlRevenueRows());
    fakePnlBranches([]);
    fakePnlCosts([], [
        ['bucket' => 'amortizare', 'account' => '6811', 'month' => 1, 'lei' => 100.0],
        ['bucket' => 'amortizare', 'account' => '6811', 'month' => 5, 'lei' => 900.0],
    ]);

    expect(pnlView('ytd3')['below']['amortizare']['accounts'])->toBe([
        ['account' => '6811', 'label' => 'Amortizarea imobilizărilor', 'lei' => 100.0],
    ]);
});

test('the cost born from a departure is a trip cost, not overhead', function () {
    fakePnlRevenue(pnlRevenueRows());
    fakePnlBranches([]);
    fakePnlCosts([
        // Motorina arsă pe cursă: centrul de cost începe cu TR, deci e a cursei.
        ['account' => '6022', 'sediu' => '', 'partner' => 'DKV', 'note' => 'Combustibil', 'month' => 1, 'lei' => 1000.0, 'trip_share' => 1.0],
        // Factură amestecată: jumătate cursă, jumătate sediu.
        ['account' => '625', 'sediu' => '', 'partner' => 'Hotel', 'note' => 'Cazare', 'month' => 1, 'lei' => 400.0, 'trip_share' => 0.5],
        // Revizia autocarului rămâne cheltuială de structură: o plătim oricum.
        ['account' => '611', 'sediu' => '', 'partner' => 'Service', 'note' => 'Revizie', 'month' => 1, 'lei' => 300.0],
    ]);

    $view = pnlView();

    expect($view['revenue']['trip_costs']['total'])->toEqual(1200.0)
        // Ce rămâne în exploatare: jumătatea de sediu plus revizia.
        ->and($view['totals']['total'])->toEqual(500.0)
        // Marja scade cu exact cât scade cheltuiala, deci EBITDA nu se mișcă.
        ->and($view['revenue']['total']['margin'])->toEqual(190.0 - 1200.0)
        ->and($view['meta']['reconciles'])->toBeTrue();
});

test('a trip cost is out of the line it would have sat on, detail included', function () {
    fakePnlRevenue(pnlRevenueRows());
    fakePnlBranches([]);

    $cost = ['account' => '6022', 'sediu' => '', 'partner' => 'DKV', 'note' => 'Combustibil', 'month' => 1, 'lei' => 1000.0, 'trip_share' => 1.0];
    fakePnlCosts([$cost], [], [[...$cost, 'data_doc' => '2026-01-10', 'tip_doc' => 'FactFI', 'nr_doc' => 'K1']]);

    $service = app(PnlReportService::class);
    $service->report($this->company, 2026, forceRefresh: true);

    // Linia de combustibil nu mai are nimic, nici în tabel, nici în panou.
    expect(collect($service->view($service->report($this->company, 2026), 'year')['lines'])->firstWhere('saf', '5010'))->toBeNull()
        ->and($service->costDetails($this->company, 2026, '5010', 'year')['total'])->toEqual(0.0);
});

test('inside a channel, shared costs can follow payroll instead of revenue', function () {
    fakePnlRevenue([
        // Magheru vinde de trei ori cât Plaza, dar are jumătate din oameni.
        ['channel' => 'retail', 'branch' => 'Magheru', 'product' => 'Charter', 'month' => 1, 'bookings' => 3, 'net' => 750.0, 'margin' => 75.0],
        ['channel' => 'retail', 'branch' => 'Plaza', 'product' => 'Charter', 'month' => 1, 'bookings' => 1, 'net' => 250.0, 'margin' => 25.0],
    ]);
    fakePnlBranches([], ['Magheru', 'Plaza']);
    fakePnlCosts(
        [['account' => '626', 'sediu' => '', 'partner' => 'Telekom', 'note' => 'Telefonie', 'month' => 1, 'lei' => 300.0]],
        [],
        [],
        [
            ['sediu' => 'SEDIUL CENTRAL', 'analytic' => '.1.Magheru', 'month' => 1, 'lei' => 100.0],
            ['sediu' => 'SEDIUL CENTRAL', 'analytic' => '.Plaza', 'month' => 1, 'lei' => 200.0],
            // Salariile sediului nu sunt ale niciunui magazin.
            ['sediu' => 'SEDIUL CENTRAL', 'analytic' => '.Contabilitate', 'month' => 1, 'lei' => 5000.0],
        ],
    );

    $service = app(PnlReportService::class);
    $report = $service->report($this->company, 2026, forceRefresh: true);

    $peVenit = $service->view($report, 'year', expand: 'retail');
    $peSalarii = $service->view($report, 'year', expand: 'retail', key: PnlReportService::KEY_PAYROLL);

    // Pe venit: 3/4 – 1/4. Pe masă salarială: 1/3 – 2/3.
    expect($peVenit['totals']['by_channel']['Magheru'])->toEqual(225.0)
        ->and($peVenit['totals']['by_channel']['Plaza'])->toEqual(75.0)
        ->and($peSalarii['totals']['by_channel']['Magheru'])->toEqual(100.0)
        ->and($peSalarii['totals']['by_channel']['Plaza'])->toEqual(200.0)
        // Oricare ar fi cheia, canalul rămâne cât era.
        ->and(array_sum($peSalarii['totals']['by_channel']))->toEqual(300.0)
        ->and($peSalarii['key'])->toBe('salarii');
});

test('a shop with no payroll of its own still carries its share', function () {
    fakePnlRevenue([
        ['channel' => 'retail', 'branch' => 'Magheru', 'product' => 'Charter', 'month' => 1, 'bookings' => 3, 'net' => 750.0, 'margin' => 75.0],
        ['channel' => 'retail', 'branch' => 'Plaza', 'product' => 'Charter', 'month' => 1, 'bookings' => 1, 'net' => 250.0, 'margin' => 25.0],
    ]);
    fakePnlBranches([], ['Magheru', 'Plaza']);
    fakePnlCosts(
        [['account' => '626', 'sediu' => '', 'partner' => 'Telekom', 'note' => 'Telefonie', 'month' => 1, 'lei' => 300.0]],
        [],
        [],
        [['sediu' => 'SEDIUL CENTRAL', 'analytic' => '.1.Magheru', 'month' => 1, 'lei' => 150.0]],
    );

    $service = app(PnlReportService::class);
    $view = $service->view($service->report($this->company, 2026, forceRefresh: true), 'year', expand: 'retail', key: PnlReportService::KEY_PAYROLL);

    // Plaza n-are salarii identificate: primește o masă estimată din venitul
    // ei, la raportul lui Magheru (150 la 750), deci 50 — un sfert din cheie.
    expect($view['totals']['by_channel']['Magheru'])->toEqual(225.0)
        ->and($view['totals']['by_channel']['Plaza'])->toEqual(75.0);
});

/**
 * Veniturile corporate vin din Tina, nu din eTrip, și se adaugă la celelalte:
 * același raport, două ERP-uri. Dacă Tina tace, raportul se face din ce
 * răspunde, dar o spune — altfel lipsa lor s-ar citi ca o scădere de business.
 */
test('the corporate revenue from Tina lands in the same report', function () {
    fakePnlRevenue(
        [['channel' => 'retail', 'branch' => 'Agenția Unu', 'product' => 'Charter', 'month' => 3, 'bookings' => 10, 'net' => 1000, 'margin' => 250]],
        [['channel' => 'corporate', 'branch' => 'Tina', 'product' => 'Corporate', 'month' => 3, 'bookings' => 4, 'net' => 600, 'margin' => 120]],
    );
    fakePnlBranches([]);
    fakePnlCosts([]);

    $report = app(PnlReportService::class)->report($this->company, 2026, forceRefresh: true);

    expect($report['revenue']['months'][3]['channel']['corporate']['net'] ?? null)->toEqual(600.0)
        ->and($report['revenue']['months'][3]['channel']['retail']['net'] ?? null)->toEqual(1000.0)
        ->and($report['revenue']['months'][3]['product']['Corporate']['margin'] ?? null)->toEqual(120.0)
        ->and($report['meta']['tina_error'])->toBeNull();
});

test('a silent Tina leaves the rest of the report standing, and says so', function () {
    fakePnlRevenue([['channel' => 'retail', 'branch' => 'Agenția Unu', 'product' => 'Charter', 'month' => 3, 'bookings' => 10, 'net' => 1000, 'margin' => 250]]);
    fakePnlBranches([]);
    fakePnlCosts([]);

    test()->mock(TinaPnlReader::class, fn (MockInterface $mock) => $mock->shouldReceive('byChannelAndProduct')->andThrow(new RuntimeException('connection refused')));

    $report = app(PnlReportService::class)->report($this->company, 2026, forceRefresh: true);

    expect($report['meta']['tina_error'])->toContain('Tina nu a putut fi citită')
        ->and($report['revenue']['months'][3]['channel']['retail']['net'] ?? null)->toEqual(1000.0);
});
