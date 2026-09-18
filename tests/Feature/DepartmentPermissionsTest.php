<?php

use App\Models\Invoice;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('sends the old issued-invoices and clients pages to their supplier counterparts', function () {
    $this->actingAs(User::factory()->create())
        ->get('/invoices/issued')
        ->assertRedirect('/invoices/received');

    $this->actingAs(User::factory()->create())
        ->get('/clients')
        ->assertRedirect('/suppliers');
});

it('lets any authenticated user open the AI assistant', function () {
    $this->actingAs(User::factory()->create())
        ->get('/ai-assistant')
        ->assertOk();
});

it('shows every received invoice on facturi primite to any user', function () {
    foreach (range(1, 3) as $i) {
        $partner = Partner::factory()->furnizor()->create();
        Invoice::factory()->create(['company_id' => $partner->company_id, 'partner_id' => $partner->id, 'partener_type' => 'furnizor']);
    }

    $response = $this->actingAs(User::factory()->create())->get('/invoices/received')->assertOk();

    expect($response->viewData('page')['props']['invoices']['data'])->toHaveCount(3);
});

it('lets any user view a supplier invoice whatever department owns it', function () {
    $partner = Partner::factory()->furnizor()->create();
    $invoice = Invoice::factory()->create(['company_id' => $partner->company_id, 'partner_id' => $partner->id, 'partener_type' => 'furnizor']);

    $this->actingAs(User::factory()->create())->get("/invoices/{$invoice->id}")->assertOk();
});

it('keeps users, departments, companies and maintenance to administrators', function (string $url) {
    $this->actingAs(User::factory()->withRoles(['finance', 'top_management'])->create())->get($url)->assertForbidden();
    $this->actingAs(User::factory()->withRoles('admin')->create())->get($url)->assertOk();
})->with(['/users', '/departments', '/companies', '/maintenance', '/database-status']);

it('lets an admin hand out roles, but never take away the last admin', function () {
    $admin = User::factory()->withRoles('admin')->create();
    $other = User::factory()->create();

    $this->actingAs($admin)->put("/users/{$other->id}", ['name' => $other->name, 'email' => $other->email, 'roles' => ['finance', 'treasury']])->assertRedirect();
    expect($other->fresh()->roles)->toBe(['finance', 'treasury']);

    $this->actingAs($admin)->put("/users/{$admin->id}", ['name' => $admin->name, 'email' => $admin->email, 'roles' => []])->assertRedirect();
    expect($admin->fresh()->isAdmin())->toBeTrue();
});
