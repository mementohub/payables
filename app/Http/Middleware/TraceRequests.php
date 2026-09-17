<?php

namespace App\Http\Middleware;

use App\Services\Maintenance\RequestProbe;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Leaves a breadcrumb on either side of every page request, so a request that
 * ends without an answer can be told apart from one that never started. On an
 * installation nobody can reach over SSH this is the only trace a killed
 * worker leaves behind.
 */
class TraceRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $probe = app(RequestProbe::class);
        $id = substr(bin2hex(random_bytes(3)), 0, 6);
        $path = $request->path();
        $method = $request->method();

        $probe->started($id, $method, $this->label($request, $path));

        $response = $next($request);

        $probe->finished(
            $id,
            $method,
            $this->label($request, $path),
            $response->getStatusCode(),
            microtime(true) - (float) ($request->server('REQUEST_TIME_FLOAT') ?: microtime(true)),
        );

        return $response;
    }

    /**
     * An Inertia partial reload asks the same URL for a different piece of the
     * page, so the piece belongs in the line.
     */
    private function label(Request $request, string $path): string
    {
        $partial = (string) $request->header('X-Inertia-Partial-Data', '');

        return $partial === '' ? '/'.ltrim($path, '/') : '/'.ltrim($path, '/').' ['.$partial.']';
    }
}
