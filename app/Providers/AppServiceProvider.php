<?php

namespace App\Providers;

use App\Services\Maintenance\MemoryLimit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->capConsoleMemory();
        $this->configureDefaults();

        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('microsoft', MicrosoftProvider::class);
        });
    }

    /**
     * Un plafon de memorie pentru comenzile din consolă.
     *
     * PHP pe linia de comandă vine cu `memory_limit = -1`: o comandă scăpată
     * de sub control nu moare singură, ci înghite toată mașina. Atunci baza
     * de date și Redis rămân fără aer, iar noaptea se duce — nu cade comanda,
     * cade serverul.
     *
     * Cu plafon, aceeași comandă se oprește cu o eroare limpede, iar restul
     * își vede de treabă. Se ridică din `CONSOLE_MEMORY_LIMIT` dacă un raport
     * chiar are nevoie de mai mult.
     */
    protected function capConsoleMemory(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $limit = (string) config('app.console_memory_limit', '1024M');

        if ($limit !== '' && (int) ini_get('memory_limit') === -1) {
            ini_set('memory_limit', $limit);
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Pe orice calculator care nu e producția, mailurile merg într-un
        // singur loc: o probă n-are voie să ajungă la oamenii adevărați.
        $override = config('notifications.override_recipient');

        if (is_string($override) && $override !== '') {
            Mail::alwaysTo($override);
        }

        (new MemoryLimit)->raiseTo(config('app.memory_limit'));

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
