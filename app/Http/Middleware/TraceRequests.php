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
            $this->headerBytes($response),
        );

        return $response;
    }

    /**
     * Roughly what the gateway has to read before the body starts: the status
     * line plus every header, each with its colon, space and line break.
     */
    private function headerBytes(Response $response): int
    {
        $bytes = strlen('HTTP/1.1 '.$response->getStatusCode().' ') + 4;

        foreach ($response->headers->allPreserveCase() as $name => $values) {
            foreach ($values as $value) {
                $bytes += strlen($name) + strlen((string) $value) + 4;
            }
        }

        return $bytes;
    }

    /**
     * A full page load and an Inertia visit ask for the same URL and get very
     * different answers — one a document, the other JSON — so the line says
     * which, and which piece of the page a partial reload came back for.
     */
    private function label(Request $request, string $path): string
    {
        $url = '/'.ltrim($path, '/');

        if (! $request->hasHeader('X-Inertia')) {
            return $url.' [document]';
        }

        $partial = (string) $request->header('X-Inertia-Partial-Data', '');

        return $partial === '' ? $url : $url.' ['.$partial.']';
    }
}
