<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Rata marginală de împrumut, din buletinul BNR.
 *
 * Standardul cere rata pe care am plăti-o ca să împrumutăm, pe o durată
 * similară, banii necesari unui activ similar. Luăm rata medie la creditele
 * noi acordate societăților nefinanciare, în moneda contractului și pe
 * scadența lui, din luna în care începe contractul sau în care se modifică.
 */
class LeaseRate extends Model
{
    /** Până la un an inclusiv. */
    public const SHORT = '<1y';

    /** Între unu și cinci ani. */
    public const MEDIUM = '1-5y';

    /** Peste cinci ani. */
    public const LONG = '>5y';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rate' => 'float',
            'provisional' => 'boolean',
        ];
    }

    /**
     * Scadența pe care se citește rata, după durata rămasă în luni.
     */
    public static function bucket(int $months): string
    {
        if ($months <= 12) {
            return self::SHORT;
        }

        return $months <= 60 ? self::MEDIUM : self::LONG;
    }

    /**
     * Rata pentru o linie care începe în luna asta, pe durata asta.
     *
     * Dacă buletinul lunii n-a apărut încă, se ia ultima lună publicată —
     * provizoriu, până la publicare.
     */
    public static function resolve(string $currency, string $month, int $months): ?self
    {
        $bucket = self::bucket($months);

        return self::query()
            ->where('currency', strtoupper($currency))
            ->where('bucket', $bucket)
            ->where('month', '<=', $month)
            ->orderByDesc('month')
            ->first();
    }
}
