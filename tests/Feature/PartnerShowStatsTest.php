<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\User;

test('supplier stats treat credit notes offset in the erp as settled amounts', function () {
    $company = Company::factory()->create();
    $supplier = Partner::factory()->for($company)->create(['is_furnizor' => true, 'is_client' => false]);

    Invoice::factory()->for($company)->for($supplier)->create([
        'nr_doc' => 'OPEN',
        'val_mon' => 1000,
        'val_mon_paid' => 100,
        'val_mon_storno' => 300,
        'data_scadenta' => '2026-01-10',
    ]);
    Invoice::factory()->for($company)->for($supplier)->create([
        'nr_doc' => 'OFFSET',
        'val_mon' => 500,
        'val_mon_paid' => 0,
        'val_mon_storno' => 500,
        'data_scadenta' => '2025-12-01',
    ]);

    $this->actingAs(User::factory()->withRoles('finance')->create())
        ->get("/suppliers/{$supplier->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('partners/show')
            ->where('statsFurnizor.totals.0.sold', 600)
            ->where('statsFurnizor.totals.0.val_mon_paid', 900)
            ->where('statsFurnizor.counts.paid', 1)
            ->where('statsFurnizor.counts.partial', 1)
            ->where('statsFurnizor.counts.unpaid', 0)
            ->where('statsFurnizor.oldest_unpaid.nr_doc', 'OPEN')
            ->where('statsFurnizor.oldest_unpaid.val_mon', 600)
        );
});

test('the invoices table totals cover every filtered invoice, not just the page', function () {
    $company = Company::factory()->create();
    $supplier = Partner::factory()->for($company)->create(['is_furnizor' => true, 'is_client' => false]);

    Invoice::factory()->count(20)->for($company)->for($supplier)->create([
        'moneda' => 'Lei',
        'data_doc' => '2025-06-15',
        'val_mon' => 100,
        'val_mon_tva' => 19,
        'val_mon_paid' => 40,
        'val_mon_storno' => 0,
    ]);

    // Out of range: must stay out of the total.
    Invoice::factory()->for($company)->for($supplier)->create([
        'moneda' => 'Lei',
        'data_doc' => '2024-06-15',
        'val_mon' => 5000,
        'val_mon_tva' => 950,
    ]);

    // Another currency gets its own total row.
    Invoice::factory()->for($company)->for($supplier)->create([
        'moneda' => 'EUR',
        'data_doc' => '2025-07-01',
        'val_mon' => 200,
        'val_mon_tva' => 0,
        'val_mon_paid' => 200,
        'val_mon_storno' => 0,
    ]);

    $this->actingAs(User::factory()->withRoles('finance')->create())
        ->get("/suppliers/{$supplier->id}?invoice_from=2025-01-01&invoice_to=2025-12-31&role=furnizor")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('partners/show')
            ->has('invoices.data', 15)
            ->has('invoiceTotals', 2)
            ->where('invoiceTotals.0.moneda', 'EUR')
            ->where('invoiceTotals.0.count', 1)
            ->where('invoiceTotals.0.sold', 0)
            ->where('invoiceTotals.1.moneda', 'Lei')
            ->where('invoiceTotals.1.count', 20)
            ->where('invoiceTotals.1.val_mon', 2000)
            ->where('invoiceTotals.1.val_mon_tva', 380)
            ->where('invoiceTotals.1.total', 2380)
            ->where('invoiceTotals.1.val_mon_paid', 800)
            ->where('invoiceTotals.1.sold', 1200)
        );
});
