<?php

namespace Database\Factories;

use App\Models\CashFlowSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashFlowSnapshot>
 */
class CashFlowSnapshotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'built_at' => '2026-09-16 04:30:00',
            'week_start' => '2026-09-14',
            'status' => CashFlowSnapshot::STATUS_OK,
            'duration_ms' => 1200,
            'payload' => ['weeks' => [], 'lines' => [], 'kpis' => []],
            'sources' => [],
            'error' => null,
            'built_by' => 'programat',
        ];
    }
}
