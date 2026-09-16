<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OMC accounting database
    |--------------------------------------------------------------------------
    |
    | The connection in config/database.php the supplier invoice check reads
    | live (FactFI / FactFE documents, what was paid and offset against them).
    |
    */

    'connection' => env('OMC_CONNECTION', 'omc'),

    /*
    | Named connections a company may keep its books in, with the label shown
    | in the company form. A company linked here is synced through the
    | connection instead of the credentials stored on the company.
    */
    'connections' => [
        'omc' => 'OMC Christian Tour',
    ],

    /*
    | The local company mirrored from that database, used when a verified
    | request is saved in the register. Left empty, the company whose
    | db_database matches the connection is used, then the one linked to
    | eTrip Christian Tour, then the only company.
    */
    'company_id' => env('OMC_COMPANY_ID'),

    'statement_timeout_ms' => (int) env('OMC_STATEMENT_TIMEOUT_MS', 45000),

    /*
    | Suppliers offered in the picker: partners with supplier invoices dated
    | in the last N years.
    */
    'supplier_years' => (int) env('OMC_SUPPLIER_YEARS', 3),

    /*
    | "Furnizori cu facturi neachitate": open supplier invoices dated in the
    | last N years (older ones are still shown on the supplier's own check).
    */
    'open_window_years' => (int) env('OMC_OPEN_YEARS', 2),

];
