<?php

use App\Models\Department;
use App\Models\Invoice;
use App\Models\InvoiceDepartmentApproval;
use App\Models\InvoiceEvent;
use App\Models\Partner;
use App\Models\User;
use App\Services\Approvals\InvoiceWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeFurnizorInvoiceForTimeline(): Invoice
{
    $partner = Partner::factory()->furnizor()->create();

    return Invoice::factory()->create([
        'company_id' => $partner->company_id,
        'partner_id' => $partner->id,
        'partener_type' => 'furnizor',
    ]);
}

it('stores comments as events visible to anyone with access', function () {
    $user = User::factory()->withRoles('finance')->create();
    $invoice = makeFurnizorInvoiceForTimeline();

    $this->actingAs($user)
        ->post("/invoices/{$invoice->id}/comments", ['body' => 'verifica TVA'])
        ->assertRedirect();

    $comment = InvoiceEvent::where('invoice_id', $invoice->id)->comments()->first();

    expect($comment)->not->toBeNull()
        ->and($comment->body)->toBe('verifica TVA')
        ->and($comment->user_id)->toBe($user->id);
});

it('rejects empty comments', function () {
    $user = User::factory()->withRoles('finance')->create();
    $invoice = makeFurnizorInvoiceForTimeline();

    $this->actingAs($user)
        ->post("/invoices/{$invoice->id}/comments", ['body' => ''])
        ->assertSessionHasErrors('body');
});

it('returns the timeline ordered most-recent-first on the show endpoint', function () {
    $department = Department::query()->where('code', 'marketing')->sole();
    $user = User::factory()->withRoles('finance')->create();
    $user->departments()->attach($department);
    $invoice = makeFurnizorInvoiceForTimeline();
    $invoice->forceFill(['assignment_state' => 'assigned', 'approval_status' => 'department'])->save();
    InvoiceDepartmentApproval::query()->create(['invoice_id' => $invoice->id, 'department_id' => $department->id, 'amount' => 1000]);

    $this->actingAs($user)->post("/invoices/{$invoice->id}/comments", ['body' => 'first']);
    app(InvoiceWorkflow::class)->decide($invoice, $department, $user, 'approved');

    $response = $this->actingAs($user)->get("/invoices/{$invoice->id}");
    $response->assertOk();

    $timeline = $response->viewData('page')['props']['invoice']['timeline'];

    expect($timeline)->toHaveCount(2)
        ->and($timeline[0]['type'])->toBe('department_approved')
        ->and($timeline[1]['type'])->toBe(InvoiceEvent::TYPE_COMMENTED)
        ->and($response->viewData('page')['props']['invoice']['workflow']['departments'][0]['status'])->toBe('approved');
});
