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

    /**
     * Numele, adus la forma în care același om se recunoaște oricum l-ar scrie.
     *
     * Adresa e cheia dovezii și nu se repetă niciodată — dar același om poate
     * confirma de două ori scriindu-și adresa altfel: azi ion.popescu@, peste
     * o săptămână i.popescu@. Atunci numele rămâne singurul fir care îi leagă,
     * așa că îl aducem la litere mici, fără diacritice, cu cuvintele puse în
     * ordine: „Popescu Ion” și „ion popescu” ies la fel.
     */
    public static function samePerson(?string $name): string
    {
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower(trim((string) $name)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $words = array_map(
            fn (string $word) => strtr($word, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']),
            $words,
        );

        sort($words);

        return implode(' ', $words);
    }

    /** Adresa cu care a confirmat e a cuiva cu cont în aplicație. */
    public function matched(): bool
    {
        return $this->user_id !== null;
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
