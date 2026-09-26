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

test('the inbox can show every received invoice, paid ones included', function () {
    $paid = Invoice::factory()->create([
        'company_id' => $this->company->id,
        'partner_id' => $this->partner->id,
        'partener_type' => 'furnizor',
        'data_doc' => '2026-01-10',
        'val_mon' => 500,
        'val_mon_paid' => 500,
        'approval_status' => InvoiceWorkflow::APPROVED,
    ]);

    $open = Invoice::factory()->create([
        'company_id' => $this->company->id,
        'partner_id' => $this->partner->id,
        'partener_type' => 'furnizor',
        'data_doc' => '2026-03-15',
        'val_mon' => 900,
        'val_mon_paid' => 0,
        'approval_status' => InvoiceWorkflow::DEPARTMENT,
    ]);

    $finance = User::factory()->create(['roles' => [User::ROLE_FINANCE]]);

    // Cozile lasă factura plătită afară; „Toate” o arată, cea mai nouă prima.
    $this->actingAs($finance)
        ->get('/approvals?tab=all')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('rows.total', 2)
            ->where('rows.data.0.id', $open->id)
            ->where('rows.data.1.id', $paid->id));

    $this->actingAs($finance)
        ->get('/approvals?tab=all&doc_from=2026-02-01')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('rows.total', 1)->where('rows.data.0.id', $open->id));
});

test('the queue is sorted by invoice date, newest first, unless you ask for due date', function () {
    $vechi = Invoice::factory()->create([
        'company_id' => $this->company->id, 'partner_id' => $this->partner->id, 'partener_type' => 'furnizor',
        'data_doc' => '2026-01-05', 'data_scadenta' => '2026-02-05', 'val_mon' => 100, 'val_mon_paid' => 0,
        'approval_status' => InvoiceWorkflow::ROUTING,
    ]);
    $nou = Invoice::factory()->create([
        'company_id' => $this->company->id, 'partner_id' => $this->partner->id, 'partener_type' => 'furnizor',
        'data_doc' => '2026-06-20', 'data_scadenta' => '2026-12-20', 'val_mon' => 100, 'val_mon_paid' => 0,
        'approval_status' => InvoiceWorkflow::ROUTING,
    ]);

    $finance = User::factory()->create(['roles' => [User::ROLE_FINANCE]]);

    $this->actingAs($finance)->get('/approvals?tab=routing')
        ->assertInertia(fn ($page) => $page->where('rows.data.0.id', $nou->id)->where('filters.sort', 'doc'));

    $this->actingAs($finance)->get('/approvals?tab=routing&sort=due')
        ->assertInertia(fn ($page) => $page->where('rows.data.0.id', $vechi->id)->where('filters.sort', 'due'));
});

test('every invoice shows whether it is paid, and the list can be filtered by it', function () {
    $achitata = Invoice::factory()->create([
        'company_id' => $this->company->id, 'partner_id' => $this->partner->id, 'partener_type' => 'furnizor',
        'data_doc' => '2026-02-01', 'val_mon' => 500, 'val_mon_paid' => 500,
        'approval_status' => InvoiceWorkflow::APPROVED,
    ]);
    $partiala = Invoice::factory()->create([
        'company_id' => $this->company->id, 'partner_id' => $this->partner->id, 'partener_type' => 'furnizor',
        'data_doc' => '2026-03-01', 'val_mon' => 800, 'val_mon_paid' => 300,
        'approval_status' => InvoiceWorkflow::DEPARTMENT,
    ]);

    $finance = User::factory()->create(['roles' => [User::ROLE_FINANCE]]);

    $this->actingAs($finance)->get('/approvals?tab=all')
        ->assertInertia(fn ($page) => $page
            ->where('rows.data.0.payment_status', 'partial')
            ->where('rows.data.1.payment_status', 'paid'));

    $this->actingAs($finance)->get('/approvals?tab=all&payment=paid')
        ->assertInertia(fn ($page) => $page
            ->where('rows.total', 1)
            ->where('rows.data.0.id', $achitata->id));

    $this->actingAs($finance)->get('/approvals?tab=all&payment=partial')
        ->assertInertia(fn ($page) => $page->where('rows.data.0.id', $partiala->id));
});
