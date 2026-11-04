<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O versiune a deciziei sau a procedurii: fișierul cum a fost încărcat, plus
 * textul citit din el, ca să se poată căuta fără să deschidă nimeni documentul.
 */
class CompanyDocumentFile extends Model
{
    /** Documentul însuși: versiunile lui se numerotează. */
    public const KIND_DOCUMENT = 'document';

    /** Anexa: formulare, liste, scheme. */
    public const KIND_ANNEX = 'annex';

    public const KINDS = [self::KIND_DOCUMENT, self::KIND_ANNEX];

    public const KIND_LABELS = [
        self::KIND_DOCUMENT => 'Document',
        self::KIND_ANNEX => 'Anexă',
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
        ];
    }

    /** Discul privat pe care stă fișierul. */
    public function disk(): string
    {
        return (string) config('documents.disk', 'documents');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(CompanyDocument::class, 'company_document_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    /** Numele sub care se arată fișierul în listă. */
    public function title(): string
    {
        return match ($this->kind) {
            self::KIND_ANNEX => trim('Anexă '.($this->label ?? '')),
            default => 'Document v'.$this->version,
        };
    }
}
