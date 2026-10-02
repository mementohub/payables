<?php

use App\Models\Department;
use App\Models\Invoice;
use App\Models\InvoiceDetail;
use App\Models\User;
use App\Notifications\InvoiceDisputed;
use App\Notifications\InvoicesRouted;
use App\Services\Approvals\InvoiceWorkflow;
use App\Services\Routing\BookingResolver;
use App\Services\Routing\DepartmentAssigner;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;

beforeEach(function () {
    Notification::fake();

    $this->departments = Department::query()->whereNotNull('code')->get()->keyBy('code');

    $this->mock(BookingResolver::class, function (MockInterface $mock) {
        $mock->shouldReceive('resolve')->andReturnUsing(fn (array $ids) => collect($ids)->mapWithKeys(fn (int $id) => [$id => [
            'department' => 'charters', 'channel' => 'sales_b2b', 'detail' => "Rezervarea {$id}",
        ]])->all());
    });

    $this->charters = $this->departments['charters'];
    $this->head = User::factory()->create(['name' => 'Șef Charters']);
    $this->head->departments()->attach($this->charters);
    $this->member = User::factory()->create(['name' => 'Om din Charters']);
    $this->member->departments()->attach($this->charters);
    $this->boss = User::factory()->withRoles('top_management')->create();
    $this->finance = User::factory()->withRoles('finance')->create();
});

function routedInvoice(): Invoice
{
    $invoice = Invoice::factory()->create(['val_mon' => 1000]);
    InvoiceDetail::factory()->create(['invoice_id' => $invoice->id, 'scv' => 1, 'pret' => 1000, 'com_int' => '1111111']);
    app(DepartmentAssigner::class)->assign(collect([$invoice]));

    return $invoice->fresh();
}

test('contesting an invoice tells Top Management who, why and for which department', function () {
    $invoice = routedInvoice();

    app(InvoiceWorkflow::class)->decide($invoice, $this->charters, $this->head, 'disputed', 'Prețul nu e cel din contract.');

    Notification::assertSentTo($this->boss, InvoiceDisputed::class, function (InvoiceDisputed $notification) use ($invoice) {
        $mail = $notification->toMail($this->boss);

        return $notification->invoice->is($invoice)
            && $notification->comment === 'Prețul nu e cel din contract.'
            && $notification->department?->is($this->charters)
            && str_contains($mail->subject, $invoice->nr_doc)
            && collect($mail->introLines)->contains(fn (string $line) => str_contains($line, 'Prețul nu e cel din contract.'));
    });

    // Cine contestă nu-și primește propria veste, iar restul lumii n-are treabă.
    Notification::assertNotSentTo($this->head, InvoiceDisputed::class);
    Notification::assertNotSentTo($this->finance, InvoiceDisputed::class);
});

test('a Top Management dispute does not come back to the one who made it', function () {
    $invoice = routedInvoice();
    $other = User::factory()->withRoles('top_management')->create();

    app(InvoiceWorkflow::class)->decide($invoice, $this->charters, $this->head, 'approved');
    app(InvoiceWorkflow::class)->decideFinal($invoice->fresh(), $this->boss, 'disputed', 'Nu plătim acum.');

    Notification::assertSentTo($other, InvoiceDisputed::class);
    Notification::assertNotSentTo($this->boss, InvoiceDisputed::class);
});

test('approving an invoice sends nobody anything', function () {
    $invoice = routedInvoice();

    app(InvoiceWorkflow::class)->decide($invoice, $this->charters, $this->head, 'approved');

    Notification::assertNothingSent();
});

test('routing an invoice to a department tells its head', function () {
    $this->charters->update(['head_user_id' => $this->head->id]);
    $invoice = Invoice::factory()->create(['nr_doc' => 'F-77', 'val_mon' => 2500]);

    $this->actingAs($this->finance)
        ->post('/routing/invoices/'.$invoice->id.'/assign', ['department_id' => $this->charters->id])
        ->assertRedirect();

    Notification::assertSentTo($this->head, InvoicesRouted::class, function (InvoicesRouted $notification) {
        return $notification->department->is($this->charters)
            && count($notification->invoices) === 1
            && $notification->invoices[0]['nr_doc'] === 'F-77'
            && $notification->from === null;
    });

    // Cu un șef pus, ceilalți membri nu mai sunt deranjați.
    Notification::assertNotSentTo($this->member, InvoicesRouted::class);
});

test('without a head, everyone in the department hears about it', function () {
    $invoice = Invoice::factory()->create(['val_mon' => 300]);

    $this->actingAs($this->finance)
        ->post('/routing/invoices/'.$invoice->id.'/assign', ['department_id' => $this->charters->id])
        ->assertRedirect();

    Notification::assertSentTo($this->head, InvoicesRouted::class);
    Notification::assertSentTo($this->member, InvoicesRouted::class);
});

test('a stack routed at once makes one mail, not one per invoice', function () {
    $this->charters->update(['head_user_id' => $this->head->id]);
    $invoices = Invoice::factory()->count(3)->create();

    $this->actingAs($this->finance)
        ->post('/routing/assign', [
            'invoice_ids' => $invoices->pluck('id')->all(),
            'department_id' => $this->charters->id,
        ])
        ->assertRedirect();

    Notification::assertSentToTimes($this->head, InvoicesRouted::class, 1);
    Notification::assertSentTo($this->head, InvoicesRouted::class, fn (InvoicesRouted $n) => count($n->invoices) === 3);
});

test('an invoice sent back to another department tells that department, with the reason', function () {
    $senior = $this->departments['senior_voyage'];
    $seniorHead = User::factory()->create();
    $senior->update(['head_user_id' => $seniorHead->id]);
    $seniorHead->departments()->attach($senior);

    $invoice = routedInvoice();

    $this->actingAs($this->head)
        ->post('/approvals/redirect', [
            'invoice_ids' => [$invoice->id],
            'department_id' => $this->charters->id,
            'to_department_id' => $senior->id,
            'comment' => 'Este un circuit, nu un charter.',
        ])
        ->assertRedirect();

    Notification::assertSentTo($seniorHead, InvoicesRouted::class, function (InvoicesRouted $notification) use ($senior) {
        return $notification->department->is($senior)
            && $notification->from?->is($this->charters)
            && $notification->reason === 'Este un circuit, nu un charter.';
    });
});

test('the config switch stops the mails without touching the flow', function () {
    config(['notifications.enabled' => false]);
    $invoice = routedInvoice();

    app(InvoiceWorkflow::class)->decide($invoice, $this->charters, $this->head, 'disputed', 'Nu e a noastră.');

    expect($invoice->fresh()->approval_status)->toBe('disputed');
    Notification::assertNothingSent();
});
