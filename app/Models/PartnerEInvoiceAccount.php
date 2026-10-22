<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un cont bancar al furnizorului, așa cum l-a scris el în e-Factură.
 */
class PartnerEInvoiceAccount extends Model
{
    protected $table = 'partner_einvoice_accounts';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['seen_at' => 'datetime'];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
