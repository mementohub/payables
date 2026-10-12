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
        'omc_mem_air' => 'OMC Memento Air',
        'omc_mem_int' => 'OMC Memento International',
        'omc_mem_log' => 'OMC Memento Management & Logistics',
    ],

    /*
    |--------------------------------------------------------------------------
    | Casele care refacturează (fostele SPV-uri)
    |--------------------------------------------------------------------------
    |
    | Memento Air, Memento International și Memento Management & Logistics
    | cumpără din piață și refacturează mai departe către Christian Tour. Pe
    | factura primită apare numele lor, dar furnizorul adevărat — compania
    | aeriană, hotelul, mall-ul — se vede abia în baza lor.
    |
    | Fiecare casă lasă altă urmă, fiindcă face altă afacere:
    |
    |   order   — Memento Air. Vânzarea stă pe o comandă de zbor, a cărei
    |             comandă-părinte e luna de charter („CHARTER AUGUST 2026”).
    |             Pe aceeași lună stau și facturile companiilor aeriene. Nu e
    |             potrivire unu-la-unu: se cumpără pe jumătăți de lună și se
    |             vinde pe zboruri, așa că furnizorii se dau procentual.
    |
    |   booking — Memento International. Aceeași comandă — numărul rezervării —
    |             stă și pe vânzarea către noi, și pe documentul hotelului.
    |             Potrivire la document, cu sume care se închid.
    |
    |   article — Memento Management & Logistics. Nu folosește comenzi, dar
    |             refacturează cu exact același articol în aceeași lună
    |             („Chirie Iulius Mall Cluj”). Tot potrivire la document.
    |
    */
    'resellers' => [
        'RO7166273' => [
            'connection' => 'omc_mem_air',
            'label' => 'Memento Air',
            'basis' => 'order',
        ],
        'RO31370020' => [
            'connection' => 'omc_mem_int',
            'label' => 'Memento International',
            'basis' => 'booking',
        ],
        'RO27452962' => [
            'connection' => 'omc_mem_log',
            'label' => 'Memento Management & Logistics',
            'basis' => 'article',
        ],
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
