<?php

use App\Models\CashFlowDetail;
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

function mockOmc(bool $anchor = true, ?array $positionRates = null, array $advances = []): void
{
    $positionRates ??= ['RON' => 1.0, 'EUR' => 5.0, 'USD' => 4.5];

    test()->mock(OmcCashFlowReader::class, function (MockInterface $mock) use ($anchor, $positionRates, $advances) {
        $mock->shouldReceive('dailyFlows')->andReturnUsing(fn (CarbonInterface $from, CarbonInterface $to) => array_values(array_filter(
            omcFlows(),
            fn (array $row) => $row['day'] >= $from->toDateString() && $row['day'] < $to->toDateString(),
        )));
        $mock->shouldReceive('openSupplierInvoiceList')->andReturn([
            ['data_doc' => '2026-08-01', 'tip_doc' => 'FactFI', 'nr_doc' => 'A1', 'partner' => 'Hotel Alfa', 'due' => '2026-09-01', 'currency' => 'RON', 'amount' => 250000, 'lei' => 250000],
            ['data_doc' => '2026-08-03', 'tip_doc' => 'FactFI', 'nr_doc' => 'B7', 'partner' => 'Hotel Beta', 'due' => '2026-09-01', 'currency' => 'RON', 'amount' => 150000, 'lei' => 150000],
            ['data_doc' => '2026-09-10', 'tip_doc' => 'FactFE', 'nr_doc' => 'INV-9', 'partner' => 'Tour Gamma', 'due' => '2026-10-20', 'currency' => 'EUR', 'amount' => 1000, 'lei' => 5000],
        ]);
        $mock->shouldReceive('supplierAdvances')->andReturn($advances['ledger'] ?? [])->byDefault();
        $mock->shouldReceive('unmatchedSupplierPayments')->andReturn($advances['unmatched'] ?? [])->byDefault();
        $mock->shouldReceive('monthlyAverageByAccount')->andReturn(['612' => 100000, '623' => 50000, '628.01' => 20000, '401' => 999]);
        $mock->shouldReceive('monthlyLedgerByAccount')->andReturn(['421' => 500000, '425' => 597000, '4411' => 100000, '627' => 10000, '6651' => 2000]);
        $mock->shouldReceive('monthEndAnchor')->andReturn($anchor ? CarbonImmutable::parse('2026-08-31') : null);
        $mock->shouldReceive('balanceAnchors')->andReturn($anchor
            ? ['as_of' => CarbonImmutable::parse('2026-09-15'), 'bank' => CarbonImmutable::parse('2026-08-31'), 'cash' => CarbonImmutable::parse('2026-08-31'), 'deposits' => CarbonImmutable::parse('2026-08-31'), 'fallback' => false]
            : ['as_of' => CarbonImmutable::parse('2026-09-15'), 'bank' => null, 'cash' => null, 'deposits' => null, 'fallback' => false]);
        $mock->shouldReceive('ratesAt')->andReturn($positionRates);
        $mock->shouldReceive('monthEndPositions')->andReturn([
            ['date' => '2025-08-31', 'bank' => ['RON' => 1000000], 'cash' => ['RON' => 0], 'deposits' => ['RON' => 4000000], 'rates' => []],
            ['date' => '2025-09-30', 'bank' => ['RON' => 1500000, 'EUR' => 10000], 'cash' => [], 'deposits' => ['RON' => 4000000], 'rates' => ['EUR' => 5.1]],
            ['date' => '2026-08-31', 'bank' => ['RON' => 1000000, 'EUR' => 100000], 'cash' => ['RON' => 10000], 'deposits' => ['RON' => 5000000], 'rates' => ['EUR' => 5.05]],
        ]);
        // The same non-internal documents as omcFlows(), per week, partner and counterpart account.
        $mock->shouldReceive('weeklyFlowsByPartner')->andReturn([
            ['week' => '2025-09-15', 'kind' => 'in', 'partner' => 'Client Unu SRL', 'coresp' => '4111', 'lei' => 300000],
            ['week' => '2025-09-15', 'kind' => 'out', 'partner' => 'Hotel Parad S.R.L.', 'coresp' => '401', 'lei' => 100000],
            ['week' => '2025-09-15', 'kind' => 'out', 'partner' => null, 'coresp' => '421', 'lei' => 20000],
            ['week' => '2026-09-07', 'kind' => 'in', 'partner' => 'Client Unu SRL', 'coresp' => '4111', 'lei' => 150000],
            ['week' => '2026-09-07', 'kind' => 'in', 'partner' => 'ANIMA WINGS AVIATION SA', 'coresp' => '4111', 'lei' => 50000],
            ['week' => '2026-09-07', 'kind' => 'out', 'partner' => 'MEMENTO INTERNATIONAL SRL', 'coresp' => '401', 'lei' => 30000],
            ['week' => '2026-09-07', 'kind' => 'out', 'partner' => 'Agentia Media SRL', 'coresp' => '401', 'lei' => 15000],
            ['week' => '2026-09-07', 'kind' => 'out', 'partner' => 'Cineva Necunoscut SRL', 'coresp' => '401', 'lei' => 5000],
        ]);
        $mock->shouldReceive('partnerMainAccounts')->andReturn(['Agentia Media SRL' => '6231']);
        $mock->shouldReceive('openingPosition')->with(Mockery::type('array'), Mockery::type(CarbonInterface::class))->andReturn([
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
        $mock->shouldReceive('receiptsByWeek')->andReturnUsing(fn (string $name) => $name === 'etrip_chr' ? [
            ['week' => '2025-09-15', 'segment_type' => 21, 'lei' => 250000, 'receipts' => 3],
            ['week' => '2026-09-07', 'segment_type' => 39, 'lei' => 120000, 'receipts' => 2],
        ] : []);
        $mock->shouldReceive('supplierCategories')->andReturnUsing(fn (string $name) => $name === 'etrip_chr' ? [
            ['code' => 'HPARAD', 'name' => 'Hotel Parad', 'vat_no' => null, 'category' => 'hotel'],
        ] : []);
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
    $draft = CharterContract::factory()->draft()->create(['deposit_percent' => 50, 'deposit_due_date' => '2026-10-05', 'contract_value' => 3000]);
    CharterFlight::factory()->for($draft, 'contract')->create(['flight_date' => '2026-12-01', 'net_value' => 2000, 'taxes' => 0]);

    $snapshot = app(CashFlowReportBuilder::class)->build('Bogdan');

    expect($snapshot->status)->toBe('ok')
        ->and($snapshot->built_by)->toBe('Bogdan')
        ->and($snapshot->payload['week_start'])->toBe('2026-09-14')
        ->and($snapshot->payload['weeks'])->toHaveCount(52)
        ->and($snapshot->payload['fx'])->toEqual(['RON' => 1, 'EUR' => 5, 'USD' => 4.5])
        ->and(collect($snapshot->sources)->pluck('status', 'key')->all())->toBe([
            'fx' => 'ok', 'opening' => 'ok', 'receivables' => 'ok', 'payables' => 'ok', 'charter' => 'ok',
            'suppliers_open' => 'ok', 'advances' => 'ok', 'opex' => 'ok', 'new_sales' => 'ok', 'actuals' => 'ok', 'actual_lines' => 'ok',
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

    // New sales per segment: last year's receipts of the package (RON), Sphinx (EUR × 5) and unsegmented bookings, 52 weeks later; B11 subtotals them.
    $lines = collect($snapshot->payload['lines'])->keyBy('code');
    expect(lineValues($snapshot, 'B11.1')[0])->toBe(10000.0)
        ->and(lineValues($snapshot, 'B11.4')[0])->toBe(2000.0)
        ->and(lineValues($snapshot, 'B11.7')[1])->toBe(500.0)
        ->and(array_slice(lineValues($snapshot, 'B11'), 0, 2))->toBe([12000.0, 500.0])
        ->and($lines['B11'])->toMatchArray(['kind' => 'subtotal', 'scenario' => true, 'total' => 12500])
        ->and($lines['B11.1'])->toMatchArray(['parent' => 'B11', 'scenario' => true, 'label' => 'Vânzări noi – Pachete charter/sejur'])
        ->and(lineValues($snapshot, 'B')[1])->toBe(1700.0)
        ->and($snapshot->payload['kpis']['scenario_receipts'])->toEqual(12500)
        ->and($snapshot->payload['structure']['new_sales_receipts'])->toContainEqual(['segment' => 'pachete', 'label' => 'Pachete charter/sejur', 'currency' => 'RON', 'amount' => 10000, 'lei' => 10000, 'receipts' => 3])
        ->and($snapshot->payload['structure']['new_sales_receipts'])->toContainEqual(['segment' => 'sphinx', 'label' => 'Sphinx', 'currency' => 'EUR', 'amount' => 400, 'lei' => 2000, 'receipts' => 1])
        ->and($snapshot->payload['structure']['new_sales_costs'])->toContainEqual(['category' => 'hotel', 'label' => 'Cazare', 'currency' => 'EUR', 'amount' => 100, 'lei' => 500, 'items' => 2]);

    // Payables from eTrip, charter contracts, open supplier invoices and the scenario.
    expect(lineValues($snapshot, 'C1')[2])->toBe(5000.0)
        ->and(lineValues($snapshot, 'C4')[0])->toBe(700.0)
        ->and(lineValues($snapshot, 'C6')[3])->toBe(5000.0)
        // The draft's 1.500 EUR deposit is due 05.10 and regularised at its last rotation, which pays 2.000 − 1.500.
        ->and(lineValues($snapshot, 'C7')[9])->toBe(2500.0)
        ->and(lineValues($snapshot, 'C8')[3])->toBe(7500.0)
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
        ->and($snapshot->payload['charter'][0]['in_horizon'])->toEqual(1000)
        // The page shows a contract's terms straight from the stored report,
        // so every row has to carry them.
        ->and($snapshot->payload['charter'][0]['terms'])
        ->toHaveKeys(['rotation', 'taxes', 'deposit', 'fx']);
});

test('the past weeks sit on the report lines, add up to OMC and chain into the forecast balance', function () {
    mockOmc();
    mockEtrip();
    CharterContract::factory()->create(['counterparty' => 'Anima Wings Aviation S.A.', 'direction' => 'in']);

    $past = app(CashFlowReportBuilder::class)->build()->payload['past'];
    $column = array_flip($past['weeks']);
    $at = fn (string $code, string $week) => $past['lines'][$code][$column[$week]];

    expect($past['weeks'])->toHaveCount(53)
        ->and($past['weeks'][0])->toBe('2025-09-15')
        ->and($past['weeks'][52])->toBe('2026-09-14')
        // Receipts: eTrip by segment, the charter counterparty on B10, the rest of OMC on BX.
        ->and($at('B1', '2025-09-15'))->toEqual(250000)
        ->and($at('BX', '2025-09-15'))->toEqual(50000)
        ->and($at('B2', '2026-09-07'))->toEqual(120000)
        ->and($at('B10', '2026-09-07'))->toEqual(50000)
        ->and($at('BX', '2026-09-07'))->toEqual(30000)
        ->and($at('B', '2026-09-07'))->toEqual(200000)
        // Payments: eTrip hotel supplier and the configured group company on C1, salaries by
        // their account on the OPEX line, a marketing supplier by its invoices, the rest on CX.
        ->and($at('C1', '2025-09-15'))->toEqual(100000)
        ->and($at('D1', '2025-09-15'))->toEqual(20000)
        ->and($at('C1', '2026-09-07'))->toEqual(30000)
        ->and($at('D6', '2026-09-07'))->toEqual(15000)
        ->and($at('CX', '2026-09-07'))->toEqual(5000)
        ->and($at('E1', '2025-09-15'))->toEqual(180000)
        ->and($at('E1', '2026-09-07'))->toEqual(150000)
        // After the last month-end (31.08: 1M + 100k EUR × 5.05 + 10k + 5M) every week walks on
        // with its own net flow, the current one included, not a week late.
        ->and($at('E2', '2026-08-31'))->toEqual(6515000)
        ->and($at('E2', '2026-09-07'))->toEqual(6665000)
        // The current week ends where the forecast starts: the position at the end of yesterday.
        ->and($at('E2', '2026-09-14'))->toEqual(6665000)
        ->and($at('E6', '2026-09-14'))->toBe('efectiv')
        // Each full past week carries the same week a year before; the current one does not.
        ->and($past['lastyear'])->toHaveCount(53)
        ->and($past['lastyear'][52])->toBeNull()
        ->and($past['lastyear'][51])->toMatchArray(['ly_week' => '2025-09-08', 'ly_in' => 0, 'ly_out' => 0])
        ->and($past['lastyear'][51]['ly_bal'])->not->toBeNull();

    // Each week opens on the last one's close, and what the documents do not
    // explain shows as the adjustment.
    foreach (array_slice($past['weeks'], 1, null, true) as $i => $week) {
        expect($past['lines']['A'][$i])->toEqual($past['lines']['E2'][$i - 1])
            ->and(round($past['lines']['A'][$i] + $past['lines']['E1'][$i] + $past['lines']['EA'][$i], 2))->toEqual($past['lines']['E2'][$i]);
    }
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

test('the position is stated at the end of yesterday, rolled from the last closed balances at the BNR rate of that day', function () {
    CashFlowSetting::query()->where('key', CashFlowSetting::PARAMETERS)->update(['value' => ['fx' => ['mode' => 'manual', 'EUR' => 5, 'USD' => 4.5], 'scenario' => ['enabled' => false]]]);
    mockOmc();
    mockEtrip();

    $snapshot = app(CashFlowReportBuilder::class)->build();
    $opening = $snapshot->payload['opening'];
    $rows = collect($opening['rows'])->keyBy('key');

    // RON: 1,000,000 + 200,000 − 300,000 banks, 10,000 + 5,000 cash, 5,000,000 + 300,000 deposits; EUR: 90,000 × 5.
    expect($snapshot->status)->toBe('ok')
        // Today is 16.09.2026, so the position is the one of the 15th.
        ->and($opening['date'])->toBe('2026-09-15')
        ->and($opening['as_of'])->toBe('2026-09-15')
        ->and($opening['base'])->toBe(['bank' => '2026-08-31', 'cash' => '2026-08-31', 'deposits' => '2026-08-31'])
        ->and($opening['fallback'])->toBeFalse()
        ->and($rows['position']['label'])->toBe('Poziție de trezorerie la 15.09.2026')
        ->and($rows['bank_now']['label'])->toBe('Conturi curente bănci la 15.09.2026')
        ->and($rows['bank_in']['label'])->toBe('Încasări prin bancă până la 15.09.2026')
        ->and($rows['bank_open']['label'])->toBe('Conturi curente bănci – sold contabil de bază (31.08.2026)')
        ->and($rows['deposits_open']['label'])->toBe('Depozite bancare (5081) – sold contabil de bază (31.08.2026)')
        ->and($opening['currencies'])->toBe(['RON', 'EUR', 'USD'])
        ->and($rows['bank_now']['values']['RON'])->toEqual(900000)
        ->and($rows['cash_now']['values']['RON'])->toEqual(15000)
        ->and($rows['deposits_now']['values']['RON'])->toEqual(5300000)
        ->and($rows['position']['values']['RON'])->toEqual(6215000)
        ->and($rows['position']['values']['EUR'])->toEqual(90000)
        ->and($opening['total'])->toEqual(6665000)
        ->and(lineValues($snapshot, 'A')[0])->toBe(6665000.0)
        ->and(collect($snapshot->sources)->firstWhere('key', 'opening')['message'])->toContain('Poziția de trezorerie la 15.09.2026 (sfârșitul zilei de ieri)')
        ->and(collect($snapshot->sources)->firstWhere('key', 'opening')['message'])->toContain('bănci 31.08.2026');
});

test('the position converts at the BNR rate OMC holds for that day, not the forecast rate', function () {
    CashFlowSetting::query()->where('key', CashFlowSetting::PARAMETERS)->update(['value' => ['fx' => ['mode' => 'manual', 'EUR' => 5, 'USD' => 4.5], 'scenario' => ['enabled' => false]]]);
    mockOmc(positionRates: ['RON' => 1.0, 'EUR' => 5.2636]);
    mockEtrip();

    $snapshot = app(CashFlowReportBuilder::class)->build();
    $opening = $snapshot->payload['opening'];

    // RON 6,215,000 plus EUR 90,000 at 5.2636, where the report's own rate is 5.
    expect($opening['rates'])->toEqual(['RON' => 1, 'EUR' => 5.2636])
        ->and($opening['total'])->toEqual(round(6215000 + 90000 * 5.2636, 2))
        ->and(lineValues($snapshot, 'A')[0])->toBe(round(6215000 + 90000 * 5.2636, 2))
        ->and(collect($snapshot->sources)->firstWhere('key', 'opening')['message'])->toContain('cursul BNR din OMC');
});

test('without saved balances in OMC the balance starts at zero and says so', function () {
    CashFlowSetting::query()->where('key', CashFlowSetting::PARAMETERS)->update(['value' => ['fx' => ['mode' => 'manual'], 'scenario' => ['enabled' => false]]]);
    mockOmc(anchor: false);
    mockEtrip();

    $snapshot = app(CashFlowReportBuilder::class)->build();

    expect($snapshot->status)->toBe('ok')
        ->and(collect($snapshot->sources)->firstWhere('key', 'opening'))->toMatchArray(['status' => 'skipped'])
        ->and(collect($snapshot->sources)->firstWhere('key', 'new_sales')['status'])->toBe('skipped')
        ->and(lineValues($snapshot, 'A')[0])->toBe(0.0)
        ->and(array_sum(lineValues($snapshot, 'B11')))->toBe(0.0)
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

test('every charter contract is settled on its own terms', function () {
    CashFlowSetting::query()->where('key', CashFlowSetting::PARAMETERS)->update(['value' => [
        'fx' => ['mode' => 'manual', 'EUR' => 5, 'USD' => 4.5],
        'scenario' => ['enabled' => false, 'charter_base_season' => null, 'charter_target_season' => null, 'charter_factor' => 0],
    ]]);
    mockOmc();
    mockEtrip();

    // Signed, taxes reconciled monthly, and lei at the BNR rate plus the 2 % the contract adds.
    $signed = CharterContract::factory()->create([
        'season' => 'S26', 'status' => 'signed', 'days_before_flight' => 10,
        'taxes_rule' => CharterContract::TAXES_MONTHLY, 'taxes_month_day' => 5, 'fx_markup_pct' => 2,
    ]);
    CharterFlight::factory()->for($signed, 'contract')->create(['flight_date' => '2026-10-15', 'net_value' => 1000, 'taxes' => 100]);

    // The same season, but the taxes fall three days after the flight.
    $afterFlight = CharterContract::factory()->create([
        'name' => 'CTR 281', 'season' => 'W25-26', 'status' => 'signed', 'days_before_flight' => 10,
        'taxes_rule' => CharterContract::TAXES_AFTER, 'taxes_days' => 3, 'fx_markup_pct' => 0,
    ]);
    CharterFlight::factory()->for($afterFlight, 'contract')->create(['flight_date' => '2026-10-15', 'net_value' => 500, 'taxes' => 200]);

    // CHR sells the seats: rotation and taxes come in together, 10 days before the flight,
    // less the 10 % deposit collected at signing, which the last rotation regularises.
    $incoming = CharterContract::factory()->create([
        'name' => 'CTR 1585', 'season' => 'S26', 'status' => 'signed', 'direction' => CharterContract::DIRECTION_IN,
        'days_before_flight' => 10, 'taxes_rule' => CharterContract::TAXES_WITH_ROTATION, 'fx_markup_pct' => 0,
        'deposit_amount' => 412, 'deposit_paid' => true,
    ]);
    CharterFlight::factory()->for($incoming, 'contract')->create(['flight_date' => '2026-10-08', 'net_value' => 2000, 'taxes' => 60]);
    CharterFlight::factory()->for($incoming, 'contract')->create(['flight_date' => '2026-10-15', 'net_value' => 2000, 'taxes' => 60]);

    // Memento Air's own contract with a carrier: terms only, no cash.
    $reference = CharterContract::factory()->create([
        'name' => 'Anima Wings C7', 'season' => 'S26', 'status' => 'signed', 'in_cash_flow' => false, 'days_before_flight' => 7,
    ]);
    CharterFlight::factory()->for($reference, 'contract')->create(['flight_date' => '2026-10-15', 'net_value' => 9999, 'taxes' => 9999]);

    $snapshot = app(CashFlowReportBuilder::class)->build();

    // Weeks from 14.09.2026: 05.10 is week 3, 12–18.10 week 4, 02–08.11 week 7.
    expect(lineValues($snapshot, 'C6')[3])->toBe(1000 * 5 * 1.02 + 500 * 5)
        ->and(lineValues($snapshot, 'C9')[7])->toBe(100 * 5 * 1.02)
        ->and(lineValues($snapshot, 'C9')[4])->toBe(200 * 5.0)
        ->and(lineValues($snapshot, 'B10')[2])->toBe((2000 + 60) * 5.0)
        ->and(lineValues($snapshot, 'B10')[3])->toBe((2000 + 60 - 412) * 5.0)
        ->and(array_sum(lineValues($snapshot, 'C7')))->toBe(0.0)
        ->and(array_sum(lineValues($snapshot, 'C8')))->toBe(0.0);

    // The reference contract stays out of every line but keeps its terms on the summary.
    $contracts = collect($snapshot->payload['charter'])->keyBy('name');
    expect(array_sum(lineValues($snapshot, 'C6')) + array_sum(lineValues($snapshot, 'C9')))
        ->toBe(1000 * 5 * 1.02 + 500 * 5 + 100 * 5 * 1.02 + 200 * 5.0)
        ->and($contracts['Anima Wings C7'])->toMatchArray(['in_cash_flow' => false, 'in_horizon' => 0.0])
        ->and($contracts['Anima Wings C7']['terms']['rotation'])->toBe('OP cu 7 zile înainte de fiecare rotație')
        ->and($contracts['CTR 1585']['terms']['taxes'])->toBe('odată cu rotația')
        ->and($contracts['CTR 281']['terms']['taxes'])->toBe('la 3 zile după zbor')
        ->and($contracts[$signed->name]['terms']['fx'])->toBe('EUR sau RON la BNR + 2%')
        ->and(collect($snapshot->sources)->firstWhere('key', 'charter')['message'])->toContain('3 contracte în flux (din 4)');
});

test('every cell of the snapshot is the sum of the pieces the build kept for it', function () {
    mockOmc();
    mockEtrip();

    $signed = CharterContract::factory()->create(['name' => 'CTR 317', 'season' => 'S26', 'status' => 'signed', 'days_before_flight' => 10]);
    CharterFlight::factory()->for($signed, 'contract')->create(['flight_date' => '2026-10-15', 'net_value' => 1000, 'taxes' => 100, 'flight_no' => 'A2 4212']);
    $draft = CharterContract::factory()->draft()->create(['deposit_percent' => 50, 'deposit_due_date' => '2026-10-05', 'contract_value' => 3000]);
    CharterFlight::factory()->for($draft, 'contract')->create(['flight_date' => '2026-12-01', 'net_value' => 2000, 'taxes' => 0]);

    $snapshot = app(CashFlowReportBuilder::class)->build();
    $pieces = CashFlowDetail::query()->where('cash_flow_snapshot_id', $snapshot->id)->get();
    $sums = $pieces->groupBy(fn (CashFlowDetail $piece) => $piece->line.'|'.(int) $piece->actual.'|'.$piece->week->toDateString())
        ->map(fn ($group) => round($group->sum('lei'), 2));
    $checked = 0;

    foreach ($snapshot->payload['lines'] as $line) {
        if ($line['kind'] !== 'value') {
            continue;
        }

        foreach ($snapshot->payload['weeks'] as $i => $week) {
            expect($sums[$line['code'].'|0|'.$week] ?? 0.0)->toEqualWithDelta((float) $line['values'][$i], 0.05, "{$line['code']} {$week}");
            $checked++;
        }

        foreach ($snapshot->payload['past']['lines'][$line['code']] ?? [] as $i => $value) {
            expect($sums[$line['code'].'|1|'.$snapshot->payload['past']['weeks'][$i]] ?? 0.0)->toEqualWithDelta((float) $value, 0.05, "past {$line['code']}");
            $checked++;
        }
    }

    $invoice = $pieces->first(fn (CashFlowDetail $piece) => $piece->line === 'C10' && $piece->reference === 'A1' && $piece->week->toDateString() === '2026-09-14');
    $rotation = $pieces->firstWhere('kind', 'rotation');

    expect($checked)->toBeGreaterThan(1000)
        ->and($pieces->whereNull('cash_flow_snapshot_id'))->toBeEmpty()
        // Overdue: half of it in each of the first two weeks.
        ->and($invoice->only(['label', 'lei', 'group', 'currency', 'amount']))->toBe(['label' => 'Hotel Alfa', 'lei' => 125000.0, 'group' => 'Restante', 'currency' => 'RON', 'amount' => 250000.0])
        ->and($invoice->meta)->toMatchArray(['tip_doc' => 'FactFI', 'nr_doc' => 'A1', 'days_overdue' => 15])
        ->and($rotation->only(['line', 'label']))->toBe(['line' => 'C6', 'label' => 'CTR 317'])
        ->and($rotation->reference)->toStartWith('A2 4212')
        ->and($pieces->where('line', 'B1')->where('actual', false)->pluck('reference')->unique()->values()->all())->toBe(['1'])
        ->and($pieces->where('line', 'C1')->first()->meta)->toMatchArray(['category' => 'hotel', 'checkin_from' => '2026-10-05', 'checkin_to' => '2026-10-11']);
});

test('a source that fails leaves no pieces behind, and only the latest snapshots keep theirs', function () {
    config(['cashflow.keep_details' => 1]);
    mockOmc();
    mockEtrip(failBookings: true);

    $first = app(CashFlowReportBuilder::class)->build();
    $second = app(CashFlowReportBuilder::class)->build();

    expect(CashFlowDetail::query()->where('cash_flow_snapshot_id', $second->id)->whereIn('line', ['B1', 'B2', 'B3', 'B4', 'B5', 'B6', 'B7', 'B8', 'B9'])->where('actual', false)->count())->toBe(0)
        ->and(CashFlowDetail::query()->where('cash_flow_snapshot_id', $second->id)->count())->toBeGreaterThan(0)
        ->and(CashFlowDetail::query()->where('cash_flow_snapshot_id', $first->id)->count())->toBe(0);
});

test('money already paid to a supplier is not paid again: unmatched payments settle its invoices, the 409 advance its contract', function () {
    mockOmc(advances: [
        // 100.000 lei paid to Hotel Alfa with no invoice: most of its open 250.000 lei invoice is settled.
        'unmatched' => [['partner' => 'HOTEL ALFA S.R.L.', 'data_doc' => '2026-08-20', 'tip_doc' => 'OP_PL', 'nr_doc' => 'BT 1', 'currency' => 'RON', 'amount' => 100000, 'lei' => 100000]],
        // A 1.500 EUR deposit booked on 409 for the charter counterparty.
        'ledger' => [['partner' => 'Memento Air Srl', 'currency' => 'EUR', 'amount' => 1500, 'lei' => 7500, 'last' => '2026-08-14']],
    ]);
    mockEtrip();

    $contract = CharterContract::factory()->draft()->create(['name' => 'W26/27', 'counterparty' => 'Memento Air S.R.L.', 'deposit_percent' => 50, 'deposit_due_date' => '2026-10-05', 'contract_value' => 2000, 'currency' => 'EUR']);
    CharterFlight::factory()->for($contract, 'contract')->create(['flight_date' => '2026-12-01', 'net_value' => 2000, 'taxes' => 0]);

    $snapshot = app(CashFlowReportBuilder::class)->build();
    $advances = collect($snapshot->payload['advances'])->keyBy('partner');
    $pieces = CashFlowDetail::query()->where('cash_flow_snapshot_id', $snapshot->id)->where('kind', 'advance')->get();

    // C10: Hotel Alfa's 250.000 lei invoice is half in each of the first two weeks; 100.000 lei come off the first ones.
    expect(lineValues($snapshot, 'C10')[0])->toEqualWithDelta(200000 - 100000, 0.01)
        // C8: the 1.000 EUR deposit (50 %) is covered by the 409 advance, the rest (500 EUR) goes to the next rotation.
        ->and(array_sum(lineValues($snapshot, 'C8')))->toEqualWithDelta(0, 0.01)
        ->and($advances['HOTEL ALFA S.R.L.'])->toMatchArray(['unmatched_lei' => 100000.0, 'applied_lei' => 100000.0, 'left_lei' => 0.0])
        ->and($advances['Memento Air Srl'])->toMatchArray(['advance_lei' => 7500.0, 'applied_lei' => 7500.0, 'left_lei' => 0.0])
        ->and($advances['Memento Air Srl']['applied'])->toEqual(['C8' => 5000.0, 'C7' => 2500.0])
        ->and($pieces->sum('lei'))->toEqualWithDelta(-107500, 0.01)
        ->and($pieces->firstWhere('line', 'C8')->meta)->toMatchArray(['basis' => '409', 'partner' => 'Memento Air Srl']);

    expect(collect($snapshot->sources)->firstWhere('key', 'advances')['message'])->toContain('107.500');
});

test('a paid deposit the contract already sets against its last rotations is not taken off a second time', function () {
    mockOmc(advances: ['ledger' => [['partner' => 'Anima Wings Aviation SA', 'currency' => 'EUR', 'amount' => 1000, 'lei' => 5000, 'last' => '2026-05-01']]]);
    mockEtrip();

    $contract = CharterContract::factory()->create(['counterparty' => 'Anima Wings Aviation S.A.', 'status' => 'signed', 'direction' => 'out', 'deposit_amount' => 1000, 'deposit_paid' => true, 'deposit_due_date' => '2026-03-01', 'currency' => 'EUR', 'days_before_flight' => 10]);
    CharterFlight::factory()->for($contract, 'contract')->create(['flight_date' => '2026-11-10', 'net_value' => 3000, 'taxes' => 0]);

    $snapshot = app(CashFlowReportBuilder::class)->build();

    // The deposit covers 1.000 of the 3.000 EUR rotation: 2.000 EUR still paid, nothing more taken off.
    expect(array_sum(lineValues($snapshot, 'C6')))->toEqualWithDelta(10000, 0.01)
        ->and(CashFlowDetail::query()->where('cash_flow_snapshot_id', $snapshot->id)->where('kind', 'advance')->count())->toBe(0)
        ->and(collect($snapshot->payload['advances'])->firstWhere('partner', 'Anima Wings Aviation SA'))->toBeNull();
});
