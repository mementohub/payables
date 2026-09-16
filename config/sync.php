<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ERP document sync
    |--------------------------------------------------------------------------
    |
    | Documents are pulled from each company's ERP database into the local
    | tables the invoice, partner and bank statement pages read. The scheduler
    | runs `erp:sync` every ten minutes for the last `recent_days` and nightly
    | for the last `window_days`; every run also re-reads the invoices still
    | open locally, so payments allocated to older invoices show up too.
    |
    */

    'recent_days' => (int) env('SYNC_RECENT_DAYS', 3),

    'window_days' => (int) env('SYNC_WINDOW_DAYS', 45),

    /*
    | A sync started from the Companies page runs inline (no queue worker
    | needed) up to this many days; longer ranges are handed to Horizon.
    */
    'inline_max_days' => (int) env('SYNC_INLINE_MAX_DAYS', 7),

];
