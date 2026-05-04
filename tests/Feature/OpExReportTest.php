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

test('invoices endpoint validates input', function () {
    $this->actingAs($this->user)
        ->getJson("/reports/opex/{$this->company->id}/invoices")
        ->assertStatus(422);
});

test('invoices endpoint calls service and returns json', function () {
    $service = mock(OpExReportService::class);
    $service->shouldReceive('invoicesForLeaves')
        ->once()
        ->withArgs(fn (Company $c, int $year, array $leaves) => $c->id === $this->company->id
            && $year === 2025
            && $leaves === ['CHELT_X', 'CHELT_Y']
        )
        ->andReturn([
            [
                'data_doc' => '2025-04-15',
                'month' => 4,
                'tip_doc' => 'FactFI',
                'nr_doc' => '12345',
                'partner' => 'ACME',
                'sediu' => 'Sediul Central',
                'moneda' => 'RON',
                'val_mon' => 1000.0,
                'line_total_lei' => 800.0,
                'invoice_id' => null,
            ],
        ]);
    $this->app->instance(OpExReportService::class, $service);

    $this->actingAs($this->user)
        ->getJson("/reports/opex/{$this->company->id}/invoices?year=2025&leaves[]=CHELT_X&leaves[]=CHELT_Y")
        ->assertOk()
        ->assertJsonPath('invoices.0.tip_doc', 'FactFI')
        ->assertJsonPath('invoices.0.nr_doc', '12345')
        ->assertJsonPath('invoices.0.sediu', 'Sediul Central');
});
