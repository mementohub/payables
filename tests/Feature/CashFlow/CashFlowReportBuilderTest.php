<?php

use App\Models\CashFlowSetting;
use App\Models\CashFlowSnapshot;
use App\Models\CharterContract;
use App\Models\CharterFlight;
use App\Services\CashFlow\CashFlowReportBuilder;
use App\Services\CashFlow\EtripCashFlowReader;
use App\Services\CashFlow\OmcCashFlowReader;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;

beforeEach(function () {
    Carbon::setTestNow('2026-09-16 10:00:00');

    CashFlowSetting::query()->create(['key' => CashFlowSetting::PARAMETERS, 'value' => [
        'fx' => ['mode' => 'manual', 'EUR' => 5, 'USD' => 4.5],
        'thresholds' => ['minimum' => 1000000, 'comfort' => 2000000],
        'scenario' => ['enabled' => true, 'factor' => 1, 'charter_factor' => 1, 'charter_base_season' => 'S26', 'charter_target_season' => 'S27'],
    ]]);
});

function omcFlows(): array
{
    return [
        ['day' => '2025-09-16', 'kind' => 'in', 'group' => 'partner', 'currency' => 'RON', 'amount' => 300000, 'lei' => 300000],
        ['day' => '2025-09-18', 'kind' => 'out', 'group' => 'partner', 'currency' => 'RON', 'amount' => 100000, 'lei' => 100000],
        ['day' => '2025-09-18', 'kind' => 'out', 'group' => 'salaries', 'currency' => 'RON', 'amount' => 20000, 'lei' => 20000],
        ['day' => '2025-09-18', 'kind' => 'out', 'group' => 'internal', 'currency' => 'RON', 'amount' => 999999, 'lei' => 999999],
        ['day' => '2026-09-10', 'kind' => 'in', 'group' => 'partner', 'currency' => 'RON', 'amount' => 200000, 'lei' => 200000],
        ['day' => '2026-09-11', 'kind' => 'out', 'group' => 'partner', 'currency' => 'EUR', 'amount' => 10000, 'lei' => 50000],
        ['day' => '2026-09-12', 'kind' => 'out', 'group' => 'internal', 'currency' => 'RON', 'amount' => 300000, 'lei' => 300000],
    ];
}

function mockOmc(bool $anchor = true): void
{
    test()->mock(OmcCashFlowReader::class, function (MockInterface $mock) use ($anchor) {
        $mock->shouldReceive('dailyFlows')->andReturnUsing(fn (CarbonInterface $from, CarbonInterface $to) => array_values(array_filter(
            omcFlows(),
            fn (array $row) => $row['day'] >= $from->toDateString() && $row['day'] < $to->toDateString(),
        )));
        $mock->shouldReceive('openSupplierInvoices')->andReturn([
            ['due' => '2026-09-01', 'currency' => 'RON', 'amount' => 400000, 'lei' => 400000, 'invoices' => 3],
            ['due' => '2026-10-20', 'currency' => 'EUR', 'amount' => 1000, 'lei' => 5000, 'invoices' => 1],
        ]);
        $mock->shouldReceive('monthlyAverageByAccount')->andReturn(['612' => 100000, '623' => 50000, '628.01' => 20000, '401' => 999]);
        $mock->shouldReceive('monthlyLedgerByAccount')->andReturn(['421' => 500000, '425' => 597000, '4411' => 100000, '627' => 10000, '6651' => 2000]);
        $mock->shouldReceive('monthEndAnchor')->andReturn($anchor ? CarbonImmutable::parse('2026-08-31') : null);
        $mock->shouldReceive('monthEndPositions')->andReturn([
            ['date' => '2025-08-31', 'bank' => ['RON' => 1000000], 'cash' => ['RON' => 0], 'deposits' => ['RON' => 4000000], 'rates' => []],
            ['date' => '2025-09-30', 'bank' => ['RON' => 1500000, 'EUR' => 10000], 'cash' => [], 'deposits' => ['RON' => 4000000], 'rates' => ['EUR' => 5.1]],
            ['date' => '2026-08-31', 'bank' => ['RON' => 1000000, 'EUR' => 100000], 'cash' => ['RON' => 10000], 'deposits' => ['RON' => 5000000], 'rates' => ['EUR' => 5.05]],
        ]);
        $mock->shouldReceive('openingPosition')->andReturn([
            ['currency' => 'EUR', 'bank_open' => 100000, 'bank_in' => 0, 'bank_out' => 10000, 'cash_open' => 0, 'cash_in' => 0, 'cash_out' => 0, 'deposits_open' => 0, 'deposits_open_lei' => 0, 'deposits_change' => 0],
            ['currency' => 'RON', 'bank_open' => 1000000, 'bank_in' => 200000, 'bank_out' => 300000, 'cash_open' => 10000, 'cash_in' => 5000, 'cash_out' => 0, 'deposits_open' => 5000000, 'deposits_open_lei' => 5000000, 'deposits_change' => 300000],
        ]);
    });
}

function mockEtrip(bool $failBookings = false): void
{
    test()->mock(EtripCashFlowReader::class, function (MockInterface $mock) use ($failBookings) {
        $bookings = $mock->shouldReceive('openBookings')->with('etrip_chr', Mockery::type(CarbonInterface::class), Mockery::type(CarbonInterface::class), 0.5);

        if ($failBookings) {
            $bookings->andThrow(new RuntimeException('connection refused'));
        } else {
            $bookings->andReturn([
                ['id' => 1, 'currency' => 'EUR', 'total_due' => 1000, 'paid' => 300, 'balance_due_date' => '2026-10-05', 'start_date' => '2026-10-20', 'brand' => 1, 'channel' => 5, 'segment_type' => 21, 'due_dates' => [['date' => '2026-09-01', 'amount' => 300], ['date' => '2026-09-25', 'amount' => 200]]],
                ['id' => 2, 'currency' => 'RON', 'total_due' => 1000, 'paid' => 0, 'balance_due_date' => '2026-09-01', 'start_date' => '2026-09-30', 'brand' => 1, 'channel' => 2, 'segment_type' => 157, 'due_dates' => []],
            ]);
        }

        $mock->shouldReceive('payables')->andReturn([
            ['week' => '2026-09-28', 'category' => 'hotel', 'currency' => 'EUR', 'cost' => 1000, 'items' => 2, 'bookings' => 1],
        ]);
        $mock->shouldReceive('ticketsOrdered')->andReturn([
            ['week' => '2026-09-14', 'currency' => 'RON', 'cost' => 700, 'items' => 1],
        ]);
        $mock->shouldReceive('bookingCurve')->andReturn([
            'receipts' => [
                ['week' => '2025-09-15', 'segment_type' => 21, 'currency' => 'RON', 'amount' => 10000, 'receipts' => 3],
                ['week' => '2025-09-15', 'segment_type' => 157, 'currency' => 'EUR', 'amount' => 400, 'receipts' => 1],
                ['week' => '2025-09-22', 'segment_type' => null, 'currency' => 'RON', 'amount' => 500, 'receipts' => 1],
            ],
            'costs' => [['week' => '2025-09-22', 'category' => 'hotel', 'currency' => 'EUR', 'cost' => 100, 'items' => 2]],
            'tickets' => [],
            'bookings' => 5,
        ]);
    });
}

function lineValues(CashFlowSnapshot $snapshot, string $code): array
{
    foreach ($snapshot->payload['lines'] as $line) {
        if ($line['code'] === $code) {
            return array_map(fn ($v) => is_numeric($v) ? (float) $v : $v, $line['values']);
        }
    }

    throw new RuntimeException("No line {$code}");
}

test('the snapshot puts every source on its week in lei', function () {
    mockOmc();
    mockEtrip();

    $signed = CharterContract::factory()->create(['season' => 'S26', 'status' => 'signed', 'days_before_flight' => 10]);
    CharterFlight::factory()->for($signed, 'contract')->create(['flight_date' => '2026-10-15', 'net_value' => 1000, 'taxes' => 100]);
    $draft = CharterContract::factory()->draft()->create(['deposit_percent' => 50, 'deposit_due_date' => '2026-10-05', 'contract_value' => 20000]);
    CharterFlight::factory()->for($draft, 'contract')->create(['flight_date' => '2026-12-01', 'net_value' => 2000, 'taxes' => 0]);

    $snapshot = app(CashFlowReportBuilder::class)->build('Bogdan');

    expect($snapshot->status)->toBe('ok')
        ->and($snapshot->built_by)->toBe('Bogdan')
        ->and($snapshot->payload['week_start'])->toBe('2026-09-14')
        ->and($snapshot->payload['weeks'])->toHaveCount(52)
        ->and($snapshot->payload['fx'])->toEqual(['RON' => 1, 'EUR' => 5, 'USD' => 4.5])
        ->and(collect($snapshot->sources)->pluck('status', 'key')->all())->toBe([
            'fx' => 'ok', 'opening' => 'ok', 'receivables' => 'ok', 'payables' => 'ok', 'charter' => 'ok',
            'suppliers_open' => 'ok', 'opex' => 'ok', 'new_sales' => 'ok', 'actuals' => 'ok',
        ]);

    // Opening: OMC month-end position at 31.08 rolled with September's documents (see mockOmc()).
    expect($snapshot->payload['opening']['total'])->toEqual(6665000)
        ->and($snapshot->payload['opening']['by_currency'])->toEqual(['RON' => 6215000, 'EUR' => 90000, 'USD' => 0])
        ->and(lineValues($snapshot, 'A')[0])->toBe(6665000.0);

    // Receivables: the EUR package's open tranches on their weeks, the overdue Sphinx booking recovered 80 % over 4 weeks.
    $b1 = lineValues($snapshot, 'B1');
    expect($b1[1])->toBe(1000.0)->and($b1[3])->toBe(2500.0)->and(array_sum($b1))->toBe(3500.0)
        ->and(array_sum(lineValues($snapshot, 'B4')))->toBe(0.0)
        ->and(array_slice(lineValues($snapshot, 'B8'), 0, 5))->toBe([200.0, 200.0, 200.0, 200.0, 0.0])
        ->and($snapshot->payload['kpis']['overdue_recent'])->toEqual(['RON' => 1000]);

    // New sales per segment: last year's receipts of the package (RON), Sphinx (EUR × 5) and unsegmented bookings, 52 weeks later; B10 subtotals them.
    $lines = collect($snapshot->payload['lines'])->keyBy('code');
    expect(lineValues($snapshot, 'B10.1')[0])->toBe(10000.0)
        ->and(lineValues($snapshot, 'B10.4')[0])->toBe(2000.0)
        ->and(lineValues($snapshot, 'B10.7')[1])->toBe(500.0)
        ->and(array_slice(lineValues($snapshot, 'B10'), 0, 2))->toBe([12000.0, 500.0])
        ->and($lines['B10'])->toMatchArray(['kind' => 'subtotal', 'scenario' => true, 'total' => 12500])
        ->and($lines['B10.1'])->toMatchArray(['parent' => 'B10', 'scenario' => true, 'label' => 'Vânzări noi – Pachete charter/sejur'])
        ->and(lineValues($snapshot, 'B')[1])->toBe(1700.0)
        ->and($snapshot->payload['kpis']['scenario_receipts'])->toEqual(12500)
        ->and($snapshot->payload['structure']['new_sales_receipts'])->toContainEqual(['segment' => 'pachete', 'label' => 'Pachete charter/sejur', 'currency' => 'RON', 'amount' => 10000, 'lei' => 10000, 'receipts' => 3])
        ->and($snapshot->payload['structure']['new_sales_receipts'])->toContainEqual(['segment' => 'sphinx', 'label' => 'Sphinx', 'currency' => 'EUR', 'amount' => 400, 'lei' => 2000, 'receipts' => 1])
        ->and($snapshot->payload['structure']['new_sales_costs'])->toContainEqual(['category' => 'hotel', 'label' => 'Cazare', 'currency' => 'EUR', 'amount' => 100, 'lei' => 500, 'items' => 2]);

    // Payables from eTrip, charter contracts, open supplier invoices and the scenario.
    expect(lineValues($snapshot, 'C1')[2])->toBe(5000.0)
        ->and(lineValues($snapshot, 'C4')[0])->toBe(700.0)
        ->and(lineValues($snapshot, 'C6')[3])->toBe(5000.0)
        ->and(lineValues($snapshot, 'C7')[9])->toBe(5000.0)
        ->and(lineValues($snapshot, 'C8')[3])->toBe(50000.0)
        ->and(lineValues($snapshot, 'C9')[7])->toBe(500.0)
        ->and(array_slice(lineValues($snapshot, 'C10'), 0, 6))->toBe([200000.0, 200000.0, 0.0, 0.0, 0.0, 5000.0])
        ->and(lineValues($snapshot, 'C11')[1])->toBe(500.0);

    // The S26 programme shifted 52 weeks estimates S27: the 15.10.2026 rotation pays on 05.10.2027 → column 55 is beyond the horizon, so nothing lands.
    expect(array_sum(lineValues($snapshot, 'C12')))->toBe(0.0);

    // OPEX: rent from the OMC average, uniform; net salaries on the 5th of each month still ahead.
    $opex = collect($snapshot->payload['opex'])->keyBy('key');
    expect($opex['chirii']['monthly'])->toEqual(100000)
        ->and($opex['chirii']['computed'])->toEqual(100000)
        ->and($opex['servicii']['monthly'])->toEqual(20000)
        ->and($opex['salarii_nete']['monthly'])->toEqual(500000)
        ->and($opex['salarii_nete']['computed'])->toEqual(500000)
        ->and($opex['impozit_profit']['computed'])->toEqual(300000)
        ->and($opex['banci']['computed'])->toEqual(12000)
        ->and($opex['avans_salarii']['monthly'])->toEqual(597000);
    $rent = collect($snapshot->payload['lines'])->firstWhere('key', 'chirii');
    $salaries = collect($snapshot->payload['lines'])->firstWhere('key', 'salarii_nete');
    expect(round($rent['values'][0], 2))->toEqual(round(100000 * 12 / 52, 2))
        ->and($salaries['values'][0])->toEqual(0)
        ->and($salaries['values'][3])->toEqual(500000)
        ->and($salaries['values'][7])->toEqual(500000);

    // Balances chain week after week and the year-earlier actuals sit on the same week.
    $net = lineValues($snapshot, 'E1');
    $closing = lineValues($snapshot, 'E2');
    expect($closing[0])->toBe(round(6665000 + $net[0], 2))
        ->and($closing[1])->toBe(round($closing[0] + $net[1], 2))
        ->and(lineValues($snapshot, 'E5')[0])->toBe('OK')
        ->and(lineValues($snapshot, 'F1')[0])->toBe(300000.0)
        ->and(lineValues($snapshot, 'F2')[0])->toBe(100000.0)
        ->and(lineValues($snapshot, 'F3')[0])->toBe(20000.0)
        ->and(lineValues($snapshot, 'F4')[0])->toBe(180000.0)
        ->and($snapshot->payload['lastyear'][0])->toMatchArray(['week' => '2026-09-14', 'ly_week' => '2025-09-15', 'ly_in' => 300000, 'ly_out' => 120000, 'ly_out_partener' => 100000, 'ly_out_salarii' => 20000, 'ly_out_alte' => 0, 'ly_in_alte' => 0])
        // Week 15–21.09.2025 sits between the 31.08 (5.0M) and 30.09 (5.551M) anchors: 5.0M + 180k net + share of the 371k residual.
        ->and($snapshot->payload['lastyear'][0]['ly_bal'])->toBeGreaterThan(5000000)
        ->and($snapshot->payload['lastyear'][0]['ly_bal'])->toBeLessThan(5551000)
        ->and($snapshot->payload['lastyear'][0]['ly_bal_open'])->toBeGreaterThanOrEqual(5000000)
        // Recent weeks: 13 before S+1. Nothing moved in the current week, so 07.09 ends at today's
        // position; the week before ends 150,000 lower (the +200,000 / −50,000 of 10–11.09).
        ->and($snapshot->payload['recent'])->toHaveCount(13)
        ->and($snapshot->payload['recent'][12])->toMatchArray(['week' => '2026-09-07', 'in' => 200000, 'out' => 50000, 'balance' => 6665000])
        ->and($snapshot->payload['recent'][11]['balance'])->toEqual(6665000 - 150000)
        ->and($snapshot->payload['recent'][0]['week'])->toBe('2026-06-15')
        ->and($snapshot->payload['charter'][0])->toMatchArray(['season' => 'S26', 'status' => 'signed', 'flights' => 1])
        ->and($snapshot->payload['charter'][0]['in_horizon'])->toEqual(1000);
});

test('without a base season every signed season estimates its next edition until that one is contracted', function () {
    CashFlowSetting::query()->where('key', CashFlowSetting::PARAMETERS)->update(['value' => [
        'fx' => ['mode' => 'manual', 'EUR' => 5, 'USD' => 4.5],
        'scenario' => ['enabled' => true, 'factor' => 1, 'charter_factor' => 1, 'charter_base_season' => null, 'charter_target_season' => null],
    ]]);
    mockOmc();
    mockEtrip();

    // Flown and paid in spring 2026 (pay 22.03.2026, taxes 05.05.2026): only its S27 echo, 364 days later, is ahead.
    $signed = CharterContract::factory()->create(['season' => 'S26', 'status' => 'signed', 'days_before_flight' => 10]);
    CharterFlight::factory()->for($signed, 'contract')->create(['flight_date' => '2026-04-01', 'net_value' => 1000, 'taxes' => 100]);
    CharterContract::factory()->draft()->create();

    $snapshot = app(CashFlowReportBuilder::class)->build();
    $lines = collect($snapshot->payload['lines'])->keyBy('code');

    expect(array_sum(lineValues($snapshot, 'C6')))->toBe(0.0)
        ->and(lineValues($snapshot, 'C12')[26])->toBe(5000.0)
        ->and(lineValues($snapshot, 'C13')[33])->toBe(500.0)
        ->and($lines['C12']['label'])->toBe('Charter S27 estimat – rotații (programul S26 decalat un an × factor)')
        ->and(collect($snapshot->sources)->firstWhere('key', 'charter')['message'])->toContain('Sezonul S27 este estimat din programul S26');

    // Once S27 has a contract of its own, the echo stops.
    CharterContract::factory()->create(['season' => 'S27', 'name' => 'CTR S27']);

    $snapshot = app(CashFlowReportBuilder::class)->build();

    expect(array_sum(lineValues($snapshot, 'C12')))->toBe(0.0)
        ->and(collect($snapshot->sources)->firstWhere('key', 'charter')['message'])->toContain('Sezonul S27 este contractat');
});

test('a season label rolls to its next edition', function (string $season, ?string $next) {
    expect(CashFlowReportBuilder::nextSeason($season))->toBe($next);
})->with([
    ['S26', 'S27'],
    ['W26-27', 'W27-28'],
    ['S2026', 'S2027'],
    ['vara', null],
]);

test('a source that fails leaves a partial snapshot with the others filled', function () {
    mockOmc();
    mockEtrip(failBookings: true);

    $snapshot = app(CashFlowReportBuilder::class)->build();

    expect($snapshot->status)->toBe('partial')
        ->and($snapshot->error)->toContain('connection refused')
        ->and(collect($snapshot->sources)->firstWhere('key', 'receivables'))->toMatchArray(['status' => 'error', 'message' => 'connection refused'])
        ->and(array_sum(lineValues($snapshot, 'B1')))->toBe(0.0)
        ->and(lineValues($snapshot, 'C1')[2])->toBe(5000.0)
        ->and(collect($snapshot->sources)->firstWhere('key', 'charter')['status'])->toBe('skipped');
});

test('the position comes from the OMC month-end balances rolled forward', function () {
    CashFlowSetting::query()->where('key', CashFlowSetting::PARAMETERS)->update(['value' => ['fx' => ['mode' => 'manual', 'EUR' => 5, 'USD' => 4.5], 'scenario' => ['enabled' => false]]]);
    mockOmc();
    mockEtrip();

    $snapshot = app(CashFlowReportBuilder::class)->build();
    $opening = $snapshot->payload['opening'];
    $rows = collect($opening['rows'])->keyBy('key');

    // RON: 1,000,000 + 200,000 − 300,000 banks, 10,000 + 5,000 cash, 5,000,000 + 300,000 deposits; EUR: 90,000 × 5.
    expect($snapshot->status)->toBe('ok')
        ->and($opening['date'])->toBe('2026-08-31')
        ->and($opening['currencies'])->toBe(['RON', 'EUR', 'USD'])
        ->and($rows['bank_now']['values']['RON'])->toEqual(900000)
        ->and($rows['cash_now']['values']['RON'])->toEqual(15000)
        ->and($rows['deposits_now']['values']['RON'])->toEqual(5300000)
        ->and($rows['position']['values']['RON'])->toEqual(6215000)
        ->and($rows['position']['values']['EUR'])->toEqual(90000)
        ->and($opening['total'])->toEqual(6665000)
        ->and(lineValues($snapshot, 'A')[0])->toBe(6665000.0)
        ->and(collect($snapshot->sources)->firstWhere('key', 'opening')['message'])->toContain('31.08.2026');
});

test('without month-end balances in OMC the balance starts at zero and says so', function () {
    CashFlowSetting::query()->where('key', CashFlowSetting::PARAMETERS)->update(['value' => ['fx' => ['mode' => 'manual'], 'scenario' => ['enabled' => false]]]);
    mockOmc(anchor: false);
    mockEtrip();

    $snapshot = app(CashFlowReportBuilder::class)->build();

    expect($snapshot->status)->toBe('ok')
        ->and(collect($snapshot->sources)->firstWhere('key', 'opening'))->toMatchArray(['status' => 'skipped'])
        ->and(collect($snapshot->sources)->firstWhere('key', 'new_sales')['status'])->toBe('skipped')
        ->and(lineValues($snapshot, 'A')[0])->toBe(0.0)
        ->and(array_sum(lineValues($snapshot, 'B10')))->toBe(0.0)
        ->and(lineValues($snapshot, 'E5')[0])->toBe('DEFICIT');
});

test('old snapshots are pruned', function () {
    config(['cashflow.keep_snapshots' => 2]);
    mockOmc();
    mockEtrip();
    CashFlowSnapshot::factory()->count(3)->sequence(fn ($sequence) => ['built_at' => '2026-09-1'.$sequence->index.' 04:30:00'])->create();

    app(CashFlowReportBuilder::class)->build();

    expect(CashFlowSnapshot::query()->count())->toBe(2)
        ->and(CashFlowSnapshot::latest()->built_by)->toBeNull();
});
