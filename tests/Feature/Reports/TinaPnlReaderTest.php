<?php

use App\Services\Reports\TinaPnlReader;
use Illuminate\Support\Facades\DB;

/**
 * Departamentul comenzii dă canalul — „Corporate” e canal de vânzare, alături
 * de B2B și Retail — iar serviciul dă produsul: un bilet rămâne Ticketing, o
 * cazare rămâne Cazare, oricine ar fi cumpărat.
 */
$map = [
    'Corporate' => ['channel' => 'corporate'],
    'Ticketing' => ['channel' => 'corporate'],
    'Hotels' => ['channel' => 'corporate'],
    'B2B Sales' => ['channel' => 'b2b'],
    'B2C Sales' => ['channel' => 'retail'],
];

$services = ['airTransport' => 'Ticketing', 'accommodation' => 'Cazare', 'default' => 'Altele'];

$codes = ['h' => 'Cazare', 'k' => 'Ticketing', 'Pc' => 'Pachete'];

test('the department gives the channel and the service gives the product', function (string $department, string $category, string $channel, string $product, string $code = '') use ($map, $services, $codes) {
    expect(app(TinaPnlReader::class)->place($department, $category, $map, $services, 'corporate', 'Altele', $code, $codes))
        ->toBe([$channel, $product]);
})->with([
    // Un bilet e Ticketing și când îl cumpără o firmă: Corporate e canalul.
    'corporate ticket' => ['Corporate', 'airTransport', 'corporate', 'Ticketing'],
    'corporate hotel' => ['Corporate', 'accommodation', 'corporate', 'Cazare'],
    'ticketing desk' => ['Ticketing', 'airTransport', 'corporate', 'Ticketing'],
    'hotels desk' => ['Hotels', 'accommodation', 'corporate', 'Cazare'],
    'b2b ticket' => ['B2B Sales', 'airTransport', 'b2b', 'Ticketing'],
    'b2b hotel' => ['B2B Sales', 'accommodation', 'b2b', 'Cazare'],
    'b2c event' => ['B2C Sales', 'others', 'retail', 'Altele'],
    'no department' => ['', 'accommodation', 'corporate', 'Cazare'],
    'nothing at all' => ['', '', 'corporate', 'Altele'],
    // O treime din servicii n-au categorie, dar au cod: „h” e hotel.
    'hotel without a category' => ['Corporate', '', 'corporate', 'Cazare', 'h'],
    'package without a category' => ['Corporate', '', 'corporate', 'Pachete', 'Pc'],
    'category beats the code' => ['Corporate', 'airTransport', 'corporate', 'Ticketing', 'h'],
]);

test('a month that has not started yet has no revenue in it', function () {
    $captured = [];

    $connection = Mockery::mock();
    $connection->shouldReceive('select')->andReturnUsing(function (string $sql, array $bindings) use (&$captured) {
        $captured = ['sql' => $sql, 'bindings' => $bindings];

        return [];
    });

    DB::shouldReceive('connection')->andReturn($connection);

    app(TinaPnlReader::class)->byChannelAndProduct(2026);

    // Serviciul nefacturat se socotește în luna în care se ține; una care n-a
    // început încă n-are ce arăta, deci viitorul se taie la ziua de azi.
    expect($captured['bindings'])->toBe(['2026-01-01', '2027-01-01', now()->toDateString()])
        ->and($captured['sql'])->toContain('coalesce(ci.invoiceDate, s.startDate) <= ?');
});
