<?php

use App\Models\Company;
use App\Models\User;

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
