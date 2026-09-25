<?php

use App\Services\Etrip\EtripReader;
use App\Services\Reports\PnlBranchMap;
use Illuminate\Database\ConnectionInterface;
use Mockery\MockInterface;

/**
 * @param  list<string>  $names
 */
function fakeEtripBranchNames(array $names): PnlBranchMap
{
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('statement')->andReturnTrue();
    $connection->shouldReceive('select')->andReturn(array_map(fn (string $name) => (object) ['name' => $name], $names));

    test()->mock(EtripReader::class, fn (MockInterface $mock) => $mock->shouldReceive('connection')->andReturn($connection));

    return app(PnlBranchMap::class);
}

test('activities that are not shops are not charged to retail', function () {
    $map = fakeEtripBranchNames(['Sun Plaza', 'Curs ghid', 'Corporate', 'Ticketing', 'abuela.ro']);

    // Retailul înseamnă magazine proprii; cursul de ghid nu e un magazin, iar
    // cheltuiala lui n-are ce căuta în rândul lor.
    expect($map->channel('Sun Plaza'))->toBe('retail')
        ->and($map->channel('Curs ghid'))->toBe('other')
        ->and($map->channel('Corporate'))->toBe('other')
        ->and($map->channel('Ticketing'))->toBe('other')
        ->and($map->channel('abuela.ro'))->toBe('other');
});

test('the work point is matched whole, not by fragments', function () {
    $map = fakeEtripBranchNames(['Corporate', 'Sun Plaza']);

    // „Corporate Events” nu e „Corporate”: o potrivire pe bucăți ar muta
    // cheltuieli dintr-un canal în altul fără să se vadă.
    expect($map->channel('Corporate Events'))->toBeNull()
        ->and($map->channel('CORPORATE'))->toBe('other');
});

test('the branches that sell for partners are charged to B2B', function () {
    $map = fakeEtripBranchNames(['Rezervari Web', 'Rezervari XML', 'Sun Plaza']);

    // Aceleași reguli ca la venit: rezervările web și XML sunt ale agențiilor
    // partenere, deci și cheltuiala punctului lor de lucru.
    expect($map->channel('Rezervari Web'))->toBe('b2b')
        ->and($map->channel('Rezervari XML'))->toBe('b2b');
});

test('a franchise work point stays with the franchise', function () {
    $map = fakeEtripBranchNames(['Franciza Brasov']);

    expect($map->channel('Franciza Brasov'))->toBe('franciza');
});
