<?php

namespace App\Models;

use Database\Factories\CharterContractFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A charter flight contract (one season with one operator): the payment
 * terms every rotation of the contract follows.
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

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'days_before_flight' => 'integer',
            'deposit_percent' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            'deposit_due_date' => 'date',
            'deposit_paid' => 'boolean',
            'contract_value' => 'decimal:2',
        ];
    }

    public function flights(): HasMany
    {
        return $this->hasMany(CharterFlight::class);
    }
}
