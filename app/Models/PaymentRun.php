<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A weekly payment proposal: the open invoices due by a date, reviewed by
 * their departments, approved as a whole by Top Management and sent to the
 * bank by Treasury.
 */
class PaymentRun extends Model
{
    public const REVIEW = 'review';

    public const FINAL = 'final';

    public const APPROVED = 'approved';

    public const EXPORTED = 'exported';

    public const CLOSED = 'closed';

    public const CANCELLED = 'cancelled';

    /** Runs whose invoices are spoken for. */
    public const ACTIVE = [self::REVIEW, self::FINAL, self::APPROVED, self::EXPORTED];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'pay_date' => 'date',
            'due_until' => 'date',
            'approved_at' => 'datetime',
            'exported_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<PaymentRunItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PaymentRunItem::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function exportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exported_by_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }
}
