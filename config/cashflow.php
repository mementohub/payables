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

    /*
    | Snapshots whose cell details (cash_flow_details) are kept; older ones
    | keep their figures but can no longer be opened cell by cell.
    */
    'keep_details' => 10,

    'statement_timeout_ms' => (int) env('CASHFLOW_STATEMENT_TIMEOUT_MS', 240000),

    /*
    | eTrip: where the bookings come from. Every connection listed here is
    | read; the CHR base is the default, Vacanza can be added from the page.
    */
    'etrip' => [
        'connections' => ['etrip_chr'],

        /**
         * public.product_types ids grouped the way the report pays them
         * (bookings.items of the confirmed bookings).
         */
        'product_types' => [
            'hotel' => [7, 22, 31, 157],
            'transfer' => [5, 311, 37, 20, 41, 365, 378, 379],
            'insurance' => [28, 304, 305, 306, 366, 384],
            'flight' => [26, 3, 375],
            'charter' => [30],
        ],

        /**
         * Receivable segments by the product type of the booking's most
         * expensive root item (B1–B7).
         */
        'segments' => [
            'pachete' => [21, 45],
            'circuite' => [39, 196],
            'exotic' => [31, 32],
            'sphinx' => [157, 375, 388],
            'cazare' => [7, 22],
            'bilete' => [26, 3, 30],
        ],

        /** settings.distribution_channels ids that sell to agencies (B2B). */
        'b2b_channels' => [2, 3, 6, 14, 49],

        'receivables' => [
            /** Bookings with departures this far back still carry receivables. */
            'lookback_days' => 365,
            /** … and this far ahead. */
            'lookahead_days' => 400,
            /** Balance due date when eTrip has none: departure minus these days. */
            'fallback_days' => 21,
            'min_balance' => 0.5,
        ],
    ],

    /*
    | OMC: the accounting database, read through config/omc.php.
    */
    'omc' => [
        /**
         * Bank and cash documents are recognised by the tip_doc flags
         * (incasare_b / plata_b / incasare_c / plata_c). Moves between the
         * company's own accounts are not flows: cash deposited to the bank
         * (FV_C / DP_Casa), deposits placed or withdrawn (counterpart 508x)
         * and transfers (581); a credit line drawn or repaid (519x) is
         * financing and only counts for the position.
         */
        'internal_tip_doc' => ['FV_C', 'FV_B', 'DP_Casa', 'DI_Casa'],
        'internal_coresp' => ['5081', '5121', '5124', '5125', '581', '5311', '5314', '5191', '1621', '1622', '167', '5186'],
        'salary_tax_coresp' => ['421', '425', '4315', '4316', '436', '444', '447', '4411', '4423', '446', '462', '457', '427', '426', '423'],
        'deposit_account' => '5081',
        'supplier_tip_doc' => ['FactFI', 'FactFE'],
        /** Months of history the OPEX averages are taken from. */
        'opex_months' => 12,
        /** Ledger credit accounts that mean "paid from the bank or the cash desk". */
        'treasury_prefixes' => ['512', '531'],
    ],

    /*
    | Actual cash flow on the report's lines (ActualCashFlowClassifier).
    | `partner_lines` sends every payment to an OMC partner to one line,
    | ahead of the other rules: group companies whose payments mix several
    | kinds of service.
    */
    'actuals' => [
        'partner_lines' => [
            'MEMENTO INTERNATIONAL SRL' => 'C1',
        ],
    ],

    /*
    | OPEX categories: monthly amount (RON) and the day it leaves the bank.
    | `accounts` are the synthetic account prefixes of the supplier-invoice
    | lines in OMC the 12-month average is computed from; categories without
    | accounts are paid from the ledger (salaries, taxes) and take the value
    | saved in the parameters.
    */
    'opex' => [
        ['key' => 'salarii_nete', 'label' => 'Salarii nete (421)', 'rule' => ['type' => 'monthly', 'day' => 5], 'basis' => 'ledger', 'accounts' => ['421'], 'default' => 574000, 'source' => 'OMC reg_jurnal 421 → 512x/531x, medie 12 luni închise; plătit ~5 ale lunii'],
        ['key' => 'avans_salarii', 'label' => 'Avans salarii (425)', 'rule' => ['type' => 'monthly', 'day' => 20], 'basis' => 'ledger', 'accounts' => ['425'], 'default' => 597000, 'source' => 'OMC reg_jurnal 425 → 512x/531x, medie 12 luni închise; plătit ~20 ale lunii'],
        ['key' => 'contributii', 'label' => 'Contribuții și impozite salariale (431x, 436, 444, 447)', 'rule' => ['type' => 'monthly', 'day' => 25], 'basis' => 'ledger', 'accounts' => ['431', '436', '444', '447'], 'default' => 978000, 'source' => 'OMC reg_jurnal 431x/436/444/447 → 512x, medie 12 luni închise; plătit 25 ale lunii'],
        ['key' => 'impozit_profit', 'label' => 'Impozit pe profit (4411) – trimestrial', 'rule' => ['type' => 'quarterly', 'day' => 25, 'months' => [1, 4, 7, 10]], 'basis' => 'ledger', 'accounts' => ['4411', '441'], 'default' => 1460000, 'source' => 'OMC reg_jurnal 4411 → 512x, medie lunară × 3; 25 ian/apr/iul/oct'],
        ['key' => 'chirii', 'label' => 'Chirii, utilități și administrare sedii (612, 605, 626)', 'rule' => ['type' => 'uniform'], 'basis' => 'invoices', 'accounts' => ['612', '605', '626'], 'default' => 387000, 'source' => 'OMC FactFI conturi 612/605/626, medie lunară 12 luni'],
        ['key' => 'marketing', 'label' => 'Marketing, media și producție publicitară (623)', 'rule' => ['type' => 'uniform'], 'basis' => 'invoices', 'accounts' => ['623'], 'default' => 466000, 'source' => 'OMC FactFI cont 623, medie lunară 12 luni'],
        ['key' => 'servicii', 'label' => 'Servicii terți, IT, consultanță (628, 622, 621)', 'rule' => ['type' => 'uniform'], 'basis' => 'invoices', 'accounts' => ['628', '622', '621'], 'default' => 473000, 'source' => 'OMC FactFI conturi 628/622/621, medie lunară 12 luni'],
        ['key' => 'consumabile', 'label' => 'Consumabile, auto, întreținere, deplasări (602-625)', 'rule' => ['type' => 'uniform'], 'basis' => 'invoices', 'accounts' => ['602', '604', '611', '613', '615', '624', '625'], 'default' => 286000, 'source' => 'OMC FactFI conturi 602/604/611/613/615/624/625'],
        ['key' => 'alte_taxe', 'label' => 'Alte taxe și cheltuieli (635, 65x, 66x)', 'rule' => ['type' => 'uniform'], 'basis' => 'invoices', 'accounts' => ['635', '65', '66'], 'default' => 54000, 'source' => 'OMC FactFI conturi 635/65x/66x'],
        ['key' => 'banci', 'label' => 'Comisioane bancare și diferențe de curs (627, 6651)', 'rule' => ['type' => 'uniform'], 'basis' => 'ledger', 'accounts' => ['627', '6651'], 'default' => 261000, 'source' => 'OMC reg_jurnal 627 + 6651 → bănci, medie 12 luni închise'],
        ['key' => 'alte_admin', 'label' => 'Alte plăți administrative (462, avansuri decontare)', 'rule' => ['type' => 'uniform'], 'basis' => 'ledger', 'accounts' => ['462'], 'default' => 300000, 'source' => 'OMC reg_jurnal 462 → 512x/531x, medie 12 luni închise (în mare parte one-off)'],
        ['key' => 'capex', 'label' => 'CAPEX (imobilizări)', 'rule' => ['type' => 'uniform'], 'basis' => 'invoices', 'accounts' => ['2'], 'default' => 0, 'source' => 'OMC FactFI conturi 2xx, medie 12 luni (achiziții one-off)'],
        ['key' => 'dividende', 'label' => 'Dividende', 'rule' => ['type' => 'uniform'], 'basis' => 'ledger', 'accounts' => ['457'], 'default' => 0, 'source' => 'OMC reg_jurnal 457 → 512x, medie 12 luni închise'],
    ],

];
