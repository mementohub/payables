<?php

use App\Models\Company;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\User;
use App\Services\Invoices\InvoicePresenter;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-09-17 10:00:00');
    $this->company = Company::factory()->create();
    $this->partner = Partner::factory()->create(['company_id' => $this->company->id]);
});

function furnizorInvoice(array $attributes = []): Invoice
{
    return Invoice::factory()->create([
        'company_id' => test()->company->id,
        'partner_id' => test()->partner->id,
        'partener_type' => 'furnizor',
        'tip_doc' => 'FactFI',
        'moneda' => 'RON',
        'curs' => 1,
        'val_mon' => 1000,
        'val_mon_tva' => 190,
        'val_mon_paid' => 0,
        'val_mon_storno' => 0,
        ...$attributes,
    ]);
}

test('the status follows what the ERP settled, with nothing marked by hand', function (float $paid, float $storno, string $expected) {
    $invoice = furnizorInvoice(['val_mon_paid' => $paid, 'val_mon_storno' => $storno]);

    expect($invoice->payment_status_manual)->toBeNull()
        ->and($invoice->erpPaymentStatus())->toBe($expected)
        ->and($invoice->paymentStatus())->toBe($expected)
        ->and($invoice->hasPaymentOverride())->toBeFalse();
})->with([
    'nothing paid' => [0.0, 0.0, Invoice::PAYMENT_UNPAID],
    'part paid' => [400.0, 0.0, Invoice::PAYMENT_PARTIAL],
    'paid in full' => [1000.0, 0.0, Invoice::PAYMENT_PAID],
    'paid to the last bani' => [999.995, 0.0, Invoice::PAYMENT_PAID],
    'offset by a credit note' => [0.0, 1000.0, Invoice::PAYMENT_PAID],
    'part paid, rest credited' => [600.0, 400.0, Invoice::PAYMENT_PAID],
]);

test('the filter returns exactly the invoices whose status the page shows', function () {
    $unpaid = furnizorInvoice(['nr_doc' => 'A1']);
    $partial = furnizorInvoice(['nr_doc' => 'A2', 'val_mon_paid' => 400]);
    $paid = furnizorInvoice(['nr_doc' => 'A3', 'val_mon_paid' => 1000]);
    $credited = furnizorInvoice(['nr_doc' => 'A4', 'val_mon_storno' => 1000]);
    $marked = furnizorInvoice(['nr_doc' => 'A5', 'payment_status_manual' => Invoice::PAYMENT_PAID, 'payment_status_updated_at' => now()]);

    $ids = fn (string $status) => Invoice::query()->paymentStatus($status)->orderBy('id')->pluck('id')->all();

    expect($ids('unpaid'))->toBe([$unpaid->id])
        ->and($ids('partial'))->toBe([$partial->id])
        ->and($ids('paid'))->toBe([$paid->id, $credited->id, $marked->id]);

    // Every invoice lands in the bucket its own status names.
    foreach (Invoice::all() as $invoice) {
        expect($ids($invoice->paymentStatus()))->toContain($invoice->id);
    }
});

test('an invoice the ERP already settled is paid however it was marked by hand', function () {
    $invoice = furnizorInvoice([
        'val_mon_paid' => 1000,
        'payment_status_manual' => Invoice::PAYMENT_UNPAID,
        'payment_status_updated_at' => now(),
    ]);

    expect($invoice->paymentStatus())->toBe(Invoice::PAYMENT_PAID)
        ->and($invoice->hasPaymentOverride())->toBeFalse()
        ->and(Invoice::query()->paymentStatus('paid')->pluck('id')->all())->toBe([$invoice->id]);
});

test('the payments department marks a payment the ERP does not have yet, and can hand the invoice back to the ERP', function () {
    $plati = Department::create(['name' => 'Plăți', 'type' => Department::TYPE_PLATI]);
    $user = User::factory()->create();
    $plati->members()->attach($user->id);

    $invoice = furnizorInvoice();

    $this->actingAs($user)
        ->post("/invoices/{$invoice->id}/payment-status", ['status' => 'paid', 'note' => 'OP 4471'])
        ->assertRedirect();

    $invoice->refresh();
    expect($invoice->paymentStatus())->toBe(Invoice::PAYMENT_PAID)
        ->and($invoice->erpPaymentStatus())->toBe(Invoice::PAYMENT_UNPAID)
        ->and($invoice->hasPaymentOverride())->toBeTrue()
        ->and($invoice->payment_status_updated_by_id)->toBe($user->id)
        ->and(Invoice::query()->paymentStatus('paid')->pluck('id')->all())->toBe([$invoice->id]);

    $this->actingAs($user)
        ->post("/invoices/{$invoice->id}/payment-status", ['status' => 'auto'])
        ->assertRedirect();

    $invoice->refresh();
    expect($invoice->payment_status_manual)->toBeNull()
        ->and($invoice->paymentStatus())->toBe(Invoice::PAYMENT_UNPAID)
        ->and($invoice->payment_status_updated_at)->toBeNull()
        ->and($invoice->payment_status_updated_by_id)->toBeNull();
});

test('the list, the invoice page and the export all read the same status', function () {
    $user = User::factory()->create();
    $invoice = furnizorInvoice(['nr_doc' => 'PAID1', 'val_mon_paid' => 1000]);

    $row = (new InvoicePresenter)->listRow($invoice->load('company', 'partner'), 'primite');

    expect($row['payment_status'])->toBe(Invoice::PAYMENT_PAID)
        ->and($row['payment_status_manual'])->toBeNull();

    $response = $this->actingAs($user)->get("/invoices/{$invoice->id}")->assertOk();
    $payload = data_get($response->viewData('page'), 'props.invoice');

    expect($payload['payment_status'])->toBe(Invoice::PAYMENT_PAID)
        ->and($payload['payment_status_erp'])->toBe(Invoice::PAYMENT_PAID)
        ->and($payload['payment_status_manual'])->toBeNull();
});
