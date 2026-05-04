<?php

use App\Models\Invoice;
use App\Models\Partner;
use App\Models\User;
use App\Services\Invoices\InvoicePresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves the real supplier through nr_doc_baza within the same company', function () {
    $partner = Partner::factory()->furnizor()->create(['name' => 'Zanzi Travel']);
    $company = $partner->company;

    $base = Invoice::factory()->create([
        'company_id' => $company->id,
        'partner_id' => $partner->id,
        'tip_doc' => 'FactFI',
        'nr_doc' => 'ZAN0833',
    ]);

    $mbtPartner = Partner::factory()->furnizor()->for($company)->create(['name' => 'Memento Bus Transport']);

    $refacturare = Invoice::factory()->create([
        'company_id' => $company->id,
        'partner_id' => $mbtPartner->id,
        'tip_doc' => 'FactFI',
        'nr_doc' => '20271653',
        'tip_doc_baza' => 'FactFI',
        'nr_doc_baza' => 'ZAN0833',
    ]);

    $presenter = new InvoicePresenter;
    $presenter->preloadBazaInvoices([$refacturare, $base]);

    $payload = $presenter->bazaPayload($refacturare);

    expect($payload)->not->toBeNull();
    expect($payload['nr_doc'])->toBe('ZAN0833');
    expect($payload['invoice'])->not->toBeNull();
    expect($payload['invoice']['id'])->toBe($base->id);
    expect($payload['invoice']['real_supplier']['name'])->toBe('Zanzi Travel');
});

it('does not point an invoice at itself when nr_doc_baza equals its own nr_doc', function () {
    $partner = Partner::factory()->furnizor()->create();

    $invoice = Invoice::factory()->create([
        'company_id' => $partner->company_id,
        'partner_id' => $partner->id,
        'nr_doc' => 'X1',
        'nr_doc_baza' => 'X1',
    ]);

    $presenter = new InvoicePresenter;
    $presenter->preloadBazaInvoices([$invoice]);

    expect($invoice->relationLoaded('bazaInvoice'))->toBeFalse();
});

it('exposes baza.invoice.real_supplier on the /invoices/primite Inertia payload', function () {
    $supplier = Partner::factory()->furnizor()->create(['name' => 'Zanzi Travel']);
    $company = $supplier->company;

    $base = Invoice::factory()->create([
        'company_id' => $company->id,
        'partner_id' => $supplier->id,
        'tip_doc' => 'FactFI',
        'nr_doc' => 'ZAN0833',
        'partener_type' => 'furnizor',
    ]);

    $mbt = Partner::factory()->furnizor()->for($company)->create(['name' => 'MBT']);

    $refacturare = Invoice::factory()->create([
        'company_id' => $company->id,
        'partner_id' => $mbt->id,
        'tip_doc' => 'FactFI',
        'nr_doc' => '20271653',
        'tip_doc_baza' => 'FactFI',
        'nr_doc_baza' => 'ZAN0833',
        'partener_type' => 'furnizor',
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->get('/invoices/received')
        ->assertOk();

    $rows = collect(data_get($response->viewData('page'), 'props.invoices.data', []));
    $row = $rows->firstWhere('id', $refacturare->id);

    expect($row)->not->toBeNull();
    expect(data_get($row, 'baza.invoice.real_supplier.name'))->toBe('Zanzi Travel');
    expect(data_get($row, 'baza.invoice.id'))->toBe($base->id);
});

it('does not cross company boundaries when matching nr_doc_baza', function () {
    $supplierA = Partner::factory()->furnizor()->create(['name' => 'Supplier A']);
    $companyA = $supplierA->company;

    $partnerB = Partner::factory()->furnizor()->create(['name' => 'Supplier B']);
    $companyB = $partnerB->company;

    Invoice::factory()->create([
        'company_id' => $companyA->id,
        'partner_id' => $supplierA->id,
        'nr_doc' => 'COMMON',
    ]);

    $refacturareInB = Invoice::factory()->create([
        'company_id' => $companyB->id,
        'partner_id' => $partnerB->id,
        'nr_doc' => 'OTHER',
        'nr_doc_baza' => 'COMMON',
    ]);

    $presenter = new InvoicePresenter;
    $presenter->preloadBazaInvoices([$refacturareInB]);

    expect($refacturareInB->relationLoaded('bazaInvoice'))->toBeFalse();
});

it('walks a 2-hop chain: cross-company source plus same-company baza', function () {
    // Mirrors the production scenario:
    //   German Touristik FactFI MBT 20271775 (received from MEMENTO INTERNATIONAL SRL)
    //     → Memento Bus FactCI MBT 20271775 (issued, with nr_doc_baza=TINA CN10379)
    //       → Memento Bus FactFI TINA CN10379 (received from CHRISTIAN76 TOUR SRL)
    // The real supplier shown on the German Touristik row should be CHRISTIAN76 TOUR SRL.

    $christian = Partner::factory()->furnizor()->create(['name' => 'CHRISTIAN76 TOUR SRL']);
    $mementoBus = $christian->company;

    $germanCustomer = Partner::factory()->for($mementoBus)->create([
        'name' => 'GERMAN TOURISTIK GROUP S.R.L.',
        'is_furnizor' => false,
        'is_client' => true,
    ]);

    $tina = Invoice::factory()->create([
        'company_id' => $mementoBus->id,
        'partner_id' => $christian->id,
        'tip_doc' => 'FactFI',
        'nr_doc' => 'TINA CN10379',
        'data_doc' => '2026-01-15',
        'partener_type' => 'furnizor',
    ]);

    $issuedByMemento = Invoice::factory()->create([
        'company_id' => $mementoBus->id,
        'partner_id' => $germanCustomer->id,
        'tip_doc' => 'FactCI',
        'nr_doc' => 'MBT 20271775',
        'data_doc' => '2026-02-09',
        'partener_type' => 'client',
        'tip_doc_baza' => 'FactFI',
        'nr_doc_baza' => 'TINA CN10379',
    ]);

    $mbtSupplier = Partner::factory()->furnizor()->create(['name' => 'MEMENTO INTERNATIONAL SRL']);
    $germanTouristik = $mbtSupplier->company;

    $received = Invoice::factory()->create([
        'company_id' => $germanTouristik->id,
        'partner_id' => $mbtSupplier->id,
        'tip_doc' => 'FactFI',
        'nr_doc' => 'MBT 20271775',
        'data_doc' => '2026-02-09',
        'partener_type' => 'furnizor',
    ]);

    $presenter = new InvoicePresenter;
    $presenter->preloadBazaInvoices([$received]);

    expect($received->sourceInvoice?->id)->toBe($issuedByMemento->id);

    $payload = $presenter->sourceInvoicePayload($received);

    expect($payload)->not->toBeNull();
    expect($payload['id'])->toBe($issuedByMemento->id);
    expect($payload['company']['name'])->toBe($mementoBus->name);
    expect($payload['real_supplier'])->not->toBeNull();
    expect($payload['real_supplier']['name'])->toBe('CHRISTIAN76 TOUR SRL');
    expect($payload['real_supplier']['id'])->toBe($christian->id);
});
