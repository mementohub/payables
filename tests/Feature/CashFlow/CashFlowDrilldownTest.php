<?php

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-09-16 10:00:00');

    $this->user = User::factory()->create();
});

function drilldownInvoice(string $nr, string $due, array $attributes = []): Invoice
{
    return Invoice::factory()->create(['nr_doc' => $nr, 'data_doc' => '2026-08-01', 'data_scadenta' => $due, 'val_mon' => 1000, ...$attributes]);
}

test('a week of open suppliers lists the overdue invoices at their share and the ones falling due in full', function () {
    $overdue = drilldownInvoice('RESTANTA', '2026-09-01');
    drilldownInvoice('SCADENTA', '2026-09-18', ['val_mon' => 300]);
    drilldownInvoice('VIITOARE', '2026-09-25');
    drilldownInvoice('PLATITA', '2026-09-02', ['val_mon_paid' => 1000]);
    drilldownInvoice('CLIENT', '2026-09-02', ['partener_type' => 'client']);

    $response = $this->actingAs($this->user)
        ->getJson('/reports/cash-flow/drilldown?line=C10&week=2026-09-14')
        ->assertSuccessful()
        ->assertJsonPath('spread_weeks', 2)
        ->assertJsonPath('overdue_in_week', true)
        ->assertJsonPath('totals.overdue', 500)
        ->assertJsonPath('totals.due', 300)
        ->assertJsonPath('totals.week', 800);

    expect(collect($response->json('rows'))->pluck('nr_doc')->all())->toBe(['RESTANTA', 'SCADENTA'])
        ->and($response->json('rows.0'))->toMatchArray(['id' => $overdue->id, 'kind' => 'overdue', 'days_overdue' => 15, 'week_lei' => 500]);
});

test('past the spread the overdue invoices no longer weigh on a week', function () {
    drilldownInvoice('RESTANTA', '2026-09-01');
    drilldownInvoice('VIITOARE', '2026-09-30');

    $this->actingAs($this->user)
        ->getJson('/reports/cash-flow/drilldown?line=C10&week=2026-09-28')
        ->assertSuccessful()
        ->assertJsonPath('overdue_in_week', false)
        ->assertJsonPath('rows.0.nr_doc', 'VIITOARE')
        ->assertJsonCount(1, 'rows');
});

test('only the open-suppliers line can be opened, and only for a week of the report', function () {
    $this->actingAs($this->user)->getJson('/reports/cash-flow/drilldown?line=B10&week=2026-09-14')->assertUnprocessable();
    $this->actingAs($this->user)->getJson('/reports/cash-flow/drilldown?line=C10&week=2025-01-06')->assertUnprocessable();
});
