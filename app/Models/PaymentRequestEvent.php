<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentRequestEvent extends Model
{
    public const TYPE_CREATED = 'created';

    public const TYPE_STATUS_CHANGED = 'status_changed';

    public const TYPE_COMMENTED = 'commented';

    public const TYPE_INVOICE_LINKED = 'invoice_linked';

    public const TYPE_INVOICE_UNLINKED = 'invoice_unlinked';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
