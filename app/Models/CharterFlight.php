<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\CharterFlightFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rotation of a charter contract, settled on the contract's own terms: a
 * date on the row overrides them, so a rotation can be scheduled by hand
 * without touching the contract.
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

    /**
     * When the rotation is settled: the contract's notice before the flight,
     * before the Monday of the operating week, or the day it was signed.
     */
    public function paymentDate(): CarbonInterface
    {
        if ($this->pay_date !== null) {
            return $this->pay_date;
        }

        $contract = $this->contract;
        $days = (int) $contract->days_before_flight;

        return match ($contract->payment_basis) {
            CharterContract::BASIS_WEEK => $this->flight_date->startOfWeek(CarbonInterface::MONDAY)->subDays($days),
            CharterContract::BASIS_SIGNING => $contract->signed_date ?? $this->flight_date->subDays($days),
            default => $this->flight_date->subDays($days),
        };
    }

    /**
     * When the airport taxes of the rotation are settled, or null when the
     * contract settles them together with the rotation itself.
     */
    public function taxesPaymentDate(): ?CarbonInterface
    {
        if ($this->taxes_pay_date !== null) {
            return $this->taxes_pay_date;
        }

        $contract = $this->contract;

        return match ($contract->taxes_rule) {
            CharterContract::TAXES_WITH_ROTATION => null,
            CharterContract::TAXES_AFTER => $this->flight_date->addDays((int) ($contract->taxes_days ?? 3)),
            CharterContract::TAXES_BEFORE => $this->flight_date->subDays((int) ($contract->taxes_days ?? 14)),
            default => $this->flight_date
                ->addMonthNoOverflow()
                ->startOfMonth()
                ->addDays(max(0, (int) ($contract->taxes_month_day ?? 5) - 1)),
        };
    }
}
