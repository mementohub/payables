<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceEvent extends Model
{
    public const TYPE_APPROVED = 'approved';

    public const TYPE_APPROVAL_REVOKED = 'approval_revoked';

    public const TYPE_COMMENTED = 'commented';

    public const TYPE_PAYMENT_STATUS_CHANGED = 'payment_status_changed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function scopeComments(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_COMMENTED);
    }
}
