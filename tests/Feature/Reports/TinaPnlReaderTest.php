<?php

use App\Services\Reports\TinaPnlReader;

/**
 * Categoria de produs vine din departamentul care răspunde de comandă, așa cum
 * îl știe Tina. Departamentele de vânzare (B2B, B2C) spun doar canalul, iar ce
 * s-a vândut se ia atunci din serviciu: un bilet rămâne Ticketing, o cazare
 * rămâne Cazare.
 */
$map = [
    'Corporate' => ['product' => 'Corporate', 'channel' => 'corporate'],
    'Ticketing' => ['product' => 'Ticketing', 'channel' => 'corporate'],
    'Hotels' => ['product' => 'Cazare', 'channel' => 'corporate'],
    'B2B Sales' => ['product' => null, 'channel' => 'b2b'],
    'B2C Sales' => ['product' => null, 'channel' => 'retail'],
];

$services = ['airTransport' => 'Ticketing', 'accommodation' => 'Cazare', 'default' => 'Corporate'];

test('the responsible department decides where the money lands', function (string $department, string $category, string $channel, string $product) use ($map, $services) {
    expect(app(TinaPnlReader::class)->place($department, $category, $map, $services, 'corporate', 'Corporate'))
        ->toBe([$channel, $product]);
})->with([
    'corporate ticket' => ['Corporate', 'airTransport', 'corporate', 'Corporate'],
    'ticketing desk' => ['Ticketing', 'airTransport', 'corporate', 'Ticketing'],
    'hotels desk' => ['Hotels', 'accommodation', 'corporate', 'Cazare'],
    // Vânzarea spune canalul, serviciul spune produsul.
    'b2b ticket' => ['B2B Sales', 'airTransport', 'b2b', 'Ticketing'],
    'b2b hotel' => ['B2B Sales', 'accommodation', 'b2b', 'Cazare'],
    'b2c event' => ['B2C Sales', 'others', 'retail', 'Corporate'],
    // Fără departament, comanda e a corporate-ului, iar serviciul spune ce e.
    'no department' => ['', 'accommodation', 'corporate', 'Cazare'],
    'nothing at all' => ['', '', 'corporate', 'Corporate'],
]);
