<?php

namespace Database\Factories;

use App\Models\CharterContract;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CharterContract>
 */
class CharterContractFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'CTR 317/11.11.2025 Memento Air',
            'season' => 'S26',
            'status' => CharterContract::STATUS_SIGNED,
            'operator' => 'Memento Air',
            'currency' => 'EUR',
            'days_before_flight' => 10,
            'deposit_percent' => null,
            'deposit_amount' => null,
            'deposit_due_date' => null,
            'deposit_paid' => false,
            'contract_value' => null,
            'notes' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => CharterContract::STATUS_DRAFT, 'season' => 'W26-27', 'name' => 'W26/27 draft']);
    }
}
