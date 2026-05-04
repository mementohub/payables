<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            ['name' => 'Andrei Ciungulete', 'email' => 'andrei.ciungulete@andali.ro', 'password' => '564Wk7nW7gGy'],
            ['name' => 'Bogdan Cismariu', 'email' => 'bogdan.cismariu@mementogroup.com', 'password' => '564Wk7nW7gGy'],
            ['name' => 'Turism Intern', 'email' => 'turism-intern@example.test', 'password' => '##turism-intern##'],
            ['name' => 'Sediul Central', 'email' => 'sediul-central@example.test', 'password' => '##sediul-central##'],
            ['name' => 'Ticketing', 'email' => 'ticketing@example.test', 'password' => '##ticketing##'],
            ['name' => 'Bookings', 'email' => 'bookings@example.test', 'password' => '##bookings##'],
            ['name' => 'Ordonator', 'email' => 'ordonator@example.test', 'password' => '##ordonator##'],
            ['name' => 'Plati', 'email' => 'plati@example.test', 'password' => '##plati##'],
        ];

        foreach ($users as $user) {
            User::updateOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'password' => $this->password($user['password']),
                    'email_verified_at' => now(),
                ],
            );
        }
    }

    private function password(string $password): string
    {
        return Hash::make(app()->isLocal() ? 'password' : $password);
    }
}
