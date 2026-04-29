<?php

use App\Models\Department;
use App\Models\Invoice;
use App\Models\InvoiceApproval;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeFurnizorInvoice(array $responsabilDepartments = []): Invoice
{
    $partner = Partner::factory()->furnizor()->create();
    $partner->departments()->attach(collect($responsabilDepartments)->pluck('id')->all());

    return Invoice::factory()->create([
        'company_id' => $partner->company_id,
        'partner_id' => $partner->id,
        'partener_type' => 'furnizor',
    ]);
}

it('lets a responsabil mark a furnizor invoice as ok on behalf of their department', function () {
    $dept = Department::create(['name' => 'Op', 'type' => Department::TYPE_RESPONSABIL]);
    $user = User::factory()->create();
    $dept->members()->attach($user->id);

    $invoice = makeFurnizorInvoice([$dept]);

    $this->actingAs($user)
        ->post("/invoices/{$invoice->id}/approve", ['department_id' => $dept->id])
        ->assertRedirect();

    expect(InvoiceApproval::where('invoice_id', $invoice->id)->count())->toBe(1);
    expect($invoice->fresh()->responsabili_approved_at)->not->toBeNull();
    expect($invoice->fresh()->is_fully_approved)->toBeFalse();
});

it('only flips responsabili_approved_at once every assigned responsabil dept has approved', function () {
    $dept1 = Department::create(['name' => 'Op', 'type' => Department::TYPE_RESPONSABIL]);
    $dept2 = Department::create(['name' => 'Finance', 'type' => Department::TYPE_RESPONSABIL]);
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $dept1->members()->attach($user1->id);
    $dept2->members()->attach($user2->id);

    $invoice = makeFurnizorInvoice([$dept1, $dept2]);

    $this->actingAs($user1)
        ->post("/invoices/{$invoice->id}/approve", ['department_id' => $dept1->id]);
    expect($invoice->fresh()->responsabili_approved_at)->toBeNull();

    $this->actingAs($user2)
        ->post("/invoices/{$invoice->id}/approve", ['department_id' => $dept2->id]);
    expect($invoice->fresh()->responsabili_approved_at)->not->toBeNull();
});

it('blocks an ordonator approval before responsabili are done', function () {
    $resp = Department::create(['name' => 'Op', 'type' => Department::TYPE_RESPONSABIL]);
    $ordonator = Department::create(['name' => 'Direcțiune', 'type' => Department::TYPE_ORDONATOR]);
    $ordonatorUser = User::factory()->create();
    $ordonator->members()->attach($ordonatorUser->id);

    $invoice = makeFurnizorInvoice([$resp]);

    $this->actingAs($ordonatorUser)
        ->post("/invoices/{$invoice->id}/approve", ['department_id' => $ordonator->id])
        ->assertSessionHasErrors('department_id');

    expect($invoice->fresh()->is_fully_approved)->toBeFalse();
});

it('flips is_fully_approved once an ordonator approves after responsabili', function () {
    $resp = Department::create(['name' => 'Op', 'type' => Department::TYPE_RESPONSABIL]);
    $ordonator = Department::create(['name' => 'Direcțiune', 'type' => Department::TYPE_ORDONATOR]);
    $respUser = User::factory()->create();
    $ordonatorUser = User::factory()->create();
    $resp->members()->attach($respUser->id);
    $ordonator->members()->attach($ordonatorUser->id);

    $invoice = makeFurnizorInvoice([$resp]);

    $this->actingAs($respUser)
        ->post("/invoices/{$invoice->id}/approve", ['department_id' => $resp->id]);
    $this->actingAs($ordonatorUser)
        ->post("/invoices/{$invoice->id}/approve", ['department_id' => $ordonator->id]);

    expect($invoice->fresh()->is_fully_approved)->toBeTrue();
    expect($invoice->fresh()->fully_approved_at)->not->toBeNull();
});

it('rejects an approval from a user outside the chosen department', function () {
    $resp = Department::create(['name' => 'Op', 'type' => Department::TYPE_RESPONSABIL]);
    $outsider = User::factory()->create();

    $invoice = makeFurnizorInvoice([$resp]);

    $this->actingAs($outsider)
        ->post("/invoices/{$invoice->id}/approve", ['department_id' => $resp->id])
        ->assertForbidden();
});

it('rejects an approval from a responsabil whose dept is not assigned to the furnizor', function () {
    $assigned = Department::create(['name' => 'Op', 'type' => Department::TYPE_RESPONSABIL]);
    $unassigned = Department::create(['name' => 'Compliance', 'type' => Department::TYPE_RESPONSABIL]);
    $user = User::factory()->create();
    $unassigned->members()->attach($user->id);

    $invoice = makeFurnizorInvoice([$assigned]);

    $this->actingAs($user)
        ->post("/invoices/{$invoice->id}/approve", ['department_id' => $unassigned->id])
        ->assertForbidden();
});
