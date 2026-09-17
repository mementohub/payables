<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\EtripSupplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EtripSupplier>
 */
class EtripSupplierFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(['etrip_connection' => 'etrip_chr']),
            'etrip_connection' => 'etrip_chr',
            'code' => (string) fake()->unique()->numberBetween(1, 99999),
            'name' => fake()->unique()->company(),
            'vat_no' => 'RO'.fake()->unique()->numberBetween(1000000, 99999999),
            'company_no' => null,
            'currency' => 'EUR',
            'country' => 'Turcia',
            'is_active' => true,
            'partner_id' => null,
            'match_source' => null,
            'synced_at' => now(),
        ];
    }
}
