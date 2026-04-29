<?php

use App\Models\Department;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeResponsabil(string $name = 'Op'): array
{
    $dept = Department::create(['name' => $name, 'type' => Department::TYPE_RESPONSABIL]);
    $user = User::factory()->create();
    $dept->members()->attach($user->id);

    return [$user, $dept];
}

it('lets any authenticated user see facturi emise', function () {
    $this->actingAs(User::factory()->create())
        ->get('/invoices/issued')
        ->assertOk();
});

it('lets any authenticated user see clienti', function () {
    $this->actingAs(User::factory()->create())
        ->get('/clients')
        ->assertOk();
});

it('lets any authenticated user open the AI assistant', function () {
    $this->actingAs(User::factory()->create())
        ->get('/ai-assistant')
        ->assertOk();
});

it('shows every received invoice on facturi primite to any user', function () {
    $user = User::factory()->create();
    [, $deptA] = makeResponsabil('A');
    [, $deptB] = makeResponsabil('B');

    $partnerA = Partner::factory()->furnizor()->create();
    $partnerA->departments()->attach($deptA->id);

    $partnerB = Partner::factory()->furnizor()->create();
    $partnerB->departments()->attach($deptB->id);

    $partnerNone = Partner::factory()->furnizor()->create();

    foreach ([$partnerA, $partnerB, $partnerNone] as $partner) {
        Invoice::factory()->create([
            'company_id' => $partner->company_id,
            'partner_id' => $partner->id,
            'partener_type' => 'furnizor',
        ]);
    }

    $response = $this->actingAs($user)->get('/invoices/received')->assertOk();
    $invoices = $response->viewData('page')['props']['invoices']['data'];

    expect($invoices)->toHaveCount(3);
});

it('lets any user view a furnizor invoice regardless of department', function () {
    $user = User::factory()->create();
    [, $deptB] = makeResponsabil('B');

    $partner = Partner::factory()->furnizor()->create();
    $partner->departments()->attach($deptB->id);

    $invoice = Invoice::factory()->create([
        'company_id' => $partner->company_id,
        'partner_id' => $partner->id,
        'partener_type' => 'furnizor',
    ]);

    $this->actingAs($user)->get("/invoices/{$invoice->id}")->assertOk();
});

it('lets any user view a client invoice', function () {
    [$user] = makeResponsabil();

    $partner = Partner::factory()->create(['is_furnizor' => false, 'is_client' => true]);
    $invoice = Invoice::factory()->create([
        'company_id' => $partner->company_id,
        'partner_id' => $partner->id,
        'partener_type' => 'client',
    ]);

    $this->actingAs($user)->get("/invoices/{$invoice->id}")->assertOk();
});
