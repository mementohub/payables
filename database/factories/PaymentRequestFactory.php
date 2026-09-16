<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentRequest>
 */
class PaymentRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'partner_id' => null,
            'etrip_supplier_id' => null,
            'kind' => PaymentRequest::KIND_CHECKIN,
            'supplier_name' => fake()->company(),
            'reference' => null,
            'requested_amount' => 1000,
            'requested_currency' => 'EUR',
            'checkin_from' => '2026-09-16',
            'checkin_to' => '2026-09-17',
            'category' => 'hotel',
            'expected_amount' => 1000,
            'expected_currency' => 'EUR',
            'difference' => 0,
            'difference_pct' => 0,
            'level' => 'ok',
            'verdict' => 'ok',
            'snapshot' => ['items' => 3, 'bookings' => 2],
            'status' => PaymentRequest::STATUS_PAYABLE,
            'note' => null,
            'created_by_id' => User::factory(),
            'status_updated_by_id' => null,
            'status_updated_at' => null,
        ];
    }

    public function invoiceKind(): self
    {
        return $this->state(fn () => [
            'kind' => PaymentRequest::KIND_INVOICE,
            'checkin_from' => null,
            'checkin_to' => null,
            'category' => null,
            'requested_currency' => 'RON',
            'expected_currency' => 'RON',
        ]);
    }
}
