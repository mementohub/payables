<?php

namespace App\Jobs;

use App\Models\Department;
use App\Models\Partner;
use App\Services\Demo\SeedDemoActivity;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SeedDemoActivityJob implements ShouldQueue
{
    use Queueable;

    /**
     * @var array<string, array<int, string>>
     */
    private const DEPARTMENT_PARTNERS = [
        'Turism Intern' => [
            '7082954',
            'RO15490210',
            'RO27526695',
        ],
        'Ticketing' => [
            'WIZZ AIR',
            'RYANAIR',
            'AMADEUS MARKETING ROMANIA SRL',
            'RO8287958',
            'RO41404510',
        ],
        'Bookings' => [
            'EXPEDIA',
            'HOTELBEDS',
        ],
        'Sediul Central' => [
            'RO28909028',
            'RO6562512',
        ],
    ];

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('long');
    }

    public function handle(SeedDemoActivity $seeder): void
    {
        $this->attachPartnersToDepartments();

        $stats = $seeder->run();

        Log::info('Demo activity seeded.', $stats);
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['demo', 'demo-seed-activity'];
    }

    private function attachPartnersToDepartments(): void
    {
        foreach (self::DEPARTMENT_PARTNERS as $departmentName => $identifiers) {
            $department = Department::where('name', $departmentName)->first();

            if ($department === null) {
                Log::warning('Demo: department not found.', ['department' => $departmentName]);

                continue;
            }

            $partnerIds = $this->resolvePartnerIds($identifiers);

            if (empty($partnerIds)) {
                Log::warning('Demo: no partners matched.', ['department' => $departmentName]);

                continue;
            }

            $department->partners()->syncWithoutDetaching($partnerIds);
        }
    }

    /**
     * @param  array<int, string>  $identifiers
     * @return array<int, int>
     */
    private function resolvePartnerIds(array $identifiers): array
    {
        $ids = [];

        foreach ($identifiers as $identifier) {
            $digits = preg_replace('/\D+/', '', $identifier) ?? '';

            $query = Partner::query()->where('is_furnizor', true);

            if ($digits !== '' && mb_strlen($digits) >= 6) {
                $query->where('cui', 'like', "%{$digits}%");
            } else {
                $query->where('name', 'like', "%{$identifier}%");
            }

            $matched = $query->pluck('id')->all();

            if (empty($matched)) {
                Log::warning('Demo: partner identifier had no match.', ['identifier' => $identifier]);

                continue;
            }

            $ids = array_merge($ids, $matched);
        }

        return array_values(array_unique($ids));
    }
}
