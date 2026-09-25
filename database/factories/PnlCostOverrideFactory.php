<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\PnlCostOverride;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PnlCostOverride>
 */
class PnlCostOverrideFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'scope' => PnlCostOverride::SCOPE_ITEM,
            // Cheia e unică pe companie și scop, deci nu poate fi fixă.
            'match_key' => '628||'.fake()->unique()->company(),
            'saf' => '18200',
            'label' => null,
            'created_by_id' => null,
        ];
    }
}
