<?php

use App\Models\AssignmentRule;
use App\Models\Company;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\InvoiceDetail;
use App\Models\PaymentRun;
use App\Models\User;
use App\Services\Routing\BookingResolver;
use App\Services\Routing\DepartmentAssigner;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;

beforeEach(function () {
    Carbon::setTestNow('2026-09-18 10:00:00');
    $this->departments = Department::query()->whereNotNull('code')->get()->keyBy('code');
    $this->company = Company::factory()->create();

    $this->mock(BookingResolver::class, function (MockInterface $mock) {
        $mock->shouldReceive('resolve')->andReturnUsing(fn (array $ids) => collect($ids)->mapWithKeys(fn (int $id) => [$id => ['department' => 'charters', 'channel' => 'sales_b2b', 'detail' => "Rezervarea {$id}"]])->all());
    });

    $this->head = User::factory()->create();
    $this->head->departments()->attach($this->departments['charters']);
    $this->boss = User::factory()->withRoles(['top_management', 'finance', 'treasury'])->create();
});

function pagesRoutedInvoice(Company $company, array $attributes = [], string $reference = '1234567'): Invoice
{
    $invoice = Invoice::factory()->for($company)->create(['val_mon' => 1000, 'data_scadenta' => '2026-09-21', ...$attributes]);
    InvoiceDetail::factory()->create(['invoice_id' => $invoice->id, 'scv' => 1, 'pret' => $invoice->val_mon, 'com_int' => $reference, 'account' => $reference === '' ? '401' : '471']);
    app(DepartmentAssigner::class)->assign(collect([$invoice]));

    return $invoice->fresh();
}

test('a department head sees the invoices waiting for their department and approves them in one go', function () {
    $first = pagesRoutedInvoice($this->company);
    $second = pagesRoutedInvoice($this->company);

    $this->actingAs($this->head)->get('/approvals')
        ->assertInertia(fn ($page) => $page
            ->component('approvals/index')
            ->where('tab', 'mine')
            ->where('counts.mine', 2)
            ->has('rows.data', 2)
            ->where('rows.data.0.departments.0.name', 'Charters')
            ->where('can.final', false));

    $this->actingAs($this->head)
        ->post('/approvals/decide', ['invoice_ids' => [$first->id, $second->id], 'department_id' => $this->departments['charters']->id, 'decision' => 'approved'])
        ->assertRedirect();

    expect($first->fresh()->approval_status)->toBe('final')
        ->and($second->fresh()->approval_status)->toBe('final');

    // Someone outside the department cannot.
    $third = pagesRoutedInvoice($this->company);
    $this->actingAs(User::factory()->create())
        ->post('/approvals/decide', ['invoice_ids' => [$third->id], 'department_id' => $this->departments['charters']->id, 'decision' => 'approved'])
        ->assertForbidden();
});

test('Top Management gives the final approval to overhead invoices from the inbox', function () {
    AssignmentRule::query()->create(['kind' => 'account', 'pattern' => '401', 'department_id' => $this->departments['marketing']->id]);
    $overhead = pagesRoutedInvoice($this->company, reference: '');
    $marketing = User::factory()->create();
    $marketing->departments()->attach($this->departments['marketing']);

    $this->actingAs($marketing)->post('/approvals/decide', ['invoice_ids' => [$overhead->id], 'department_id' => $this->departments['marketing']->id, 'decision' => 'approved']);

    $this->actingAs($this->boss)->get('/approvals?tab=final')
        ->assertInertia(fn ($page) => $page->where('counts.final', 1)->where('rows.data.0.id', $overhead->id)->where('rows.data.0.approval_track', 'invoice'));

    $this->actingAs($this->head)->post('/approvals/final', ['invoice_ids' => [$overhead->id], 'decision' => 'approved'])->assertForbidden();
    $this->actingAs($this->boss)->post('/approvals/final', ['invoice_ids' => [$overhead->id], 'decision' => 'approved'])->assertRedirect();

    expect($overhead->fresh()->approval_status)->toBe('approved');
});

test('a payment run is created, shown with its cash position, approved and marked as sent', function () {
    $invoice = pagesRoutedInvoice($this->company);
    $this->actingAs($this->head)->post('/approvals/decide', ['invoice_ids' => [$invoice->id], 'department_id' => $this->departments['charters']->id, 'decision' => 'approved']);

    $this->actingAs($this->head)->post('/payment-runs', ['pay_date' => '2026-09-23', 'due_until' => '2026-09-25'])->assertForbidden();
    $this->actingAs($this->boss)->withSession(['active_company_id' => $this->company->id])
        ->post('/payment-runs', ['pay_date' => '2026-09-23', 'due_until' => '2026-09-25'])
        ->assertRedirect();

    $run = PaymentRun::query()->sole();

    $this->actingAs($this->boss)->get("/payment-runs/{$run->id}")
        ->assertInertia(fn ($page) => $page
            ->component('payment-runs/show')
            ->where('run.status', 'final')
            ->has('items', 1)
            ->where('items.0.invoice.id', $invoice->id)
            ->where('can.approve', true)
            ->has('cash'));

    $this->actingAs($this->boss)->post("/payment-runs/{$run->id}/approve")->assertRedirect();
    $this->actingAs($this->boss)->get("/payment-runs/{$run->id}")->assertInertia(fn ($page) => $page->where('payable_invoice_ids', [$invoice->id])->where('can.export', true));
    $this->actingAs($this->boss)->post("/payment-runs/{$run->id}/exported")->assertRedirect();

    expect($run->fresh()->status)->toBe('exported');
});

test('Finance finds the invoices without a department in Facturi primite and routes them from there', function () {
    $stuck = pagesRoutedInvoice($this->company, reference: '');
    $other = pagesRoutedInvoice($this->company);

    $this->actingAs($this->boss)->get('/invoices/received?approval=routing')
        ->assertInertia(fn ($page) => $page->has('invoices.data', 1)->where('invoices.data.0.id', $stuck->id)->where('invoices.data.0.workflow.approval_status', 'routing'));

    $this->actingAs($this->head)->post('/routing/assign', ['invoice_ids' => [$stuck->id], 'department_id' => $this->departments['administration']->id])->assertForbidden();
    $this->actingAs($this->boss)->post('/routing/assign', ['invoice_ids' => [$stuck->id, $other->id], 'department_id' => $this->departments['administration']->id, 'remember' => true])->assertRedirect();

    expect($stuck->fresh()->assignment_state)->toBe('assigned')
        ->and($stuck->fresh()->department_id)->toBe($this->departments['administration']->id)
        ->and($other->fresh()->department_id)->toBe($this->departments['administration']->id)
        ->and($stuck->fresh()->approval_status)->toBe('department')
        ->and(AssignmentRule::query()->where('kind', 'partner')->where('pattern', $stuck->partner->name)->value('department_id'))->toBe($this->departments['administration']->id);

    // A broken pattern is refused on the rules page.
    $this->actingAs($this->boss)->post('/routing/rules', ['kind' => 'loc', 'pattern' => '~(unclosed', 'department_id' => $this->departments['marketing']->id])->assertSessionHasErrors('pattern');
});

test('Facturi primite filters by supplier, found by name or CUI', function () {
    $wanted = pagesRoutedInvoice($this->company);
    pagesRoutedInvoice($this->company);
    $wanted->partner->update(['name' => 'HOTEL PARADIS SRL', 'cui' => 'RO123456']);

    $this->actingAs($this->head)->getJson('/suppliers/search?q=paradis')
        ->assertOk()
        ->assertJsonPath('suppliers.0.id', $wanted->partner_id)
        ->assertJsonCount(1, 'suppliers');
    $this->actingAs($this->head)->getJson('/suppliers/search?q=RO1234')->assertJsonPath('suppliers.0.name', 'HOTEL PARADIS SRL');

    $this->actingAs($this->head)->get("/invoices/received?partner_id={$wanted->partner_id}")
        ->assertInertia(fn ($page) => $page->has('invoices.data', 1)->where('invoices.data.0.id', $wanted->id)->where('selectedPartner.name', 'HOTEL PARADIS SRL'));
});

test('a department sends a share that is not its own to the right department, which then approves it', function () {
    $invoice = pagesRoutedInvoice($this->company);
    $senior = User::factory()->create();
    $senior->departments()->attach($this->departments['senior_voyage']);

    $this->actingAs($senior)->post('/approvals/redirect', [
        'invoice_ids' => [$invoice->id], 'department_id' => $this->departments['charters']->id,
        'to_department_id' => $this->departments['senior_voyage']->id, 'comment' => 'Nu e al meu',
    ])->assertForbidden();

    $this->actingAs($this->head)->post('/approvals/redirect', [
        'invoice_ids' => [$invoice->id], 'department_id' => $this->departments['charters']->id,
        'to_department_id' => $this->departments['charters']->id, 'comment' => 'x',
    ])->assertSessionHasErrors(['to_department_id', 'comment']);

    $this->actingAs($this->head)->post('/approvals/redirect', [
        'invoice_ids' => [$invoice->id], 'department_id' => $this->departments['charters']->id,
        'to_department_id' => $this->departments['senior_voyage']->id, 'comment' => 'Grup Senior Voyage, nu charter',
    ])->assertRedirect();

    $invoice->refresh();
    $event = $invoice->events()->where('type', 'department_redirected')->sole();

    expect($invoice->departmentApprovals()->pluck('status', 'department_id')->all())->toBe([$this->departments['senior_voyage']->id => 'pending'])
        ->and($invoice->department_id)->toBe($this->departments['senior_voyage']->id)
        ->and($invoice->approval_status)->toBe('department')
        ->and($event->body)->toBe('Grup Senior Voyage, nu charter')
        ->and($event->payload['to'])->toBe('Senior Voyage');

    // Now it is Senior Voyage's to approve, and the rules leave it there.
    $this->actingAs($senior)->get('/approvals')->assertInertia(fn ($page) => $page->where('counts.mine', 1));
    app(DepartmentAssigner::class)->assign(collect([$invoice]));
    expect($invoice->fresh()->department_id)->toBe($this->departments['senior_voyage']->id);
});
