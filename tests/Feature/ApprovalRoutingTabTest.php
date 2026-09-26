<?php

use App\Models\Company;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\User;
use App\Services\Approvals\InvoiceWorkflow;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->partner = Partner::factory()->create();
});

/**
 * Facturile care n-au primit un departament se rutau din „Facturi → Primite”,
 * o listă care dubla Aprobările. Acum stau în aceeași căsuță, într-un tab al
 * Financiarului, ca decizia și rutarea să fie în același loc.
 */
test('finance sees the unrouted invoices in the approvals inbox', function () {
    $invoice = Invoice::factory()->create([
        'company_id' => $this->company->id,
        'partner_id' => $this->partner->id,
        'partener_type' => 'furnizor',
        'val_mon' => 1000,
        'val_mon_paid' => 0,
        'approval_status' => InvoiceWorkflow::ROUTING,
    ]);

    $finance = User::factory()->create(['roles' => [User::ROLE_FINANCE]]);

    $this->actingAs($finance)
        ->get('/approvals?tab=routing')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tab', 'routing')
            ->where('can.route', true)
            ->where('counts.routing', 1)
            ->where('rows.data.0.id', $invoice->id));
});

test('someone who cannot route does not get the tab', function () {
    $department = Department::query()->create(['name' => 'Marketing', 'code' => 'mkt']);
    $user = User::factory()->create(['roles' => []]);
    $user->departments()->attach($department->id);

    $this->actingAs($user)
        ->get('/approvals')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.route', false)->where('counts.routing', 0));
});
