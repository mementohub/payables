<?php

return [

    /*
    |--------------------------------------------------------------------------
    | WCFR 52 Weeks — weekly cash-flow report
    |--------------------------------------------------------------------------
    |
    | The report is rebuilt every night after the OMC sync and on demand from
    | the report page; the page shows the last snapshot. Everything a user may
    | want to tune lives in the parameters saved from the page (see
    | App\Services\CashFlow\CashFlowParameters); these are the defaults.
    |
    */

    'weeks' => (int) env('CASHFLOW_WEEKS', 52),

    'timezone' => env('CASHFLOW_TIMEZONE', 'Europe/Bucharest'),

    'nightly_hour' => (int) env('CASHFLOW_NIGHTLY_HOUR', 4),

    'nightly_minute' => (int) env('CASHFLOW_NIGHTLY_MINUTE', 30),

    /** Snapshots kept for the history of the report. */
    'keep_snapshots' => 60,

    'statement_timeout_ms' => (int) env('CASHFLOW_STATEMENT_TIMEOUT_MS', 240000),

    /*
    | eTrip: where the bookings come from. Every connection listed here is
    | read; the CHR base is the default, Vacanza can be added from the page.
    */
    'etrip' => [
        'connections' => ['etrip_chr'],

        /** public.product_types ids, grouped the way the report pays them. */
        'product_types' => [
            'package' => [21],
            'hotel' => [7, 22, 157, 31, 266, 388, 32],
            'transfer' => [5, 311, 20, 37],
            'insurance' => [28],
            'flight' => [26, 3],
            'charter' => [30],
            'tour' => [39],
        ],

        /** settings.distribution_channels ids of the Sphinx platform. */
        'sphinx_channels' => [16, 49],

        /** Continent (public.geography level 1) names that are not "exotic". */
        'home_continents' => ['Europa', 'Europe'],
    ],

    /*
    | OMC: the accounting database, read through config/omc.php.
    */
    'omc' => [
        'receipt_tip_doc' => ['OP_INC', 'Ch_INC', 'CardINC', 'Reg_INC', 'TichVac', 'Dob_INC'],
        'payment_tip_doc' => ['OP_PL', 'Ch_PL', 'Reg_PL', 'Comis_B'],
        /** Cash/bank moves between the company's own accounts, not flows. */
        'internal_tip_doc' => ['DP_Casa', 'DI_Casa'],
        'supplier_tip_doc' => ['FactFI', 'FactFE'],
        /** Months of history the OPEX averages are taken from. */
        'opex_months' => 12,
    ],

    /*
    | OPEX categories: monthly amount (RON) and the day it leaves the bank.
    | `accounts` are the synthetic account prefixes of the supplier-invoice
    | lines in OMC the 12-month average is computed from; categories without
    | accounts are paid from the ledger (salaries, taxes) and take the value
    | saved in the parameters.
    */
    'opex' => [
        ['key' => 'salarii_nete', 'label' => 'Salarii nete (421)', 'rule' => ['type' => 'monthly', 'day' => 5], 'accounts' => [], 'default' => 574000, 'source' => 'OMC 421 → 5121, medie 12 luni; plătit ~5 ale lunii'],
        ['key' => 'avans_salarii', 'label' => 'Avans salarii (425)', 'rule' => ['type' => 'monthly', 'day' => 20], 'accounts' => [], 'default' => 597000, 'source' => 'OMC 425 → 5121, medie 12 luni; plătit ~20 ale lunii'],
        ['key' => 'contributii', 'label' => 'Contribuții și impozite salariale (431x, 436, 444, 447)', 'rule' => ['type' => 'monthly', 'day' => 25], 'accounts' => [], 'default' => 978000, 'source' => 'OMC, medie 12 luni; plătit 25 ale lunii'],
        ['key' => 'impozit_profit', 'label' => 'Impozit pe profit (4411) – trimestrial', 'rule' => ['type' => 'quarterly', 'day' => 25, 'months' => [1, 4, 7, 10]], 'accounts' => [], 'default' => 1460000, 'source' => 'OMC 4411 ≈ 487k/lună ⇒ ~1,46M/trimestru; 25 ian/apr/iul/oct'],
        ['key' => 'chirii', 'label' => 'Chirii, utilități și administrare sedii (612, 605, 626)', 'rule' => ['type' => 'uniform'], 'accounts' => ['612', '605', '626'], 'default' => 387000, 'source' => 'OMC FactFI conturi 612/605/626, medie lunară 12 luni'],
        ['key' => 'marketing', 'label' => 'Marketing, media și producție publicitară (623)', 'rule' => ['type' => 'uniform'], 'accounts' => ['623'], 'default' => 466000, 'source' => 'OMC FactFI cont 623, medie lunară 12 luni'],
        ['key' => 'servicii', 'label' => 'Servicii terți, IT, consultanță (628, 622, 621)', 'rule' => ['type' => 'uniform'], 'accounts' => ['628', '622', '621'], 'default' => 473000, 'source' => 'OMC FactFI conturi 628/622/621, medie lunară 12 luni'],
        ['key' => 'consumabile', 'label' => 'Consumabile, auto, întreținere, deplasări (602-625)', 'rule' => ['type' => 'uniform'], 'accounts' => ['602', '604', '611', '613', '615', '624', '625'], 'default' => 286000, 'source' => 'OMC FactFI conturi 602/604/611/613/615/624/625'],
        ['key' => 'alte_taxe', 'label' => 'Alte taxe și cheltuieli (635, 65x, 66x)', 'rule' => ['type' => 'uniform'], 'accounts' => ['635', '65', '66'], 'default' => 54000, 'source' => 'OMC FactFI conturi 635/65x/66x'],
        ['key' => 'banci', 'label' => 'Comisioane bancare și diferențe de curs (627, 6651)', 'rule' => ['type' => 'uniform'], 'accounts' => [], 'default' => 261000, 'source' => 'OMC 627 + 6651 → bănci, medie 12 luni'],
        ['key' => 'alte_admin', 'label' => 'Alte plăți administrative (462, avansuri decontare)', 'rule' => ['type' => 'uniform'], 'accounts' => [], 'default' => 300000, 'source' => 'Ipoteză prudentă (OMC 462 medie 589k/lună, în mare parte one-off)'],
        ['key' => 'capex', 'label' => 'CAPEX (imobilizări)', 'rule' => ['type' => 'uniform'], 'accounts' => [], 'default' => 0, 'source' => 'De completat: OMC ~1,33M lei/lună în ultimele 12 luni (achiziții one-off)'],
        ['key' => 'dividende', 'label' => 'Dividende', 'rule' => ['type' => 'uniform'], 'accounts' => [], 'default' => 0, 'source' => 'De completat: OMC 457 – 36M lei plătiți în ultimele 12 luni'],
    ],

];
