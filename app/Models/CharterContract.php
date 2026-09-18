<?php

namespace App\Models;

use Database\Factories\CharterContractFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A charter contract with the terms it is settled on: who pays whom, when a
 * rotation is due, how the airport taxes are settled, the deposit and the
 * currency clause. Every rule the cash-flow report applies comes from here,
 * so changing a contract changes the forecast.
 */
class CharterContract extends Model
{
    /** @use HasFactory<CharterContractFactory> */
    use HasFactory;

    public const STATUS_SIGNED = 'signed';

    public const STATUS_DRAFT = 'draft';

    public const STATUSES = [self::STATUS_SIGNED, self::STATUS_DRAFT];

    public const STATUS_LABELS = [
        self::STATUS_SIGNED => 'semnat',
        self::STATUS_DRAFT => 'draft',
    ];

    /** Money leaving CHR. */
    public const DIRECTION_OUT = 'out';

    /** Money coming in: CHR sells the seats. */
    public const DIRECTION_IN = 'in';

    public const DIRECTIONS = [self::DIRECTION_OUT, self::DIRECTION_IN];

    public const DIRECTION_LABELS = [
        self::DIRECTION_OUT => 'plată CHR',
        self::DIRECTION_IN => 'încasare CHR',
    ];

    /** The rotation is due a number of days before the flight. */
    public const BASIS_FLIGHT = 'flight';

    /** The week of flights is paid a number of days before its Monday. */
    public const BASIS_WEEK = 'week_start';

    /** The whole contract is settled on its signing date. */
    public const BASIS_SIGNING = 'signing';

    public const PAYMENT_BASES = [self::BASIS_FLIGHT, self::BASIS_WEEK, self::BASIS_SIGNING];

    public const BASIS_LABELS = [
        self::BASIS_FLIGHT => 'zile înainte de fiecare zbor',
        self::BASIS_WEEK => 'zile înainte de luni, pe săptămâna de operare',
        self::BASIS_SIGNING => 'integral la semnare',
    ];

    /** Reconciled monthly, in the first week of the month after the flight. */
    public const TAXES_MONTHLY = 'monthly_first_week';

    /** Collected or paid together with the rotation. */
    public const TAXES_WITH_ROTATION = 'with_rotation';

    /** Due a number of days after the flight. */
    public const TAXES_AFTER = 'days_after_flight';

    /** Paid in advance at full capacity, a number of days before the flight. */
    public const TAXES_BEFORE = 'days_before_flight';

    public const TAXES_RULES = [self::TAXES_MONTHLY, self::TAXES_WITH_ROTATION, self::TAXES_AFTER, self::TAXES_BEFORE];

    public const TAXES_LABELS = [
        self::TAXES_MONTHLY => 'reconciliere lunară, prima săptămână a lunii următoare',
        self::TAXES_WITH_ROTATION => 'odată cu rotația',
        self::TAXES_AFTER => 'zile după zbor',
        self::TAXES_BEFORE => 'în avans, zile înainte de zbor',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'in_cash_flow' => 'boolean',
            'signed_date' => 'date',
            'period_from' => 'date',
            'period_to' => 'date',
            'days_before_flight' => 'integer',
            'taxes_days' => 'integer',
            'taxes_month_day' => 'integer',
            'deposit_percent' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            'deposit_due_date' => 'date',
            'deposit_paid' => 'boolean',
            'contract_value' => 'decimal:2',
            'contract_value_with_taxes' => 'decimal:2',
            'fx_markup_pct' => 'decimal:2',
            'late_penalty_pct_per_day' => 'decimal:3',
        ];
    }

    public function flights(): HasMany
    {
        return $this->hasMany(CharterFlight::class);
    }

    /** Contracts the report turns into money, in either direction. */
    public function scopeInCashFlow(Builder $query): Builder
    {
        return $query->where('in_cash_flow', true);
    }

    public function isIncoming(): bool
    {
        return $this->direction === self::DIRECTION_IN;
    }

    /**
     * What the taxes of a rotation are worth on their own line: nothing when
     * the contract settles them together with the rotation, because the
     * rotation already carries them.
     */
    public function taxesRideWithRotation(): bool
    {
        return $this->taxes_rule === self::TAXES_WITH_ROTATION;
    }

    /**
     * The rate a contract amount is converted at: the currency rate plus the
     * markup its currency clause adds, for instance the 2 % over the BNR rate
     * the Memento Air contracts charge when CHR pays in lei.
     */
    public function rate(float $currencyRate): float
    {
        return $currencyRate * (1 + (float) $this->fx_markup_pct / 100);
    }

    /**
     * The deposit still to settle, from the amount when it is known and from
     * the percentage of the contract value otherwise.
     */
    public function depositAmount(float $flightsNet = 0.0): float
    {
        if ($this->deposit_amount !== null) {
            return (float) $this->deposit_amount;
        }

        if ($this->deposit_percent === null) {
            return 0.0;
        }

        $base = (float) ($this->contract_value ?? 0) ?: $flightsNet;

        return round($base * (float) $this->deposit_percent / 100, 2);
    }
}
