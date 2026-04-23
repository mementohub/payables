<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'cui' => (string) fake()->unique()->numberBetween(10000000, 99999999),
            'db_driver' => 'pgsql',
            'db_host' => '127.0.0.1',
            'db_port' => '5432',
            'db_database' => 'test_'.fake()->unique()->word(),
            'db_username' => 'test',
            'db_password' => 'secret',
        ];
    }
}
