<?php

namespace App\Http\Middleware;

use App\Services\Maintenance\AutoSync;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * After a signed-in user's page view has been answered, give AutoSync a
 * chance to start the background ERP sync. Runs in terminate(), so the
 * response is never delayed by it.
 */
class KickErpSync
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $request->isMethod('GET') || $request->user() === null || $response->getStatusCode() >= 400) {
            return;
        }

        try {
            app(AutoSync::class)->kick();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
