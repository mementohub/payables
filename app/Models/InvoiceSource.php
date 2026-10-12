<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Furnizorul adevărat din spatele unei facturi refacturate.
 */
class InvoiceSource extends Model
{
    public const BASIS_ORDER = 'order';

    public const BASIS_BOOKING = 'booking';

    public const BASIS_ARTICLE = 'article';

    public const BASIS_LABELS = [
        self::BASIS_ORDER => 'comandă de charter',
        self::BASIS_BOOKING => 'rezervare',
        self::BASIS_ARTICLE => 'articol refacturat',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'doc_date' => 'date',
            'share' => 'float',
            'val_lei' => 'float',
            'exact' => 'boolean',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
