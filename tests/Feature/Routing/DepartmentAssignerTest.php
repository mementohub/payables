<?php

use App\Models\AssignmentRule;
use App\Models\CharterContract;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\InvoiceDetail;
use App\Models\InvoiceLineDepartment;
use App\Models\Partner;
use App\Models\User;
use App\Services\Routing\BookingResolver;
use App\Services\Routing\DepartmentAssigner;
use Mockery\MockInterface;

beforeEach(function () {
    $this->ids = Department::idsByCode();

    // The seeded taxonomy and rules are in place (migrations); eTrip is not.
    $this->mock(BookingResolver::class, function (MockInterface $mock) {
        $mock->shouldReceive('resolve')->andReturnUsing(fn (array $ids) => collect($ids)->mapWithKeys(fn (int $id) => [$id => match ($id) {
            1234567 => ['department' => 'circuite_culturale', 'channel' => 'sales_b2b', 'detail' => 'Rezervarea eTrip 1234567: 2.Christian Tour Circuite (furnizor intern)'],
            12345678 => ['department' => 'charters', 'channel' => 'sales_b2b', 'detail' => 'Rezervarea Vacanza 12345678'],
            default => ['department' => null, 'channel' => null, 'detail' => ''],
        }])->all());
    });
});

function assignerInvoice(array $attributes = [], ?Partner $partner = null): Invoice
{
    return Invoice::factory()->for($partner ?? Partner::factory()->create(['name' => 'Furnizor '.uniqid()]), 'partner')->create($attributes);
}

function assignerLine(Invoice $invoice, int $scv, array $attributes = []): InvoiceDetail
{
    return InvoiceDetail::factory()->create(['invoice_id' => $invoice->id, 'scv' => $scv, ...$attributes]);
}

function assignedLine(Invoice $invoice, int $scv): InvoiceLineDepartment
{
    return InvoiceLineDepartment::query()->where('invoice_id', $invoice->id)->where('scv', $scv)->sole();
}

test('a tourism line goes to the product category of its booking, with the booking channel', function () {
    $invoice = assignerInvoice();
    assignerLine($invoice, 1, ['com_int' => '1234567.', 'cant' => 1, 'pret' => 800]);
    assignerLine($invoice, 2, ['com_int' => '12345678', 'cant' => 1, 'pret' => 200]);

    app(DepartmentAssigner::class)->assign(collect([$invoice]));
    $invoice->refresh();

    expect(assignedLine($invoice, 1)->only(['department_id', 'channel_id', 'rule']))->toBe(['department_id' => $this->ids['circuite_culturale'], 'channel_id' => $this->ids['sales_b2b'], 'rule' => 'booking'])
        ->and(assignedLine($invoice, 1)->detail)->toContain('1234567')
        ->and(assignedLine($invoice, 2)->department_id)->toBe($this->ids['charters'])
        // The invoice belongs to the department holding most of its value.
        ->and($invoice->department_id)->toBe($this->ids['circuite_culturale'])
        ->and($invoice->assignment_state)->toBe('assigned');
});

test('the cost centre decides an overhead line, and what the other rules said is kept for the shadow check', function () {
    $partner = Partner::factory()->create(['name' => 'META PLATFORMS IRELAND']);
    AssignmentRule::query()->create(['kind' => 'partner', 'pattern' => 'META PLATFORMS IRELAND', 'department_id' => $this->ids['marketing']]);

    $invoice = assignerInvoice(partner: $partner);
    assignerLine($invoice, 1, ['account' => '6231', 'loc' => 'HQ']);
    assignerLine($invoice, 2, ['account' => '6231', 'loc' => 'MARK']);
    assignerLine($invoice, 3, ['account' => '6231', 'loc' => 'B 751 CHR']);
    assignerLine($invoice, 4, ['account' => '6231', 'loc' => 'Agentia Ploiesti']);

    app(DepartmentAssigner::class)->assign(collect([$invoice]));

    expect(assignedLine($invoice, 1)->only(['department_id', 'predicted_department_id', 'rule']))->toBe(['department_id' => $this->ids['administration'], 'predicted_department_id' => $this->ids['marketing'], 'rule' => 'loc'])
        ->and(assignedLine($invoice, 2)->department_id)->toBe($this->ids['marketing'])
        // A bus plate is the fleet, an agency name one of the own shops.
        ->and(assignedLine($invoice, 3)->department_id)->toBe($this->ids['administration'])
        ->and(assignedLine($invoice, 4)->department_id)->toBe($this->ids['b2c_retail']);
});

test('charter seats, Tina tickets and the office route lines that carry no booking', function () {
    CharterContract::factory()->create(['counterparty' => 'Memento Air S.R.L.']);
    $charter = assignerInvoice(partner: Partner::factory()->create(['name' => 'Memento Air Srl']));
    assignerLine($charter, 1, ['com_int' => '10424', 'detaliu_articol' => '100 seats FH4258/4259']);

    $ticket = assignerInvoice();
    assignerLine($ticket, 1, ['com_int' => 'TN_556677']);
    $corporateTicket = assignerInvoice(['office' => 'Corporate']);
    assignerLine($corporateTicket, 1, ['com_int' => 'TN_556678']);

    $fair = assignerInvoice(['office' => 'Targ Turism 2026']);
    assignerLine($fair, 1, ['account' => '6232']);

    app(DepartmentAssigner::class)->assign(collect([$charter, $ticket, $corporateTicket, $fair]));

    expect(assignedLine($charter, 1)->only(['department_id', 'rule']))->toBe(['department_id' => $this->ids['charters'], 'rule' => 'charter'])
        ->and(assignedLine($ticket, 1)->department_id)->toBe($this->ids['ticketing'])
        ->and(assignedLine($corporateTicket, 1)->department_id)->toBe($this->ids['corporate'])
        ->and(assignedLine($fair, 1)->only(['department_id', 'rule']))->toBe(['department_id' => $this->ids['marketing'], 'rule' => 'office']);
});

test('an untagged line follows where the supplier\'s tagged lines on the same account went', function () {
    $partner = Partner::factory()->create(['name' => 'PWC ROMANIA']);

    foreach (range(1, 3) as $i) {
        $past = assignerInvoice(partner: $partner);
        assignerLine($past, 1, ['account' => '628', 'loc' => 'Financiar']);
        app(DepartmentAssigner::class)->assign(collect([$past]));
    }

    $new = assignerInvoice(partner: $partner);
    assignerLine($new, 1, ['account' => '628']);
    assignerLine($new, 2, ['account' => '6588']);
    $unknown = assignerInvoice();
    assignerLine($unknown, 1, ['account' => '401']);

    app(DepartmentAssigner::class)->assign(collect([$new, $unknown]));

    expect(assignedLine($new, 1)->only(['department_id', 'rule']))->toBe(['department_id' => $this->ids['financial'], 'rule' => 'history'])
        // Another account: the supplier's lines overall.
        ->and(assignedLine($new, 2)->department_id)->toBe($this->ids['financial'])
        ->and(assignedLine($unknown, 1)->only(['department_id', 'rule']))->toBe(['department_id' => null, 'rule' => 'none'])
        ->and($unknown->fresh()->assignment_state)->toBe('unassigned');
});

test('a line routed by hand stays put when the rules run again, until it is released', function () {
    $user = User::factory()->create();
    $invoice = assignerInvoice();
    assignerLine($invoice, 1, ['com_int' => '1234567']);
    assignerLine($invoice, 2, ['com_int' => '1234567']);
    $assigner = app(DepartmentAssigner::class);
    $assigner->assign(collect([$invoice]));

    $assigner->assignManually($invoice, Department::query()->where('code', 'senior_voyage')->sole(), $user->id, [2]);
    $assigner->assign(collect([$invoice->fresh()]));

    expect(assignedLine($invoice, 1)->department_id)->toBe($this->ids['circuite_culturale'])
        ->and(assignedLine($invoice, 2)->only(['department_id', 'is_manual', 'assigned_by_id']))->toBe(['department_id' => $this->ids['senior_voyage'], 'is_manual' => true, 'assigned_by_id' => $user->id]);

    $assigner->release($invoice->fresh());

    expect(assignedLine($invoice, 2)->only(['department_id', 'is_manual']))->toBe(['department_id' => $this->ids['circuite_culturale'], 'is_manual' => false]);
});

test('only invoices never routed, or changed in OMC since, are routed again', function () {
    $routed = assignerInvoice(['omc_modified_at' => now()->subDay()]);
    assignerLine($routed, 1, ['com_int' => '1234567']);
    $fresh = assignerInvoice();
    assignerLine($fresh, 1, ['com_int' => '1234567']);
    $removed = assignerInvoice(['omc_removed_at' => now()]);
    assignerLine($removed, 1, ['com_int' => '1234567']);

    $assigner = app(DepartmentAssigner::class);
    $assigner->assign(collect([$routed]));

    expect($assigner->assignPending()['invoices'])->toBe(1)
        ->and($assigner->assignPending()['invoices'])->toBe(0);

    $routed->forceFill(['omc_modified_at' => now()->addMinute()])->save();

    expect($assigner->assignPending()['invoices'])->toBe(1)
        ->and($removed->fresh()->assigned_at)->toBeNull();
});
