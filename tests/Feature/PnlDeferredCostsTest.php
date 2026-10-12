<?php

use App\Services\Reports\PnlReportService;

test('the two views split the deferrals differently, but close on the same total', function () {
    // Două cheltuieli de combustibil și o notă care mută una din ele în
    // cheltuieli în avans, plus o tranșă întoarsă din avans.
    $costs = [
        ['account' => '6022', 'sediu' => '', 'partner' => '', 'note' => 'combustibil', 'month' => 8, 'trip_share' => 0, 'kind' => 'consum', 'lei' => 400000.0],
        ['account' => '6022', 'sediu' => '', 'partner' => '', 'note' => 'combustibil', 'month' => 8, 'trip_share' => 0, 'kind' => 'in_avans', 'lei' => -1600000.0],
        ['account' => '6022', 'sediu' => '', 'partner' => '', 'note' => 'combustibil', 'month' => 8, 'trip_share' => 0, 'kind' => 'din_avans', 'lei' => 250000.0],
    ];

    $service = app(PnlReportService::class);
    $allocate = (new ReflectionClass($service))->getMethod('allocate');

    [$together] = $allocate->invokeArgs($service, [$costs, [], [], [], [], [], [], false]);
    [$apart] = $allocate->invokeArgs($service, [$costs, [], [], [], [], [], [], true]);

    $total = fn (array $lines) => round(array_sum(array_map(
        fn (array $line) => array_sum(array_column($line['months'], 'total')),
        $lines,
    )), 2);

    // Liniile se întorc ca listă, nu cu codul drept cheie.
    $line = function (array $lines, string $saf) {
        foreach ($lines as $one) {
            if ((string) $one['saf'] === $saf) {
                return round(array_sum(array_column($one['months'], 'total')), 2);
            }
        }

        return null;
    };

    // Financiar: totul stă în linia cheltuielii, ca în registru.
    expect($line($together, '5010'))->toBe(-950000.0)
        ->and($line($together, PnlReportService::LINE_PARKED))->toBeNull();

    // Operațional: linia spune cât s-a consumat, mutările stau deoparte.
    expect($line($apart, '5010'))->toBe(400000.0)
        ->and($line($apart, PnlReportService::LINE_PARKED))->toBe(-1600000.0)
        ->and($line($apart, PnlReportService::LINE_RELEASED))->toBe(250000.0);

    // Și, mai presus de toate: aceeași sumă în amândouă.
    expect($total($apart))->toBe($total($together))
        ->and($total($together))->toBe(-950000.0);
});

test('a cost that moved nowhere stays where it was', function () {
    $costs = [
        ['account' => '6022', 'sediu' => '', 'partner' => '', 'note' => 'combustibil', 'month' => 3, 'trip_share' => 0, 'kind' => 'consum', 'lei' => 12345.0],
    ];

    $service = app(PnlReportService::class);
    $allocate = (new ReflectionClass($service))->getMethod('allocate');
    [$apart] = $allocate->invokeArgs($service, [$costs, [], [], [], [], [], [], true]);

    expect($apart)->toHaveCount(1)
        ->and((string) $apart[0]['saf'])->toBe('5010')
        ->and(round($apart[0]['months'][3]['total'], 2))->toBe(12345.0);
});
