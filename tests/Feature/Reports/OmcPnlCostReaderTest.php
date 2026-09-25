<?php

use App\Models\Company;
use App\Services\RemoteConnection;
use App\Services\Reports\OmcPnlCostReader;
use Illuminate\Database\ConnectionInterface;
use Mockery\MockInterface;

/**
 * Registrul OMC e o replică de producție: aici se poate verifica ce pleacă
 * spre el, nu ce vine înapoi.
 *
 * @return list<string>
 */
function capturePnlCostQueries(callable $call): array
{
    $queries = [];

    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('select')->andReturnUsing(function (string $sql) use (&$queries) {
        $queries[] = $sql;

        return [];
    });

    test()->mock(RemoteConnection::class, fn (MockInterface $mock) => $mock->shouldReceive('connection')->andReturn($connection));

    $call(app(OmcPnlCostReader::class), Company::factory()->create());

    return $queries;
}

test('the period follows the accounting note, not the invoice date', function () {
    // O factură plătită în avans stă pe 471 și se trece pe cheltuială în tranșe
    // lunare. Pe data facturii, toate tranșele ar cădea în luna ei, iar lunile
    // următoare ar rămâne goale — în 2026, peste 5,6 milioane de lei.
    $queries = capturePnlCostQueries(function (OmcPnlCostReader $reader, Company $company) {
        $reader->costs($company, 2026);
        $reader->details($company, 2026, [1, 2]);
        $reader->belowEbitda($company, 2026);
        $reader->revenueAndCogs($company, 2026);
    });

    expect($queries)->toHaveCount(4);

    foreach ($queries as $sql) {
        expect($sql)->toContain('j.data_reg_jurnal >= ?::date')
            ->and($sql)->not->toContain('WHERE j.data_doc >=');
    }
});

test('the document keeps being identified by its own date', function () {
    // Perioada e a notei contabile, dar factura rămâne factura: pe data ei se
    // prind corecturile și pe ea se deschide detaliul.
    $queries = capturePnlCostQueries(fn (OmcPnlCostReader $reader, Company $company) => $reader->details($company, 2026, [3]));

    expect($queries[0])->toContain('j.data_doc::date AS data_doc')
        ->and($queries[0])->toContain('EXTRACT(MONTH FROM j.data_reg_jurnal)::int AS month')
        // Contrapartida spune de ce o factură intră în bucăți: 471, avans.
        ->and($queries[0])->toContain("COALESCE(BTRIM(j.conts_cr), '') AS counterpart");
});
