<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where one invoice line goes: its department, the sales channel of the
 * booking behind it, and the rule that decided (or the person who did).
 * Keyed by the line's position (scv) rather than the line row, which the
 * sync replaces each time it reads the invoice.
 */
class InvoiceLineDepartment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'is_manual' => 'boolean',
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
     * @return BelongsTo<Department, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'channel_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_id');
    }
}
