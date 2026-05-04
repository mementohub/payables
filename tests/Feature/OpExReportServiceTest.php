<?php

use App\Models\Company;
use App\Services\RemoteConnection;
use App\Services\Reports\OpExReportService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Cache;

function makeService(array $monthly, array $chains): OpExReportService
{
    $remote = Mockery::mock(ConnectionInterface::class);
    $remote->shouldReceive('select')->andReturnUsing(function (string $sql) use ($monthly, $chains) {
        if (str_contains($sql, 'EXTRACT(MONTH')) {
            return array_map(fn ($r) => (object) $r, $monthly);
        }

        return array_map(fn ($r) => (object) $r, $chains);
    });

    $connection = Mockery::mock(RemoteConnection::class);
    $connection->shouldReceive('connection')->andReturn($remote);

    return new OpExReportService($connection);
}

beforeEach(function () {
    Cache::flush();
});

test('builds tree, rolls up totals, sorts roots by total desc', function () {
    $service = makeService(
        monthly: [
            ['leaf' => 'leaf-a', 'month' => 1, 'total_lei' => 100],
            ['leaf' => 'leaf-a', 'month' => 2, 'total_lei' => 50],
            ['leaf' => 'leaf-b', 'month' => 3, 'total_lei' => 1000],
        ],
        chains: [
            ['leaf' => 'leaf-a', 'depth' => 0, 'code' => 'leaf-a', 'label' => 'Leaf A label'],
            ['leaf' => 'leaf-a', 'depth' => 1, 'code' => 'SUB-A', 'label' => 'Sub category A'],
            ['leaf' => 'leaf-a', 'depth' => 2, 'code' => 'ROOT-1', 'label' => 'ROOT 1'],
            ['leaf' => 'leaf-b', 'depth' => 0, 'code' => 'leaf-b', 'label' => 'Leaf B label'],
            ['leaf' => 'leaf-b', 'depth' => 1, 'code' => 'ROOT-2', 'label' => 'ROOT 2'],
        ],
    );

    $company = Company::factory()->make();
    $company->id = 999;

    $report = $service->report($company, 2025, true);

    expect($report['grand_total'])->toBe(1150.0);
    expect($report['totals_by_month'][1])->toBe(100.0);
    expect($report['totals_by_month'][3])->toBe(1000.0);
    expect($report['roots'])->toHaveCount(2);
    expect($report['roots'][0]['label'])->toBe('ROOT 2');
    expect($report['roots'][0]['total'])->toBe(1000.0);
    expect($report['roots'][1]['label'])->toBe('ROOT 1');
    expect($report['roots'][1]['total'])->toBe(150.0);
    expect($report['roots'][1]['children'])->toHaveCount(1);
    expect($report['roots'][1]['children'][0]['label'])->toBe('Sub category A');
});

test('drops chains rooted in revenue branch', function () {
    $service = makeService(
        monthly: [
            ['leaf' => 'leaf-rev', 'month' => 5, 'total_lei' => 9999],
            ['leaf' => 'leaf-cost', 'month' => 5, 'total_lei' => 100],
        ],
        chains: [
            ['leaf' => 'leaf-rev', 'depth' => 0, 'code' => 'leaf-rev', 'label' => 'Leaf revenue'],
            ['leaf' => 'leaf-rev', 'depth' => 1, 'code' => 'V-ROOT', 'label' => 'Venituri exploatare'],
            ['leaf' => 'leaf-cost', 'depth' => 0, 'code' => 'leaf-cost', 'label' => 'Cost leaf'],
            ['leaf' => 'leaf-cost', 'depth' => 1, 'code' => 'C-ROOT', 'label' => 'ADMINISTRATIV'],
        ],
    );

    $company = Company::factory()->make();
    $company->id = 1;

    $report = $service->report($company, 2025, true);

    expect($report['grand_total'])->toBe(100.0);
    expect($report['roots'])->toHaveCount(1);
    expect($report['roots'][0]['label'])->toBe('ADMINISTRATIV');
    expect($report['meta']['rejected_roots'])->toContain('Venituri exploatare');
});

test('drops chains rooted in junk numeric code', function () {
    $service = makeService(
        monthly: [['leaf' => 'orphan', 'month' => 1, 'total_lei' => 50]],
        chains: [
            ['leaf' => 'orphan', 'depth' => 0, 'code' => 'orphan', 'label' => '1.1.10.29'],
        ],
    );

    $company = Company::factory()->make();
    $company->id = 1;

    $report = $service->report($company, 2025, true);

    expect($report['roots'])->toHaveCount(0);
    expect($report['grand_total'])->toBe(0.0);
});

test('skips leaf when its label is just a code and uses the parent as visible deepest node', function () {
    $service = makeService(
        monthly: [['leaf' => '2.2.6.48.Timisoara Bega', 'month' => 1, 'total_lei' => 200]],
        chains: [
            ['leaf' => '2.2.6.48.Timisoara Bega', 'depth' => 0, 'code' => '2.2.6.48.Timisoara Bega', 'label' => '2.2.6.48.Timisoara Bega'],
            ['leaf' => '2.2.6.48.Timisoara Bega', 'depth' => 1, 'code' => 'SALUBRIZAREA', 'label' => 'SALUBRIZAREA'],
            ['leaf' => '2.2.6.48.Timisoara Bega', 'depth' => 2, 'code' => 'CHELTUIELI UTILITATI', 'label' => 'CHELTUIELI UTILITATI'],
            ['leaf' => '2.2.6.48.Timisoara Bega', 'depth' => 3, 'code' => 'ADMINISTRATIV', 'label' => 'ADMINISTRATIV'],
        ],
    );

    $company = Company::factory()->make();
    $company->id = 1;

    $report = $service->report($company, 2025, true);

    $admin = $report['roots'][0];
    expect($admin['label'])->toBe('ADMINISTRATIV');
    $util = $admin['children'][0];
    expect($util['label'])->toBe('CHELTUIELI UTILITATI');
    $salub = $util['children'][0];
    expect($salub['label'])->toBe('SALUBRIZAREA');
    expect($salub['is_leaf_for_drilldown'])->toBeTrue();
    expect($salub['drilldown_leaves'])->toBe(['2.2.6.48.Timisoara Bega']);
    expect($salub['children'])->toBe([]);
});

test('compareReports merges current and previous reports with delta percentages', function () {
    $current = [
        'year' => 2026,
        'months' => range(1, 12),
        'roots' => [
            [
                'code' => 'A',
                'label' => 'A',
                'totals_by_month' => array_fill(1, 12, 0.0) + [1 => 100.0, 6 => 200.0],
                'total' => 300.0,
                'children' => [],
                'is_leaf_for_drilldown' => true,
                'drilldown_leaves' => ['leaf-A'],
            ],
            [
                'code' => 'NEW',
                'label' => 'New only in current',
                'totals_by_month' => array_fill(1, 12, 0.0) + [3 => 50.0],
                'total' => 50.0,
                'children' => [],
                'is_leaf_for_drilldown' => true,
                'drilldown_leaves' => ['leaf-NEW'],
            ],
        ],
        'totals_by_month' => array_fill(1, 12, 0.0) + [1 => 100.0, 3 => 50.0, 6 => 200.0],
        'grand_total' => 350.0,
        'meta' => ['generated_at' => now()->toIso8601String(), 'duration_ms' => 50, 'leaf_count' => 2, 'rejected_roots' => []],
    ];
    $previous = [
        'year' => 2025,
        'months' => range(1, 12),
        'roots' => [
            [
                'code' => 'A',
                'label' => 'A',
                'totals_by_month' => array_fill(1, 12, 0.0) + [1 => 50.0, 6 => 200.0],
                'total' => 250.0,
                'children' => [],
                'is_leaf_for_drilldown' => true,
                'drilldown_leaves' => ['leaf-A'],
            ],
            [
                'code' => 'GONE',
                'label' => 'Old only',
                'totals_by_month' => array_fill(1, 12, 0.0) + [2 => 30.0],
                'total' => 30.0,
                'children' => [],
                'is_leaf_for_drilldown' => true,
                'drilldown_leaves' => ['leaf-GONE'],
            ],
        ],
        'totals_by_month' => array_fill(1, 12, 0.0) + [1 => 50.0, 2 => 30.0, 6 => 200.0],
        'grand_total' => 280.0,
        'meta' => ['generated_at' => now()->toIso8601String(), 'duration_ms' => 40, 'leaf_count' => 2, 'rejected_roots' => []],
    ];

    $service = makeService(monthly: [], chains: []);
    $merged = $service->compareReports($current, $previous);

    expect($merged['year'])->toBe(2026);
    expect($merged['compare_year'])->toBe(2025);
    expect($merged['grand_total'])->toBe(350.0);
    expect($merged['grand_total_prev'])->toBe(280.0);
    expect($merged['delta_total_pct'])->toBe(25.0);
    expect($merged['roots'])->toHaveCount(3);

    $byCode = collect($merged['roots'])->keyBy('code')->all();
    expect($byCode['A']['total'])->toBe(300.0);
    expect($byCode['A']['total_prev'])->toBe(250.0);
    expect($byCode['A']['delta_total_pct'])->toBe(20.0);
    expect($byCode['NEW']['total_prev'])->toBe(0.0);
    expect($byCode['NEW']['delta_total_pct'])->toBeNull();
    expect($byCode['GONE']['total'])->toBe(0.0);
    expect($byCode['GONE']['total_prev'])->toBe(30.0);
});

test('merges sibling nodes that share the same normalized label', function () {
    $service = makeService(
        monthly: [
            ['leaf' => 'leaf-1', 'month' => 1, 'total_lei' => 100],
            ['leaf' => 'leaf-2', 'month' => 1, 'total_lei' => 50],
            ['leaf' => 'leaf-3', 'month' => 2, 'total_lei' => 25],
        ],
        chains: [
            ['leaf' => 'leaf-1', 'depth' => 0, 'code' => 'ENERGIE ELECTRICA', 'label' => 'ENERGIE ELECTRICA'],
            ['leaf' => 'leaf-1', 'depth' => 1, 'code' => 'ROOT', 'label' => 'ROOT'],
            ['leaf' => 'leaf-2', 'depth' => 0, 'code' => 'ENERGIE ELECTRICA ', 'label' => 'ENERGIE ELECTRICA '],
            ['leaf' => 'leaf-2', 'depth' => 1, 'code' => 'ROOT', 'label' => 'ROOT'],
            ['leaf' => 'leaf-3', 'depth' => 0, 'code' => 'energie electrica', 'label' => 'energie electrica'],
            ['leaf' => 'leaf-3', 'depth' => 1, 'code' => 'ROOT', 'label' => 'ROOT'],
        ],
    );

    $company = Company::factory()->make();
    $company->id = 1;

    $report = $service->report($company, 2025, true);

    expect($report['roots'])->toHaveCount(1);
    $root = $report['roots'][0];
    expect($root['children'])->toHaveCount(1);
    $merged = $root['children'][0];
    expect($merged['total'])->toBe(175.0);
    expect($merged['totals_by_month'][1])->toBe(150.0);
    expect($merged['totals_by_month'][2])->toBe(25.0);
    expect($merged['drilldown_leaves'])->toEqualCanonicalizing(['leaf-1', 'leaf-2', 'leaf-3']);
    expect($merged['is_leaf_for_drilldown'])->toBeTrue();
});
