<?php

use App\Models\Company;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\InvoiceDetail;
use App\Models\Partner;
use App\Models\User;
use App\Services\Routing\BookingResolver;
use App\Services\Routing\DepartmentAssigner;
use Mockery\MockInterface;

beforeEach(function () {
    $this->departments = Department::query()->whereNotNull('code')->get()->keyBy('code');
    $this->company = Company::factory()->create();

    $this->mock(BookingResolver::class, function (MockInterface $mock) {
        $mock->shouldReceive('resolve')->andReturnUsing(fn (array $ids) => collect($ids)
            ->mapWithKeys(fn (int $id) => [$id => ['department' => 'charters', 'channel' => 'sales_b2b', 'detail' => "Rezervarea {$id}"]])
            ->all());
    });

    $this->charters = $this->departments['charters'];
    $this->other = Department::query()->whereNotNull('code')->where('id', '!=', $this->charters->id)->first();

    $this->head = User::factory()->create();
    $this->head->departments()->attach($this->charters);

    $this->stranger = User::factory()->create();
    $this->stranger->departments()->attach($this->other);
});

/**
 * O factură rutată pe departamentul de charter.
 */
function visibilityInvoice(Company $company, array $attributes = []): Invoice
{
    $partner = Partner::factory()->furnizor()->create(['company_id' => $company->id]);
    $invoice = Invoice::factory()->for($company)->create([
        'partner_id' => $partner->id,
        'partener_type' => 'furnizor',
        'val_mon' => 1000,
        ...$attributes,
    ]);
    InvoiceDetail::factory()->create(['invoice_id' => $invoice->id, 'scv' => 1, 'pret' => 1000, 'com_int' => '1234567', 'account' => '471']);
    app(DepartmentAssigner::class)->assign(collect([$invoice]));

    return $invoice->fresh();
}

test('the inbox shows a department user only their own department\'s invoices', function () {
    visibilityInvoice($this->company);

    // Omul unui departament nu mai are lista de facturi a companiei; are
    // căsuța lui, iar în ea „Toate facturile” arată doar ce e al lui.
    $this->actingAs($this->head)->get('/approvals?tab=all')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('rows.data', 1));

    $this->actingAs($this->stranger)->get('/approvals?tab=all')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('rows.data', 0));

    // Și nici nu ajunge la lista Financiarului.
    $this->actingAs($this->head)->get('/invoices/received')->assertForbidden();
});

test('a direct link to another department\'s invoice is refused', function () {
    $invoice = visibilityInvoice($this->company);

    $this->actingAs($this->head)->get("/invoices/{$invoice->id}")->assertOk();
    $this->actingAs($this->stranger)->get("/invoices/{$invoice->id}")->assertForbidden();
});

test('writing on another department\'s invoice is refused too', function () {
    $invoice = visibilityInvoice($this->company);

    $this->actingAs($this->stranger)
        ->post("/invoices/{$invoice->id}/comments", ['body' => 'nu e al meu'])
        ->assertForbidden();
});

test('finance, treasury and top management keep seeing every department', function (array $roles) {
    visibilityInvoice($this->company);

    $user = User::factory()->withRoles($roles)->create();
    $user->departments()->attach($this->other);

    $response = $this->actingAs($user)->get('/invoices/received')->assertOk();
    expect($response->viewData('page')['props']['invoices']['data'])->toHaveCount(1);
})->with([[['finance']], [['top_management']], [['admin']]]);

test('a user with no department of their own is not locked out of the list', function () {
    visibilityInvoice($this->company);

    $response = $this->actingAs(User::factory()->withRoles('finance')->create())->get('/invoices/received')->assertOk();
    expect($response->viewData('page')['props']['invoices']['data'])->toHaveCount(1);
});

test('the export carries the same limit as the list', function () {
    visibilityInvoice($this->company);

    // Exportul folosește aceeași interogare ca lista, deci aceeași limitare.
    expect(Invoice::query()->visibleTo($this->stranger)->count())->toBe(0)
        ->and(Invoice::query()->visibleTo($this->head)->count())->toBe(1);
});

test('nobody approves for a department that is not theirs', function () {
    $invoice = visibilityInvoice($this->company);

    // Formularul altcuiva, trimis de mână: departamentul nu e al lui.
    $this->actingAs($this->stranger)
        ->post('/approvals/decide', [
            'invoice_ids' => [$invoice->id],
            'department_id' => $this->charters->id,
            'decision' => 'approved',
        ])
        ->assertForbidden();

    expect($invoice->fresh()->departmentApprovals()->where('status', 'approved')->count())->toBe(0);

    $this->actingAs($this->head)
        ->post('/approvals/decide', [
            'invoice_ids' => [$invoice->id],
            'department_id' => $this->charters->id,
            'decision' => 'approved',
        ])
        ->assertRedirect();

    expect($invoice->fresh()->departmentApprovals()->where('status', 'approved')->count())->toBe(1);
});

test('the final decision stays with top management', function () {
    $invoice = visibilityInvoice($this->company);

    $this->actingAs($this->head)
        ->post('/approvals/final', ['invoice_ids' => [$invoice->id], 'decision' => 'approved'])
        ->assertForbidden();

    $boss = User::factory()->withRoles('top_management')->create();
    $this->actingAs($boss)
        ->post('/approvals/final', ['invoice_ids' => [$invoice->id], 'decision' => 'approved'])
        ->assertRedirect();
});

test('reopening an invoice is not for everyone', function () {
    $invoice = visibilityInvoice($this->company);

    $this->actingAs($this->head)->post("/approvals/invoices/{$invoice->id}/reopen")->assertForbidden();
    $this->actingAs(User::factory()->withRoles('finance')->create())
        ->post("/approvals/invoices/{$invoice->id}/reopen")
        ->assertRedirect();
});

test('an invoice of another department cannot be redirected away by a stranger', function () {
    $invoice = visibilityInvoice($this->company);

    $this->actingAs($this->stranger)
        ->post('/approvals/redirect', [
            'invoice_ids' => [$invoice->id],
            'department_id' => $this->charters->id,
            'to_department_id' => $this->other->id,
            'comment' => 'nu e al nostru',
        ])
        ->assertForbidden();

    expect($invoice->fresh()->department_id)->toBe($this->charters->id);
});

test('a batch holding one foreign invoice is refused whole, not in part', function () {
    $mine = visibilityInvoice($this->company);
    $foreign = visibilityInvoice($this->company);
    // Complet a altui departament: și repartizarea, și liniile, și aprobările.
    $foreign->update(['department_id' => $this->other->id]);
    $foreign->departmentApprovals()->update(['department_id' => $this->other->id]);
    $foreign->lineDepartments()->update(['department_id' => $this->other->id]);

    expect(Invoice::query()->whereKey($foreign->id)->visibleTo($this->head)->exists())->toBeFalse();

    $this->actingAs($this->head)
        ->post('/approvals/decide', [
            'invoice_ids' => [$mine->id, $foreign->id],
            'department_id' => $this->charters->id,
            'decision' => 'approved',
        ])
        ->assertForbidden();

    // Nici măcar cea proprie nu s-a aprobat: lotul pică întreg.
    expect($mine->fresh()->departmentApprovals()->where('status', 'approved')->count())->toBe(0);
});
