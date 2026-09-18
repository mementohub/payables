<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\KickErpSync;
use App\Http\Middleware\TraceRequests;
use App\Services\Maintenance\ApplicationLog;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->alias(['role' => EnsureUserHasRole::class]);

        $middleware->web(prepend: [
            TraceRequests::class,
        ]);

        // AddLinkHeadersForPreloadedAssets is deliberately absent. The root
        // view hands @vite the page component, so its Link header carries
        // every chunk that page imports — nearly 3 KB on the cash flow report
        // and over 4 KB on a partner — and with the cookies alongside it that
        // is more response header than nginx's FastCGI buffer holds. nginx
        // answers 502 without PHP ever hearing about it, and only the pages
        // with the most chunks are affected. The same assets are already in
        // the head as <link rel="modulepreload">, so the header bought us
        // nothing that the document does not already say.
        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            KickErpSync::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Nobody can open a shell on the installation, so every logged
        // exception has to say which request raised it and how much memory
        // that request had taken: a fatal out of memory leaves nothing else
        // to go on.
        $exceptions->context(function (): array {
            // In console Laravel makes up a GET of APP_URL, which reads in the
            // log exactly like somebody opening the home page; say which
            // command it really was instead.
            $console = app()->runningInConsole();
            $argv = array_slice((array) ($_SERVER['argv'] ?? []), 1);

            return [
                'source' => $console
                    ? trim('artisan '.implode(' ', array_map('strval', $argv)))
                    : trim((string) request()?->method().' '.(string) request()?->fullUrl()),
                'memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
                'memory_limit' => ini_get('memory_limit'),
                'release' => ApplicationLog::release(),
            ];
        });
    })->create();
