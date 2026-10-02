<?php

use App\Services\Reports\TinaPnlReader;

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

test('the department gives the channel and the service gives the product', function (string $department, string $category, string $channel, string $product) use ($map, $services) {
    expect(app(TinaPnlReader::class)->place($department, $category, $map, $services, 'corporate', 'Altele'))
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
]);
