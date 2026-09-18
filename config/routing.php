<?php

/*
|--------------------------------------------------------------------------
| Routing supplier invoices to departments
|--------------------------------------------------------------------------
|
| A tourism cost line in OMC carries the eTrip booking it was bought for
| (doc_poz.com_int: Christian Tour ids below 10,000,000, Vacanza above). The
| booking's main root item says which product category owns the cost: the
| internal supplier on the package (1. Christian Tour, 2. Circuite, …), its
| brand, or else its product type. The booking's client and branch say the
| sales channel. The ids below are eTrip's (they differ between the two
| databases).
|
*/

return [

    'etrip' => [
        'vacanza_from' => 10_000_000,

        'etrip_chr' => [
            // Checked in this order; the first match wins.
            'brands' => ['corporate' => [3], 'senior_voyage' => [2]],
            'suppliers' => [
                'corporate' => ['2573'],
                'senior_voyage' => ['94', '2435', '2436'],
                'circuite_exotice' => ['2398'],
                'circuite_culturale' => ['53', '914'],
                'sejururi_exotice' => ['33'],
                'croaziere' => ['643', '720', '314', '910', '995', '1001', '684', '2557', '3489', '2386'],
            ],
            'product_types' => [
                'corporate' => [156],
                'senior_voyage' => [195, 266],
                'circuite_exotice' => [196],
                'circuite_culturale' => [39],
                'sejururi_exotice' => [31, 32],
                'croaziere' => [49, 368],
                'charters' => [21, 34, 40, 45, 29, 48, 300, 265, 303, 38, 388, 52, 30],
                'cazari_individuale' => [7, 22, 157, 33, 355, 363, 369, 387, 198, 44, 50, 389],
                'ticketing' => [26, 3, 375],
            ],
        ],

        'etrip_vcz' => [
            'brands' => [],
            'suppliers' => [],
            'product_types' => [
                'charters' => [10, 48, 275, 46, 45, 2],
                'croaziere' => [1],
                'circuite_culturale' => [6, 87],
                'cazari_individuale' => [47, 7, 49, 241, 50, 51, 84, 88, 121, 122, 123, 124, 157, 190, 191, 192, 193, 194, 195, 203, 242],
                'ticketing' => [9, 3, 207],
            ],
        ],

        /** settings.branch_offices ids of the web shop. */
        'site_branches' => [112, 16],
    ],

    /*
    | A charter seat invoice from an airline under contract carries the
    | rotation code, not a booking: the partners of the charter contracts go
    | to this department whatever their lines say.
    */
    'charter_department' => 'charters',

    /*
    | Tina ticket references (TN_…) are Ticketing, or Corporate when the
    | invoice was booked at the Corporate office.
    */
    'ticket_prefix' => 'TN_',
    'ticketing_department' => 'ticketing',
    'corporate_office' => 'Corporate',
    'corporate_department' => 'corporate',

    /*
    | A supplier's past lines tell where a new one goes when nothing else
    | does: the department that took at least this share of them.
    */
    'history_share' => 0.6,
    'history_min_lines' => 3,

];
