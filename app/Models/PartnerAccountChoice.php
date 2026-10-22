<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contul folosit ultima dată la plata unui furnizor, pe o monedă.
 */
class PartnerAccountChoice extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    /** „Lei” și „RON” sunt aceeași monedă; se ține una singură. */
    public static function currencyKey(?string $currency): string
    {
        return match (strtoupper(trim((string) $currency))) {
            'LEI', 'RON' => 'RON',
            '' => 'RON',
            default => strtoupper(trim((string) $currency)),
        };
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function lastUsedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_used_by_id');
    }
}
