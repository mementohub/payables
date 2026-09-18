<?php

use App\Models\EtripSupplier;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\User;
use App\Services\Etrip\EtripReader;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;

beforeEach(function () {
    Carbon::setTestNow('2026-09-18 10:00:00');

    $this->user = User::factory()->create();
});

function reconciliationCoverage(string $month, int $type, string $currency, float $cost, float $invoiced, bool $done = true, ?string $source = null, int $unbilled = 0, float $unbilledCost = 0): array
{
    return ['month' => $month, 'product_type' => $type, 'currency' => $currency, 'done' => $done, 'source' => $source, 'services' => 10,
        'cost' => $cost, 'invoiced' => $invoiced, 'unbilled_services' => $unbilled, 'unbilled_cost' => $unbilledCost];
}

function reconciliationInvoice(int $id, string $number, string $date, float $amount, float $paid, ?string $due, bool $finalized = true): array
{
    return ['id' => $id, 'number' => $number, 'date' => $date, 'currency' => 'USD', 'amount' => $amount, 'due_date' => $due, 'finalized' => $finalized,
        'good_for_payment' => $finalized, 'lines' => 3, 'bookings' => 2, 'checkin_from' => '2026-07-01', 'checkin_to' => '2026-07-05', 'etrip_cost' => $amount, 'paid' => $paid];
}

/**
 * @param  list<array<string, mixed>>  $invoices
 */
function mockReconciliationReader(bool $secondaryOmc, array $invoices, array $payments = []): void
{
    test()->mock(EtripReader::class, function (MockInterface $mock) use ($secondaryOmc, $invoices, $payments) {
        $mock->shouldReceive('supplierProfile')->andReturn([
            'code' => '4272', 'name' => 'Planet Tours & Travel', 'country' => 'EG', 'currency' => 'EUR', 'active' => true,
            'balance_due' => '70 days', 'balance_due_days' => 70, 'self_billing' => false, 'vat_rate' => 0, 'vat_no' => null,
            'company_no' => null, 'web_access' => false, 'use_secondary_omc_connection' => $secondaryOmc, 'created_at' => '2025-11-25',
        ]);
        $mock->shouldReceive('productTypes')->andReturn([7 => 'Hotel allotment', 5 => 'Transfer']);
        $mock->shouldReceive('serviceCoverage')->andReturn([
            reconciliationCoverage('2026-06', 7, 'USD', 100000, 100500),
            reconciliationCoverage('2026-07', 7, 'USD', 100000, 95000, unbilled: 4, unbilledCost: 5000),
            reconciliationCoverage('2026-07', 5, 'USD', 20000, 100, unbilled: 30, unbilledCost: 19900),
            reconciliationCoverage('2026-08', 7, 'USD', 100000, 99000),
            reconciliationCoverage('2026-09', 7, 'USD', 50000, 10000),
            reconciliationCoverage('2026-09', 7, 'USD', 30000, 0, done: false),
            reconciliationCoverage('2026-10', 7, 'USD', 70000, 0, done: false),
        ]);
        $mock->shouldReceive('supplierInvoices')->andReturn($invoices);
        $mock->shouldReceive('supplierPayments')->andReturn($payments);
    });
}

test('services are set against the invoice lines per month and per type, with the thresholds of the handover', function () {
    $supplier = EtripSupplier::factory()->create(['code' => '4272']);
    mockReconciliationReader(true, [
        reconciliationInvoice(1, 'Q056 arr 17-8', '2026-08-17', 1000, 0, '2026-08-17'),
        reconciliationInvoice(2, 'Arrv 11.05.2026.C', '2026-05-12', -200, 0, '2026-05-12'),
        reconciliationInvoice(3, 'N053 arr 13-7', '2026-07-13', 500, 500, '0206-07-25'),
        reconciliationInvoice(4, 'U092 arr 7-9', '2026-09-07', 300, 0, '2026-09-07', finalized: false),
    ], [['currency' => 'USD', 'payments' => 2, 'total' => 400, 'first' => '2026-02-01', 'last' => '2026-07-01']]);

    $response = $this->actingAs($this->user)
        ->getJson('/payment-checks/reconcile?connection=etrip_chr&supplier=4272&from=2026-01-01&to=2026-09-18')
        ->assertSuccessful()
        ->assertJsonPath('supplier.secondary_omc', true)
        ->assertJsonPath('supplier.manual', true)
        ->assertJsonPath('payments_source', 'etrip')
        ->assertJsonPath('omc', null);

    $months = collect($response->json('months'))->keyBy('month');
    $types = collect($response->json('types'))->keyBy('label');
    $invoices = collect($response->json('invoices'))->keyBy('id');

    expect($months['2026-06'])->toMatchArray(['diff' => 500, 'diff_pct' => 0.5, 'alert' => false, 'status' => 'closed'])
        // Hotels and transfers together: 24,900 short of 120,000.
        ->and($months['2026-07'])->toMatchArray(['cost' => 120000, 'billed' => 95100, 'unbilled_cost' => 24900, 'alert' => true])
        ->and($months['2026-08']['alert'])->toBeFalse()
        // The current month is still being billed.
        ->and($months['2026-09'])->toMatchArray(['status' => 'billing', 'alert' => false])
        ->and($types['Transfer'])->toMatchArray(['alert' => true, 'window_billed_pct' => 0.5])
        ->and($types['Hotel allotment']['alert'])->toBeFalse()
        ->and($response->json('future'))->toBe([
            ['month' => '2026-09', 'currency' => 'USD', 'services' => 10, 'cost' => 30000, 'billed' => 0],
            ['month' => '2026-10', 'currency' => 'USD', 'services' => 10, 'cost' => 70000, 'billed' => 0],
        ])
        // The eTrip due date equals the invoice date: the 70-day term decides.
        ->and($invoices[1])->toMatchArray(['due' => '2026-10-26', 'due_source' => 'term', 'days_overdue' => 0, 'open' => 1000])
        ->and($invoices[2]['correction'])->toBeTrue()
        ->and($invoices[3])->toMatchArray(['invalid_due' => true, 'due' => '2026-09-21', 'open' => 0])
        ->and($response->json('kpis.0'))->toMatchArray(['currency' => 'USD', 'cost' => 370000, 'billed' => 304600, 'future_cost' => 100000, 'invoiced' => 1600])
        // 500 allocated on the invoices, 400 paid in the range.
        ->and($response->json('payments.0'))->toMatchArray(['paid' => 400, 'allocated' => 500, 'difference' => 100])
        ->and(collect($response->json('alerts'))->pluck('kind')->all())->toBe(['types', 'months', 'draft', 'invalid_due']);
});

test('a supplier of the main OMC base is paid there, so each invoice takes its status from the mirror', function () {
    $partner = Partner::factory()->create();
    $supplier = EtripSupplier::factory()->create(['code' => '10', 'partner_id' => $partner->id]);
    $paid = Invoice::factory()->for($partner)->create(['nr_doc' => 'RO 260097', 'data_doc' => '2026-08-01', 'val_mon' => 1000, 'val_mon_paid' => 1000]);
    $open = Invoice::factory()->for($partner)->create(['nr_doc' => 'RO260098', 'data_doc' => '2026-08-02', 'data_scadenta' => '2026-09-01', 'val_mon' => 800, 'val_mon_paid' => 300]);
    mockReconciliationReader(false, [
        reconciliationInvoice(1, 'ro260097', '2026-08-01', 1000, 0, '2026-08-01'),
        reconciliationInvoice(2, 'RO260098', '2026-08-02', 800, 0, '2026-08-02'),
        reconciliationInvoice(3, 'RO260099', '2026-08-03', 400, 0, '2026-08-03'),
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/payment-checks/reconcile?connection=etrip_chr&supplier=10&from=2026-01-01&to=2026-09-18')
        ->assertSuccessful()
        ->assertJsonPath('payments_source', 'omc')
        ->assertJsonPath('omc.partner_id', $partner->id);

    $invoices = collect($response->json('invoices'))->keyBy('id');

    expect($invoices[1])->toMatchArray(['open' => 0, 'paid' => 1000])
        ->and($invoices[1]['omc']['id'])->toBe($paid->id)
        ->and($invoices[2])->toMatchArray(['open' => 500, 'due' => '2026-09-01', 'due_source' => 'omc', 'days_overdue' => 17])
        ->and($invoices[2]['omc']['id'])->toBe($open->id)
        ->and($invoices[3])->toMatchArray(['open' => null, 'omc' => null])
        ->and($response->json('kpis.0'))->toMatchArray(['open' => 500, 'overdue' => 500, 'open_unknown' => 1])
        ->and(collect($response->json('alerts'))->firstWhere('kind', 'omc_missing'))->toMatchArray(['count' => 1, 'detail' => 'RO260099']);
});

test('a main-base supplier not tied to an OMC partner is said so rather than shown as unpaid', function () {
    EtripSupplier::factory()->create(['code' => '77']);
    mockReconciliationReader(false, [reconciliationInvoice(1, 'A1', '2026-08-01', 1000, 0, '2026-08-01')]);

    $response = $this->actingAs($this->user)
        ->getJson('/payment-checks/reconcile?connection=etrip_chr&supplier=77&from=2026-01-01&to=2026-09-18')
        ->assertSuccessful()
        ->assertJsonPath('invoices.0.open', null)
        ->assertJsonPath('omc.partner_id', null);

    expect(collect($response->json('alerts'))->pluck('kind'))->toContain('unlinked')->not->toContain('overdue');
});

test('the check needs a known etrip base and a range', function () {
    $this->actingAs($this->user)->getJson('/payment-checks/reconcile?connection=nope&supplier=1&from=2026-01-01&to=2026-02-01')->assertUnprocessable();
    $this->actingAs($this->user)->getJson('/payment-checks/reconcile?connection=etrip_chr&supplier=1&from=2026-02-01&to=2026-01-01')->assertUnprocessable();
});

test('a link opens the invoices tab with its period', function () {
    $this->actingAs($this->user)
        ->get('/payment-checks?view=invoices&connection=etrip_chr&supplier=4272&period_from=2026-01-01&period_to=2026-06-30')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.view', 'invoices')
            ->where('filters.period_from', '2026-01-01')
            ->where('filters.period_to', '2026-06-30')
        );
});
