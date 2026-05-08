<?php

use App\Models\Invoice;
use App\Models\Partner;
use App\Models\User;
use App\Services\Invoices\InvoicePresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns issued invoices that share the received invoice com_int', function () {
    $supplier = Partner::factory()->furnizor()->create();
    $company = $supplier->company;
    $client = Partner::factory()->for($company)->create([
        'is_furnizor' => false,
        'is_client' => true,
    ]);

    $received = Invoice::factory()->create([
        'company_id' => $company->id,
        'partner_id' => $supplier->id,
        'tip_doc' => 'FactFI',
        'nr_doc' => 'BT 1513',
        'partener_type' => 'furnizor',
        'com_int' => 'UHQRWMMO',
    ]);

    $issued = Invoice::factory()->create([
        'company_id' => $company->id,
        'partner_id' => $client->id,
        'tip_doc' => 'FactCI',
        'nr_doc' => 'B2BFW016331',
        'partener_type' => 'client',
        'com_int' => 'UHQRWMMO',
        'moneda' => 'EUR',
        'val_mon' => 33.49,
        'val_mon_paid' => 33.49,
        'payment_status' => Invoice::PAYMENT_PAID,
    ]);

    $payload = (new InvoicePresenter)->comIntMatchesPayload($received);

    expect($payload)->toHaveCount(1);
    expect($payload[0]['id'])->toBe($issued->id);
    expect($payload[0]['nr_doc'])->toBe('B2BFW016331');
    expect($payload[0]['payment_status'])->toBe(Invoice::PAYMENT_PAID);
    expect($payload[0]['val_mon_paid'])->toBe(33.49);
    expect($payload[0]['partner']['id'])->toBe($client->id);
});

it('does not match across companies even when com_int matches', function () {
    $supplier = Partner::factory()->furnizor()->create();
    $companyA = $supplier->company;

    $received = Invoice::factory()->create([
        'company_id' => $companyA->id,
        'partner_id' => $supplier->id,
        'partener_type' => 'furnizor',
        'com_int' => 'UHQRWMMO',
    ]);

    $otherClient = Partner::factory()->create([
        'is_client' => true,
        'is_furnizor' => false,
    ]);
    Invoice::factory()->create([
        'company_id' => $otherClient->company_id,
        'partner_id' => $otherClient->id,
        'tip_doc' => 'FactCI',
        'partener_type' => 'client',
        'com_int' => 'UHQRWMMO',
    ]);

    expect((new InvoicePresenter)->comIntMatchesPayload($received))->toBe([]);
});

it('ignores the placeholder com_int "-"', function () {
    $supplier = Partner::factory()->furnizor()->create();
    $company = $supplier->company;
    $client = Partner::factory()->for($company)->create([
        'is_furnizor' => false,
        'is_client' => true,
    ]);

    $received = Invoice::factory()->create([
        'company_id' => $company->id,
        'partner_id' => $supplier->id,
        'partener_type' => 'furnizor',
        'com_int' => '-',
    ]);

    Invoice::factory()->create([
        'company_id' => $company->id,
        'partner_id' => $client->id,
        'tip_doc' => 'FactCI',
        'partener_type' => 'client',
        'com_int' => '-',
    ]);

    expect((new InvoicePresenter)->comIntMatchesPayload($received))->toBe([]);
});

it('returns empty for issued (client) invoices', function () {
    $client = Partner::factory()->create([
        'is_furnizor' => false,
        'is_client' => true,
    ]);

    $issued = Invoice::factory()->create([
        'company_id' => $client->company_id,
        'partner_id' => $client->id,
        'partener_type' => 'client',
        'com_int' => 'UHQRWMMO',
    ]);

    expect((new InvoicePresenter)->comIntMatchesPayload($issued))->toBe([]);
});

it('flags listing rows when a com_int counterpart exists', function () {
    $supplier = Partner::factory()->furnizor()->create();
    $company = $supplier->company;
    $client = Partner::factory()->for($company)->create([
        'is_furnizor' => false,
        'is_client' => true,
    ]);

    $received = Invoice::factory()->create([
        'company_id' => $company->id,
        'partner_id' => $supplier->id,
        'tip_doc' => 'FactFI',
        'partener_type' => 'furnizor',
        'com_int' => 'UHQRWMMO',
    ]);
    $issued = Invoice::factory()->create([
        'company_id' => $company->id,
        'partner_id' => $client->id,
        'tip_doc' => 'FactCI',
        'partener_type' => 'client',
        'com_int' => 'UHQRWMMO',
    ]);
    $orphan = Invoice::factory()->create([
        'company_id' => $company->id,
        'partner_id' => $supplier->id,
        'tip_doc' => 'FactFI',
        'partener_type' => 'furnizor',
        'com_int' => 'NOMATCH1',
    ]);
    $placeholder = Invoice::factory()->create([
        'company_id' => $company->id,
        'partner_id' => $supplier->id,
        'tip_doc' => 'FactFI',
        'partener_type' => 'furnizor',
        'com_int' => '-',
    ]);

    $presenter = new InvoicePresenter;
    $presenter->preloadComIntCounterparts([$received, $issued, $orphan, $placeholder]);

    expect($received->has_com_int_counterpart)->toBeTrue();
    expect($issued->has_com_int_counterpart)->toBeTrue();
    expect($orphan->has_com_int_counterpart)->toBeFalse();
    expect($placeholder->has_com_int_counterpart)->toBeFalse();
});

it('exposes has_com_int_counterpart on the listing Inertia payload', function () {
    $supplier = Partner::factory()->furnizor()->create();
    $company = $supplier->company;
    $client = Partner::factory()->for($company)->create([
        'is_furnizor' => false,
        'is_client' => true,
    ]);

    $received = Invoice::factory()->create([
        'company_id' => $company->id,
        'partner_id' => $supplier->id,
        'tip_doc' => 'FactFI',
        'partener_type' => 'furnizor',
        'com_int' => 'UHQRWMMO',
    ]);
    Invoice::factory()->create([
        'company_id' => $company->id,
        'partner_id' => $client->id,
        'tip_doc' => 'FactCI',
        'partener_type' => 'client',
        'com_int' => 'UHQRWMMO',
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->get('/invoices/received')
        ->assertOk();

    $rows = collect(data_get($response->viewData('page'), 'props.invoices.data', []));
    $row = $rows->firstWhere('id', $received->id);

    expect($row)->not->toBeNull();
    expect(data_get($row, 'has_com_int_counterpart'))->toBeTrue();
});

it('exposes com_int_matches on the invoice show Inertia payload', function () {
    $supplier = Partner::factory()->furnizor()->create();
    $company = $supplier->company;
    $client = Partner::factory()->for($company)->create([
        'is_furnizor' => false,
        'is_client' => true,
        'name' => 'Alexandra Olaru',
    ]);

    $received = Invoice::factory()->create([
        'company_id' => $company->id,
        'partner_id' => $supplier->id,
        'tip_doc' => 'FactFI',
        'nr_doc' => 'BT 1513',
        'partener_type' => 'furnizor',
        'com_int' => 'UHQRWMMO',
    ]);

    $issued = Invoice::factory()->create([
        'company_id' => $company->id,
        'partner_id' => $client->id,
        'tip_doc' => 'FactCI',
        'nr_doc' => 'B2BFW016331',
        'partener_type' => 'client',
        'com_int' => 'UHQRWMMO',
        'moneda' => 'EUR',
        'val_mon' => 33.49,
        'val_mon_paid' => 0,
        'payment_status' => Invoice::PAYMENT_UNPAID,
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->get("/invoices/{$received->id}")
        ->assertOk();

    $matches = data_get($response->viewData('page'), 'props.invoice.com_int_matches');

    expect($matches)->toHaveCount(1);
    expect($matches[0]['id'])->toBe($issued->id);
    expect($matches[0]['partner']['name'])->toBe('Alexandra Olaru');
    expect(data_get($response->viewData('page'), 'props.invoice.com_int'))->toBe('UHQRWMMO');
});
