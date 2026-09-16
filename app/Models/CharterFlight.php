<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\CharterFlightFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rotation of a charter contract: paid `days_before_flight` days before
 * the flight unless a date is set on the row; the airport taxes are settled
 * in the first week of the following month.
 */
class CharterFlight extends Model
{
    /** @use HasFactory<CharterFlightFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'flight_date' => 'date',
            'seats' => 'integer',
            'price_per_seat' => 'decimal:4',
            'net_value' => 'decimal:2',
            'taxes' => 'decimal:2',
            'pay_date' => 'date',
            'taxes_pay_date' => 'date',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(CharterContract::class, 'charter_contract_id');
    }

    public function paymentDate(): CarbonInterface
    {
        return $this->pay_date ?? $this->flight_date->subDays((int) $this->contract->days_before_flight);
    }

    public function taxesPaymentDate(): CarbonInterface
    {
        return $this->taxes_pay_date ?? $this->flight_date->addMonthNoOverflow()->startOfMonth()->addDays(4);
    }
}
