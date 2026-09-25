<?php

namespace App\Models;

use Database\Factories\PnlCostOverrideFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O cheltuială mutată de om pe altă linie a contului de profit și pierdere,
 * peste ce a decis maparea automată.
 */
class PnlCostOverride extends Model
{
    /** @use HasFactory<PnlCostOverrideFactory> */
    use HasFactory;

    /** Toată linia se mută. */
    public const SCOPE_LINE = 'line';

    /** Doar cheltuielile cu aceeași semnătură cont|sediu|partener se mută. */
    public const SCOPE_ITEM = 'item';

    /** Un singur document: factura aia, nu tot ce vine de la furnizorul ei. */
    public const SCOPE_DOCUMENT = 'document';

    public const SCOPES = [self::SCOPE_LINE, self::SCOPE_ITEM, self::SCOPE_DOCUMENT];

    protected $guarded = [];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * Semnătura unei cheltuieli: ce anume se mută când corectura e pe `item`.
     * Ține cont doar de câmpurile pe care se sprijină maparea, ca aceeași
     * corectură să prindă și lunile următoare.
     */
    public static function itemKey(string $account, string $sediu, string $partner): string
    {
        return implode('|', [trim($account), trim($sediu), trim($partner)]);
    }

    /**
     * Semnătura unui document: data, tipul și numărul lui, așa cum le ține OMC.
     */
    public static function documentKey(string $dataDoc, string $tipDoc, string $nrDoc): string
    {
        return implode('|', [trim($dataDoc), trim($tipDoc), trim($nrDoc)]);
    }
}
