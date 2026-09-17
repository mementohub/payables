<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\KickErpSync;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
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
            ];
        });
    })->create();
