<?php

use App\Models\Company;
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

test('the status follows what the ERP settled, and nothing else', function (float $paid, float $storno, string $expected) {
    $invoice = furnizorInvoice(['val_mon_paid' => $paid, 'val_mon_storno' => $storno]);

    expect($invoice->erpPaymentStatus())->toBe($expected)
        ->and($invoice->paymentStatus())->toBe($expected);
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

    $ids = fn (string $status) => Invoice::query()->paymentStatus($status)->orderBy('id')->pluck('id')->all();

    expect($ids('unpaid'))->toBe([$unpaid->id])
        ->and($ids('partial'))->toBe([$partial->id])
        ->and($ids('paid'))->toBe([$paid->id, $credited->id]);

    // Every invoice lands in the bucket its own status names.
    foreach (Invoice::all() as $invoice) {
        expect($ids($invoice->paymentStatus()))->toContain($invoice->id);
    }
});

/**
 * Statusul plății se citește din OMC și nu se scrie din aplicație: marcajul
 * manual a fost scos, cu tot cu ruta lui, ca despre aceeași plată să nu se
 * poată spune două lucruri diferite.
 */
test('the payment status cannot be marked by hand any more', function () {
    $invoice = furnizorInvoice();

    $this->actingAs(User::factory()->withRoles('treasury')->create())
        ->post("/invoices/{$invoice->id}/payment-status", ['status' => 'paid'])
        ->assertNotFound();

    expect($invoice->fresh()->paymentStatus())->toBe(Invoice::PAYMENT_UNPAID);
});

test('the list, the invoice page and the export all read the same status', function () {
    $user = User::factory()->withRoles('finance')->create();
    $invoice = furnizorInvoice(['nr_doc' => 'PAID1', 'val_mon_paid' => 1000]);

    $row = (new InvoicePresenter)->listRow($invoice->load('company', 'partner'), 'primite');

    expect($row['payment_status'])->toBe(Invoice::PAYMENT_PAID);

    $response = $this->actingAs($user)->get("/invoices/{$invoice->id}")->assertOk();
    $payload = data_get($response->viewData('page'), 'props.invoice');

    expect($payload['payment_status'])->toBe(Invoice::PAYMENT_PAID)
        ->and($payload)->not->toHaveKey('payment_status_manual');
});
