<?php

use App\Services\CashFlow\ReceivablesScheduler;

function bookingRow(array $overrides = []): array
{
    return [
        'id' => 1,
        'currency' => 'EUR',
        'total_due' => 1000,
        'paid' => 0,
        'balance_due_date' => '2026-10-05',
        'start_date' => '2026-10-20',
        'due_dates' => [],
        ...$overrides,
    ];
}

test('advances come first and the balance goes on the balance due date', function () {
    $tranches = (new ReceivablesScheduler)->tranches(bookingRow([
        'due_dates' => [['date' => '2026-09-25', 'amount' => 200], ['date' => '2026-09-01', 'amount' => 300]],
    ]));

    expect($tranches)->toBe([
        ['booking' => 1, 'date' => '2026-09-01', 'amount' => 300.0, 'currency' => 'EUR', 'type' => 'avans'],
        ['booking' => 1, 'date' => '2026-09-25', 'amount' => 200.0, 'currency' => 'EUR', 'type' => 'avans'],
        ['booking' => 1, 'date' => '2026-10-05', 'amount' => 500.0, 'currency' => 'EUR', 'type' => 'sold'],
    ]);
});

test('what the client paid is applied to the earliest tranches', function () {
    $tranches = (new ReceivablesScheduler)->tranches(bookingRow([
        'paid' => 350,
        'due_dates' => [['date' => '2026-09-01', 'amount' => 300], ['date' => '2026-09-25', 'amount' => 200]],
    ]));

    expect($tranches)->toBe([
        ['booking' => 1, 'date' => '2026-09-25', 'amount' => 150.0, 'currency' => 'EUR', 'type' => 'avans'],
        ['booking' => 1, 'date' => '2026-10-05', 'amount' => 500.0, 'currency' => 'EUR', 'type' => 'sold'],
    ]);
});

test('a booking without a schedule is due seven days before departure', function () {
    $tranches = (new ReceivablesScheduler)->tranches(bookingRow(['balance_due_date' => null]));

    expect($tranches)->toBe([
        ['booking' => 1, 'date' => '2026-10-13', 'amount' => 1000.0, 'currency' => 'EUR', 'type' => 'sold'],
    ]);
});

test('a paid booking and advances above the total produce nothing extra', function () {
    $scheduler = new ReceivablesScheduler;

    expect($scheduler->tranches(bookingRow(['paid' => 1000])))->toBe([])
        ->and($scheduler->tranches(bookingRow([
            'due_dates' => [['date' => '2026-09-01', 'amount' => 800], ['date' => '2026-09-25', 'amount' => 800]],
        ])))->toBe([
            ['booking' => 1, 'date' => '2026-09-01', 'amount' => 800.0, 'currency' => 'EUR', 'type' => 'avans'],
            ['booking' => 1, 'date' => '2026-09-25', 'amount' => 200.0, 'currency' => 'EUR', 'type' => 'avans'],
        ]);
});
