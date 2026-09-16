<?php

namespace Database\Factories;

use App\Models\CharterContract;
use App\Models\CharterFlight;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CharterFlight>
 */
class CharterFlightFactory extends Factory
{
    public function definition(): array
    {
        return [
            'charter_contract_id' => CharterContract::factory(),
            'route' => 'OTP AYT OTP',
            'flight_no' => 'A2 4238/4239',
            'flight_date' => '2026-10-15',
            'seats' => 180,
            'price_per_seat' => 161.58,
            'net_value' => 29084.40,
            'taxes' => 7300.80,
            'pay_date' => null,
            'taxes_pay_date' => null,
        ];
    }
}
