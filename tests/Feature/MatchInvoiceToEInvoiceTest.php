<?php

use App\Actions\EInvoices\MatchInvoiceToEInvoice;
use App\Models\Company;
use App\Models\EInvoice;
use App\Models\Invoice;
use App\Models\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeMatcherEInvoice(Company $company, array $attrs = []): EInvoice
{
    return EInvoice::create(array_merge([
        'company_id' => $company->id,
        'msg_id' => fake()->unique()->numerify('##########'),
        'msg_data_creare_d' => now(),
        'data_doc_xml' => now()->toDateString(),
        'tip_doc_xml' => '380',
        'nr_doc_xml' => 'F'.fake()->unique()->numerify('#####'),
    ], $attrs));
}

it('returns null when supplier_cui is missing', function () {
    $company = Company::factory()->create();
    $eInvoice = makeMatcherEInvoice($company, ['nr_doc_xml' => 'A1', 'supplier_cui' => null]);

    expect((new MatchInvoiceToEInvoice)->find($eInvoice))->toBeNull();
});

it('matches an invoice via supplier_cui', function () {
    $company = Company::factory()->create();
    $partner = Partner::factory()->for($company)->create(['cui' => 'RO12345678']);
    $invoice = Invoice::factory()
        ->for($company)
        ->for($partner)
        ->create(['nr_doc' => 'A12345', 'tip_doc' => 'FactFI']);

    $eInvoice = makeMatcherEInvoice($company, [
        'nr_doc_xml' => 'A12345',
        'supplier_cui' => '12345678',
    ]);

    expect((new MatchInvoiceToEInvoice)->find($eInvoice)?->id)->toBe($invoice->id);
});

it('matches when partner CUI carries the SeniorERP suffix', function () {
    $company = Company::factory()->create();
    $partner = Partner::factory()->for($company)->create(['cui' => 'RO1243237a']);
    $invoice = Invoice::factory()
        ->for($company)
        ->for($partner)
        ->create(['nr_doc' => 'A51438', 'tip_doc' => 'FactFI']);

    $eInvoice = makeMatcherEInvoice($company, [
        'nr_doc_xml' => 'A51438',
        'supplier_cui' => '1243237',
    ]);

    expect((new MatchInvoiceToEInvoice)->find($eInvoice)?->id)->toBe($invoice->id);
});

it('matches across whitespace variations in nr_doc', function () {
    $company = Company::factory()->create();
    $partner = Partner::factory()->for($company)->create(['cui' => 'RO36359200']);
    $invoice = Invoice::factory()
        ->for($company)
        ->for($partner)
        ->create(['nr_doc' => 'EXP 56841', 'tip_doc' => 'FactFI']);

    $eInvoice = makeMatcherEInvoice($company, [
        'nr_doc_xml' => 'EXP56841',
        'supplier_cui' => '36359200',
    ]);

    expect((new MatchInvoiceToEInvoice)->find($eInvoice)?->id)->toBe($invoice->id);
});

it('matches an invoice whose partner is a name-duplicate without CUI', function () {
    $company = Company::factory()->create();
    $canonical = Partner::factory()->for($company)->create([
        'name' => 'BOOKINGPEDIA SRL',
        'cui' => 'RO36359200',
    ]);
    $duplicate = Partner::factory()->for($company)->create([
        'name' => 'Bookingpedia S.R.L.',
        'cui' => null,
    ]);
    $invoice = Invoice::factory()
        ->for($company)
        ->for($duplicate)
        ->create(['nr_doc' => 'EXP 56841', 'tip_doc' => 'FactFI']);

    $eInvoice = makeMatcherEInvoice($company, [
        'nr_doc_xml' => 'EXP56841',
        'supplier_cui' => '36359200',
    ]);

    expect((new MatchInvoiceToEInvoice)->find($eInvoice)?->id)->toBe($invoice->id);
    expect($canonical->id)->not->toBe($duplicate->id);
});

it('does not match invoices from a different supplier', function () {
    $company = Company::factory()->create();
    $supplierA = Partner::factory()->for($company)->create(['cui' => 'RO11111111']);
    $supplierB = Partner::factory()->for($company)->create(['cui' => 'RO22222222']);
    Invoice::factory()
        ->for($company)
        ->for($supplierA)
        ->create(['nr_doc' => 'F1', 'tip_doc' => 'FactFI']);

    $eInvoice = makeMatcherEInvoice($company, [
        'nr_doc_xml' => 'F1',
        'supplier_cui' => '22222222',
    ]);

    expect((new MatchInvoiceToEInvoice)->find($eInvoice))->toBeNull();
    expect($supplierB->id)->not->toBe($supplierA->id);
});
