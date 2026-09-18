<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invoice in a payment run, with the amount proposed (what was still
 * open when it was added) and whether it is still in.
 */
class PaymentRunItem extends Model
{
    public const INCLUDED = 'included';

    public const EXCLUDED = 'excluded';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PaymentRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PaymentRun::class, 'payment_run_id');
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
}
