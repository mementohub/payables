<?php

use App\Models\Company;
use App\Models\EtripSupplier;
use App\Services\Etrip\CheckinCostCheckService;
use App\Services\Etrip\EtripReader;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;

/**
 * @return list<array<string, mixed>>
 */
function checkinRows(): array
{
    $hotel = fn (int $booking, int $item, string $start, float $cost, string $lead) => [
        'booking' => $booking, 'item' => $item, 'product_type' => 7, 'start_date' => $start, 'end_date' => '2026-09-23',
        'currency' => 'EUR', 'cost' => $cost, 'hotel' => 'Rida International', 'room' => 'Doubla', 'meal' => 'BB',
        'transfer' => null, 'description' => 'Rida', 'pax' => 2, 'lead' => $lead,
    ];

    return [
        $hotel(1, 10, '2026-09-16', 1000, 'Popescu Ion'),
        ['booking' => 1, 'item' => 11, 'product_type' => 5, 'start_date' => '2026-09-16', 'end_date' => '2026-09-16',
            'currency' => 'EUR', 'cost' => 40, 'hotel' => null, 'room' => null, 'meal' => null,
            'transfer' => 'Transfer Agadir', 'description' => 'Transfer', 'pax' => 2, 'lead' => 'Popescu Ion'],
        $hotel(2, 20, '2026-09-17', 2000, 'Ionescu Maria'),
        ['booking' => 3, 'item' => 30, 'product_type' => 26, 'start_date' => '2026-09-17', 'end_date' => null,
            'currency' => 'USD', 'cost' => 500, 'hotel' => null, 'room' => null, 'meal' => null,
            'transfer' => null, 'description' => null, 'pax' => 1, 'lead' => 'Smith John'],
    ];
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-16 10:00:00');

    $this->company = Company::factory()->create(['etrip_connection' => 'etrip_chr']);
    $this->supplier = EtripSupplier::factory()->for($this->company)->create(['code' => '10', 'name' => 'Rida International', 'currency' => 'EUR']);

    $this->mock(EtripReader::class, function (MockInterface $mock) {
        $mock->shouldReceive('productTypes')->andReturn([7 => 'Hotel allotment', 5 => 'Transfer', 26 => 'Flight international']);
        $mock->shouldReceive('costLines')
            ->withArgs(fn (Company $company, string $code) => $company->is($this->company) && $code === '10')
            ->andReturn(checkinRows());
        $mock->shouldReceive('ronPerUnit')->andReturnUsing(fn (Company $company, string $currency) => match (strtoupper($currency)) {
            'EUR' => 5.0,
            'USD' => 4.6,
            'RON' => 1.0,
            default => null,
        });
    });
});

function checkin(string $category = 'hotel', ?float $amount = null, ?string $currency = null): array
{
    return app(CheckinCostCheckService::class)->check(
        test()->company,
        test()->supplier,
        Carbon::parse('2026-09-16'),
        Carbon::parse('2026-09-17'),
        $category,
        $amount,
        $currency,
    );
}

test('accommodation totals are grouped by currency, hotel, day and product type', function () {
    $result = checkin('hotel');

    expect($result['totals'])->toBe([['currency' => 'EUR', 'cost' => 3000.0, 'items' => 2, 'bookings' => 2]])
        ->and($result['items'])->toBe(2)
        ->and($result['bookings'])->toBe(2)
        ->and($result['by_hotel'])->toBe([['name' => 'Rida International', 'currency' => 'EUR', 'cost' => 3000.0, 'bookings' => 2]])
        ->and($result['by_day'])->toBe([
            ['date' => '2026-09-16', 'currency' => 'EUR', 'cost' => 1000.0, 'bookings' => 1],
            ['date' => '2026-09-17', 'currency' => 'EUR', 'cost' => 2000.0, 'bookings' => 1],
        ])
        ->and($result['by_product'][0])->toMatchArray(['product_type' => 7, 'label' => 'Hotel allotment', 'cost' => 3000.0])
        ->and($result['lines'][0])->toMatchArray(['booking' => 1, 'lead' => 'Popescu Ion', 'nights' => 7, 'room' => 'Doubla', 'meal' => 'BB', 'service' => 'Rida International', 'category' => 'hotel'])
        ->and($result['requested'])->toBeNull();
});

test('the category filter picks transfers, other services or everything', function () {
    expect(checkin('transfer')['totals'])->toBe([['currency' => 'EUR', 'cost' => 40.0, 'items' => 1, 'bookings' => 1]])
        ->and(checkin('transfer')['by_hotel'][0]['name'])->toBe('Transfer Agadir (transfer)')
        ->and(checkin('other')['totals'])->toBe([['currency' => 'USD', 'cost' => 500.0, 'items' => 1, 'bookings' => 1]])
        ->and(checkin('other')['lines'][0]['service'])->toBe('Flight international')
        ->and(collect(checkin('all')['totals'])->pluck('cost', 'currency')->all())->toBe(['EUR' => 3040.0, 'USD' => 500.0])
        ->and(checkin('all')['bookings'])->toBe(3);
});

test('a request within half a percent of the etrip cost matches', function () {
    $requested = checkin('hotel', 3000, 'EUR')['requested'];

    expect($requested)->toMatchArray(['etrip' => 3000.0, 'diff' => 0.0, 'diff_pct' => 0.0, 'level' => 'ok', 'compared_currency' => 'EUR', 'rate' => null])
        ->and($requested['message'])->toContain('0,5%');
});

test('a small difference is flagged and a large one rejected', function () {
    expect(checkin('hotel', 3050, 'EUR')['requested'])->toMatchArray(['diff' => 50.0, 'diff_pct' => 1.67, 'level' => 'warn'])
        ->and(checkin('hotel', 3200, 'EUR')['requested'])->toMatchArray(['diff' => 200.0, 'diff_pct' => 6.67, 'level' => 'crit']);
});

test('a request in another currency is converted through bnr rates before comparing', function () {
    $requested = checkin('hotel', 3300, 'USD')['requested'];

    expect($requested)->toMatchArray([
        'amount' => 3300.0,
        'currency' => 'USD',
        'compared_amount' => 3036.0,
        'compared_currency' => 'EUR',
        'diff' => 36.0,
        'diff_pct' => 1.2,
        'level' => 'warn',
    ])
        ->and($requested['rate'])->toMatchArray(['from' => 'USD', 'to' => 'EUR', 'value' => 0.92, 'date' => '2026-09-16']);
});

test('a request in a currency without a bnr rate cannot be compared', function () {
    $requested = checkin('hotel', 100, 'CHF')['requested'];

    expect($requested['compared_amount'])->toBeNull()
        ->and($requested['diff'])->toBeNull()
        ->and($requested['level'])->toBe('warn')
        ->and($requested['message'])->toContain('nu există curs');
});

test('a request compared in the etrip currency after conversion can still be a significant difference', function () {
    $requested = checkin('other', 500, 'EUR')['requested'];

    expect($requested['compared_currency'])->toBe('USD')
        ->and($requested['compared_amount'])->toBe(543.48)
        ->and($requested['diff_pct'])->toBe(8.7)
        ->and($requested['level'])->toBe('crit')
        ->and($requested['rate']['from'])->toBe('EUR');
});
