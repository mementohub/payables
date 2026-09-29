<?php

use App\Models\Company;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\InvoiceDetail;
use App\Models\PaymentRunItem;
use App\Models\User;
use App\Services\Approvals\InvoiceWorkflow;
use App\Services\Approvals\PaymentRunService;
use App\Services\Routing\BookingResolver;
use App\Services\Routing\DepartmentAssigner;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;

beforeEach(function () {
    Carbon::setTestNow('2026-09-29 10:00:00');
    $this->departments = Department::query()->whereNotNull('code')->get()->keyBy('code');
    $this->company = Company::factory()->create();

    $this->mock(BookingResolver::class, function (MockInterface $mock) {
        $mock->shouldReceive('resolve')->andReturnUsing(fn (array $ids) => collect($ids)->mapWithKeys(fn (int $id) => [$id => ['department' => 'charters', 'channel' => null, 'detail' => '']])->all());
    });

    $this->head = User::factory()->create();
    $this->head->departments()->attach($this->departments['charters']);
    $this->boss = User::factory()->withRoles(['top_management', 'finance'])->create();
});

function partialInvoice(Company $company, float $value = 1000): Invoice
{
    $invoice = Invoice::factory()->for($company)->create(['val_mon' => $value, 'val_mon_paid' => 0, 'val_mon_storno' => 0, 'data_scadenta' => '2026-09-30']);
    InvoiceDetail::factory()->create(['invoice_id' => $invoice->id, 'scv' => 1, 'cant' => 1, 'pret' => $value, 'com_int' => '1234567']);
    app(DepartmentAssigner::class)->assign(collect([$invoice]));

    return $invoice->fresh();
}

/**
 * Se poate aproba și o parte din sumă: atât intră în plată, restul rămâne
 * neaprobat. Suma e în banii facturii, adică exact cât se plătește.
 */
test('a department approves part of its share and only that part is paid', function () {
    $invoice = partialInvoice($this->company);

    $this->actingAs($this->head)->post('/approvals/decide', [
        'invoice_ids' => [$invoice->id],
        'department_id' => $this->departments['charters']->id,
        'decision' => 'approved',
        'amount' => 400,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $approval = $invoice->departmentApprovals()->sole();

    expect($approval->status)->toBe('approved')
        ->and($approval->approved_amount)->toBe(400.0)
        ->and($invoice->fresh()->approvedForPayment())->toBe(400.0)
        ->and($invoice->fresh()->approval_status)->toBe('final');

    // Rulajul duce la bancă doar suma aprobată.
    $this->actingAs($this->boss)->post('/approvals/final', ['invoice_ids' => [$invoice->id], 'decision' => 'approved'])->assertRedirect();

    $run = app(PaymentRunService::class)->create($this->company, Carbon::parse('2026-09-30'), Carbon::parse('2026-10-05'), $this->boss);
    $item = PaymentRunItem::query()->where('payment_run_id', $run->id)->where('invoice_id', $invoice->id)->sole();

    expect((float) $item->amount)->toBe(400.0);
});

test('Top Management can cut the amount at the final approval', function () {
    $invoice = partialInvoice($this->company);
    app(InvoiceWorkflow::class)->decide($invoice, $this->departments['charters'], $this->head, 'approved');

    $this->actingAs($this->boss)->post('/approvals/final', [
        'invoice_ids' => [$invoice->id],
        'decision' => 'approved',
        'amount' => 250,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($invoice->fresh()->approved_amount)->toBe(250.0)
        ->and($invoice->fresh()->approvedForPayment())->toBe(250.0);

    $run = app(PaymentRunService::class)->create($this->company, Carbon::parse('2026-09-30'), Carbon::parse('2026-10-05'), $this->boss);

    expect((float) PaymentRunItem::query()->where('payment_run_id', $run->id)->sole()->amount)->toBe(250.0);
});

test('the whole share approved is kept as whole, so a later part payment does not shrink it', function () {
    $invoice = partialInvoice($this->company);

    $this->actingAs($this->head)->post('/approvals/decide', [
        'invoice_ids' => [$invoice->id],
        'department_id' => $this->departments['charters']->id,
        'decision' => 'approved',
        'amount' => 1000,
    ])->assertRedirect();

    expect($invoice->departmentApprovals()->sole()->approved_amount)->toBeNull()
        ->and($invoice->fresh()->approvedForPayment())->toBe(1000.0);
});

test('more than the share, a sum on several invoices or on a dispute are all refused', function () {
    $invoice = partialInvoice($this->company);
    $other = partialInvoice($this->company);

    $this->actingAs($this->head)->post('/approvals/decide', [
        'invoice_ids' => [$invoice->id],
        'department_id' => $this->departments['charters']->id,
        'decision' => 'approved',
        'amount' => 1500,
    ])->assertSessionHasErrors('amount');

    $this->actingAs($this->head)->post('/approvals/decide', [
        'invoice_ids' => [$invoice->id, $other->id],
        'department_id' => $this->departments['charters']->id,
        'decision' => 'approved',
        'amount' => 100,
    ])->assertSessionHasErrors('amount');

    $this->actingAs($this->head)->post('/approvals/decide', [
        'invoice_ids' => [$invoice->id],
        'department_id' => $this->departments['charters']->id,
        'decision' => 'disputed',
        'comment' => 'nu e bună',
        'amount' => 100,
    ])->assertSessionHasErrors('amount');

    expect($invoice->fresh()->departmentApprovals()->sole()->status)->toBe('pending');
});

test('reopening an invoice drops the partial amounts with the decisions', function () {
    $invoice = partialInvoice($this->company);
    app(InvoiceWorkflow::class)->decide($invoice, $this->departments['charters'], $this->head, 'approved', amount: 300);
    app(InvoiceWorkflow::class)->decideFinal($invoice->fresh(), $this->boss, 'approved', amount: 200);

    expect($invoice->fresh()->approvedForPayment())->toBe(200.0);

    app(InvoiceWorkflow::class)->reopen($invoice->fresh(), $this->boss, 'de la capăt');

    expect($invoice->fresh()->approved_amount)->toBeNull()
        // Decizia departamentului rămâne, cu suma ei: redeschiderea atinge
        // doar deciziile care opreau factura.
        ->and($invoice->fresh()->approval_status)->toBe('final');
});
