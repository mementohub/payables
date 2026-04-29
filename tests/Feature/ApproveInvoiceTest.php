<?php

use App\Models\Department;
use App\Models\Invoice;
use App\Models\InvoiceApproval;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeFurnizorInvoice(array $supervisorDepartments = []): Invoice
{
    $partner = Partner::factory()->furnizor()->create();
    $partner->departments()->attach(collect($supervisorDepartments)->pluck('id')->all());

    return Invoice::factory()->create([
        'company_id' => $partner->company_id,
        'partner_id' => $partner->id,
        'partener_type' => 'furnizor',
    ]);
}

it('lets a supervisor mark a furnizor invoice as ok on behalf of their department', function () {
    $dept = Department::create(['name' => 'Op', 'type' => Department::TYPE_SUPERVISOR]);
    $user = User::factory()->create();
    $dept->members()->attach($user->id);

    $invoice = makeFurnizorInvoice([$dept]);

    $this->actingAs($user)
        ->post("/facturi/{$invoice->id}/approve", ['department_id' => $dept->id])
        ->assertRedirect();

    expect(InvoiceApproval::where('invoice_id', $invoice->id)->count())->toBe(1);
    expect($invoice->fresh()->supervisors_approved_at)->not->toBeNull();
    expect($invoice->fresh()->is_fully_approved)->toBeFalse();
});

it('only flips supervisors_approved_at once every assigned supervisor dept has approved', function () {
    $dept1 = Department::create(['name' => 'Op', 'type' => Department::TYPE_SUPERVISOR]);
    $dept2 = Department::create(['name' => 'Finance', 'type' => Department::TYPE_SUPERVISOR]);
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $dept1->members()->attach($user1->id);
    $dept2->members()->attach($user2->id);

    $invoice = makeFurnizorInvoice([$dept1, $dept2]);

    $this->actingAs($user1)
        ->post("/facturi/{$invoice->id}/approve", ['department_id' => $dept1->id]);
    expect($invoice->fresh()->supervisors_approved_at)->toBeNull();

    $this->actingAs($user2)
        ->post("/facturi/{$invoice->id}/approve", ['department_id' => $dept2->id]);
    expect($invoice->fresh()->supervisors_approved_at)->not->toBeNull();
});

it('blocks a master approval before supervisors are done', function () {
    $sup = Department::create(['name' => 'Op', 'type' => Department::TYPE_SUPERVISOR]);
    $master = Department::create(['name' => 'Direcțiune', 'type' => Department::TYPE_MASTER]);
    $masterUser = User::factory()->create();
    $master->members()->attach($masterUser->id);

    $invoice = makeFurnizorInvoice([$sup]);

    $this->actingAs($masterUser)
        ->post("/facturi/{$invoice->id}/approve", ['department_id' => $master->id])
        ->assertSessionHasErrors('department_id');

    expect($invoice->fresh()->is_fully_approved)->toBeFalse();
});

it('flips is_fully_approved once a master approves after supervisors', function () {
    $sup = Department::create(['name' => 'Op', 'type' => Department::TYPE_SUPERVISOR]);
    $master = Department::create(['name' => 'Direcțiune', 'type' => Department::TYPE_MASTER]);
    $supUser = User::factory()->create();
    $masterUser = User::factory()->create();
    $sup->members()->attach($supUser->id);
    $master->members()->attach($masterUser->id);

    $invoice = makeFurnizorInvoice([$sup]);

    $this->actingAs($supUser)
        ->post("/facturi/{$invoice->id}/approve", ['department_id' => $sup->id]);
    $this->actingAs($masterUser)
        ->post("/facturi/{$invoice->id}/approve", ['department_id' => $master->id]);

    expect($invoice->fresh()->is_fully_approved)->toBeTrue();
    expect($invoice->fresh()->fully_approved_at)->not->toBeNull();
});

it('rejects an approval from a user outside the chosen department', function () {
    $sup = Department::create(['name' => 'Op', 'type' => Department::TYPE_SUPERVISOR]);
    $outsider = User::factory()->create();

    $invoice = makeFurnizorInvoice([$sup]);

    $this->actingAs($outsider)
        ->post("/facturi/{$invoice->id}/approve", ['department_id' => $sup->id])
        ->assertForbidden();
});

it('rejects an approval from a supervisor whose dept is not assigned to the furnizor', function () {
    $assigned = Department::create(['name' => 'Op', 'type' => Department::TYPE_SUPERVISOR]);
    $unassigned = Department::create(['name' => 'Compliance', 'type' => Department::TYPE_SUPERVISOR]);
    $user = User::factory()->create();
    $unassigned->members()->attach($user->id);

    $invoice = makeFurnizorInvoice([$assigned]);

    $this->actingAs($user)
        ->post("/facturi/{$invoice->id}/approve", ['department_id' => $unassigned->id])
        ->assertForbidden();
});
