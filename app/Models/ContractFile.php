<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O versiune a contractului: fișierul cum a fost încărcat, plus textul citit
 * din el. Textul se ține ca să se poată căuta o clauză fără să deschidă
 * nimeni documentul.
 */
class ContractFile extends Model
{
    public const OCR_PENDING = 'pending';

    public const OCR_DONE = 'done';

    public const OCR_FAILED = 'failed';

    public const OCR_SKIPPED = 'skipped';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'pages' => 'integer',
            'version' => 'integer',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }
}
