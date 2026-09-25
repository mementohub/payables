<?php

namespace App\Services\Exports;

/**
 * Culorile și logo-ul aplicației, într-un singur loc, pentru fișierele care
 * pleacă din ea. Sunt aceleași cu cele din `resources/css/app.css`: un raport
 * trimis prin e-mail trebuie să arate a noi, nu a export generic.
 */
class Brand
{
    /** Bleumarinul Christian Tour. */
    public const INK = '#011f5b';

    /** Portocaliul de accent. */
    public const ACCENT = '#ff4200';

    /** Fundalul rândurilor de grupă. */
    public const MUTED = '#eef1f7';

    /** Textul secundar. */
    public const SUBTLE = '#74809a';

    public const BORDER = '#d7dde9';

    /** Roșul pentru cifre negative. */
    public const NEGATIVE = '#c8102e';

    public static function company(): string
    {
        return (string) config('app.name', 'Christian Tour');
    }

    /**
     * Conținutul logo-ului, sau null dacă lipsește — un raport fără logo e mai
     * bun decât un export care crapă.
     */
    public static function logo(): ?string
    {
        $path = public_path('img/logo.png');

        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    /** Logo-ul ca `data:` URI, cum îl cere dompdf ca să nu iasă pe rețea. */
    public static function logoDataUri(): ?string
    {
        $logo = self::logo();

        return $logo === null ? null : 'data:image/png;base64,'.base64_encode($logo);
    }
}
