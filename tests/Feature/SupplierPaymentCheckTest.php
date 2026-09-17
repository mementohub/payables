<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\User;
use App\Services\Invoices\SupplierPaymentCheckService;
use App\Services\Omc\OmcReader;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;

beforeEach(function () {
    Carbon::setTestNow('2026-09-16 10:00:00');

    $this->company = Company::factory()->create();
    $this->supplier = Partner::factory()->for($this->company)->create(['is_furnizor' => true, 'is_client' => false]);

    // Another company is the one mirrored from OMC, so these checks read the synced invoices.
    config()->set('omc.company_id', Company::factory()->create()->id);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function supplierInvoice(Partner $supplier, array $attributes = []): Invoice
{
    return Invoice::factory()->for($supplier->company)->for($supplier)->create([
        'moneda' => 'Lei',
        'curs' => 1,
        ...$attributes,
    ]);
}

function paymentCheck(Partner $supplier, ?float $amount = null, ?string $currency = null): array
{
    return app(SupplierPaymentCheckService::class)->check($supplier, $amount, $currency);
}

test('guests are redirected to the login page', function () {
    $this->get("/suppliers/{$this->supplier->id}/payment-check")->assertRedirect('/login');
});

test('the endpoint returns the check as json for a supplier', function () {
    supplierInvoice($this->supplier, ['nr_doc' => 'F1', 'val_mon' => 500]);

    $this->actingAs(User::factory()->create())
        ->getJson("/suppliers/{$this->supplier->id}/payment-check?amount=500&currency=Lei")
        ->assertOk()
        ->assertJsonPath('requested.verdict', 'exact')
        ->assertJsonPath('requested.invoice.nr_doc', 'F1')
        ->assertJsonPath('open.0.rest', 500);
});

test('the endpoint is not available for clients', function () {
    $client = Partner::factory()->for($this->company)->create(['is_furnizor' => false, 'is_client' => true]);

    $this->actingAs(User::factory()->create())
        ->getJson("/suppliers/{$client->id}/payment-check")
        ->assertNotFound();
});

test('the endpoint validates the requested amount', function () {
    $this->actingAs(User::factory()->create())
        ->getJson("/suppliers/{$this->supplier->id}/payment-check?amount=abc")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('amount');
});

test('open invoices subtract payments and credit notes and are ordered by due date', function () {
    supplierInvoice($this->supplier, ['nr_doc' => 'LATE', 'val_mon' => 1000, 'val_mon_paid' => 200, 'val_mon_storno' => 300, 'data_scadenta' => '2026-09-01']);
    supplierInvoice($this->supplier, ['nr_doc' => 'SOON', 'val_mon' => 400, 'data_scadenta' => '2026-09-20']);
    supplierInvoice($this->supplier, ['nr_doc' => 'SETTLED', 'val_mon' => 250, 'val_mon_paid' => 100, 'val_mon_storno' => 150]);

    $result = paymentCheck($this->supplier);

    expect(collect($result['open'])->pluck('nr_doc')->all())->toBe(['LATE', 'SOON'])
        ->and($result['open'][0]['rest'])->toBe(500.0)
        ->and($result['open'][0]['days_to_due'])->toBe(-15)
        ->and($result['open_totals'])->toBe([['moneda' => 'RON', 'count' => 2, 'rest' => 900.0]])
        ->and($result['first_due'])->toBe(['date' => '2026-09-01', 'days' => -15, 'overdue' => true]);
});

test('a request equal to one open invoice is accepted', function () {
    supplierInvoice($this->supplier, ['nr_doc' => 'A', 'val_mon' => 1200, 'val_mon_paid' => 200]);
    supplierInvoice($this->supplier, ['nr_doc' => 'B', 'val_mon' => 80]);

    $requested = paymentCheck($this->supplier, 1000, 'Lei')['requested'];

    expect($requested['verdict'])->toBe('exact')
        ->and($requested['level'])->toBe('ok')
        ->and($requested['invoice']['nr_doc'])->toBe('A')
        ->and($requested['open_sum'])->toBe(1080.0);
});

test('a request equal to the sum of the open invoices is accepted', function () {
    supplierInvoice($this->supplier, ['val_mon' => 300]);
    supplierInvoice($this->supplier, ['val_mon' => 700]);

    $requested = paymentCheck($this->supplier, 1000, 'RON')['requested'];

    expect($requested['verdict'])->toBe('sum')
        ->and($requested['level'])->toBe('ok')
        ->and($requested['open_count'])->toBe(2);
});

test('a request matching an invoice that is already paid is flagged as a duplicate', function () {
    supplierInvoice($this->supplier, ['nr_doc' => 'PAID', 'val_mon' => 650, 'val_mon_paid' => 650]);
    supplierInvoice($this->supplier, ['nr_doc' => 'OTHER', 'val_mon' => 9000]);

    $requested = paymentCheck($this->supplier, 650, 'Lei')['requested'];

    expect($requested['verdict'])->toBe('paid')
        ->and($requested['level'])->toBe('crit')
        ->and($requested['invoice']['nr_doc'])->toBe('PAID')
        ->and($requested['message'])->toContain('deja plătită');
});

test('a request within three percent of the open total is flagged, not rejected', function () {
    supplierInvoice($this->supplier, ['val_mon' => 1000]);

    $requested = paymentCheck($this->supplier, 1020, 'Lei')['requested'];

    expect($requested['verdict'])->toBe('near')
        ->and($requested['level'])->toBe('warn');
});

test('a request that matches nothing is rejected', function () {
    supplierInvoice($this->supplier, ['val_mon' => 1000]);

    $requested = paymentCheck($this->supplier, 4321.5, 'Lei')['requested'];

    expect($requested['verdict'])->toBe('missing')
        ->and($requested['level'])->toBe('crit')
        ->and($requested['open_sum'])->toBe(1000.0);
});

test('matching only considers invoices in the requested currency', function () {
    supplierInvoice($this->supplier, ['val_mon' => 1000, 'moneda' => 'EUR', 'curs' => 5]);
    supplierInvoice($this->supplier, ['val_mon' => 1000, 'moneda' => 'Lei']);

    $result = paymentCheck($this->supplier, 1000, 'EUR');

    expect($result['requested']['verdict'])->toBe('exact')
        ->and($result['requested']['invoice']['moneda'])->toBe('EUR')
        ->and($result['currencies'])->toBe(['EUR', 'RON'])
        ->and(collect($result['open_totals'])->pluck('rest', 'moneda')->all())->toBe(['EUR' => 1000.0, 'RON' => 1000.0]);
});

test('the monthly pattern covers twenty-four months in lei and flags a last invoice out of pattern', function () {
    foreach (range(1, 12) as $monthsAgo) {
        supplierInvoice($this->supplier, [
            'data_doc' => Carbon::today()->subMonths($monthsAgo)->startOfMonth()->toDateString(),
            'val_mon' => 100,
            'moneda' => 'EUR',
            'curs' => 5,
        ]);
    }
    supplierInvoice($this->supplier, ['nr_doc' => 'BIG', 'data_doc' => '2026-09-10', 'val_mon' => 900, 'moneda' => 'Lei']);

    $result = paymentCheck($this->supplier);

    expect($result['pattern'])->toHaveCount(24)
        ->and($result['pattern'][23])->toMatchArray(['month' => '2026-09', 'label' => 'sep. 26', 'total_lei' => 900.0, 'count' => 1])
        ->and($result['pattern'][22]['total_lei'])->toBe(500.0)
        ->and($result['invoices_12m'])->toBe(12)
        ->and($result['average_invoice_lei'])->toBe(round((11 * 500 + 900) / 12, 2))
        ->and($result['average_month_lei'])->toBe(round((11 * 500 + 900) / 12, 2))
        ->and($result['last_invoice']['nr_doc'])->toBe('BIG')
        ->and($result['last_invoice']['total_lei'])->toBe(900.0)
        ->and($result['last_invoice']['level'])->toBe('warn');
});

test('a supplier without invoices gets an empty check', function () {
    $result = paymentCheck($this->supplier, 100, 'EUR');

    expect($result['open'])->toBe([])
        ->and($result['open_totals'])->toBe([])
        ->and($result['first_due'])->toBeNull()
        ->and($result['last_invoice'])->toBeNull()
        ->and($result['pattern'])->toHaveCount(24)
        ->and($result['requested']['verdict'])->toBe('missing');
});

test('the supplier page check reads live from omc for the company mirrored from it', function () {
    config()->set('omc.company_id', $this->company->id);
    supplierInvoice($this->supplier, ['nr_doc' => 'LOCAL-ONLY', 'val_mon' => 999]);

    $this->partialMock(OmcReader::class, function (MockInterface $mock) {
        $mock->shouldReceive('supplier')->with($this->supplier->name)->once()->andReturn([
            'name' => $this->supplier->name, 'cui' => $this->supplier->cui, 'country' => 'RO', 'city' => null, 'is_company' => true,
        ]);
        $mock->shouldReceive('supplierInvoices')->once()->andReturn([[
            'data_doc' => '2026-09-05', 'tip_doc' => 'FactFI', 'nr_doc' => 'LIVE', 'moneda' => 'Lei', 'curs' => 1,
            'val_mon' => 500, 'val_mon_tva' => 79.83, 'val_mon_pl' => 0, 'val_mon_dimin_negru' => 0,
            'data_scadenta' => '2026-09-20', 'paid_at' => null, 'description' => null, 'accounts' => null,
        ]]);
    });

    $this->actingAs(User::factory()->create())
        ->getJson("/suppliers/{$this->supplier->id}/payment-check?amount=500&currency=Lei")
        ->assertOk()
        ->assertJsonPath('source', 'omc')
        ->assertJsonPath('supplier.partner_id', $this->supplier->id)
        ->assertJsonCount(1, 'open')
        ->assertJsonPath('open.0.nr_doc', 'LIVE')
        ->assertJsonPath('requested.verdict', 'exact');
});

test('the supplier page check stays on the synced invoices for other companies', function () {
    supplierInvoice($this->supplier, ['nr_doc' => 'F1', 'val_mon' => 500]);

    $this->partialMock(OmcReader::class, function (MockInterface $mock) {
        $mock->shouldNotReceive('supplierInvoices');
    });

    $this->actingAs(User::factory()->create())
        ->getJson("/suppliers/{$this->supplier->id}/payment-check?amount=500&currency=Lei")
        ->assertOk()
        ->assertJsonPath('source', 'local')
        ->assertJsonPath('requested.invoice.nr_doc', 'F1');
});

test('an unreachable omc database falls through to a 503 on the supplier page', function () {
    config()->set('omc.company_id', $this->company->id);

    $this->partialMock(OmcReader::class, function (MockInterface $mock) {
        $mock->shouldReceive('supplier')->andThrow(new RuntimeException('connection refused'));
    });

    $this->actingAs(User::factory()->create())
        ->getJson("/suppliers/{$this->supplier->id}/payment-check")
        ->assertStatus(503)
        ->assertJsonPath('message', 'Baza OMC nu poate fi accesată: connection refused');
});

test('the live check matches the synced copies without reading them as models', function () {
    config()->set('omc.company_id', $this->company->id);
    $synced = supplierInvoice($this->supplier, ['nr_doc' => 'LIVE', 'data_doc' => '2026-09-05', 'val_mon' => 500]);

    $this->partialMock(OmcReader::class, function (MockInterface $mock) {
        $mock->shouldReceive('supplier')->andReturn([
            'name' => $this->supplier->name, 'cui' => $this->supplier->cui, 'country' => 'RO', 'city' => null, 'is_company' => true,
        ]);
        $mock->shouldReceive('supplierInvoices')->once()->andReturn([[
            'data_doc' => '2026-09-05 00:00:00', 'tip_doc' => 'FactFI', 'nr_doc' => 'LIVE', 'moneda' => 'Lei', 'curs' => 1,
            'val_mon' => 500, 'val_mon_tva' => 79.83, 'val_mon_pl' => 0, 'val_mon_dimin_negru' => 0,
            'data_scadenta' => '2026-09-20', 'paid_at' => null, 'description' => null, 'accounts' => null,
        ]]);
    });

    // Two years of a busy supplier run to tens of thousands of documents;
    // a model for each match is how this page ran out of memory.
    $hydrated = 0;
    Invoice::retrieved(function () use (&$hydrated) {
        $hydrated++;
    });

    $this->actingAs(User::factory()->create())
        ->getJson("/suppliers/{$this->supplier->id}/payment-check?amount=500&currency=Lei")
        ->assertOk()
        // The OMC row and the synced copy are the same document, whichever
        // way the two databases spell its date.
        ->assertJsonPath('open.0.id', $synced->id);

    expect($hydrated)->toBe(0);
});
