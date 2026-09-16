<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-09-16 10:00:00');

    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['name' => 'Christian Tour', 'last_synced_at' => '2026-09-16 06:00:00']);
});

test('guests are redirected to the login page', function () {
    $this->get('/payment-checks/invoices')->assertRedirect('/login');
});

test('the page lists the suppliers with open invoices, earliest due date first', function () {
    $late = Partner::factory()->for($this->company)->create(['name' => 'VODAFONE ROMANIA SA', 'cui' => 'RO8971726']);
    $soon = Partner::factory()->for($this->company)->create(['name' => 'Clever Media']);
    $settled = Partner::factory()->for($this->company)->create(['name' => 'Settled']);
    $elsewhere = Partner::factory()->create(['name' => 'Other company']);

    Invoice::factory()->for($this->company)->for($late)->create(['moneda' => 'EUR', 'curs' => 5, 'val_mon' => 1000, 'val_mon_paid' => 200, 'data_scadenta' => '2026-09-01']);
    Invoice::factory()->for($this->company)->for($late)->create(['moneda' => 'Lei', 'curs' => 1, 'val_mon' => 300, 'data_scadenta' => '2026-10-01']);
    Invoice::factory()->for($this->company)->for($soon)->create(['val_mon' => 500, 'data_scadenta' => '2026-09-20']);
    Invoice::factory()->for($this->company)->for($settled)->create(['val_mon' => 500, 'val_mon_paid' => 200, 'val_mon_storno' => 300]);
    Invoice::factory()->for($this->company)->for($soon)->create(['tip_doc' => 'FactCI', 'partener_type' => 'client', 'val_mon' => 700, 'data_scadenta' => '2026-08-01']);
    Invoice::factory()->for($elsewhere->company)->for($elsewhere)->create(['val_mon' => 900, 'data_scadenta' => '2026-08-01']);

    $this->actingAs($this->user)
        ->get('/payment-checks/invoices?company_id='.$this->company->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('payment-checks/invoices')
            ->where('filters.company_id', $this->company->id)
            ->where('filters.partner', null)
            ->where('companies.0.name', 'Christian Tour')
            ->where('companies.0.synced_at', fn ($value) => str_starts_with((string) $value, '2026-09-16T06:00:00'))
            ->has('openSuppliers', 2)
            ->where('openSuppliers.0.partner_id', $late->id)
            ->where('openSuppliers.0.name', 'VODAFONE ROMANIA SA')
            ->where('openSuppliers.0.cui', 'RO8971726')
            ->where('openSuppliers.0.invoices', 2)
            ->where('openSuppliers.0.first_due', '2026-09-01')
            ->where('openSuppliers.0.overdue', true)
            ->where('openSuppliers.0.rest', [['moneda' => 'EUR', 'rest' => 800], ['moneda' => 'RON', 'rest' => 300]])
            ->where('openSuppliers.0.rest_lei', 4300)
            ->where('openSuppliers.1.name', 'Clever Media')
            ->where('openSuppliers.1.first_due', '2026-09-20')
            ->where('openSuppliers.1.overdue', false)
            ->where('openSuppliers.1.rest_lei', 500)
        );
});

test('a supplier and amount from the url are preselected', function () {
    $partner = Partner::factory()->for($this->company)->create(['name' => 'VODAFONE ROMANIA SA']);
    $client = Partner::factory()->for($this->company)->create(['is_furnizor' => false, 'is_client' => true]);

    $this->actingAs($this->user)
        ->get('/payment-checks/invoices?company_id='.$this->company->id."&partner_id={$partner->id}&amount=15694.48&currency=RON")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.partner.id', $partner->id)
            ->where('filters.partner.name', 'VODAFONE ROMANIA SA')
            ->where('filters.amount', '15694.48')
            ->where('filters.currency', 'RON')
        );

    $this->actingAs($this->user)
        ->get('/payment-checks/invoices?company_id='.$this->company->id."&partner_id={$client->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('filters.partner', null));
});

test('the supplier search matches the name or vat number within the company', function () {
    Partner::factory()->for($this->company)->create(['name' => 'VODAFONE ROMANIA S.A.', 'cui' => 'RO8971726']);
    Partner::factory()->for($this->company)->create(['name' => 'Orange Romania', 'cui' => 'RO9010105']);
    Partner::factory()->for($this->company)->create(['name' => 'Vodafone client', 'is_furnizor' => false, 'is_client' => true]);
    Partner::factory()->create(['name' => 'Vodafone elsewhere']);

    $this->actingAs($this->user)
        ->getJson('/companies/'.$this->company->id.'/suppliers?q=voda')
        ->assertOk()
        ->assertJsonCount(1, 'suppliers')
        ->assertJsonPath('suppliers.0.name', 'VODAFONE ROMANIA S.A.');

    $this->actingAs($this->user)
        ->getJson('/companies/'.$this->company->id.'/suppliers?q=8971726')
        ->assertOk()
        ->assertJsonPath('suppliers.0.cui', 'RO8971726');

    $this->actingAs($this->user)
        ->getJson('/companies/'.$this->company->id.'/suppliers')
        ->assertOk()
        ->assertJsonCount(2, 'suppliers')
        ->assertJsonPath('suppliers.0.name', 'Orange Romania');
});
