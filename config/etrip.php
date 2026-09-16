<?php

return [

    /*
    |--------------------------------------------------------------------------
    | eTrip reservation databases
    |--------------------------------------------------------------------------
    |
    | Named connections (see config/database.php) a company may be linked to
    | through companies.etrip_connection, with the label shown in the UI.
    |
    */

    'connections' => [
        'etrip_chr' => 'eTrip Christian Tour',
        'etrip_vcz' => 'eTrip Vacanza',
    ],

    /*
    |--------------------------------------------------------------------------
    | Product type categories
    |--------------------------------------------------------------------------
    |
    | public.product_types ids grouped the way suppliers bill: accommodation
    | and packages are requested per check-in, transfers separately, and the
    | rest (flights, excursions, insurance) is shown only as totals.
    |
    */

    'product_types' => [
        'hotel' => [7, 22, 157, 31, 266, 21, 388, 32],
        'transfer' => [5, 311],
    ],

    /*
    |--------------------------------------------------------------------------
    | Check-in verification
    |--------------------------------------------------------------------------
    |
    | Relative tolerances between the requested amount and the eTrip cost:
    | up to `ok` the request matches, up to `warn` it needs an explanation,
    | above that it is a significant difference.
    |
    */

    'tolerance' => [
        'ok' => 0.005,
        'warn' => 0.03,
    ],

    'statement_timeout_ms' => (int) env('ETRIP_STATEMENT_TIMEOUT_MS', 30000),

    'expected_cache_minutes' => (int) env('ETRIP_EXPECTED_CACHE_MINUTES', 360),

];
