<?php

use App\Models\Company;
use App\Models\EtripSupplier;
use App\Models\Partner;
use App\Services\Etrip\EtripReader;
use App\Services\Etrip\EtripSupplierSyncService;
use Mockery\MockInterface;

/**
 * @param  list<array<string, mixed>>  $rows
 */
function fakeEtripSuppliers(array $rows): void
{
    test()->mock(EtripReader::class, function (MockInterface $mock) use ($rows) {
        $mock->shouldReceive('suppliers')->andReturn(array_map(fn (array $row) => [
            'code' => $row['code'],
            'name' => $row['name'],
            'vat_no' => $row['vat_no'] ?? null,
            'company_no' => null,
            'currency' => $row['currency'] ?? 'EUR',
            'country' => null,
            'active' => $row['active'] ?? true,
        ], $rows));
    });
}

beforeEach(function () {
    $this->company = Company::factory()->create(['etrip_connection' => 'etrip_chr']);
});

test('suppliers are mirrored and matched to partners by vat number, then by name', function () {
    $byCui = Partner::factory()->for($this->company)->create(['name' => 'RIDA INTERNATIONAL SRL', 'cui' => 'RO12345678']);
    $byName = Partner::factory()->for($this->company)->create(['name' => 'Memento Turkiye', 'cui' => '99999999']);
    Partner::factory()->for($this->company)->create(['name' => 'Unrelated', 'cui' => '11111111']);

    fakeEtripSuppliers([
        ['code' => '10', 'name' => 'Rida International', 'vat_no' => 'RO 12345678'],
        ['code' => '20', 'name' => 'MEMENTO  TURKIYE ', 'vat_no' => null],
        ['code' => '30', 'name' => 'Nobody Knows', 'vat_no' => 'RO777', 'active' => false],
    ]);

    $result = app(EtripSupplierSyncService::class)->sync($this->company);

    expect($result)->toBe(['synced' => 3, 'matched_cui' => 1, 'matched_name' => 1, 'unmatched' => 1])
        ->and(EtripSupplier::where('code', '10')->first())->toMatchArray(['partner_id' => $byCui->id, 'match_source' => 'cui', 'name' => 'Rida International'])
        ->and(EtripSupplier::where('code', '20')->first())->toMatchArray(['partner_id' => $byName->id, 'match_source' => 'name'])
        ->and(EtripSupplier::where('code', '30')->first())->toMatchArray(['partner_id' => null, 'is_active' => false]);
});

test('a second sync updates supplier details without touching manual links', function () {
    $partner = Partner::factory()->for($this->company)->create(['cui' => '55555555']);
    $other = Partner::factory()->for($this->company)->create(['name' => 'Rida International', 'cui' => '66666666']);
    EtripSupplier::factory()->for($this->company)->create([
        'code' => '10', 'name' => 'Old name', 'partner_id' => $partner->id, 'match_source' => 'manual',
    ]);

    fakeEtripSuppliers([['code' => '10', 'name' => 'Rida International', 'vat_no' => 'RO66666666', 'currency' => 'USD']]);

    app(EtripSupplierSyncService::class)->sync($this->company);

    expect(EtripSupplier::where('code', '10')->first())
        ->toMatchArray(['name' => 'Rida International', 'currency' => 'USD', 'partner_id' => $partner->id, 'match_source' => 'manual'])
        ->and(EtripSupplier::where('partner_id', $other->id)->exists())->toBeFalse();
});

test('a partner is never linked to two etrip suppliers', function () {
    $partner = Partner::factory()->for($this->company)->create(['name' => 'Hotel Sunny', 'cui' => 'RO4242']);

    fakeEtripSuppliers([
        ['code' => '1', 'name' => 'Hotel Sunny', 'vat_no' => 'RO4242'],
        ['code' => '2', 'name' => 'Hotel Sunny', 'vat_no' => null],
    ]);

    app(EtripSupplierSyncService::class)->sync($this->company);

    expect(EtripSupplier::where('partner_id', $partner->id)->pluck('code')->all())->toBe(['1']);
});

test('the artisan command syncs every company linked to etrip', function () {
    Company::factory()->create(['etrip_connection' => null]);

    fakeEtripSuppliers([['code' => '10', 'name' => 'Rida International', 'vat_no' => null]]);

    $this->artisan('etrip:sync-suppliers')
        ->expectsTable(
            ['Companie', 'eTrip', 'Furnizori', 'Potriviți CUI', 'Potriviți nume', 'Nepotriviți'],
            [[$this->company->name, 'etrip_chr', 1, 0, 0, 1]],
        )
        ->assertSuccessful();
});
