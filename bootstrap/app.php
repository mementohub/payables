<?php

use App\Http\Middleware\ApplyViewAs;
use App\Http\Middleware\EnsureUserCanSee;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\KickErpSync;
use App\Http\Middleware\TraceRequests;
use App\Services\Maintenance\ApplicationLog;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Unde se întoarce omul după un jeton expirat: chiar pagina de pe care a
 * apăsat, dacă e una de-a noastră — după expirare sesiunea nu mai ține minte
 * pe unde a umblat, așa că `back()` l-ar duce la pagina de pornire, iar pe
 * aceea cel fără cont n-o poate deschide.
 */
$backTo = static function (Request $request): string {
    $referer = (string) $request->headers->get('referer', '');

    return $referer !== '' && str_starts_with($referer, $request->getSchemeAndHttpHost())
        ? $referer
        : url('/');
};

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'area' => EnsureUserCanSee::class,
        ]);

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
            ApplyViewAs::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            KickErpSync::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) use ($backTo): void {
        // „Page Expired”, pe înțelesul omului.
        //
        // Jetonul de formular ține cât sesiunea — două ore de tăcere. Pe
        // paginile aplicației nu prea se întâmplă, dar decizia și procedura se
        // citesc dintr-un mail, de către oameni fără cont aici: deschid
        // dimineața, citesc pe îndelete, apasă după prânz. În loc de un ecran
        // de eroare pe care n-au cum să-l înțeleagă, îi ducem înapoi la
        // documentul lor, cu jeton nou și cu un rând care spune ce s-a
        // întâmplat și ce au de făcut. Apărarea rămâne întreagă: jetonul tot
        // se cere, doar că refuzul lui nu mai arată ca o aplicație stricată.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) use ($backTo) {
            if ($response->getStatusCode() !== 419 || ! $request->hasSession()) {
                return $response;
            }

            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'Pagina a stat prea mult deschisă și s-a închis singură. Am redeschis-o: mai apasă o dată.',
            ]);

            return redirect()->to($backTo($request));
        });

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
