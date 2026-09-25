<?php

use App\Models\Company;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\PnlCostOverride;
use App\Models\User;
use App\Services\Maintenance\ArtisanRunner;
use App\Services\Reports\OmcPnlCostReader;
use App\Services\Reports\PnlReportService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Mockery\MockInterface;

beforeEach(function () {
    Process::fake();

    $this->user = User::factory()->withRoles('top_management')->create();
    $this->company = Company::factory()->create(['name' => 'Christian Tour']);
    $this->dir = sys_get_temp_dir().'/payables-pnl-'.uniqid();
    $this->app->instance(ArtisanRunner::class, new ArtisanRunner($this->dir));
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

test('guests are redirected to login', function () {
    $this->get('/reports/pnl')->assertRedirect('/login');
});

test('the page picks the first company and the current year by default', function () {
    $this->mock(PnlReportService::class, fn (MockInterface $mock) => $mock->shouldReceive('cached')->andReturn(['year' => (int) now()->year]) && $mock->shouldReceive('view')->andReturn(['year' => (int) now()->year]));

    $this->actingAs($this->user)
        ->get('/reports/pnl')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('reports/pnl')
            ->where('filters.company_id', $this->company->id)
            ->where('filters.year', (int) now()->year)
            ->where('filters.view', 'channel'));
});

test('the view can be switched to product categories', function () {
    $this->mock(PnlReportService::class, fn (MockInterface $mock) => $mock->shouldReceive('cached')->andReturn(['year' => 2024]) && $mock->shouldReceive('view')->andReturn(['year' => 2024]));

    $this->actingAs($this->user)
        ->get('/reports/pnl?view=product&year=2024&company_id='.$this->company->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('filters.view', 'product')->where('filters.year', 2024));
});

test('an unknown view falls back to the channel one instead of failing', function () {
    $this->mock(PnlReportService::class, fn (MockInterface $mock) => $mock->shouldReceive('cached')->andReturn(['year' => 2024]) && $mock->shouldReceive('view')->andReturn(['year' => 2024]));

    $this->actingAs($this->user)
        ->get('/reports/pnl?view=orice')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('filters.view', 'channel'));
});

test('refreshing clears the cached report and starts the build in the background', function () {
    $this->mock(PnlReportService::class, function (MockInterface $mock) {
        $mock->shouldReceive('clearCache')->once()->withArgs(fn (Company $company, int $year) => $company->is($this->company) && $year === 2024);
    });

    $this->actingAs($this->user)
        ->from('/reports/pnl')
        ->post("/reports/pnl/{$this->company->id}/refresh", ['year' => 2024])
        ->assertRedirect('/reports/pnl');
});

test('a year that is not built yet starts the background run instead of computing it in the request', function () {
    // Construcția trece de un minut, iar PHP-FPM taie cererea la 60 de secunde.
    $this->mock(PnlReportService::class, fn (MockInterface $mock) => $mock->shouldReceive('cached')->andReturn(null));

    $this->actingAs($this->user)
        ->get('/reports/pnl?year=2019&company_id='.$this->company->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('report', null));

    Process::assertRan(fn ($process) => str_contains($process->command, 'artisan pnl:build')
        && str_contains($process->command, '--company='.$this->company->id)
        && str_contains($process->command, '--year=2019'));
});

test('a second visit does not start a second run while one is going', function () {
    $this->mock(PnlReportService::class, fn (MockInterface $mock) => $mock->shouldReceive('cached')->andReturn(null));

    app(ArtisanRunner::class)->start(ArtisanRunner::PNL, ['--year=2019']);

    $this->actingAs($this->user)->get('/reports/pnl?year=2019&company_id='.$this->company->id)->assertOk();
    $this->actingAs($this->user)->get('/reports/pnl?year=2019&company_id='.$this->company->id)->assertOk();

    // Doar pornirea din test, niciuna dintre cele două vizite.
    Process::assertRanTimes(fn ($process) => str_contains($process->command, 'artisan pnl:build'), 1);
});

test('the period is read from the query and anything odd falls back to the year', function () {
    $this->mock(PnlReportService::class, fn (MockInterface $mock) => $mock->shouldReceive('cached')->andReturn(['year' => 2024])
        && $mock->shouldReceive('view')->andReturn(['year' => 2024]));

    foreach (['q3' => 'q3', 'm11' => 'm11', 'year' => 'year', 'm13' => 'year', 'q9' => 'year', 'luna' => 'year'] as $asked => $expected) {
        $this->actingAs($this->user)
            ->get('/reports/pnl?period='.$asked)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('filters.period', $expected));
    }
});

test('the drill-down returns what is behind one cost line', function () {
    $this->mock(PnlReportService::class, function (MockInterface $mock) {
        $mock->shouldReceive('costDetails')
            ->once()
            ->withArgs(fn ($company, $year, $saf, $period) => $company->is($this->company) && $year === 2025 && $saf === '4000' && $period === 'q2')
            ->andReturn(['saf' => '4000', 'total' => 1234.0, 'items' => [], 'documents' => []]);
    });

    $this->actingAs($this->user)
        ->getJson("/reports/pnl/{$this->company->id}/details?year=2025&saf=4000&period=q2")
        ->assertOk()
        ->assertJsonPath('total', 1234);
});

test('moving a cost stores the correction without rebuilding the report', function () {
    // Construcția ia peste un minut: corecturile se adună și se aplică odată,
    // la cerere, nu una câte una.
    $this->mock(PnlReportService::class, function (MockInterface $mock) {
        $mock->shouldReceive('clearCache')->never();
        $mock->shouldReceive('cached')->andReturn(null);
        $mock->shouldReceive('view')->andReturn([]);
    });

    $this->actingAs($this->user)
        ->from('/reports/pnl')
        ->post("/reports/pnl/{$this->company->id}/move", [
            'scope' => 'item',
            'match_key' => '628||Soft Mediatel',
            'saf' => '18920',
            'label' => '628 · Soft Mediatel',
            'year' => 2025,
        ])
        ->assertRedirect('/reports/pnl');

    $this->assertDatabaseHas('pnl_cost_overrides', [
        'company_id' => $this->company->id,
        'scope' => 'item',
        'match_key' => '628||Soft Mediatel',
        'saf' => '18920',
        'created_by_id' => $this->user->id,
    ]);
});

test('a move onto a line that does not exist is refused', function () {
    $this->actingAs($this->user)
        ->post("/reports/pnl/{$this->company->id}/move", [
            'scope' => 'item',
            'match_key' => '628||Cineva',
            'saf' => 'linie-inventata',
        ])
        ->assertSessionHasErrors('saf');

    $this->assertDatabaseCount('pnl_cost_overrides', 0);
});

test('moving the same cost twice updates the correction instead of piling them up', function () {
    $this->mock(PnlReportService::class, fn (MockInterface $mock) => $mock->shouldReceive('clearCache'));

    foreach (['18920', '18200'] as $saf) {
        $this->actingAs($this->user)->post("/reports/pnl/{$this->company->id}/move", [
            'scope' => 'item', 'match_key' => '628||Soft Mediatel', 'saf' => $saf,
        ]);
    }

    $this->assertDatabaseCount('pnl_cost_overrides', 1);
    $this->assertDatabaseHas('pnl_cost_overrides', ['match_key' => '628||Soft Mediatel', 'saf' => '18200']);
});

test('a correction can be undone, and only by the company that owns it', function () {
    $this->mock(PnlReportService::class, fn (MockInterface $mock) => $mock->shouldReceive('clearCache'));

    $override = PnlCostOverride::factory()->create(['company_id' => $this->company->id]);
    $other = Company::factory()->create(['name' => 'Altcineva SRL']);

    $this->actingAs($this->user)
        ->delete("/reports/pnl/{$other->id}/overrides/{$override->id}")
        ->assertNotFound();

    $this->actingAs($this->user)
        ->from('/reports/pnl')
        ->delete("/reports/pnl/{$this->company->id}/overrides/{$override->id}")
        ->assertRedirect('/reports/pnl');

    $this->assertDatabaseCount('pnl_cost_overrides', 0);
});

test('a single invoice can be moved from the page', function () {
    $this->mock(PnlReportService::class, fn (MockInterface $mock) => $mock->shouldReceive('clearCache'));

    $this->actingAs($this->user)
        ->from('/reports/pnl')
        ->post("/reports/pnl/{$this->company->id}/move", [
            'scope' => 'document',
            'match_key' => '2026-01-20|FactFI|A7',
            'saf' => '18920',
            'label' => 'FactFI A7 · Soft Mediatel',
            'year' => 2026,
        ])
        ->assertRedirect('/reports/pnl');

    $this->assertDatabaseHas('pnl_cost_overrides', [
        'company_id' => $this->company->id,
        'scope' => 'document',
        'match_key' => '2026-01-20|FactFI|A7',
        'saf' => '18920',
    ]);
});

test('an unknown scope is refused', function () {
    $this->actingAs($this->user)
        ->post("/reports/pnl/{$this->company->id}/move", [
            'scope' => 'altceva',
            'match_key' => 'x',
            'saf' => '18920',
        ])
        ->assertSessionHasErrors('scope');

    $this->assertDatabaseCount('pnl_cost_overrides', 0);
});

test('the accounting basis is read from the query and anything odd falls back to statutory', function () {
    $this->mock(PnlReportService::class, function (MockInterface $mock) {
        $mock->shouldReceive('cached')->andReturn(['year' => 2025]);
        $mock->shouldReceive('view')->andReturn(['year' => 2025]);
    });

    foreach (['ifrs16' => 'ifrs16', 'ras' => 'ras', 'ifrs9' => 'ras', '' => 'ras'] as $asked => $expected) {
        $this->actingAs($this->user)
            ->get('/reports/pnl?basis='.$asked)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('filters.basis', $expected));
    }
});

test('the comparison loads the previous year for the same period', function () {
    $this->mock(PnlReportService::class, function (MockInterface $mock) {
        $mock->shouldReceive('cached')->with(Mockery::any(), 2026)->andReturn(['year' => 2026]);
        $mock->shouldReceive('cached')->with(Mockery::any(), 2025)->andReturn(['year' => 2025]);
        $mock->shouldReceive('view')->andReturnUsing(fn (array $report) => ['year' => $report['year']]);
    });

    $this->actingAs($this->user)
        ->get('/reports/pnl?year=2026&period=q2&compare=1&company_id='.$this->company->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.compare', true)
            ->where('report.year', 2026)
            ->where('previous.year', 2025));
});

test('without the comparison the previous year is not even read', function () {
    $this->mock(PnlReportService::class, function (MockInterface $mock) {
        $mock->shouldReceive('cached')->once()->andReturn(['year' => 2026]);
        $mock->shouldReceive('view')->andReturn(['year' => 2026]);
    });

    $this->actingAs($this->user)
        ->get('/reports/pnl?year=2026&company_id='.$this->company->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('previous', null));
});

test('the page counts the corrections that are not in the numbers yet', function () {
    $this->mock(PnlReportService::class, function (MockInterface $mock) {
        // Raport construit ieri; corectura de azi nu e încă în el.
        $mock->shouldReceive('cached')->andReturn([
            'year' => 2026,
            'meta' => ['generated_at' => now()->subDay()->toIso8601String()],
        ]);
        $mock->shouldReceive('view')->andReturn(['year' => 2026]);
    });

    PnlCostOverride::factory()->count(2)->create(['company_id' => $this->company->id]);
    $old = PnlCostOverride::factory()->create(['company_id' => $this->company->id, 'match_key' => 'vechi']);
    $old->forceFill(['updated_at' => now()->subWeek()])->saveQuietly();

    $this->actingAs($this->user)
        ->get('/reports/pnl?company_id='.$this->company->id.'&year=2026')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('pending', 2));
});

test('applying rebuilds once, whatever the number of corrections', function () {
    $this->mock(PnlReportService::class, function (MockInterface $mock) {
        $mock->shouldReceive('clearCache')->once();
    });

    PnlCostOverride::factory()->count(5)->create(['company_id' => $this->company->id]);

    $this->actingAs($this->user)
        ->from('/reports/pnl')
        ->post("/reports/pnl/{$this->company->id}/refresh", ['year' => 2026])
        ->assertRedirect('/reports/pnl');
});

/**
 * Numele liniilor de cost sunt lungi („Cheltuieli salarii angajați
 * (salarii+contribuții)”), iar prima coloană stă fixată peste care defilează
 * restul tabelului. `truncate` pe un `span` în linie nu taie nimic, așa că
 * numele ieșea din celulă peste coloana de total. Celula trebuie să aibă
 * lățime fixă și să taie ce nu încape.
 */
test('the line column keeps long names inside it', function () {
    $page = file_get_contents(resource_path('js/pages/reports/pnl.tsx'));

    expect($page)->toContain("'sticky left-0 z-10 w-80 max-w-80 min-w-80 overflow-hidden bg-card'")
        // Lățimea stă pe elementul dinăuntru: `max-width` pe o celulă de tabel
        // e ignorată de algoritmul de așezare, deci singură nu taie nimic.
        ->and($page)->toContain('<span className="flex w-72 min-w-0 items-center">')
        ->and($page)->toContain('<span className="truncate">{label}</span>');
});

test('a cost can be moved onto another product category', function () {
    // Vederea pe categorii trebuie să poată muta pe categorii; până acum se
    // putea alege doar canalul de vânzare, care nu are ce căuta acolo.
    $this->mock(PnlReportService::class, function (MockInterface $mock) {
        $mock->shouldReceive('clearCache')->never();
        $mock->shouldReceive('cached')->andReturn(['products' => ['Charter', 'Croaziere']]);
    });

    $this->actingAs($this->user)
        ->from('/reports/pnl')
        ->post("/reports/pnl/{$this->company->id}/move", [
            'scope' => 'item',
            'match_key' => '623||Google Ireland',
            'product' => 'Croaziere',
            'label' => '623 · Google Ireland',
            'year' => 2026,
        ])
        ->assertRedirect('/reports/pnl');

    $this->assertDatabaseHas('pnl_cost_overrides', [
        'match_key' => '623||Google Ireland',
        'product' => 'Croaziere',
        'saf' => null,
        'channel' => null,
    ]);
});

test('a category that is not in the report is refused', function () {
    $this->mock(PnlReportService::class, fn (MockInterface $mock) => $mock->shouldReceive('cached')->andReturn(['products' => ['Charter']]));

    $this->actingAs($this->user)
        ->post("/reports/pnl/{$this->company->id}/move", [
            'scope' => 'item',
            'match_key' => '623||Google Ireland',
            'product' => 'Ceva inventat',
        ])
        ->assertSessionHasErrors('product');

    $this->assertDatabaseCount('pnl_cost_overrides', 0);
});

test('choosing a category keeps the line and the channel chosen before', function () {
    // Cele trei axe se aleg din vederi diferite; una n-o șterge pe cealaltă.
    $this->mock(PnlReportService::class, fn (MockInterface $mock) => $mock->shouldReceive('cached')->andReturn(['products' => ['Croaziere']]));

    $this->actingAs($this->user)->post("/reports/pnl/{$this->company->id}/move", [
        'scope' => 'item', 'match_key' => '623||Google Ireland', 'saf' => '18920', 'channel' => 'site',
    ]);

    $this->actingAs($this->user)->post("/reports/pnl/{$this->company->id}/move", [
        'scope' => 'item', 'match_key' => '623||Google Ireland', 'product' => 'Croaziere',
    ]);

    $this->assertDatabaseCount('pnl_cost_overrides', 1);
    $this->assertDatabaseHas('pnl_cost_overrides', [
        'match_key' => '623||Google Ireland',
        'saf' => '18920',
        'channel' => 'site',
        'product' => 'Croaziere',
    ]);
});

test('a correction with nothing chosen is refused', function () {
    $this->actingAs($this->user)
        ->post("/reports/pnl/{$this->company->id}/move", [
            'scope' => 'item',
            'match_key' => '623||Google Ireland',
        ])
        ->assertSessionHasErrors('saf');

    $this->assertDatabaseCount('pnl_cost_overrides', 0);
});

/**
 * Butoanele de vedere cu explicație („Venit și COGS”, „Chirii”) își pierduseră
 * evidențierea: `TooltipTrigger asChild` scrie `data-state` pe copil, iar
 * butonul din grup se arată apăsat tocmai după `data-state=on`. Declanșatorul
 * tooltipului trebuie să stea înăuntrul butonului.
 */
test('a view button with an explanation still shows which one is chosen', function () {
    $page = file_get_contents(resource_path('js/pages/reports/pnl.tsx'));

    expect($page)->not->toContain("<TooltipTrigger asChild>\n                <ToggleGroupItem")
        ->and($page)->toContain("<ToggleGroupItem value={value}>\n            <Tooltip>");
});

/**
 * Panoul de documente arată factura întreagă, nu doar felia din linie:
 * scadență, monedă, TVA, cine a emis-o, plus ce știe aplicația despre ea —
 * departament, aprobare, plată.
 */
test('a document carries the whole invoice behind it', function () {
    $company = $this->company;

    $document = [
        'data_doc' => '2026-02-28', 'tip_doc' => 'FactFI', 'nr_doc' => 'BP2026 0319',
        'account' => '6232', 'sediu' => 'Google Awards', 'partner' => 'Google Ireland',
        'note' => 'Promovare', 'month' => 2, 'lei' => 100.0,
        'currency' => 'Lei', 'rate' => 1.0, 'doc_total' => 561767.38, 'doc_vat' => 0.0,
        'due' => '2026-03-30', 'issued' => '2026-02-28', 'issuer' => 'OSICEANU EMILIA',
        'observation' => '5509890289 Google Ireland Limited', 'partner_office' => 'Google',
        'partner_account' => '401', 'journal' => 'Furnizori',
    ];

    $this->mock(OmcPnlCostReader::class, function (MockInterface $mock) use ($document) {
        $mock->shouldReceive('details')->andReturn([$document]);
    });

    $department = Department::query()->create(['name' => 'Marketing', 'code' => 'mkt']);
    Invoice::factory()->create([
        'company_id' => $company->id,
        'department_id' => $department->id,
        'data_doc' => '2026-02-28',
        'tip_doc' => 'FactFI',
        'nr_doc' => 'BP2026 0319',
        'val_mon' => 561767.38,
        'val_mon_paid' => 561767.38,
        'approval_status' => 'approved',
    ]);

    $details = app(PnlReportService::class)->costDetails($company, 2026, '16950', 'ytd8');
    $row = $details['documents'][0];

    expect($row['due'])->toBe('2026-03-30')
        ->and($row['doc_total'])->toBe(561767.38)
        ->and($row['issuer'])->toBe('OSICEANU EMILIA')
        ->and($row['invoice']['department'])->toBe('Marketing')
        ->and($row['invoice']['approval_status'])->toBe('approved')
        ->and($row['invoice']['payment_status'])->toBe('paid');
});

test('a document the application does not have still shows what the ledger knows', function () {
    $this->mock(OmcPnlCostReader::class, function (MockInterface $mock) {
        $mock->shouldReceive('details')->andReturn([[
            'data_doc' => '2026-02-28', 'tip_doc' => 'FactFI', 'nr_doc' => 'NEPRINSA',
            'account' => '6232', 'sediu' => 'Google Awards', 'partner' => 'Google Ireland', 'note' => 'Promovare',
            'month' => 2, 'lei' => 50.0, 'currency' => 'EUR', 'rate' => 4.97,
            'doc_total' => 10.0, 'doc_vat' => 1.9, 'due' => null, 'issued' => null,
            'issuer' => '', 'observation' => '', 'partner_office' => '',
            'partner_account' => '401', 'journal' => 'Furnizori',
        ]]);
    });

    $row = app(PnlReportService::class)->costDetails($this->company, 2026, '16950', 'ytd8')['documents'][0];

    expect($row['invoice'])->toBeNull()
        ->and($row['currency'])->toBe('EUR')
        ->and($row['rate'])->toBe(4.97);
});
