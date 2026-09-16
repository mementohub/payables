<?php

use App\Models\Company;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\Partner;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestEvent;
use App\Models\User;
use App\Services\PaymentRequests\PaymentRequestService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Carbon::setTestNow('2026-09-16 10:00:00');

    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['etrip_connection' => 'etrip_chr']);
    $this->supplier = Partner::factory()->for($this->company)->create(['name' => 'VODAFONE ROMANIA S.A.']);
});

/**
 * @return array<string, mixed>
 */
function checkinRequestPayload(Company $company, array $overrides = []): array
{
    return [
        'company_id' => $company->id,
        'kind' => 'checkin',
        'supplier_name' => 'Rida International',
        'requested_amount' => 12760,
        'requested_currency' => 'USD',
        'checkin_from' => '2026-09-16',
        'checkin_to' => '2026-09-17',
        'category' => 'hotel',
        'expected_amount' => 12760,
        'expected_currency' => 'USD',
        'difference' => 0,
        'difference_pct' => 0,
        'level' => 'ok',
        'verdict' => 'ok',
        'snapshot' => ['items' => 7, 'bookings' => 7],
        'status' => 'payable',
        'note' => 'cerere nr. 44',
        ...$overrides,
    ];
}

test('guests are redirected to the login page', function () {
    $this->get('/payment-requests')->assertRedirect('/login');
});

test('a verified check-in request is saved in the register with its first event', function () {
    $this->actingAs($this->user)
        ->post('/payment-requests', checkinRequestPayload($this->company))
        ->assertRedirect('/payment-requests/'.PaymentRequest::first()->id);

    $request = PaymentRequest::first();

    expect($request)->toMatchArray(['kind' => 'checkin', 'supplier_name' => 'Rida International', 'status' => 'payable', 'level' => 'ok', 'created_by_id' => $this->user->id])
        ->and((float) $request->requested_amount)->toBe(12760.0)
        ->and($request->snapshot)->toBe(['items' => 7, 'bookings' => 7])
        ->and($request->events)->toHaveCount(1)
        ->and($request->events->first()->type)->toBe(PaymentRequestEvent::TYPE_CREATED)
        ->and($request->events->first()->body)->toBe('cerere nr. 44');
});

test('storing a request validates the check-in interval and the company scope', function () {
    $otherCompanyPartner = Partner::factory()->create();

    $this->actingAs($this->user)
        ->post('/payment-requests', checkinRequestPayload($this->company, [
            'checkin_to' => '2026-09-10',
            'partner_id' => $otherCompanyPartner->id,
            'status' => 'paid',
        ]))
        ->assertSessionHasErrors(['checkin_to', 'partner_id', 'status']);
});

test('an invoice request links the invoices that cover it and writes to their timeline', function () {
    $invoice = Invoice::factory()->for($this->company)->for($this->supplier)->create(['nr_doc' => 'VF2608', 'val_mon' => 20431.86]);

    $this->actingAs($this->user)
        ->post('/payment-requests', [
            'company_id' => $this->company->id,
            'kind' => 'invoice',
            'supplier_name' => $this->supplier->name,
            'partner_id' => $this->supplier->id,
            'reference' => 'VF2608',
            'requested_amount' => 20431.86,
            'requested_currency' => 'RON',
            'expected_amount' => 20431.86,
            'expected_currency' => 'RON',
            'difference' => 0,
            'difference_pct' => 0,
            'level' => 'ok',
            'verdict' => 'exact',
            'status' => 'payable',
            'invoice_ids' => [$invoice->id],
        ])
        ->assertRedirect();

    $request = PaymentRequest::first();

    expect($request->invoices->pluck('id')->all())->toBe([$invoice->id])
        ->and($request->events->pluck('type')->all())->toBe([PaymentRequestEvent::TYPE_INVOICE_LINKED, PaymentRequestEvent::TYPE_CREATED])
        ->and(InvoiceEvent::where('invoice_id', $invoice->id)->where('type', InvoiceEvent::TYPE_PAYMENT_REQUEST_LINKED)->first()->payload)
        ->toMatchArray(['payment_request_id' => $request->id, 'verdict' => 'exact', 'level' => 'ok']);
});

test('only supplier invoices of the same company can cover a request', function () {
    $request = PaymentRequest::factory()->for($this->company)->create();
    $foreign = Invoice::factory()->create();
    $client = Invoice::factory()->for($this->company)->create(['partener_type' => 'client', 'tip_doc' => 'FactCI']);

    $service = app(PaymentRequestService::class);

    expect(fn () => $service->linkInvoice($request, $this->user, $foreign))->toThrow(ValidationException::class)
        ->and(fn () => $service->linkInvoice($request, $this->user, $client))->toThrow(ValidationException::class);
});

test('a status change is recorded and marking as paid needs the payments department', function () {
    $request = PaymentRequest::factory()->for($this->company)->create(['status' => 'payable']);
    $service = app(PaymentRequestService::class);

    $service->changeStatus($request, $this->user, 'disputed', 'furnizorul a trimis alt total');

    expect($request->fresh())->toMatchArray(['status' => 'disputed', 'status_updated_by_id' => $this->user->id])
        ->and($request->events()->first())->toMatchArray(['type' => 'status_changed', 'body' => 'furnizorul a trimis alt total'])
        ->and($request->events()->first()->payload)->toBe(['from' => 'payable', 'to' => 'disputed']);

    expect(fn () => $service->changeStatus($request, $this->user, 'paid'))->toThrow(AuthorizationException::class);

    $plati = Department::create(['name' => 'Plăți', 'type' => Department::TYPE_PLATI]);
    $plati->members()->attach($this->user);

    $this->actingAs($this->user)
        ->post("/payment-requests/{$request->id}/status", ['status' => 'paid'])
        ->assertRedirect();

    expect($request->fresh()->status)->toBe('paid');
});

test('invoices can be linked and unlinked from the request page', function () {
    $request = PaymentRequest::factory()->for($this->company)->create(['partner_id' => $this->supplier->id]);
    $invoice = Invoice::factory()->for($this->company)->for($this->supplier)->create();

    $this->actingAs($this->user)
        ->post("/payment-requests/{$request->id}/invoices", ['invoice_id' => $invoice->id])
        ->assertRedirect();

    expect($request->invoices()->count())->toBe(1);

    $this->actingAs($this->user)
        ->delete("/payment-requests/{$request->id}/invoices/{$invoice->id}")
        ->assertRedirect();

    expect($request->invoices()->count())->toBe(0)
        ->and($request->events()->pluck('type')->all())->toBe(['invoice_unlinked', 'invoice_linked']);
});

test('comments need a body and land in the timeline', function () {
    $request = PaymentRequest::factory()->for($this->company)->create();

    $this->actingAs($this->user)
        ->post("/payment-requests/{$request->id}/comments", ['body' => '  '])
        ->assertSessionHasErrors('body');

    $this->actingAs($this->user)
        ->post("/payment-requests/{$request->id}/comments", ['body' => 'verificat cu hotelul'])
        ->assertRedirect();

    expect($request->events()->first())->toMatchArray(['type' => 'commented', 'body' => 'verificat cu hotelul', 'user_id' => $this->user->id]);
});

test('the register lists requests with status counts and filters', function () {
    PaymentRequest::factory()->for($this->company)->count(2)->create(['status' => 'payable', 'supplier_name' => 'Rida International']);
    PaymentRequest::factory()->for($this->company)->create(['status' => 'disputed', 'supplier_name' => 'Memento Turkiye']);
    PaymentRequest::factory()->create(['status' => 'paid']);

    $this->actingAs($this->user)
        ->withSession(['active_company_id' => $this->company->id])
        ->get('/payment-requests?status=disputed')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('payment-requests/index')
            ->has('requests.data', 1)
            ->where('requests.data.0.supplier_name', 'Memento Turkiye')
            ->where('requests.data.0.status_label', 'Disputat')
            ->where('counts.payable', 2)
            ->where('counts.disputed', 1)
            ->where('counts.paid', 0)
            ->where('filters.status', 'disputed')
        );

    $this->actingAs($this->user)
        ->withSession(['active_company_id' => $this->company->id])
        ->get('/payment-requests?search=rida')
        ->assertInertia(fn ($page) => $page->has('requests.data', 2));
});

test('the request page shows the snapshot, linked invoices, candidates and timeline', function () {
    $request = PaymentRequest::factory()->for($this->company)->create(['partner_id' => $this->supplier->id, 'created_by_id' => $this->user->id]);
    $linked = Invoice::factory()->for($this->company)->for($this->supplier)->create(['nr_doc' => 'LINKED']);
    $open = Invoice::factory()->for($this->company)->for($this->supplier)->create(['nr_doc' => 'OPEN', 'val_mon' => 300]);
    Invoice::factory()->for($this->company)->for($this->supplier)->create(['nr_doc' => 'SETTLED', 'val_mon' => 100, 'val_mon_paid' => 100]);
    app(PaymentRequestService::class)->linkInvoice($request, $this->user, $linked);

    $this->actingAs($this->user)
        ->get("/payment-requests/{$request->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('payment-requests/show')
            ->where('request.id', $request->id)
            ->where('request.snapshot.items', 3)
            ->has('request.invoices', 1)
            ->where('request.invoices.0.nr_doc', 'LINKED')
            ->has('candidateInvoices', 1)
            ->where('candidateInvoices.0.nr_doc', 'OPEN')
            ->where('candidateInvoices.0.rest', 300)
            ->has('request.events', 1)
            ->where('request.events.0.type', 'invoice_linked')
            ->where('currentUser.is_plati', false)
        );

    expect($open->fresh()->paymentRequests()->count())->toBe(0);
});

test('a linked request is shown on the invoice page', function () {
    $request = PaymentRequest::factory()->for($this->company)->create(['partner_id' => $this->supplier->id, 'difference_pct' => 1.5, 'level' => 'warn']);
    $invoice = Invoice::factory()->for($this->company)->for($this->supplier)->create();
    app(PaymentRequestService::class)->linkInvoice($request, $this->user, $invoice);

    $this->actingAs($this->user)
        ->get("/invoices/{$invoice->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('invoice.payment_requests', 1)
            ->where('invoice.payment_requests.0.id', $request->id)
            ->where('invoice.payment_requests.0.level', 'warn')
            ->where('invoice.timeline.0.type', 'payment_request_linked')
        );
});
