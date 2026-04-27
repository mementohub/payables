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
            ['name' => 'developers', 'type' => Department::TYPE_MASTER],
        );

        $userIds = User::whereIn('id', [1, 2])->pluck('id')->all();

        if (! empty($userIds)) {
            $developers->members()->syncWithoutDetaching($userIds);
        }
    }
}
