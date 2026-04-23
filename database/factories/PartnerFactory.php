<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Partner>
 */
class PartnerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->unique()->company(),
            'cui' => (string) fake()->unique()->numberBetween(10000000, 99999999),
            'is_furnizor' => true,
            'is_client' => false,
        ];
    }

    public function furnizor(): self
    {
        return $this->state(fn () => ['is_furnizor' => true, 'is_client' => false]);
    }
}
