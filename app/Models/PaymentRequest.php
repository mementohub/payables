<?php

namespace App\Models;

use Database\Factories\PaymentRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A supplier's request to be paid, saved together with what the app found
 * when it was verified: the eTrip check-in cost or the ERP invoices it was
 * compared against, the difference, and the verdict.
 */
class PaymentRequest extends Model
{
    /** @use HasFactory<PaymentRequestFactory> */
    use HasFactory;

    public const KIND_CHECKIN = 'checkin';

    public const KIND_INVOICE = 'invoice';

    public const KINDS = [self::KIND_CHECKIN, self::KIND_INVOICE];

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAYABLE = 'payable';

    public const STATUS_DISPUTED = 'disputed';

    public const STATUS_PAID = 'paid';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_PAYABLE, self::STATUS_DISPUTED, self::STATUS_PAID];

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'De verificat',
        self::STATUS_PAYABLE => 'De plătit',
        self::STATUS_DISPUTED => 'Disputat',
        self::STATUS_PAID => 'Plătit',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'checkin_from' => 'date',
            'checkin_to' => 'date',
            'requested_amount' => 'decimal:2',
            'expected_amount' => 'decimal:2',
            'difference' => 'decimal:2',
            'difference_pct' => 'decimal:2',
            'snapshot' => 'array',
            'status_updated_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function etripSupplier(): BelongsTo
    {
        return $this->belongsTo(EtripSupplier::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function statusUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_updated_by_id');
    }

    public function invoices(): BelongsToMany
    {
        return $this->belongsToMany(Invoice::class, 'payment_request_invoice')->withTimestamps();
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentRequestEvent::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
