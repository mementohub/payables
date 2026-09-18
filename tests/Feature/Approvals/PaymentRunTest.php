<?php

use App\Models\CashFlowSnapshot;
use App\Models\Company;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\InvoiceDetail;
use App\Models\User;
use App\Services\Approvals\InvoiceWorkflow;
use App\Services\Approvals\PaymentRunService;
use App\Services\Routing\BookingResolver;
use App\Services\Routing\DepartmentAssigner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;

beforeEach(function () {
    Carbon::setTestNow('2026-09-18 10:00:00');
    $this->departments = Department::query()->whereNotNull('code')->get()->keyBy('code');
    $this->company = Company::factory()->create();

    $this->mock(BookingResolver::class, function (MockInterface $mock) {
        $mock->shouldReceive('resolve')->andReturnUsing(fn (array $ids) => collect($ids)->mapWithKeys(fn (int $id) => [$id => ['department' => 'charters', 'channel' => null, 'detail' => '']])->all());
    });

    $this->head = User::factory()->create();
    $this->head->departments()->attach($this->departments['charters']);
    $this->finance = User::factory()->withRoles('finance')->create();
    $this->boss = User::factory()->withRoles('top_management')->create();
    $this->treasury = User::factory()->withRoles('treasury')->create();
});

function runDueInvoice(Company $company, array $attributes = []): Invoice
{
    $invoice = Invoice::factory()->for($company)->create(['val_mon' => 1000, 'data_scadenta' => '2026-09-20', ...$attributes]);
    InvoiceDetail::factory()->create(['invoice_id' => $invoice->id, 'scv' => 1, 'pret' => $invoice->val_mon, 'com_int' => '1234567']);
    app(DepartmentAssigner::class)->assign(collect([$invoice]));

    return $invoice->fresh();
}

test('a run gathers the open invoices due, goes through the departments and Top Management, then to the bank', function () {
    $due = runDueInvoice($this->company);
    $partlyPaid = runDueInvoice($this->company, ['val_mon_paid' => 400]);
    runDueInvoice($this->company, ['data_scadenta' => '2026-10-30']);
    runDueInvoice($this->company, ['val_mon_paid' => 1000]);
    $service = app(PaymentRunService::class);

    expect(fn () => $service->create($this->company, Carbon::parse('2026-09-23'), Carbon::parse('2026-09-25'), $this->head))->toThrow(AuthorizationException::class);

    $run = $service->create($this->company, Carbon::parse('2026-09-23'), Carbon::parse('2026-09-25'), $this->finance);

    expect($run->reference)->toBe('Plăți 2026 S39')
        ->and($run->status)->toBe('review')
        ->and($run->items()->pluck('amount', 'invoice_id')->all())->toEqual([$due->id => 1000, $partlyPaid->id => 600])
        ->and(fn () => $service->approve($run, $this->boss))->toThrow(ValidationException::class);

    $workflow = app(InvoiceWorkflow::class);
    $workflow->decide($due, $this->departments['charters'], $this->head, 'approved');
    $workflow->decide($partlyPaid, $this->departments['charters'], $this->head, 'approved');

    expect($service->recompute($run->fresh())->status)->toBe('final');

    $service->approve($run->fresh(), $this->boss, 'Aprobat pe cash-flow');

    expect($run->fresh()->status)->toBe('approved')
        ->and($due->fresh()->approval_status)->toBe('approved')
        ->and($service->payableInvoiceIds($run->fresh()))->toEqualCanonicalizing([$due->id, $partlyPaid->id])
        ->and(fn () => $service->markExported($run->fresh(), $this->finance))->toThrow(AuthorizationException::class);

    $service->markExported($run->fresh(), $this->treasury);
    expect($run->fresh()->status)->toBe('exported');

    // OMC records the payments: the run closes.
    Invoice::query()->whereKey([$due->id, $partlyPaid->id])->update(['val_mon_paid' => DB::raw('val_mon')]);
    expect($service->recompute($run->fresh())->status)->toBe('closed');
});

test('disputed, postponed and already planned invoices stay out of a new run; an excluded one waits for the next', function () {
    $disputed = runDueInvoice($this->company);
    app(InvoiceWorkflow::class)->decide($disputed, $this->departments['charters'], $this->head, 'disputed', 'Serviciu neprestat');
    $postponed = runDueInvoice($this->company);
    app(InvoiceWorkflow::class)->decide($postponed, $this->departments['charters'], $this->head, 'postponed', until: Carbon::parse('2026-10-15'));
    $planned = runDueInvoice($this->company);
    $service = app(PaymentRunService::class);

    $first = $service->create($this->company, Carbon::parse('2026-09-23'), Carbon::parse('2026-09-25'), $this->finance);
    expect($first->items()->pluck('invoice_id')->all())->toBe([$planned->id]);

    $service->setIncluded($first, $first->items()->sole(), false, $this->boss, 'Luna viitoare');
    $second = $service->create($this->company, Carbon::parse('2026-09-30'), Carbon::parse('2026-10-02'), $this->finance);

    expect($second->reference)->toBe('Plăți 2026 S40')
        ->and($second->items()->pluck('invoice_id')->all())->toBe([$planned->id]);
});

test('the run is shown against the balance the WCFR forecast expects that week', function () {
    CashFlowSnapshot::factory()->create(['payload' => [
        'weeks' => ['2026-09-14', '2026-09-21', '2026-09-28'],
        'fx' => ['EUR' => 5.0],
        'params' => ['thresholds' => ['minimum' => 3_000_000]],
        'lines' => [['code' => 'E2', 'values' => [5_000_000, 4_200_000, 4_000_000]]],
    ]]);
    runDueInvoice($this->company);
    runDueInvoice($this->company, ['moneda' => 'EUR', 'val_mon' => 100]);

    $run = app(PaymentRunService::class)->create($this->company, Carbon::parse('2026-09-23'), Carbon::parse('2026-09-25'), $this->finance);
    $position = app(PaymentRunService::class)->cashPosition($run);

    expect($position)->toMatchArray(['total_lei' => 1500.0, 'by_currency' => ['RON' => 1000.0, 'EUR' => 100.0], 'week' => '2026-09-21', 'closing' => 4_200_000.0, 'minimum' => 3_000_000.0, 'margin' => 1_200_000.0]);
});
