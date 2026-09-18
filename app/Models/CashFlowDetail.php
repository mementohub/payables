<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One piece of a cash-flow cell: a booking tranche, a supplier's services,
 * a charter rotation, an invoice, a payment. The pieces of a cell add up to
 * the value the snapshot shows for it.
 */
class CashFlowDetail extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'week' => 'date',
            'actual' => 'boolean',
            'date' => 'date',
            'amount' => 'float',
            'lei' => 'float',
            'meta' => 'array',
        ];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(CashFlowSnapshot::class, 'cash_flow_snapshot_id');
    }
}
