<?php

use App\Models\Department;
use App\Models\Invoice;
use App\Models\InvoiceApproval;
use App\Models\InvoiceEvent;
use App\Models\Partner;
use App\Models\User;
use App\Services\Demo\SeedDemoActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedDemoFurnizorInvoice(Department $responsabil, int $count = 25): void
{
    User::factory()->count(2)->create()->each(fn ($u) => $responsabil->members()->attach($u->id));

    for ($i = 0; $i < $count; $i++) {
        $partner = Partner::factory()->furnizor()->create();
        $partner->departments()->attach($responsabil->id);

        Invoice::factory()->create([
            'company_id' => $partner->company_id,
            'partner_id' => $partner->id,
            'partener_type' => 'furnizor',
            'val_mon' => 1000,
            'val_mon_paid' => fake()->randomElement([0, 500, 1000]),
            'payment_status' => fake()->randomElement(['unpaid', 'partial', 'paid']),
        ]);
    }
}

it('seeds approvals across the full lifecycle', function () {
    $responsabil = Department::create(['name' => 'Turism Intern', 'type' => Department::TYPE_RESPONSABIL]);
    $ordonator = Department::create(['name' => 'Ordonator', 'type' => Department::TYPE_ORDONATOR]);
    $plati = Department::create(['name' => 'Plati', 'type' => Department::TYPE_PLATI]);

    $ordonator->members()->attach(User::factory()->create()->id);
    $plati->members()->attach(User::factory()->create()->id);

    seedDemoFurnizorInvoice($responsabil, 40);

    $stats = (new SeedDemoActivity)->run();

    expect($stats['invoices'])->toBe(40)
        ->and($stats['approvals'])->toBeGreaterThan(0);

    $totalInvoices = Invoice::count();
    $fullyApproved = Invoice::where('is_fully_approved', true)->count();
    $respOk = Invoice::whereNotNull('responsabili_approved_at')->count();
    $withApprovals = Invoice::has('approvals')->count();

    expect($fullyApproved)->toBeLessThan($totalInvoices)
        ->and($respOk)->toBeGreaterThanOrEqual($fullyApproved)
        ->and($withApprovals)->toBeGreaterThan(0);

    expect(InvoiceApproval::where('role', InvoiceApproval::ROLE_RESPONSABIL)->count())->toBeGreaterThan(0);
});

it('writes events and comments tied to seeded invoices', function () {
    $responsabil = Department::create(['name' => 'Bookings', 'type' => Department::TYPE_RESPONSABIL]);
    Department::create(['name' => 'Ordonator', 'type' => Department::TYPE_ORDONATOR])
        ->members()->attach(User::factory()->create()->id);
    Department::create(['name' => 'Plati', 'type' => Department::TYPE_PLATI])
        ->members()->attach(User::factory()->create()->id);

    seedDemoFurnizorInvoice($responsabil, 30);

    $stats = (new SeedDemoActivity)->run();

    expect(InvoiceEvent::where('type', InvoiceEvent::TYPE_APPROVED)->count())
        ->toBe($stats['approvals']);

    expect(InvoiceEvent::where('type', InvoiceEvent::TYPE_COMMENTED)->count())
        ->toBe($stats['comments']);
});

it('skips invoices with no responsabil departments', function () {
    Department::create(['name' => 'Ordonator', 'type' => Department::TYPE_ORDONATOR]);

    $partner = Partner::factory()->furnizor()->create();
    Invoice::factory()->create([
        'company_id' => $partner->company_id,
        'partner_id' => $partner->id,
        'partener_type' => 'furnizor',
    ]);

    $stats = (new SeedDemoActivity)->run();

    expect($stats['invoices'])->toBe(0)
        ->and($stats['approvals'])->toBe(0);
});
