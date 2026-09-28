<?php

namespace App\Http\Middleware;

use App\Support\ViewAs;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ia `?as=` din adresă și îl ține minte în sesiune, înainte ca zonele să
 * cântărească drepturile.
 *
 * Aici, și nu în controler, pentru că altfel „Vezi ca” ar putea închide ușa
 * după el: dacă omul privit n-are voie pe pagina pe care stă administratorul,
 * zona ar refuza cererea și n-ar mai apuca nimeni să oprească previzualizarea.
 * `?as=0` o oprește de oriunde.
 */
class ApplyViewAs
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession() && $request->has('as')) {
            ViewAs::set($request, $request->integer('as'));
        }

        return $next($request);
    }
}
