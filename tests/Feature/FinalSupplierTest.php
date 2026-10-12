<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceSource;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 10:00:00');
    Cache::flush();

    $this->company = Company::factory()->create();
    $this->air = Partner::factory()->create(['company_id' => $this->company->id, 'name' => 'Memento Air Srl', 'cui' => 'RO7166273']);
    $this->other = Partner::factory()->create(['company_id' => $this->company->id, 'name' => 'Altcineva SRL', 'cui' => 'RO1']);
    $this->user = User::factory()->withRoles('admin')->create();
});

function invoiceFrom(Partner $partner, float $net = 1000, float $vat = 190): Invoice
{
    return Invoice::factory()->create([
        'company_id' => test()->company->id,
        'partner_id' => $partner->id,
        'partener_type' => 'furnizor',
        'tip_doc' => 'FactFI',
        'moneda' => 'RON',
        'curs' => 1,
        'val_mon' => $net + $vat,
        'val_mon_tva' => $vat,
        'val_mon_paid' => 0,
        'val_mon_storno' => 0,
        'data_doc' => '2026-10-01',
    ]);
}

test('the real suppliers behind an invoice are gathered by name, biggest first', function () {
    $invoice = invoiceFrom($this->air);

    // Aceeași companie aeriană pe două zboruri: pe ecran trebuie să apară o
    // singură dată, cu suma adunată.
    InvoiceSource::query()->create(['invoice_id' => $invoice->id, 'supplier_name' => 'Anima Wings Aviation SA', 'supplier_tax_id' => 'RO1234', 'basis' => 'order', 'reference' => 'CHARTER AUGUST 2026', 'share' => 0.4, 'val_lei' => 400, 'exact' => false]);
    InvoiceSource::query()->create(['invoice_id' => $invoice->id, 'supplier_name' => 'Anima Wings Aviation SA', 'supplier_tax_id' => 'RO1234', 'basis' => 'order', 'reference' => 'CHARTER AUGUST 2026', 'share' => 0.3, 'val_lei' => 300, 'exact' => false]);
    InvoiceSource::query()->create(['invoice_id' => $invoice->id, 'supplier_name' => 'AVIAT Ltd.', 'basis' => 'order', 'reference' => 'CHARTER AUGUST 2026', 'share' => 0.2, 'val_lei' => 200, 'exact' => false]);

    $this->actingAs($this->user)
        ->get("/invoices/{$invoice->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('invoice.sources.suppliers', 2)
            ->where('invoice.sources.suppliers.0.name', 'Anima Wings Aviation SA')
            ->where('invoice.sources.suppliers.0.lei', fn ($lei) => (float) $lei === 700.0)
            // Partea se socotește din valoarea fără TVA: 700 din 1.000, nu din 1.190.
            ->where('invoice.sources.suppliers.0.share', fn ($share) => (float) $share === 0.7)
            ->where('invoice.sources.net_lei', fn ($net) => (float) $net === 1000.0)
            ->where('invoice.sources.covered', fn ($covered) => (float) $covered === 0.9)
            // La charter e o repartizare, nu o potrivire la document.
            ->where('invoice.sources.exact', false)
        );
});

test('an invoice with nothing behind it says nothing', function () {
    $invoice = invoiceFrom($this->other);

    $this->actingAs($this->user)
        ->get("/invoices/{$invoice->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('invoice.sources', null));
});

test('the list can be narrowed to what comes, in truth, from one supplier', function () {
    $mine = invoiceFrom($this->air);
    $hers = invoiceFrom($this->air);
    $plain = invoiceFrom($this->other);

    InvoiceSource::query()->create(['invoice_id' => $mine->id, 'supplier_name' => 'Anima Wings Aviation SA', 'basis' => 'order', 'share' => 1, 'val_lei' => 1000, 'exact' => false]);
    InvoiceSource::query()->create(['invoice_id' => $hers->id, 'supplier_name' => 'AVIAT Ltd.', 'basis' => 'order', 'share' => 1, 'val_lei' => 1000, 'exact' => false]);

    $this->actingAs($this->user)
        ->get('/invoices/received?final_supplier='.urlencode('Anima Wings Aviation SA'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('invoices.data', 1)
            ->where('invoices.data.0.id', $mine->id)
        );

    expect([$hers->id, $plain->id])->not->toContain($mine->id);
});

test('the filter offers the suppliers we already found, the biggest first', function () {
    $invoice = invoiceFrom($this->air);

    InvoiceSource::query()->create(['invoice_id' => $invoice->id, 'supplier_name' => 'Mărunt SRL', 'basis' => 'order', 'share' => 0.1, 'val_lei' => 100, 'exact' => false]);
    InvoiceSource::query()->create(['invoice_id' => $invoice->id, 'supplier_name' => 'Anima Wings Aviation SA', 'basis' => 'order', 'share' => 0.9, 'val_lei' => 900, 'exact' => false]);

    $this->actingAs($this->user)
        ->get('/invoices/received')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('finalSuppliers', 2)
            ->where('finalSuppliers.0.name', 'Anima Wings Aviation SA')
            ->where('finalSuppliers.0.invoices', 1)
            ->where('finalSuppliers.1.name', 'Mărunt SRL')
        );
});
