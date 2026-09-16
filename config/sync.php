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

    /*
    | A window is pulled in slices of this many days, so each slice's lookups
    | stay small and the log shows progress.
    */
    'slice_days' => (int) env('SYNC_SLICE_DAYS', 3),

    'window_days' => (int) env('SYNC_WINDOW_DAYS', 45),

    /*
    | Syncs started from the browser run as a detached background process.
    | When one cannot be started, a range up to this many days runs inline
    | as a last resort (a web request that takes minutes times out).
    */
    'inline_max_days' => (int) env('SYNC_INLINE_MAX_DAYS', 2),

];
