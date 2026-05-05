<?php

use App\Models\Company;
use App\Models\User;
use App\Services\Reports\OpExReportService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['name' => 'Acme SRL']);
});

test('guests are redirected to login', function () {
    $this->get('/reports/opex')->assertRedirect('/login');
});

test('authenticated user sees report page with companies', function () {
    $service = mock(OpExReportService::class);
    $service->shouldReceive('report')
        ->once()
        ->andReturn([
            'year' => 2025,
            'months' => range(1, 12),
            'roots' => [],
            'totals_by_month' => array_fill(1, 12, 0.0),
            'grand_total' => 0.0,
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'duration_ms' => 10,
                'leaf_count' => 0,
                'rejected_roots' => [],
            ],
        ]);
    $this->app->instance(OpExReportService::class, $service);

    $this->actingAs($this->user)
        ->get('/reports/opex?company_id='.$this->company->id.'&year=2025')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('reports/opex')
            ->where('filters.company_id', $this->company->id)
            ->where('filters.year', 2025)
            ->has('companies', 1)
            ->has('report')
        );
});

test('refresh clears cache and redirects back', function () {
    $service = mock(OpExReportService::class);
    $service->shouldReceive('clearCache')
        ->once()
        ->withArgs(fn (Company $c, int $y) => $c->id === $this->company->id && $y === 2025);
    $this->app->instance(OpExReportService::class, $service);

    $this->actingAs($this->user)
        ->from('/reports/opex')
        ->post("/reports/opex/{$this->company->id}/refresh", ['year' => 2025])
        ->assertRedirect('/reports/opex');
});

test('invoices page validates input', function () {
    $this->actingAs($this->user)
        ->get("/reports/opex/{$this->company->id}/invoices")
        ->assertSessionHasErrors(['year', 'leaves']);
});

test('invoices page renders with sediu filter', function () {
    $service = mock(OpExReportService::class);
    $service->shouldReceive('invoicesForLeaves')
        ->once()
        ->withArgs(fn (Company $c, int $year, array $leaves, ?string $sediu) => $c->id === $this->company->id
            && $year === 2025
            && $leaves === ['CHELT_X', 'CHELT_Y']
            && $sediu === 'TM Bega'
        )
        ->andReturn([
            [
                'data_doc' => '2025-04-15',
                'month' => 4,
                'tip_doc' => 'FactFI',
                'nr_doc' => '12345',
                'partner' => 'ACME',
                'sediu' => 'TM Bega',
                'moneda' => 'RON',
                'val_mon' => 1000.0,
                'line_total_lei' => 800.0,
                'invoice_id' => null,
            ],
        ]);
    $this->app->instance(OpExReportService::class, $service);

    $this->actingAs($this->user)
        ->get("/reports/opex/{$this->company->id}/invoices?year=2025&sediu=TM%20Bega&leaves[]=CHELT_X&leaves[]=CHELT_Y")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('reports/opex-invoices')
            ->where('filters.year', 2025)
            ->where('filters.sediu', 'TM Bega')
            ->has('invoices', 1)
            ->where('invoices.0.tip_doc', 'FactFI')
            ->where('invoices.0.nr_doc', '12345')
            ->where('summary.count_filtered', 1)
            ->where('summary.count_total', 1)
            ->where('options.tip_doc.0', 'FactFI')
        );
});

test('invoices page applies month and tip_doc filters', function () {
    $service = mock(OpExReportService::class);
    $service->shouldReceive('invoicesForLeaves')
        ->once()
        ->andReturn([
            [
                'data_doc' => '2025-04-15',
                'month' => 4,
                'tip_doc' => 'FactFI',
                'nr_doc' => '111',
                'partner' => 'ACME',
                'sediu' => null,
                'moneda' => 'RON',
                'val_mon' => 1000.0,
                'line_total_lei' => 800.0,
                'invoice_id' => null,
            ],
            [
                'data_doc' => '2025-05-10',
                'month' => 5,
                'tip_doc' => 'BC',
                'nr_doc' => '222',
                'partner' => 'BETA',
                'sediu' => null,
                'moneda' => 'RON',
                'val_mon' => 500.0,
                'line_total_lei' => 500.0,
                'invoice_id' => null,
            ],
        ]);
    $this->app->instance(OpExReportService::class, $service);

    $this->actingAs($this->user)
        ->get("/reports/opex/{$this->company->id}/invoices?year=2025&leaves[]=CHELT_X&month=4&tip_doc=FactFI")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('reports/opex-invoices')
            ->where('filters.month', 4)
            ->where('filters.tip_doc', 'FactFI')
            ->has('invoices', 1)
            ->where('invoices.0.nr_doc', '111')
            ->where('summary.count_filtered', 1)
            ->where('summary.count_total', 2)
        );
});
