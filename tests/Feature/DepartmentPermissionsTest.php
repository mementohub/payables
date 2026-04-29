<?php

use App\Models\Department;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeMaster(): User
{
    $dept = Department::firstOrCreate(['name' => 'developers', 'type' => Department::TYPE_MASTER]);
    $user = User::factory()->create();
    $dept->members()->attach($user->id);

    return $user;
}

function makeSupervisor(string $name = 'Op'): array
{
    $dept = Department::create(['name' => $name, 'type' => Department::TYPE_SUPERVISOR]);
    $user = User::factory()->create();
    $dept->members()->attach($user->id);

    return [$user, $dept];
}

it('lets masters see facturi emise', function () {
    $this->actingAs(makeMaster())
        ->get('/facturi-emise')
        ->assertOk();
});

it('blocks non-masters from facturi emise', function () {
    [$user] = makeSupervisor();

    $this->actingAs($user)->get('/facturi-emise')->assertForbidden();
    $this->actingAs(User::factory()->create())
        ->get('/facturi-emise')->assertForbidden();
});

it('lets masters see clienti', function () {
    $this->actingAs(makeMaster())
        ->get('/clienti')
        ->assertOk();
});

it('blocks non-masters from clienti', function () {
    [$user] = makeSupervisor();

    $this->actingAs($user)->get('/clienti')->assertForbidden();
});

it('lets masters open the AI assistant', function () {
    $this->actingAs(makeMaster())
        ->get('/asistent-ai')
        ->assertOk();
});

it('blocks non-masters from the AI assistant', function () {
    [$user] = makeSupervisor();

    $this->actingAs($user)->get('/asistent-ai')->assertForbidden();
    $this->actingAs($user)->post('/asistent-ai/stream', ['message' => 'hi'])->assertForbidden();
});

it('shows masters every received invoice on facturi primite', function () {
    $master = makeMaster();
    [, $deptA] = makeSupervisor('A');
    [, $deptB] = makeSupervisor('B');

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

    $response = $this->actingAs($master)->get('/facturi-primite')->assertOk();
    $invoices = $response->viewData('page')['props']['invoices']['data'];

    expect($invoices)->toHaveCount(3);
});

it('limits non-master facturi primite to their department + unassigned furnizori', function () {
    [$user, $deptA] = makeSupervisor('A');
    [, $deptB] = makeSupervisor('B');

    $partnerA = Partner::factory()->furnizor()->create();
    $partnerA->departments()->attach($deptA->id);

    $partnerB = Partner::factory()->furnizor()->create();
    $partnerB->departments()->attach($deptB->id);

    $partnerNone = Partner::factory()->furnizor()->create();

    $invoiceA = Invoice::factory()->create([
        'company_id' => $partnerA->company_id,
        'partner_id' => $partnerA->id,
        'partener_type' => 'furnizor',
    ]);
    $invoiceB = Invoice::factory()->create([
        'company_id' => $partnerB->company_id,
        'partner_id' => $partnerB->id,
        'partener_type' => 'furnizor',
    ]);
    $invoiceNone = Invoice::factory()->create([
        'company_id' => $partnerNone->company_id,
        'partner_id' => $partnerNone->id,
        'partener_type' => 'furnizor',
    ]);

    $response = $this->actingAs($user)->get('/facturi-primite')->assertOk();
    $ids = collect($response->viewData('page')['props']['invoices']['data'])->pluck('id')->all();

    expect($ids)->toContain($invoiceA->id, $invoiceNone->id)
        ->not->toContain($invoiceB->id);
});

it('blocks non-master from showing a furnizor invoice outside their department', function () {
    [$user] = makeSupervisor('A');
    [, $deptB] = makeSupervisor('B');

    $partner = Partner::factory()->furnizor()->create();
    $partner->departments()->attach($deptB->id);

    $invoice = Invoice::factory()->create([
        'company_id' => $partner->company_id,
        'partner_id' => $partner->id,
        'partener_type' => 'furnizor',
    ]);

    $this->actingAs($user)->get("/facturi/{$invoice->id}")->assertForbidden();
});

it('blocks non-master from showing a client invoice', function () {
    [$user] = makeSupervisor();

    $partner = Partner::factory()->create(['is_furnizor' => false, 'is_client' => true]);
    $invoice = Invoice::factory()->create([
        'company_id' => $partner->company_id,
        'partner_id' => $partner->id,
        'partener_type' => 'client',
    ]);

    $this->actingAs($user)->get("/facturi/{$invoice->id}")->assertForbidden();
});
