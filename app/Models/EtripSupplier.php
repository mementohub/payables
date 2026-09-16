<?php

namespace App\Models;

use Database\Factories\EtripSupplierFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A supplier from the eTrip reservation database a company is linked to,
 * mirrored locally so it can be searched and tied to the ERP partner that
 * sends the payment requests.
 */
class EtripSupplier extends Model
{
    /** @use HasFactory<EtripSupplierFactory> */
    use HasFactory;

    public const MATCH_CUI = 'cui';

    public const MATCH_NAME = 'name';

    public const MATCH_MANUAL = 'manual';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
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

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeUnmatched(Builder $query): Builder
    {
        return $query->whereNull('partner_id');
    }

    public function label(): string
    {
        return "{$this->name} [{$this->code}]";
    }
}
