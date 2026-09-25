<?php

namespace App\Models;

use Database\Factories\EtripSupplierFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A supplier of one of the eTrip bases (config/etrip.php), mirrored locally
 * so it can be searched and tied to the ERP partner that sends the payment
 * requests; company_id is the company whose partners it is matched to.
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

    public function scopeForConnection(Builder $query, string $connection): Builder
    {
        return $query->where('etrip_connection', $connection);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeUnmatched(Builder $query): Builder
    {
        return $query->whereNull('partner_id');
    }

    /**
     * The suppliers the sync may still link on its own: an unlink made by
     * hand says the VAT number and the name lead to the wrong partner, so
     * the next sync must not make the same match again.
     */
    public function scopeMatchable(Builder $query): Builder
    {
        return $query->unmatched()->where(
            fn (Builder $query) => $query->whereNull('match_source')->orWhere('match_source', '!=', self::MATCH_MANUAL),
        );
    }

    public function label(): string
    {
        return "{$this->name} [{$this->code}]";
    }
}
