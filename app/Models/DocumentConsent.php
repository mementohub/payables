<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * „Am citit și am luat la cunoștință.”
 *
 * Dovada că procedura a ajuns la om: numele și adresa cu care s-a identificat,
 * ceasul, de unde a venit și cu ce a deschis. Rândul nu se mai schimbă după ce
 * a fost scris.
 */
class DocumentConsent extends Model
{
    /** S-a identificat singur, din mailul trimis pe adresa de grup. */
    public const SOURCE_LINK = 'link';

    /** Era autentificat în aplicație, deci știm exact cine e. */
    public const SOURCE_ACCOUNT = 'account';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['consented_at' => 'datetime'];
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
