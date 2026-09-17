<?php

use App\Services\CashFlow\BookingSegments;

test('the segment follows the product type of the most expensive root item', function (?int $type, string $segment) {
    expect(BookingSegments::of(['segment_type' => $type]))->toBe($segment);
})->with([
    'package' => [21, 'pachete'],
    'charter package' => [45, 'pachete'],
    'circuit' => [39, 'circuite'],
    'exotic' => [32, 'exotic'],
    'sphinx' => [157, 'sphinx'],
    'hotel' => [7, 'cazare'],
    'line ticket' => [26, 'bilete'],
    'charter seat' => [30, 'bilete'],
    'transfer' => [5, 'altele'],
    'nothing confirmed' => [null, 'altele'],
]);

test('the channel follows the distribution channel of the booking', function () {
    expect(BookingSegments::channel(['channel' => 2]))->toBe('B2B')
        ->and(BookingSegments::channel(['channel' => 49]))->toBe('B2B')
        ->and(BookingSegments::channel(['channel' => 5]))->toBe('B2C')
        ->and(BookingSegments::channel(['channel' => null]))->toBe('B2C');
});
