<?php

use App\Models\CashFlowSnapshot;
use App\Models\Company;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\InvoiceDetail;
use App\Models\PaymentRun;
use App\Models\PaymentRunItem;
use App\Models\User;
use App\Services\Approvals\InvoiceWorkflow;
use App\Services\Approvals\PaymentRunService;
use App\Services\Routing\BookingResolver;
use App\Services\Routing\DepartmentAssigner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
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

/**
 * O factură dusă până la capătul fluxului: departamentul și-a aprobat partea,
 * Top Management a aprobat-o. Abia așa intră într-un rulaj de plată.
 */
function runApprovedInvoice(Company $company, array $attributes = []): Invoice
{
    $invoice = runDueInvoice($company, $attributes);
    $workflow = app(InvoiceWorkflow::class);
    $workflow->decide($invoice, test()->departments['charters'], test()->head, 'approved');
    $workflow->decideFinal($invoice->fresh(), test()->boss, 'approved');

    return $invoice->fresh();
}

test('a run gathers only what Top Management has approved, and goes straight to the bank', function () {
    $due = runApprovedInvoice($this->company);
    $partlyPaid = runApprovedInvoice($this->company, ['val_mon_paid' => 400]);
    // Aprobată, dar scadentă peste orizontul rulajului.
    runApprovedInvoice($this->company, ['data_scadenta' => '2026-10-30']);
    // Scadentă, dar încă la departament: nu e treaba rulajului s-o aprobe.
    $waiting = runDueInvoice($this->company);
    $service = app(PaymentRunService::class);

    expect(fn () => $service->create($this->company, Carbon::parse('2026-09-23'), Carbon::parse('2026-09-25'), $this->head))->toThrow(AuthorizationException::class);

    $run = $service->create($this->company, Carbon::parse('2026-09-23'), Carbon::parse('2026-09-25'), $this->finance);

    // Lista e gata din clipa în care s-a făcut: nu mai are ce să aștepte.
    expect($run->reference)->toBe('Plăți 2026 S39')
        ->and($run->status)->toBe('approved')
        ->and($run->items()->pluck('amount', 'invoice_id')->all())->toEqual([$due->id => 1000, $partlyPaid->id => 600])
        ->and($run->items()->pluck('invoice_id')->all())->not->toContain($waiting->id)
        ->and($service->payableInvoiceIds($run))->toEqualCanonicalizing([$due->id, $partlyPaid->id])
        ->and(fn () => $service->markExported($run->fresh(), $this->finance))->toThrow(AuthorizationException::class);

    $service->markExported($run->fresh(), $this->treasury);
    expect($run->fresh()->status)->toBe('exported');

    // OMC înregistrează plățile: rulajul se închide.
    Invoice::query()->whereKey([$due->id, $partlyPaid->id])->update(['val_mon_paid' => DB::raw('val_mon')]);
    expect($service->recompute($run->fresh())->status)->toBe('closed');
});

test('what is not approved stays out of a run; an excluded invoice waits for the next', function () {
    $disputed = runDueInvoice($this->company);
    app(InvoiceWorkflow::class)->decide($disputed, $this->departments['charters'], $this->head, 'disputed', 'Serviciu neprestat');
    $postponed = runDueInvoice($this->company);
    app(InvoiceWorkflow::class)->decide($postponed, $this->departments['charters'], $this->head, 'postponed', until: Carbon::parse('2026-10-15'));
    $planned = runApprovedInvoice($this->company);
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
    runApprovedInvoice($this->company);
    runApprovedInvoice($this->company, ['moneda' => 'EUR', 'val_mon' => 100]);

    $run = app(PaymentRunService::class)->create($this->company, Carbon::parse('2026-09-23'), Carbon::parse('2026-09-25'), $this->finance);
    $position = app(PaymentRunService::class)->cashPosition($run);

    expect($position)->toMatchArray(['total_lei' => 1500.0, 'by_currency' => ['RON' => 1000.0, 'EUR' => 100.0], 'week' => '2026-09-21', 'closing' => 4_200_000.0, 'minimum' => 3_000_000.0, 'margin' => 1_200_000.0]);
});

/**
 * Un rulaj greșit se șterge, dar numai de administrator: rulajul e lista
 * Trezoreriei, nu decizia, deci ștergerea lui lasă facturile aprobate, gata
 * să intre în următorul.
 */
test('an administrator deletes a payment run; the invoices stay approved', function () {
    $invoice = runApprovedInvoice($this->company);
    $run = app(PaymentRunService::class)->create($this->company, Carbon::parse('2026-09-23'), Carbon::parse('2026-09-25'), $this->finance);

    expect($run->items()->count())->toBe(1);

    foreach ([$this->finance, $this->boss, $this->treasury, $this->head] as $user) {
        $this->actingAs($user)->delete("/payment-runs/{$run->id}")->assertForbidden();
    }

    $this->actingAs(User::factory()->withRoles('admin')->create())
        ->delete("/payment-runs/{$run->id}")
        ->assertRedirect('/payment-runs');

    expect(PaymentRun::query()->whereKey($run->id)->exists())->toBeFalse()
        ->and(PaymentRunItem::query()->where('payment_run_id', $run->id)->count())->toBe(0)
        ->and($invoice->fresh()->approval_status)->toBe('approved');

    // Și poate intra într-un rulaj nou, ca și cum primul n-ar fi fost.
    $next = app(PaymentRunService::class)->create($this->company, Carbon::parse('2026-09-24'), Carbon::parse('2026-09-25'), $this->finance);
    expect($next->items()->pluck('invoice_id')->all())->toBe([$invoice->id]);
});

test('the list offers the delete only to an administrator', function () {
    app(PaymentRunService::class)->create($this->company, Carbon::parse('2026-09-23'), Carbon::parse('2026-09-25'), $this->finance);

    $this->actingAs(User::factory()->withRoles('admin')->create())->get('/payment-runs')
        ->assertInertia(fn ($page) => $page->where('can.delete', true));

    $this->actingAs($this->finance)->get('/payment-runs')
        ->assertInertia(fn ($page) => $page->where('can.delete', false));
});
