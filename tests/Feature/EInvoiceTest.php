<?php

use App\Models\Company;
use App\Models\EInvoice;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
});

function makeEInvoice(array $attrs = []): EInvoice
{
    $company = $attrs['company'] ?? Company::factory()->create();
    unset($attrs['company']);

    return EInvoice::create(array_merge([
        'company_id' => $company->id,
        'msg_id' => fake()->unique()->numerify('##########'),
        'msg_cif' => $company->cui,
        'msg_data_creare_d' => now(),
        'data_doc_xml' => now()->toDateString(),
        'tip_doc_xml' => '380',
        'nr_doc_xml' => 'F'.fake()->unique()->numerify('#####'),
        'partener_xml' => fake()->company(),
        'cod_cci_xml' => (string) fake()->numberBetween(10000000, 99999999),
        'msg_detalii' => 'Factura cu id_incarcare=...',
    ], $attrs));
}

beforeEach(function () {
    $this->user = User::factory()->create(['workos_id' => 'u-'.fake()->uuid()]);
});

it('lists e-invoices and defaults to pending status', function () {
    $company = Company::factory()->create();
    $pending = makeEInvoice(['company' => $company, 'data_ins_omc' => null]);
    makeEInvoice(['company' => $company, 'data_ins_omc' => now()]);

    $this->actingAs($this->user)
        ->get('/efacturi')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('e-invoices/index')
            ->where('filters.status', 'pending')
            ->has('eInvoices.data', 1)
            ->where('eInvoices.data.0.id', $pending->id)
        );
});

it('filters by error status', function () {
    $company = Company::factory()->create();
    makeEInvoice(['company' => $company, 'data_ins_omc' => null, 'err_ins_omc' => null]);
    $errored = makeEInvoice(['company' => $company, 'data_ins_omc' => null, 'err_ins_omc' => 'boom']);

    $this->actingAs($this->user)
        ->get('/efacturi?status=error')
        ->assertInertia(fn ($page) => $page
            ->has('eInvoices.data', 1)
            ->where('eInvoices.data.0.id', $errored->id)
        );
});

it('filters by matched yes/no', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->for($company)->create(['nr_doc' => 'X1']);
    $matched = makeEInvoice(['company' => $company, 'invoice_id' => $invoice->id]);
    makeEInvoice(['company' => $company, 'invoice_id' => null]);

    $this->actingAs($this->user)
        ->get('/efacturi?status=all&matched=yes')
        ->assertInertia(fn ($page) => $page
            ->has('eInvoices.data', 1)
            ->where('eInvoices.data.0.id', $matched->id)
        );
});

it('returns detail json with msg_detalii and msg_xml', function () {
    $eInvoice = makeEInvoice(['msg_xml' => '<Invoice/>']);

    $this->actingAs($this->user)
        ->getJson("/efacturi/{$eInvoice->id}/detail")
        ->assertOk()
        ->assertJsonPath('eInvoice.id', $eInvoice->id)
        ->assertJsonPath('eInvoice.msg_xml', '<Invoice/>');
});

it('returns invoice candidates filtered by company and furnizor tip_doc', function () {
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    $eInvoice = makeEInvoice(['company' => $company, 'nr_doc_xml' => 'F123']);

    $match = Invoice::factory()->for($company)->create(['nr_doc' => 'F123', 'tip_doc' => 'FactFI']);
    Invoice::factory()->for($other)->create(['nr_doc' => 'F123', 'tip_doc' => 'FactFI']);
    Invoice::factory()->for($company)->create(['nr_doc' => 'F123', 'tip_doc' => 'FactCI', 'partener_type' => 'client']);

    $this->actingAs($this->user)
        ->getJson("/efacturi/{$eInvoice->id}/candidates")
        ->assertOk()
        ->assertJsonCount(1, 'candidates')
        ->assertJsonPath('candidates.0.id', $match->id);
});

it('manually links and unlinks an invoice via match endpoint', function () {
    $company = Company::factory()->create();
    $eInvoice = makeEInvoice(['company' => $company]);
    $invoice = Invoice::factory()->for($company)->create();

    $this->actingAs($this->user)
        ->post("/efacturi/{$eInvoice->id}/match", ['invoice_id' => $invoice->id])
        ->assertRedirect();
    expect($eInvoice->fresh()->invoice_id)->toBe($invoice->id);

    $this->actingAs($this->user)
        ->post("/efacturi/{$eInvoice->id}/match", [])
        ->assertRedirect();
    expect($eInvoice->fresh()->invoice_id)->toBeNull();
});

it('rejects matching to an invoice from a different company', function () {
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    $eInvoice = makeEInvoice(['company' => $company]);
    $foreign = Invoice::factory()->for($other)->create();

    $this->actingAs($this->user)
        ->post("/efacturi/{$eInvoice->id}/match", ['invoice_id' => $foreign->id])
        ->assertNotFound();
});
