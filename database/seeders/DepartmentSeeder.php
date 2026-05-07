<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $developers = Department::firstOrCreate(
            ['name' => 'DEV', 'type' => Department::TYPE_ORDONATOR],
        );

        $userIds = User::whereIn('id', [1, 2])->pluck('id')->all();

        if (! empty($userIds)) {
            $developers->members()->syncWithoutDetaching($userIds);
        }

        $departments = [
            ['name' => 'Turism Intern', 'type' => Department::TYPE_RESPONSABIL, 'email' => 'turism-intern@example.test'],
            ['name' => 'Sediul Central', 'type' => Department::TYPE_RESPONSABIL, 'email' => 'sediul-central@example.test'],
            ['name' => 'Ticketing', 'type' => Department::TYPE_RESPONSABIL, 'email' => 'ticketing@example.test'],
            ['name' => 'Bookings', 'type' => Department::TYPE_RESPONSABIL, 'email' => 'bookings@example.test'],
            ['name' => 'Ordonator', 'type' => Department::TYPE_ORDONATOR, 'email' => 'ordonator@example.test'],
            ['name' => 'Plati', 'type' => Department::TYPE_PLATI, 'email' => 'plati@example.test'],
        ];

        foreach ($departments as $data) {
            $department = Department::firstOrCreate(
                ['name' => $data['name'], 'type' => $data['type']],
            );

            $userId = User::where('email', $data['email'])->value('id');

            if ($userId !== null) {
                $department->members()->syncWithoutDetaching([$userId]);
            }
        }
    }
}
