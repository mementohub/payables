<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One department's say on an invoice it owns part of: pending, approved,
 * disputed or postponed to a date.
 */
class InvoiceDepartmentApproval extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const DISPUTED = 'disputed';

    public const POSTPONED = 'postponed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'postponed_until' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }
}
