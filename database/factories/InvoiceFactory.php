<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    public function definition(): array
    {
        $company = Company::factory();

        return [
            'company_id' => $company,
            'partner_id' => Partner::factory()->for($company),
            'data_doc' => fake()->dateTimeBetween('-30 days')->format('Y-m-d'),
            'tip_doc' => 'FactFI',
            'nr_doc' => fake()->unique()->numerify('F#####'),
            'partener_type' => 'furnizor',
            'moneda' => 'RON',
            'curs' => 1,
            'val_mon' => 1000,
            'val_mon_tva' => 190,
            'val_mon_paid' => 0,
        ];
    }
}
