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

    $this->actingAs(User::factory()->create())
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
