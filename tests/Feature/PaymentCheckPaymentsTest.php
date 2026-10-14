<?php

use App\Models\Company;
use App\Models\EtripSupplier;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-10-14 09:00:00');
    $this->company = Company::factory()->create();
    $this->user = User::factory()->withRoles('admin')->create();
    $this->connection = array_key_first((array) config('etrip.connections'));
});

test('the check shows what we already paid the supplier', function () {
    $partner = Partner::factory()->create(['company_id' => $this->company->id, 'name' => 'Hotel Egeu SRL', 'cui' => 'RO123']);

    EtripSupplier::query()->create([
        'etrip_connection' => $this->connection, 'code' => 'HE1', 'name' => 'Hotel Egeu',
        'company_id' => $this->company->id, 'partner_id' => $partner->id,
    ]);

    // Una plătită pe jumătate, una închisă: amândouă trebuie să se vadă.
    Invoice::factory()->create([
        'company_id' => $this->company->id, 'partner_id' => $partner->id, 'partener_type' => 'furnizor',
        'tip_doc' => 'FactFI', 'nr_doc' => 'HE-100', 'moneda' => 'EUR', 'curs' => 5,
        'val_mon' => 10000, 'val_mon_tva' => 0, 'val_mon_paid' => 4000, 'val_mon_storno' => 0,
        'data_doc' => '2026-10-01',
    ]);
    Invoice::factory()->create([
        'company_id' => $this->company->id, 'partner_id' => $partner->id, 'partener_type' => 'furnizor',
        'tip_doc' => 'FactFI', 'nr_doc' => 'HE-099', 'moneda' => 'EUR', 'curs' => 5,
        'val_mon' => 2000, 'val_mon_tva' => 0, 'val_mon_paid' => 2000, 'val_mon_storno' => 0,
        'data_doc' => '2026-09-20',
    ]);

    $said = $this->actingAs($this->user)
        ->getJson('/payment-checks/payments?'.http_build_query([
            'connection' => $this->connection, 'supplier' => 'HE1', 'amount' => 6000, 'currency' => 'EUR',
        ]))
        ->assertOk()
        ->json();

    expect($said['linked'])->toBeTrue()
        ->and($said['supplier']['name'])->toBe('Hotel Egeu SRL')
        // Restul neachitat: 10.000 − 4.000.
        ->and((float) $said['open_totals'][0]['rest'])->toBe(6000.0)
        ->and($said['open_totals'][0]['moneda'])->toBe('EUR')
        ->and(collect($said['recent'])->pluck('nr_doc')->all())->toContain('HE-100', 'HE-099')
        // Sumă cerută care se potrivește exact cu ce e neachitat.
        ->and($said['requested']['level'])->toBe('ok');
});

test('a supplier not tied to a partner says so, instead of showing nothing', function () {
    EtripSupplier::query()->create([
        'etrip_connection' => $this->connection, 'code' => 'FARA', 'name' => 'Nelegat',
        'company_id' => $this->company->id, 'partner_id' => null,
    ]);

    $said = $this->actingAs($this->user)
        ->getJson('/payment-checks/payments?'.http_build_query([
            'connection' => $this->connection, 'supplier' => 'FARA',
        ]))
        ->assertOk()
        ->json();

    expect($said['linked'])->toBeFalse()
        ->and($said['supplier']['name'])->toBe('Nelegat');
});
