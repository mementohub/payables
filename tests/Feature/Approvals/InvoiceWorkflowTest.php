<?php

use App\Models\Department;
use App\Models\Invoice;
use App\Models\InvoiceDepartmentApproval;
use App\Models\InvoiceDetail;
use App\Models\InvoiceEvent;
use App\Models\User;
use App\Services\Approvals\InvoiceWorkflow;
use App\Services\Routing\BookingResolver;
use App\Services\Routing\DepartmentAssigner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;

beforeEach(function () {
    $this->departments = Department::query()->whereNotNull('code')->get()->keyBy('code');

    $this->mock(BookingResolver::class, function (MockInterface $mock) {
        $mock->shouldReceive('resolve')->andReturnUsing(fn (array $ids) => collect($ids)->mapWithKeys(fn (int $id) => [$id => [
            'department' => $id === 1111111 ? 'charters' : 'senior_voyage', 'channel' => 'sales_b2b', 'detail' => "Rezervarea {$id}",
        ]])->all());
    });

    $this->charters = User::factory()->create(['name' => 'Șef Charters']);
    $this->charters->departments()->attach($this->departments['charters']);
    $this->senior = User::factory()->create(['name' => 'Șef Senior Voyage']);
    $this->senior->departments()->attach($this->departments['senior_voyage']);
    $this->boss = User::factory()->withRoles('top_management')->create();
});

/**
 * An open invoice of 1000 whose lines belong 700 to Charters and 300 to
 * Senior Voyage, routed.
 */
function workflowSharedInvoice(): Invoice
{
    $invoice = Invoice::factory()->create(['val_mon' => 1000]);
    InvoiceDetail::factory()->create(['invoice_id' => $invoice->id, 'scv' => 1, 'pret' => 700, 'com_int' => '1111111']);
    InvoiceDetail::factory()->create(['invoice_id' => $invoice->id, 'scv' => 2, 'pret' => 300, 'com_int' => '2222222']);
    app(DepartmentAssigner::class)->assign(collect([$invoice]));

    return $invoice->fresh();
}

test('an invoice waits for every department that owns part of it, then for Top Management', function () {
    $invoice = workflowSharedInvoice();
    $workflow = app(InvoiceWorkflow::class);

    expect($invoice->approval_status)->toBe('department')
        ->and($invoice->approval_track)->toBe('run')
        ->and(InvoiceDepartmentApproval::query()->where('invoice_id', $invoice->id)->pluck('amount', 'department_id')->all())
        ->toEqual([$this->departments['charters']->id => 700, $this->departments['senior_voyage']->id => 300]);

    $workflow->decide($invoice, $this->departments['charters'], $this->charters, 'approved');
    expect($invoice->fresh()->approval_status)->toBe('department');

    $workflow->decide($invoice->fresh(), $this->departments['senior_voyage'], $this->senior, 'approved', 'ok');
    expect($invoice->fresh()->approval_status)->toBe('final');

    $workflow->decideFinal($invoice->fresh(), $this->boss, 'approved');

    expect($invoice->fresh()->approval_status)->toBe('approved')
        ->and($invoice->fresh()->final_decided_by_id)->toBe($this->boss->id)
        ->and(InvoiceEvent::query()->where('invoice_id', $invoice->id)->pluck('type')->all())
        ->toBe(['department_approved', 'department_approved', 'final_approved']);
});

test('only the members of a department decide for it, and only Top Management decides last', function () {
    $invoice = workflowSharedInvoice();
    $workflow = app(InvoiceWorkflow::class);

    expect(fn () => $workflow->decide($invoice, $this->departments['charters'], $this->senior, 'approved'))->toThrow(AuthorizationException::class)
        ->and(fn () => $workflow->decideFinal($invoice, $this->charters, 'approved'))->toThrow(AuthorizationException::class)
        // Not before every department approved.
        ->and(fn () => $workflow->decideFinal($invoice, $this->boss, 'approved'))->toThrow(ValidationException::class);

    // An admin speaks for every department.
    $workflow->decide($invoice, $this->departments['charters'], User::factory()->withRoles('admin')->create(), 'approved');
    expect(InvoiceDepartmentApproval::query()->where('invoice_id', $invoice->id)->where('status', 'approved')->count())->toBe(1);
});

test('a dispute needs a reason and stops the invoice; a postponement needs a future date', function () {
    $invoice = workflowSharedInvoice();
    $workflow = app(InvoiceWorkflow::class);

    expect(fn () => $workflow->decide($invoice, $this->departments['charters'], $this->charters, 'disputed'))->toThrow(ValidationException::class)
        ->and(fn () => $workflow->decide($invoice, $this->departments['charters'], $this->charters, 'postponed', until: now()->subDay()))->toThrow(ValidationException::class);

    $workflow->decide($invoice, $this->departments['senior_voyage'], $this->senior, 'postponed', until: now()->addWeeks(2));
    expect($invoice->fresh()->approval_status)->toBe('postponed')
        ->and($invoice->fresh()->postponed_until->toDateString())->toBe(now()->addWeeks(2)->toDateString());

    $workflow->decide($invoice->fresh(), $this->departments['charters'], $this->charters, 'disputed', 'Rotația nu a operat');
    expect($invoice->fresh()->approval_status)->toBe('disputed');

    // Reopened, the disputing and postponing departments decide again.
    $workflow->reopen($invoice->fresh(), $this->boss, 'Rezolvat cu furnizorul');
    expect($invoice->fresh()->approval_status)->toBe('department')
        ->and(InvoiceDepartmentApproval::query()->where('invoice_id', $invoice->id)->pluck('status')->unique()->all())->toBe(['pending']);
});

test('when the routing changes, the approvals follow it', function () {
    $invoice = workflowSharedInvoice();
    $workflow = app(InvoiceWorkflow::class);
    $workflow->decide($invoice, $this->departments['charters'], $this->charters, 'approved');

    // Finance sends the Senior Voyage line to Charters as well.
    app(DepartmentAssigner::class)->assignManually($invoice, $this->departments['charters'], $this->boss->id, [2]);

    $approvals = InvoiceDepartmentApproval::query()->where('invoice_id', $invoice->id)->get();

    expect($approvals)->toHaveCount(1)
        ->and($approvals->first()->only(['department_id', 'amount', 'status']))->toEqual(['department_id' => $this->departments['charters']->id, 'amount' => 1000.0, 'status' => 'approved'])
        ->and($invoice->fresh()->approval_status)->toBe('final');
});

test('an invoice with lines no rule could route waits for Finance, and paid or negative ones stay out of the flow', function () {
    $invoice = Invoice::factory()->create();
    InvoiceDetail::factory()->create(['invoice_id' => $invoice->id, 'scv' => 1, 'account' => '401']);
    $paid = Invoice::factory()->create(['val_mon' => 500, 'val_mon_paid' => 500]);
    InvoiceDetail::factory()->create(['invoice_id' => $paid->id, 'scv' => 1, 'com_int' => '1111111']);

    app(DepartmentAssigner::class)->assign(collect([$invoice, $paid]));

    expect($invoice->fresh()->approval_status)->toBe('routing')
        ->and($paid->fresh()->approval_status)->toBeNull()
        ->and(InvoiceDepartmentApproval::query()->where('invoice_id', $paid->id)->exists())->toBeFalse();
});
