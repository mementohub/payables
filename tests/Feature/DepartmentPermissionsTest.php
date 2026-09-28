<?php

use App\Models\Company;
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

// Asistentul interoghează baza și răspunde cu cifrele companiei, deci ține de
// aceeași regulă ca Rapoartele: Top Management și administratorii.
it('keeps the AI assistant for the people who may see the company figures', function () {
    $this->actingAs(User::factory()->create(['roles' => [User::ROLE_TOP_MANAGEMENT]]))
        ->get('/ai-assistant')
        ->assertOk();

    $this->actingAs(User::factory()->create(['roles' => [User::ROLE_FINANCE]]))
        ->get('/ai-assistant')
        ->assertForbidden();
});

it('shows every received invoice on facturi primite to a user with no department', function () {
    foreach (range(1, 3) as $i) {
        $partner = Partner::factory()->furnizor()->create();
        Invoice::factory()->create(['company_id' => $partner->company_id, 'partner_id' => $partner->id, 'partener_type' => 'furnizor']);
    }

    $response = $this->actingAs(User::factory()->create())->get('/invoices/received')->assertOk();

    expect($response->viewData('page')['props']['invoices']['data'])->toHaveCount(3);
});

it('lets a user with no department view a supplier invoice whatever department owns it', function () {
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

it('keeps the reports to Top Management', function (string $url) {
    // Rapoartele arată venitul, marja și profitul companiei întregi.
    $this->actingAs(User::factory()->withRoles(['finance', 'treasury'])->create())->get($url)->assertForbidden();
    $this->actingAs(User::factory()->create())->get($url)->assertForbidden();

    $this->actingAs(User::factory()->withRoles('top_management')->create())->get($url)->assertOk();
    $this->actingAs(User::factory()->withRoles('admin')->create())->get($url)->assertOk();
})->with(['/reports/pnl', '/reports/opex', '/reports/cash-flow']);

it('guards the report actions too, not just the pages', function () {
    $company = Company::factory()->create();
    $outsider = User::factory()->withRoles('finance')->create();

    $this->actingAs($outsider)->post("/reports/pnl/{$company->id}/refresh")->assertForbidden();
    $this->actingAs($outsider)->get("/reports/pnl/{$company->id}/details?year=2026&saf=4000")->assertForbidden();
    $this->actingAs($outsider)->post('/reports/cash-flow/build')->assertForbidden();
});
