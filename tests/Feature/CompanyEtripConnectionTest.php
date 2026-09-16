<?php

use App\Models\Company;
use App\Models\User;
use App\Services\DatabaseStatusService;
use App\Services\Omc\OmcReader;
use App\Services\RemoteConnection;

beforeEach(function () {
    $this->user = User::factory()->create();
});

/**
 * @return array<string, string>
 */
function companyPayload(array $overrides = []): array
{
    return [
        'name' => 'Christian Tour',
        'cui' => '9617078',
        'db_driver' => 'pgsql',
        'db_host' => '10.0.0.5',
        'db_port' => '5432',
        'db_database' => 'christian_76_tour',
        'db_username' => 'reporting',
        'db_password' => 'secret',
        ...$overrides,
    ];
}

test('a company can be linked to an etrip database', function () {
    $this->actingAs($this->user)
        ->post('/companies', companyPayload(['etrip_connection' => 'etrip_chr']))
        ->assertRedirect();

    expect(Company::where('name', 'Christian Tour')->first()->etrip_connection)->toBe('etrip_chr');
});

test('an unknown etrip connection is rejected', function () {
    $this->actingAs($this->user)
        ->post('/companies', companyPayload(['etrip_connection' => 'etrip_nope']))
        ->assertSessionHasErrors('etrip_connection');
});

test('leaving the etrip link empty stores no connection', function () {
    $company = Company::factory()->create(['etrip_connection' => 'etrip_vcz']);

    $this->actingAs($this->user)
        ->put("/companies/{$company->id}", companyPayload(['etrip_connection' => '', 'db_password' => '']))
        ->assertRedirect();

    expect($company->fresh()->etrip_connection)->toBeNull()
        ->and($company->fresh()->etripConnection())->toBeNull();
});

test('the edit page exposes the available etrip connections', function () {
    $company = Company::factory()->create(['etrip_connection' => 'etrip_chr']);

    $this->actingAs($this->user)
        ->get("/companies/{$company->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('company.etrip_connection', 'etrip_chr')
            ->where('etripConnections.etrip_chr', 'eTrip Christian Tour')
            ->where('etripConnections.etrip_vcz', 'eTrip Vacanza')
        );
});

test('a company can be linked to the omc connection', function () {
    $this->actingAs($this->user)
        ->post('/companies', companyPayload(['erp_connection' => 'omc']))
        ->assertRedirect();

    expect(Company::where('name', 'Christian Tour')->first()->erp_connection)->toBe('omc');
});

test('an unknown erp connection is rejected', function () {
    $this->actingAs($this->user)
        ->post('/companies', companyPayload(['erp_connection' => 'saga']))
        ->assertSessionHasErrors('erp_connection');
});

test('a linked company is read through the named connection, the others through their credentials', function () {
    $linked = Company::factory()->create(['erp_connection' => 'omc']);
    $own = Company::factory()->create();

    $remote = app(RemoteConnection::class);

    expect($remote->name($linked))->toBe('omc')
        ->and($remote->connection($linked)->getName())->toBe('omc')
        ->and($remote->name($own))->toBe("company_{$own->id}")
        ->and($remote->connection($own)->getName())->toBe("company_{$own->id}");
});

test('the status page probes the named connection for a linked company', function () {
    config()->set('database.connections.omc.host', '127.0.0.1');
    config()->set('database.connections.omc.port', '1');
    config()->set('database.connections.omc.database', 'christian_76_tour');

    $company = Company::factory()->create(['name' => 'Christian Tour', 'erp_connection' => 'omc', 'db_host' => '10.9.9.9']);

    $status = collect(app(DatabaseStatusService::class)->statuses())->firstWhere('name', "company_{$company->id}");

    expect($status['label'])->toBe('Christian Tour · OMC Christian Tour')
        ->and($status['host'])->toBe('127.0.0.1')
        ->and($status['database'])->toBe('christian_76_tour')
        ->and($status['connected'])->toBeFalse();
});

test('the omc company is the one linked to the connection', function () {
    Company::factory()->create(['etrip_connection' => 'etrip_chr']);
    $linked = Company::factory()->create(['erp_connection' => 'omc']);

    expect(app(OmcReader::class)->company()?->id)->toBe($linked->id);
});
