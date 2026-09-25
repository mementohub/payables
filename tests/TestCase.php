<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Testele rulează pe baza lor, niciodată pe alta.
     *
     * Aplicația stă pe serverul de producție, în directorul din care se rulează
     * și suita. `RefreshDatabase` începe cu `migrate:fresh`, adică aruncă toate
     * tabelele conexiunii curente — dacă acea conexiune ajunge vreodată să fie
     * cea de producție, suita șterge baza.
     *
     * Se poate întâmpla ușor: cu `config:cache` rulat, fișierul din
     * `bootstrap/cache` bate variabilele din `phpunit.xml`, iar conexiunea
     * implicită redevine MySQL-ul de producție.
     *
     * Verificarea stă aici, nu în `setUp`, fiindcă `RefreshDatabase` se agață
     * de `setUpTraits()`, care rulează în `parent::setUp()`: până acolo ar fi
     * prea târziu. `refreshApplication()` e ultimul moment dinaintea lui.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $default = (string) config('database.default');
        $driver = (string) config("database.connections.{$default}.driver");
        $database = (string) config("database.connections.{$default}.database");

        if ($driver !== 'sqlite' || ! in_array($database, [':memory:', ''], true)) {
            throw new RuntimeException(sprintf(
                'Testele s-ar lega la „%s” (%s), nu la baza de test în memorie, iar RefreshDatabase ar șterge-o. '
                .'Rulează „php artisan config:clear”: o configurație pusă în cache acoperă variabilele din phpunit.xml.',
                $database,
                $driver,
            ));
        }
    }
}
