<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            ['name' => 'Andrei Ciungulete', 'email' => 'andrei.ciugulete@andali.ro', 'password' => '564Wk7nW7gGy'],
            ['name' => 'Bogdan Cismariu', 'email' => 'bogdan.cismariu@mementogroup.com', 'password' => '564Wk7nW7gGy'],
            ['name' => 'Turism Intern', 'email' => 'turism-intern@example.test', 'password' => '##turism-intern##'],
            ['name' => 'Sediul Central', 'email' => 'sediul-central@example.test', 'password' => '##sediul-central##'],
            ['name' => 'Ticketing', 'email' => 'ticketing@example.test', 'password' => '##ticketing##'],
            ['name' => 'Bookings', 'email' => 'bookings@example.test', 'password' => '##bookings##'],
            ['name' => 'Ordonator', 'email' => 'ordonator@example.test', 'password' => '##ordonator##'],
            ['name' => 'Plati', 'email' => 'plati@example.test', 'password' => '##plati##'],
        ];

        foreach ($users as $user) {
            User::factory()->create([
                'name' => $user['name'],
                'email' => $user['email'],
                'password' => $user['password'] ?? $this->password(),
            ]);
        }
    }

    private function password(): string
    {
        return Hash::make(app()->isLocal() ? 'password' : Str::random(32));
    }
}
