<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ce i s-a întâmplat documentului: cine l-a adus, l-a schimbat, l-a trimis. */
class CompanyDocumentEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(CompanyDocument::class, 'company_document_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
