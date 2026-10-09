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
    /** Contractul însuși: versiunile lui se numerotează. */
    public const KIND_CONTRACT = 'contract';

    /** Actul adițional: nu înlocuiește contractul, îl schimbă. */
    public const KIND_ADDENDUM = 'addendum';

    /** Anexa: grile, liste de prețuri, caiete de sarcini. */
    public const KIND_ANNEX = 'annex';

    public const KIND_OTHER = 'other';

    public const KINDS = [self::KIND_CONTRACT, self::KIND_ADDENDUM, self::KIND_ANNEX, self::KIND_OTHER];

    public const KIND_LABELS = [
        self::KIND_CONTRACT => 'Contract',
        self::KIND_ADDENDUM => 'Act adițional',
        self::KIND_ANNEX => 'Anexă',
        self::KIND_OTHER => 'Alt document',
    ];

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
            'signed_at' => 'date',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** Numele sub care se arată documentul în listă. */
    public function title(): string
    {
        $kind = self::KIND_LABELS[$this->kind] ?? 'Document';

        return match ($this->kind) {
            self::KIND_CONTRACT => 'Contract v'.$this->version,
            default => trim($kind.' '.($this->label ?? '')),
        };
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }
}
