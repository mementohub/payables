<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\QueryException;
use PDOException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

trait ReadsRemote
{
    /**
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    private function readingEtrip(callable $read): mixed
    {
        return $this->reading('eTrip', $read);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    private function readingOmc(callable $read): mixed
    {
        return $this->reading('OMC', $read);
    }

    /**
     * Run a read against a remote database, turning a database failure into a
     * 503 whose message names the cause, so the page can show it whatever
     * APP_DEBUG is.
     *
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    private function reading(string $database, callable $read): mixed
    {
        try {
            return $read();
        } catch (QueryException|PDOException|RuntimeException $e) {
            if ($e instanceof HttpExceptionInterface) {
                throw $e;
            }

            $cause = $e->getPrevious() ?? $e;

            abort(503, "Baza {$database} nu poate fi accesată: ".trim($cause->getMessage()));
        }
    }
}
