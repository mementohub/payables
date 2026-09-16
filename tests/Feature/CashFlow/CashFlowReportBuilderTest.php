<?php

use App\Models\CashFlowSetting;
use App\Models\CashFlowSnapshot;
use App\Models\CharterContract;
use App\Models\CharterFlight;
use App\Services\CashFlow\CashFlowReportBuilder;
use App\Services\CashFlow\EtripCashFlowReader;
use App\Services\CashFlow\OmcCashFlowReader;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;

beforeEach(function () {
    Carbon::setTestNow('2026-09-16 10:00:00');

    CashFlowSetting::query()->create(['key' => CashFlowSetting::PARAMETERS, 'value' => [
        'fx' => ['mode' => 'manual', 'EUR' => 5, 'USD' => 4.5],
        'thresholds' => ['minimum' => 1000000, 'comfort' => 2000000],
        'scenario' => ['enabled' => true, 'factor' => 1, 'charter_factor' => 1, 'charter_base_season' => 'S26', 'charter_target_season' => 'S27'],
        'opening' => [
            'date' => '2026-08-31',
            'bank' => ['RON' => 1000000, 'EUR' => 100000, 'USD' => 0],
            'cash' => ['RON' => 10000, 'EUR' => 0, 'USD' => 0],
            'deposits' => ['RON' => 5000000, 'EUR' => 0, 'USD' => 0],
        ],
    ]]);
});

function omcFlows(): array
{
    return [
        ['day' => '2025-09-16', 'kind' => 'in', 'currency' => 'RON', 'amount' => 300000, 'lei' => 300000],
        ['day' => '2025-09-18', 'kind' => 'out', 'currency' => 'RON', 'amount' => 120000, 'lei' => 120000],
        ['day' => '2026-09-10', 'kind' => 'in', 'currency' => 'RON', 'amount' => 200000, 'lei' => 200000],
        ['day' => '2026-09-11', 'kind' => 'out', 'currency' => 'EUR', 'amount' => 10000, 'lei' => 50000],
    ];
}

function mockOmc(): void
{
    test()->mock(OmcCashFlowReader::class, function (MockInterface $mock) {
        $mock->shouldReceive('dailyFlows')->andReturnUsing(fn (CarbonInterface $from, CarbonInterface $to) => array_values(array_filter(
            omcFlows(),
            fn (array $row) => $row['day'] >= $from->toDateString() && $row['day'] < $to->toDateString(),
        )));
        $mock->shouldReceive('openSupplierInvoices')->andReturn([
            ['due' => '2026-09-01', 'currency' => 'RON', 'amount' => 400000, 'lei' => 400000, 'invoices' => 3],
            ['due' => '2026-10-20', 'currency' => 'EUR', 'amount' => 1000, 'lei' => 5000, 'invoices' => 1],
        ]);
        $mock->shouldReceive('monthlyAverageByAccount')->andReturn(['612' => 100000, '623' => 50000, '628.01' => 20000, '401' => 999]);
    });
}

function mockEtrip(bool $failBookings = false): void
{
    test()->mock(EtripCashFlowReader::class, function (MockInterface $mock) use ($failBookings) {
        $bookings = $mock->shouldReceive('openBookings')->with('etrip_chr', Mockery::type(CarbonInterface::class));

        if ($failBookings) {
            $bookings->andThrow(new RuntimeException('connection refused'));
        } else {
            $bookings->andReturn([
                ['id' => 1, 'currency' => 'EUR', 'total_due' => 1000, 'paid' => 300, 'balance_due_date' => '2026-10-05', 'start_date' => '2026-10-20', 'brand' => 1, 'channel' => 1, 'client_type' => 'direct', 'root_types' => [21], 'continent' => 'Europa', 'due_dates' => [['date' => '2026-09-01', 'amount' => 300], ['date' => '2026-09-25', 'amount' => 200]]],
                ['id' => 2, 'currency' => 'RON', 'total_due' => 1000, 'paid' => 0, 'balance_due_date' => '2026-09-01', 'start_date' => '2026-09-30', 'brand' => 1, 'channel' => 16, 'client_type' => 'trade', 'root_types' => [7], 'continent' => 'Europa', 'due_dates' => []],
            ]);
        }

        $mock->shouldReceive('payables')->andReturn([
            ['week' => '2026-09-28', 'category' => 'hotel', 'currency' => 'EUR', 'cost' => 1000, 'items' => 2, 'bookings' => 1],
        ]);
        $mock->shouldReceive('ticketsOrdered')->andReturn([
            ['week' => '2026-09-14', 'currency' => 'RON', 'cost' => 700, 'items' => 1],
        ]);
        $mock->shouldReceive('bookingCurve')->andReturn([
            'receipts' => [['week' => '2025-09-15', 'currency' => 'RON', 'amount' => 10000]],
            'costs' => [['week' => '2025-09-22', 'category' => 'hotel', 'currency' => 'EUR', 'cost' => 100]],
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

    // Opening: parameters at 31.08 rolled with September's OMC documents.
    expect($snapshot->payload['opening']['total'])->toEqual(6660000)
        ->and($snapshot->payload['opening']['by_currency'])->toEqual(['RON' => 6210000, 'EUR' => 90000, 'USD' => 0])
        ->and(lineValues($snapshot, 'A')[0])->toBe(6660000.0);

    // Receivables: the EUR package's open tranches on their weeks, the overdue Sphinx booking recovered 80 % over 4 weeks.
    $b1 = lineValues($snapshot, 'B1');
    expect($b1[1])->toBe(1000.0)->and($b1[3])->toBe(2500.0)->and(array_sum($b1))->toBe(3500.0)
        ->and(array_sum(lineValues($snapshot, 'B4')))->toBe(0.0)
        ->and(array_slice(lineValues($snapshot, 'B8'), 0, 5))->toBe([200.0, 200.0, 200.0, 200.0, 0.0])
        ->and(lineValues($snapshot, 'B10')[0])->toBe(10000.0)
        ->and($snapshot->payload['kpis']['overdue_recent'])->toEqual(['RON' => 1000]);

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
        ->and($opex['salarii_nete']['monthly'])->toEqual(574000);
    $rent = collect($snapshot->payload['lines'])->firstWhere('key', 'chirii');
    $salaries = collect($snapshot->payload['lines'])->firstWhere('key', 'salarii_nete');
    expect(round($rent['values'][0], 2))->toEqual(round(100000 * 12 / 52, 2))
        ->and($salaries['values'][0])->toEqual(0)
        ->and($salaries['values'][3])->toEqual(574000)
        ->and($salaries['values'][7])->toEqual(574000);

    // Balances chain week after week and the year-earlier actuals sit on the same week.
    $net = lineValues($snapshot, 'E1');
    $closing = lineValues($snapshot, 'E2');
    expect($closing[0])->toBe(round(6660000 + $net[0], 2))
        ->and($closing[1])->toBe(round($closing[0] + $net[1], 2))
        ->and(lineValues($snapshot, 'E5')[0])->toBe('OK')
        ->and(lineValues($snapshot, 'F1')[0])->toBe(300000.0)
        ->and(lineValues($snapshot, 'F2')[0])->toBe(120000.0)
        ->and($snapshot->payload['lastyear'][0])->toEqual(['week' => '2026-09-14', 'ly_week' => '2025-09-15', 'ly_in' => 300000, 'ly_out' => 120000, 'ly_bal' => $snapshot->payload['lastyear'][0]['ly_bal'], 'ly_bal_open' => $snapshot->payload['lastyear'][0]['ly_bal_open']])
        ->and($snapshot->payload['lastyear'][0]['ly_bal'])->toBeNumeric()
        ->and($snapshot->payload['charter'][0])->toMatchArray(['season' => 'S26', 'status' => 'signed', 'flights' => 1])
        ->and($snapshot->payload['charter'][0]['in_horizon'])->toEqual(1000);
});

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

test('without an opening date the balance starts at zero and says so', function () {
    CashFlowSetting::query()->where('key', CashFlowSetting::PARAMETERS)->update(['value' => ['fx' => ['mode' => 'manual'], 'scenario' => ['enabled' => false]]]);
    mockOmc();
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
