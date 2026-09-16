<?php

use App\Models\Company;
use App\Models\EtripSupplier;
use App\Models\Partner;
use App\Models\User;
use App\Services\Etrip\EtripReader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;

beforeEach(function () {
    Carbon::setTestNow('2026-09-16 10:00:00');
    Cache::flush();

    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['name' => 'Christian Tour', 'etrip_connection' => 'etrip_chr']);
    Company::factory()->create(['name' => 'Fara eTrip', 'etrip_connection' => null]);
});

test('guests are redirected to the login page', function () {
    $this->get('/payment-checks')->assertRedirect('/login');
});

test('the page lists only companies linked to etrip and default filters', function () {
    $this->actingAs($this->user)
        ->get('/payment-checks')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('payment-checks/index')
            ->has('companies', 1)
            ->where('companies.0.name', 'Christian Tour')
            ->where('companies.0.etrip', 'eTrip Christian Tour')
            ->where('filters.company_id', $this->company->id)
            ->where('filters.from', '2026-09-16')
            ->where('filters.to', '2026-09-17')
            ->where('filters.category', 'hotel')
            ->has('categories', 4)
            ->where('windows', [2, 7, 14])
        );
});

test('the check endpoint validates its input', function () {
    $this->actingAs($this->user)
        ->getJson('/payment-checks/check?company_id='.$this->company->id.'&from=2026-09-16&to=2026-09-10')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['supplier', 'to']);
});

test('the check endpoint rejects a company without etrip', function () {
    $company = Company::factory()->create(['etrip_connection' => null]);

    $this->actingAs($this->user)
        ->getJson('/payment-checks/check?company_id='.$company->id.'&supplier=10&from=2026-09-16&to=2026-09-17')
        ->assertUnprocessable();
});

test('the check endpoint returns the etrip comparison as json', function () {
    EtripSupplier::factory()->for($this->company)->create(['code' => '10', 'name' => 'Rida International']);

    $this->mock(EtripReader::class, function (MockInterface $mock) {
        $mock->shouldReceive('productTypes')->andReturn([7 => 'Hotel allotment']);
        $mock->shouldReceive('costLines')
            ->withArgs(fn (Company $company, string $code, Carbon $from, Carbon $to) => $code === '10'
                && $from->toDateString() === '2026-09-16' && $to->toDateString() === '2026-09-17')
            ->once()
            ->andReturn([[
                'booking' => 1, 'item' => 10, 'product_type' => 7, 'start_date' => '2026-09-16', 'end_date' => '2026-09-20',
                'currency' => 'EUR', 'cost' => 1500, 'hotel' => 'Rida', 'room' => 'Dbl', 'meal' => 'AI',
                'transfer' => null, 'description' => null, 'pax' => 2, 'lead' => 'Popescu Ion',
            ]]);
    });

    $this->actingAs($this->user)
        ->getJson('/payment-checks/check?company_id='.$this->company->id.'&supplier=10&from=2026-09-16&to=2026-09-17&amount=1500&currency=EUR')
        ->assertOk()
        ->assertJsonPath('supplier.name', 'Rida International')
        ->assertJsonPath('totals.0.cost', 1500)
        ->assertJsonPath('requested.level', 'ok')
        ->assertJsonPath('lines.0.nights', 4);
});

test('an unreachable etrip database is reported with its cause', function () {
    EtripSupplier::factory()->for($this->company)->create(['code' => '10']);

    $this->mock(EtripReader::class, function (MockInterface $mock) {
        $mock->shouldReceive('productTypes')->andThrow(new RuntimeException('connection to server at "10.0.0.9" failed'));
    });

    $this->actingAs($this->user)
        ->getJson('/payment-checks/check?company_id='.$this->company->id.'&supplier=10&from=2026-09-16&to=2026-09-17')
        ->assertStatus(503)
        ->assertJsonPath('message', 'Baza eTrip nu poate fi accesată: connection to server at "10.0.0.9" failed');
});

test('the check endpoint does not know suppliers that were never synced', function () {
    $this->mock(EtripReader::class);

    $this->actingAs($this->user)
        ->getJson('/payment-checks/check?company_id='.$this->company->id.'&supplier=404&from=2026-09-16&to=2026-09-17')
        ->assertNotFound();
});

test('expected requests are read from etrip once and cached', function () {
    $partner = Partner::factory()->for($this->company)->create();
    EtripSupplier::factory()->for($this->company)->create(['code' => '10', 'partner_id' => $partner->id]);

    $this->mock(EtripReader::class, function (MockInterface $mock) {
        $mock->shouldReceive('expectedCosts')
            ->withArgs(fn (Company $company, Carbon $from, Carbon $to) => $from->toDateString() === '2026-09-16'
                && $to->toDateString() === '2026-09-22')
            ->once()
            ->andReturn([
                ['supplier_code' => '10', 'supplier_name' => 'Rida International', 'currency' => 'EUR', 'cost' => 12760.0, 'bookings' => 7, 'items' => 7],
                ['supplier_code' => '99', 'supplier_name' => 'Unknown', 'currency' => 'USD', 'cost' => 100.0, 'bookings' => 1, 'items' => 1],
            ]);
    });

    $url = '/payment-checks/expected?company_id='.$this->company->id.'&days=7';

    $this->actingAs($this->user)
        ->getJson($url)
        ->assertOk()
        ->assertJsonPath('days', 7)
        ->assertJsonPath('to', '2026-09-22')
        ->assertJsonPath('suppliers.0.supplier_name', 'Rida International')
        ->assertJsonPath('suppliers.0.partner_id', $partner->id)
        ->assertJsonPath('suppliers.1.partner_id', null);

    $this->actingAs($this->user)->getJson($url)->assertOk()->assertJsonCount(2, 'suppliers');
});

test('the supplier search returns the active suppliers of a company', function () {
    EtripSupplier::factory()->for($this->company)->create(['code' => '10', 'name' => 'Rida International']);
    EtripSupplier::factory()->for($this->company)->create(['code' => '11', 'name' => 'Closed Hotel', 'is_active' => false]);
    EtripSupplier::factory()->create(['code' => '12', 'name' => 'Other company supplier']);

    $this->actingAs($this->user)
        ->getJson('/companies/'.$this->company->id.'/etrip-suppliers')
        ->assertOk()
        ->assertJsonCount(1, 'suppliers')
        ->assertJsonPath('suppliers.0.code', '10');

    $this->actingAs($this->user)
        ->getJson('/companies/'.$this->company->id.'/etrip-suppliers?q=nothing')
        ->assertOk()
        ->assertJsonCount(0, 'suppliers');
});

test('a partner can be linked to and unlinked from an etrip supplier', function () {
    $partner = Partner::factory()->for($this->company)->create();
    $previous = Partner::factory()->for($this->company)->create();
    $supplier = EtripSupplier::factory()->for($this->company)->create(['partner_id' => $previous->id, 'match_source' => 'cui']);
    $foreign = EtripSupplier::factory()->create();

    $this->actingAs($this->user)
        ->post("/partners/{$partner->id}/etrip-supplier", ['etrip_supplier_id' => $foreign->id])
        ->assertSessionHasErrors('etrip_supplier_id');

    $this->actingAs($this->user)
        ->from("/suppliers/{$partner->id}")
        ->post("/partners/{$partner->id}/etrip-supplier", ['etrip_supplier_id' => $supplier->id])
        ->assertRedirect("/suppliers/{$partner->id}");

    expect($supplier->fresh())->toMatchArray(['partner_id' => $partner->id, 'match_source' => 'manual']);

    $this->actingAs($this->user)
        ->delete("/partners/{$partner->id}/etrip-supplier")
        ->assertRedirect();

    expect($supplier->fresh()->partner_id)->toBeNull();
});

test('the supplier page exposes the etrip link', function () {
    $partner = Partner::factory()->for($this->company)->create();
    EtripSupplier::factory()->for($this->company)->create(['code' => '10', 'name' => 'Rida International', 'partner_id' => $partner->id, 'match_source' => 'cui']);

    $this->actingAs($this->user)
        ->get("/suppliers/{$partner->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('partner.etrip_enabled', true)
            ->where('partner.etrip_supplier.code', '10')
            ->where('partner.etrip_supplier.match_source', 'cui')
        );
});
