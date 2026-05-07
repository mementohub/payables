<?php

use App\Models\Department;
use App\Models\Invoice;
use App\Models\InvoiceApproval;
use App\Models\InvoiceEvent;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeFurnizorInvoiceForTimeline(array $responsabilDepartments = []): Invoice
{
    $partner = Partner::factory()->furnizor()->create();
    $partner->departments()->attach(collect($responsabilDepartments)->pluck('id')->all());

    return Invoice::factory()->create([
        'company_id' => $partner->company_id,
        'partner_id' => $partner->id,
        'partener_type' => 'furnizor',
    ]);
}

it('writes an approved event when a responsabil approves', function () {
    $dept = Department::create(['name' => 'Op', 'type' => Department::TYPE_RESPONSABIL]);
    $user = User::factory()->create();
    $dept->members()->attach($user->id);

    $invoice = makeFurnizorInvoiceForTimeline([$dept]);

    $this->actingAs($user)
        ->post("/invoices/{$invoice->id}/approve", ['department_id' => $dept->id])
        ->assertRedirect();

    $event = InvoiceEvent::where('invoice_id', $invoice->id)->first();

    expect($event)->not->toBeNull()
        ->and($event->type)->toBe(InvoiceEvent::TYPE_APPROVED)
        ->and($event->user_id)->toBe($user->id)
        ->and($event->department_id)->toBe($dept->id)
        ->and($event->payload['role'])->toBe(InvoiceApproval::ROLE_RESPONSABIL);
});

it('lets the original approver revoke their approval and clears responsabili_approved_at', function () {
    $dept = Department::create(['name' => 'Op', 'type' => Department::TYPE_RESPONSABIL]);
    $user = User::factory()->create();
    $dept->members()->attach($user->id);

    $invoice = makeFurnizorInvoiceForTimeline([$dept]);

    $this->actingAs($user)
        ->post("/invoices/{$invoice->id}/approve", ['department_id' => $dept->id]);

    $approval = InvoiceApproval::where('invoice_id', $invoice->id)->firstOrFail();
    expect($invoice->fresh()->responsabili_approved_at)->not->toBeNull();

    $this->actingAs($user)
        ->post("/invoices/{$invoice->id}/approvals/{$approval->id}/revoke", ['reason' => 'gresit'])
        ->assertRedirect();

    $approval->refresh();
    expect($approval->revoked_at)->not->toBeNull()
        ->and($approval->revoked_by_id)->toBe($user->id)
        ->and($approval->revoke_reason)->toBe('gresit')
        ->and($invoice->fresh()->responsabili_approved_at)->toBeNull();

    expect(InvoiceEvent::where('invoice_id', $invoice->id)
        ->where('type', InvoiceEvent::TYPE_APPROVAL_REVOKED)
        ->count())->toBe(1);
});

it('clears is_fully_approved when an ordonator revokes their own approval', function () {
    $resp = Department::create(['name' => 'Op', 'type' => Department::TYPE_RESPONSABIL]);
    $ord = Department::create(['name' => 'Direcțiune', 'type' => Department::TYPE_ORDONATOR]);
    $respUser = User::factory()->create();
    $ordUser = User::factory()->create();
    $resp->members()->attach($respUser->id);
    $ord->members()->attach($ordUser->id);

    $invoice = makeFurnizorInvoiceForTimeline([$resp]);

    $this->actingAs($respUser)
        ->post("/invoices/{$invoice->id}/approve", ['department_id' => $resp->id]);
    $this->actingAs($ordUser)
        ->post("/invoices/{$invoice->id}/approve", ['department_id' => $ord->id]);

    expect($invoice->fresh()->is_fully_approved)->toBeTrue();

    $ordApproval = InvoiceApproval::where('invoice_id', $invoice->id)
        ->where('role', InvoiceApproval::ROLE_ORDONATOR)
        ->firstOrFail();

    $this->actingAs($ordUser)
        ->post("/invoices/{$invoice->id}/approvals/{$ordApproval->id}/revoke", ['reason' => 'eroare contabila'])
        ->assertRedirect();

    expect($invoice->fresh()->is_fully_approved)->toBeFalse()
        ->and($invoice->fresh()->fully_approved_at)->toBeNull()
        ->and($invoice->fresh()->responsabili_approved_at)->not->toBeNull();
});

it('blocks revoke from a different user than the original approver', function () {
    $dept = Department::create(['name' => 'Op', 'type' => Department::TYPE_RESPONSABIL]);
    $user = User::factory()->create();
    $other = User::factory()->create();
    $dept->members()->attach([$user->id, $other->id]);

    $invoice = makeFurnizorInvoiceForTimeline([$dept]);

    $this->actingAs($user)
        ->post("/invoices/{$invoice->id}/approve", ['department_id' => $dept->id]);
    $approval = InvoiceApproval::where('invoice_id', $invoice->id)->firstOrFail();

    $this->actingAs($other)
        ->post("/invoices/{$invoice->id}/approvals/{$approval->id}/revoke", ['reason' => 'mute'])
        ->assertForbidden();
});

it('requires a reason on revoke', function () {
    $dept = Department::create(['name' => 'Op', 'type' => Department::TYPE_RESPONSABIL]);
    $user = User::factory()->create();
    $dept->members()->attach($user->id);

    $invoice = makeFurnizorInvoiceForTimeline([$dept]);

    $this->actingAs($user)
        ->post("/invoices/{$invoice->id}/approve", ['department_id' => $dept->id]);
    $approval = InvoiceApproval::where('invoice_id', $invoice->id)->firstOrFail();

    $this->actingAs($user)
        ->post("/invoices/{$invoice->id}/approvals/{$approval->id}/revoke", ['reason' => ''])
        ->assertSessionHasErrors('reason');
});

it('lets the same user re-approve after revoking', function () {
    $dept = Department::create(['name' => 'Op', 'type' => Department::TYPE_RESPONSABIL]);
    $user = User::factory()->create();
    $dept->members()->attach($user->id);

    $invoice = makeFurnizorInvoiceForTimeline([$dept]);

    $this->actingAs($user)
        ->post("/invoices/{$invoice->id}/approve", ['department_id' => $dept->id]);
    $approval = InvoiceApproval::where('invoice_id', $invoice->id)->firstOrFail();
    $this->actingAs($user)
        ->post("/invoices/{$invoice->id}/approvals/{$approval->id}/revoke", ['reason' => 'gresit']);

    $this->actingAs($user)
        ->post("/invoices/{$invoice->id}/approve", ['department_id' => $dept->id])
        ->assertRedirect();

    expect(InvoiceApproval::where('invoice_id', $invoice->id)->active()->count())->toBe(1)
        ->and($invoice->fresh()->responsabili_approved_at)->not->toBeNull();
});

it('stores comments as events visible to anyone with access', function () {
    $user = User::factory()->create();
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
    $user = User::factory()->create();
    $invoice = makeFurnizorInvoiceForTimeline();

    $this->actingAs($user)
        ->post("/invoices/{$invoice->id}/comments", ['body' => ''])
        ->assertSessionHasErrors('body');
});

it('lets a plati department member mark the invoice as paid', function () {
    $plati = Department::create(['name' => 'Plăți', 'type' => Department::TYPE_PLATI]);
    $user = User::factory()->create();
    $plati->members()->attach($user->id);

    $invoice = makeFurnizorInvoiceForTimeline();

    $this->actingAs($user)
        ->post("/invoices/{$invoice->id}/payment-status", ['status' => 'paid', 'note' => 'OP 123'])
        ->assertRedirect();

    $invoice->refresh();
    expect($invoice->payment_status)->toBe('paid')
        ->and($invoice->payment_status_updated_by_id)->toBe($user->id);

    $event = InvoiceEvent::where('invoice_id', $invoice->id)
        ->where('type', InvoiceEvent::TYPE_PAYMENT_STATUS_CHANGED)
        ->first();
    expect($event)->not->toBeNull()
        ->and($event->payload['from'])->toBe('unpaid')
        ->and($event->payload['to'])->toBe('paid')
        ->and($event->body)->toBe('OP 123');
});

it('blocks payment status updates from non-plati users', function () {
    $resp = Department::create(['name' => 'Op', 'type' => Department::TYPE_RESPONSABIL]);
    $user = User::factory()->create();
    $resp->members()->attach($user->id);

    $invoice = makeFurnizorInvoiceForTimeline();

    $this->actingAs($user)
        ->post("/invoices/{$invoice->id}/payment-status", ['status' => 'paid'])
        ->assertForbidden();

    expect($invoice->fresh()->payment_status)->toBe('unpaid');
});

it('rejects unknown payment statuses', function () {
    $plati = Department::create(['name' => 'Plăți', 'type' => Department::TYPE_PLATI]);
    $user = User::factory()->create();
    $plati->members()->attach($user->id);

    $invoice = makeFurnizorInvoiceForTimeline();

    $this->actingAs($user)
        ->post("/invoices/{$invoice->id}/payment-status", ['status' => 'whatever'])
        ->assertSessionHasErrors('status');
});

it('returns the timeline ordered most-recent-first on the show endpoint', function () {
    $dept = Department::create(['name' => 'Op', 'type' => Department::TYPE_RESPONSABIL]);
    $plati = Department::create(['name' => 'Plăți', 'type' => Department::TYPE_PLATI]);
    $user = User::factory()->create();
    $platiUser = User::factory()->create();
    $dept->members()->attach($user->id);
    $plati->members()->attach($platiUser->id);

    $invoice = makeFurnizorInvoiceForTimeline([$dept]);

    $this->actingAs($user)
        ->post("/invoices/{$invoice->id}/comments", ['body' => 'first']);
    $this->actingAs($user)
        ->post("/invoices/{$invoice->id}/approve", ['department_id' => $dept->id]);
    $this->actingAs($platiUser)
        ->post("/invoices/{$invoice->id}/payment-status", ['status' => 'partial']);

    $response = $this->actingAs($user)->get("/invoices/{$invoice->id}");
    $response->assertOk();

    $timeline = $response->viewData('page')['props']['invoice']['timeline'];

    expect($timeline)->toHaveCount(3)
        ->and($timeline[0]['type'])->toBe(InvoiceEvent::TYPE_PAYMENT_STATUS_CHANGED)
        ->and($timeline[1]['type'])->toBe(InvoiceEvent::TYPE_APPROVED)
        ->and($timeline[2]['type'])->toBe(InvoiceEvent::TYPE_COMMENTED);
});
