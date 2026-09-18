<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    /**
     * The administrators of a fresh install. They sign in with Microsoft;
     * the password is random (reset it from the login page if needed).
     */
    public function run(): void
    {
        foreach ([
            ['name' => 'Andrei Ciungulete', 'email' => 'andrei.ciungulete@andali.ro'],
            ['name' => 'Bogdan Cismariu', 'email' => 'bogdan.cismariu@mementogroup.com'],
        ] as $user) {
            User::query()->updateOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'password' => Hash::make(app()->isLocal() ? 'password' : Str::random(32)),
                    'email_verified_at' => now(),
                    'roles' => [User::ROLE_ADMIN],
                ],
            );
        }
    }
}
