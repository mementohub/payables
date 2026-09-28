<?php

namespace App\Http\Middleware;

use App\Support\ViewAs;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lasă cererea să treacă doar dacă omul are voie în zona aia a aplicației.
 *
 * Regulile stau pe `User` (`canSeeReports`, `canSeePayments`…), ca meniul și
 * rutele să citească același lucru: meniul ascunde, ruta refuză. Cât timp un
 * administrator se uită prin ochii altuia („Vezi ca”), se cântărește omul
 * privit: previzualizarea strânge drepturi, nu dă.
 */
class EnsureUserCanSee
{
    /** Zona → metoda de pe user care spune dacă are voie. */
    private const AREAS = [
        'dashboard' => 'canSeeDashboard',
        'approvals' => 'canSeeApprovals',
        'invoices' => 'canSeeInvoices',
        'payments' => 'canSeePayments',
        'reports' => 'canSeeReports',
        'routing' => 'canSeeRouting',
        'team' => 'canManageOwnTeam',
        'invoice' => 'canOpenInvoice',
    ];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $area): Response
    {
        $user = ViewAs::effective($request);
        $method = self::AREAS[$area] ?? null;

        abort_if($method === null, 500, "Zonă necunoscută: {$area}.");
        abort_unless($user !== null && $user->{$method}(), 403, 'Nu aveți acces la această pagină.');

        return $next($request);
    }
}
