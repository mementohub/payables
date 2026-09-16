<?php

use App\Services\CashFlow\BookingSegments;

test('bookings are classified by channel, products and destination', function (array $booking, string $segment) {
    expect(BookingSegments::of($booking))->toBe($segment);
})->with([
    'sphinx channel' => [['channel' => 16, 'root_types' => [21], 'continent' => 'Europa'], 'sphinx'],
    'circuit' => [['channel' => 1, 'root_types' => [39], 'continent' => 'Europa'], 'circuite'],
    'line tickets only' => [['channel' => 1, 'root_types' => [26, 3], 'continent' => null], 'bilete'],
    'package to Asia' => [['channel' => 1, 'root_types' => [21], 'continent' => 'Asia'], 'exotic'],
    'hotel only' => [['channel' => 5, 'root_types' => [7], 'continent' => 'Europa'], 'cazare'],
    'charter package' => [['channel' => 1, 'root_types' => [21, 5], 'continent' => 'Europa'], 'pachete'],
    'transfer only' => [['channel' => 1, 'root_types' => [5], 'continent' => 'Europa'], 'altele'],
    'nothing confirmed' => [['channel' => null, 'root_types' => [], 'continent' => null], 'altele'],
]);

test('trade and business clients are B2B', function () {
    expect(BookingSegments::channel('trade'))->toBe('B2B')
        ->and(BookingSegments::channel('business'))->toBe('B2B')
        ->and(BookingSegments::channel('direct'))->toBe('B2C');
});
