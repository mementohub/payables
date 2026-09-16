<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\User;
use App\Services\Omc\OmcReader;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function omcInvoice(array $overrides = []): array
{
    return [
        'data_doc' => '2026-09-05',
        'tip_doc' => 'FactFI',
        'nr_doc' => 'VDF815374737',
        'moneda' => 'Lei',
        'curs' => 1,
        'val_mon' => 15694.48,
        'val_mon_tva' => 2505.7,
        'val_mon_pl' => 0,
        'val_mon_dimin_negru' => 0,
        'data_scadenta' => '2026-09-20',
        'paid_at' => null,
        'description' => 'VPN - Vodafone',
        'accounts' => '626',
        ...$overrides,
    ];
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-16 10:00:00');
    config()->set('database.connections.omc.database', 'christian_76_tour');

    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['name' => 'Christian Tour', 'db_database' => 'christian_76_tour']);
});

test('guests are redirected to the login page', function () {
    $this->get('/payment-checks/invoices')->assertRedirect('/login');
});

test('the page names the omc database and company and keeps the url filters', function () {
    $this->actingAs($this->user)
        ->get('/payment-checks/invoices?supplier=VODAFONE%20ROMANIA%20SA&amount=15694.48&currency=RON')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('payment-checks/invoices')
            ->where('company.id', $this->company->id)
            ->where('database', fn ($value) => str_starts_with((string) $value, 'christian_76_tour @ '))
            ->where('filters.supplier', 'VODAFONE ROMANIA SA')
            ->where('filters.amount', '15694.48')
            ->where('filters.currency', 'RON')
        );
});

test('suppliers are searched live in omc', function () {
    $this->partialMock(OmcReader::class, function (MockInterface $mock) {
        $mock->shouldReceive('searchSuppliers')->with('voda', 30)->once()->andReturn([
            ['name' => 'VODAFONE ROMANIA SA', 'cui' => 'RO8971726', 'country' => 'RO', 'city' => 'București', 'invoices' => 23, 'last_invoice' => '2026-09-05'],
        ]);
    });

    $this->actingAs($this->user)
        ->getJson('/payment-checks/invoices/suppliers?q=voda')
        ->assertOk()
        ->assertJsonCount(1, 'suppliers')
        ->assertJsonPath('suppliers.0.name', 'VODAFONE ROMANIA SA')
        ->assertJsonPath('suppliers.0.invoices', 23);
});

test('the check reads the supplier invoices live from omc and maps the ones synced locally', function () {
    $partner = Partner::factory()->for($this->company)->create(['name' => 'VODAFONE ROMANIA SA', 'cui' => 'RO8971726']);
    $synced = Invoice::factory()->for($this->company)->for($partner)->create(['data_doc' => '2026-09-05', 'tip_doc' => 'FactFI', 'nr_doc' => 'VDF815374737']);

    $this->partialMock(OmcReader::class, function (MockInterface $mock) {
        $mock->shouldReceive('supplier')->with('VODAFONE ROMANIA SA')->once()->andReturn([
            'name' => 'VODAFONE ROMANIA SA', 'cui' => 'RO8971726', 'country' => 'RO', 'city' => 'București', 'is_company' => true,
        ]);
        $mock->shouldReceive('supplierInvoices')
            ->withArgs(fn (string $name, Carbon $since) => $name === 'VODAFONE ROMANIA SA' && $since->toDateString() === '2024-10-01')
            ->once()
            ->andReturn([
                omcInvoice(),
                omcInvoice(['data_doc' => '2026-08-05', 'nr_doc' => 'VDF808642943', 'val_mon' => 15715.64, 'val_mon_pl' => 15715.64, 'data_scadenta' => '2026-08-20', 'paid_at' => '2026-08-17', 'accounts' => '626, 461']),
                omcInvoice(['data_doc' => '2024-01-05', 'nr_doc' => 'OLD', 'val_mon' => 100, 'val_mon_pl' => 40, 'data_scadenta' => '2024-01-20', 'description' => null]),
            ]);
    });

    $this->actingAs($this->user)
        ->getJson('/payment-checks/invoices/check?supplier=VODAFONE%20ROMANIA%20SA&amount=15694.48&currency=RON')
        ->assertOk()
        ->assertJsonPath('source', 'omc')
        ->assertJsonPath('supplier.cui', 'RO8971726')
        ->assertJsonPath('supplier.partner_id', $partner->id)
        ->assertJsonPath('supplier.accounts', '461, 626')
        ->assertJsonCount(2, 'open')
        ->assertJsonPath('open.0.nr_doc', 'OLD')
        ->assertJsonPath('open.0.rest', 60)
        ->assertJsonPath('open.0.id', null)
        ->assertJsonPath('open.1.id', $synced->id)
        ->assertJsonPath('open.1.description', 'VPN - Vodafone')
        ->assertJsonPath('open.1.moneda', 'RON')
        ->assertJsonCount(2, 'recent')
        ->assertJsonPath('recent.1.payment_status', 'paid')
        ->assertJsonPath('recent.1.paid_at', '2026-08-17')
        ->assertJsonPath('requested.verdict', 'exact')
        ->assertJsonPath('requested.invoice.id', $synced->id)
        ->assertJsonPath('last_invoice.nr_doc', 'VDF815374737')
        ->assertJsonPath('invoices_12m', 2);
});

test('a supplier unknown to omc is a 404', function () {
    $this->partialMock(OmcReader::class, function (MockInterface $mock) {
        $mock->shouldReceive('supplier')->once()->andReturnNull();
    });

    $this->actingAs($this->user)
        ->getJson('/payment-checks/invoices/check?supplier=NOBODY')
        ->assertNotFound();
});

test('an unreachable omc database is reported with its cause', function () {
    $this->partialMock(OmcReader::class, function (MockInterface $mock) {
        $mock->shouldReceive('searchSuppliers')->andThrow(new RuntimeException('connection to server at "chr-etrip-pgbouncer" failed'));
    });

    $this->actingAs($this->user)
        ->getJson('/payment-checks/invoices/suppliers?q=x')
        ->assertStatus(503)
        ->assertJsonPath('message', 'Baza OMC nu poate fi accesată: connection to server at "chr-etrip-pgbouncer" failed');
});

test('suppliers with open invoices are grouped and ordered by first due date', function () {
    config()->set('omc.open_window_years', 2);

    $this->partialMock(OmcReader::class, function (MockInterface $mock) {
        $mock->shouldReceive('openSupplierInvoices')
            ->withArgs(fn (Carbon $since) => $since->toDateString() === '2024-09-16')
            ->once()
            ->andReturn([
                ['partener' => 'Clever Media', 'cod_cci' => 'RO1', ...omcInvoice(['nr_doc' => 'C1', 'val_mon' => 500, 'data_scadenta' => '2026-09-20'])],
                ['partener' => 'VODAFONE ROMANIA SA', 'cod_cci' => 'RO8971726', ...omcInvoice(['nr_doc' => 'V1', 'moneda' => 'EUR', 'curs' => 5, 'val_mon' => 1000, 'val_mon_pl' => 200, 'data_scadenta' => '2026-09-01'])],
                ['partener' => 'VODAFONE ROMANIA SA', 'cod_cci' => 'RO8971726', ...omcInvoice(['nr_doc' => 'V2', 'val_mon' => 300, 'data_scadenta' => '2026-10-01'])],
            ]);
    });

    $this->actingAs($this->user)
        ->getJson('/payment-checks/invoices/open')
        ->assertOk()
        ->assertJsonPath('since', '2024-09-16')
        ->assertJsonCount(2, 'suppliers')
        ->assertJsonPath('suppliers.0.name', 'VODAFONE ROMANIA SA')
        ->assertJsonPath('suppliers.0.cui', 'RO8971726')
        ->assertJsonPath('suppliers.0.invoices', 2)
        ->assertJsonPath('suppliers.0.first_due', '2026-09-01')
        ->assertJsonPath('suppliers.0.overdue', true)
        ->assertJsonPath('suppliers.0.rest', [['moneda' => 'EUR', 'rest' => 800], ['moneda' => 'RON', 'rest' => 300]])
        ->assertJsonPath('suppliers.0.rest_lei', 4300)
        ->assertJsonPath('suppliers.1.name', 'Clever Media')
        ->assertJsonPath('suppliers.1.overdue', false)
        ->assertJsonPath('suppliers.1.rest_lei', 500);
});
