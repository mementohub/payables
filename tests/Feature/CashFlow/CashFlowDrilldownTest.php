<?php

use App\Models\CashFlowDetail;
use App\Models\CashFlowSnapshot;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\User;
use App\Services\CashFlow\EtripCashFlowReader;
use App\Services\CashFlow\OmcCashFlowReader;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;

beforeEach(function () {
    Carbon::setTestNow('2026-09-16 10:00:00');

    $this->user = User::factory()->create();
    $this->snapshot = CashFlowSnapshot::factory()->create(['payload' => ['today' => '2026-09-16', 'params' => ['payables' => ['supplier_balance_weeks' => 2]]]]);
});

/**
 * A piece written the way the build's recorder writes it (plain dates).
 */
function cellPiece(CashFlowSnapshot $snapshot, array $attributes): void
{
    $row = [
        'cash_flow_snapshot_id' => $snapshot->id,
        'source' => 'test',
        'line' => 'C10',
        'week' => '2026-09-14',
        'actual' => false,
        'kind' => 'invoice',
        'label' => 'Furnizor',
        'lei' => 100,
        ...$attributes,
    ];

    CashFlowDetail::query()->insert([...$row, 'meta' => isset($row['meta']) ? json_encode($row['meta']) : null]);
}

function drillUrl(CashFlowSnapshot $snapshot, string $line, array $weeks, bool $actual = false): string
{
    return '/reports/cash-flow/drilldown?'.http_build_query(['snapshot' => $snapshot->id, 'line' => $line, 'weeks' => $weeks, 'actual' => $actual ? 1 : 0]);
}

test('a cell lists the pieces the build laid on it, the largest first, with the invoice they open', function () {
    $department = Department::query()->firstOrFail();
    $invoice = Invoice::factory()->create(['data_doc' => '2026-08-01', 'tip_doc' => 'FactFI', 'nr_doc' => 'A1', 'department_id' => $department->id, 'approval_status' => 'department']);
    cellPiece($this->snapshot, ['label' => 'Hotel Alfa', 'reference' => 'A1', 'group' => 'Restante', 'currency' => 'EUR', 'amount' => 1000, 'lei' => 2500, 'meta' => ['data_doc' => '2026-08-01', 'tip_doc' => 'FactFI', 'nr_doc' => 'A1', 'days_overdue' => 15, 'share' => 0.5, 'weeks' => 2]]);
    cellPiece($this->snapshot, ['label' => 'Hotel Beta', 'reference' => 'B7', 'group' => 'Scadente în săptămână', 'currency' => 'RON', 'amount' => 300, 'lei' => 300, 'meta' => ['data_doc' => '2026-09-01', 'tip_doc' => 'FactFI', 'nr_doc' => 'B7']]);
    // Another week, the actual flows of the same week, another line: not in the cell.
    cellPiece($this->snapshot, ['week' => '2026-09-21', 'lei' => 999]);
    cellPiece($this->snapshot, ['actual' => true, 'lei' => 888]);
    cellPiece($this->snapshot, ['line' => 'C1', 'lei' => 777]);

    $response = $this->actingAs($this->user)
        ->getJson(drillUrl($this->snapshot, 'C10', ['2026-09-14']))
        ->assertSuccessful()
        ->assertJsonPath('available', true)
        ->assertJsonPath('total', 2800)
        ->assertJsonPath('count', 2)
        ->assertJsonPath('rows.0.label', 'Hotel Alfa')
        ->assertJsonPath('rows.0.link.href', route('invoices.show', $invoice))
        ->assertJsonPath('rows.0.status', 'department')
        ->assertJsonPath('rows.0.department', $department->name)
        ->assertJsonPath('rows.1.link', null);

    expect($response->json('rows.0.note'))->toContain('15 zile')->toContain('1/2')
        ->and(collect($response->json('groups'))->pluck('lei', 'label')->all())->toBe(['Restante' => 2500, 'Scadente în săptămână' => 300])
        ->and($response->json('explanation'))->toContain('1/2');
});

test('a month adds up its weeks, and the actual side of the current week stays apart', function () {
    cellPiece($this->snapshot, ['line' => 'C1', 'kind' => 'services', 'week' => '2026-09-14', 'lei' => 100, 'meta' => ['connection' => 'etrip_chr', 'supplier' => '4272', 'category' => 'hotel', 'checkin_from' => '2026-09-23', 'checkin_to' => '2026-09-27', 'items' => 3]]);
    cellPiece($this->snapshot, ['line' => 'C1', 'kind' => 'services', 'week' => '2026-09-21', 'lei' => 50]);
    cellPiece($this->snapshot, ['line' => 'C1', 'kind' => 'omc_payment', 'week' => '2026-09-14', 'actual' => true, 'lei' => 70, 'meta' => ['partner' => 'Planet Tours', 'coresp' => '401', 'rule' => 'etrip']]);

    $this->actingAs($this->user)
        ->getJson(drillUrl($this->snapshot, 'C1', ['2026-09-14', '2026-09-21']))
        ->assertSuccessful()
        ->assertJsonPath('total', 150)
        ->assertJsonPath('rows.0.link.href', route('payment-checks.index', ['connection' => 'etrip_chr', 'supplier' => '4272', 'from' => '2026-09-23', 'to' => '2026-09-27', 'category' => 'hotel']));

    $this->actingAs($this->user)
        ->getJson(drillUrl($this->snapshot, 'C1', ['2026-09-14'], actual: true))
        ->assertSuccessful()
        ->assertJsonPath('total', 70)
        ->assertJsonPath('rows.0.expand', ['kind' => 'omc', 'direction' => 'out', 'week' => '2026-09-14', 'partner' => 'Planet Tours', 'coresp' => '401', 'until' => '2026-09-16']);
});

test('a snapshot built before the details were kept says so', function () {
    $this->actingAs($this->user)
        ->getJson(drillUrl($this->snapshot, 'C10', ['2026-09-14']))
        ->assertSuccessful()
        ->assertJsonPath('available', false)
        ->assertJsonPath('count', 0);
});

test('a cell needs a snapshot and its weeks', function () {
    $this->actingAs($this->user)->getJson('/reports/cash-flow/drilldown?line=C10')->assertUnprocessable();
    $this->actingAs($this->user)->getJson('/reports/cash-flow/drilldown?snapshot=999&line=C10&weeks[]=2026-09-14')->assertUnprocessable();
});

test('the documents behind a past piece are read live, up to the day the report stops', function () {
    $this->mock(OmcCashFlowReader::class, function (MockInterface $mock) {
        $mock->shouldReceive('treasuryDocuments')
            ->withArgs(fn ($from, $to, $direction, $partner, $coresp) => $from->toDateString() === '2026-09-14' && $to->toDateString() === '2026-09-16' && $direction === 'out' && $partner === 'IATA ROMANIA' && $coresp === '401')
            ->andReturn([['data_doc' => '2026-09-15', 'tip_doc' => 'OP_PL', 'nr_doc' => '035', 'currency' => 'EUR', 'amount' => 100, 'lei' => 500, 'note' => null]]);
    });
    $this->mock(EtripCashFlowReader::class, function (MockInterface $mock) {
        $mock->shouldReceive('receiptsIn')->andReturn([
            ['receipt' => 'CT 1', 'issue_date' => '2026-09-07', 'booking' => 1, 'client' => 'Ion', 'currency' => 'RON', 'amount' => 10, 'lei' => 10, 'segment_type' => 21],
            ['receipt' => 'CT 2', 'issue_date' => '2026-09-08', 'booking' => 2, 'client' => 'Ana', 'currency' => 'RON', 'amount' => 20, 'lei' => 20, 'segment_type' => 157],
        ]);
    });

    $this->actingAs($this->user)
        ->getJson('/reports/cash-flow/documents?'.http_build_query(['kind' => 'omc', 'direction' => 'out', 'week' => '2026-09-14', 'until' => '2026-09-16', 'partner' => 'IATA ROMANIA', 'coresp' => '401']))
        ->assertSuccessful()
        ->assertJsonPath('rows.0.nr_doc', '035');

    $this->actingAs($this->user)
        ->getJson('/reports/cash-flow/documents?'.http_build_query(['kind' => 'etrip_receipts', 'week' => '2026-09-07', 'connection' => 'etrip_chr', 'segment' => 'pachete']))
        ->assertSuccessful()
        ->assertJsonCount(1, 'rows')
        ->assertJsonPath('rows.0.receipt', 'CT 1');
});
