<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoiceDetail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceDetail>
 */
class InvoiceDetailFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'scv' => fake()->unique()->numberBetween(1, 1_000_000),
            'articol' => 'SERVICII TURISTICE',
            'detaliu_articol' => null,
            'cant' => 1,
            'um' => 'buc',
            'pret' => 100,
            'proc_tva' => 0,
            'account' => '471',
            'analytic' => '.',
            'loc' => null,
            'com_int' => null,
        ];
    }
}
