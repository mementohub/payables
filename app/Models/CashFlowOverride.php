<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A value set by hand on one line of the cash-flow report for one week. It
 * replaces what the nightly build worked out for that cell and outlives
 * every rebuild until someone resets it.
 */
class CashFlowOverride extends Model
{
    protected $guarded = [];

    /**
     * The week stays a plain Y-m-d string: it is matched as the report's
     * column key, never used as a date.
     */
    protected function casts(): array
    {
        return [
            'amount' => 'float',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }
}
