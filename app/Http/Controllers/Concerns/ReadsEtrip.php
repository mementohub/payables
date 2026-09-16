<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\QueryException;
use PDOException;
use RuntimeException;

trait ReadsEtrip
{
    /**
     * Run a read against eTrip, turning a database failure into a 503 whose
     * message names the cause, so the page can show it whatever APP_DEBUG is.
     *
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    private function readingEtrip(callable $read): mixed
    {
        try {
            return $read();
        } catch (QueryException|PDOException|RuntimeException $e) {
            $cause = $e->getPrevious() ?? $e;

            abort(503, 'Baza eTrip nu poate fi accesată: '.trim($cause->getMessage()));
        }
    }
}
