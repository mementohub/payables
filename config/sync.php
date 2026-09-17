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
    | Without a running scheduler, page views start the background sync when
    | the last run is older than `auto_minutes`, and a `window_days` pass once
    | a day from `nightly_hour` on. With the scheduler alive, its cron does it.
    */
    'auto_enabled' => (bool) env('SYNC_AUTO_ENABLED', true),

    /*
     * Memory the detached background runs ask PHP for. They walk a decade of
     * ERP documents in batches, so they need more than a web request does,
     * and the CLI ini of a managed server is not ours to edit. Empty leaves
     * the server's own setting alone.
     */
    'run_memory_limit' => env('SYNC_RUN_MEMORY_LIMIT', '512M'),

    'auto_minutes' => (int) env('SYNC_AUTO_MINUTES', 10),

    /*
    | The nightly pass (cron and page-view fallback alike) runs at this hour
    | in this timezone.
    */
    'timezone' => env('SYNC_TIMEZONE', 'Europe/Bucharest'),

    'nightly_hour' => (int) env('SYNC_NIGHTLY_HOUR', 4),

    /*
    | "Adu tot istoricul" pulls every document from this date on, in slices of
    | `history_slice_days`, remembering the last finished slice so a stopped
    | run continues where it left off.
    */
    'history_from' => env('SYNC_HISTORY_FROM', '2016-01-01'),

    'history_slice_days' => (int) env('SYNC_HISTORY_SLICE_DAYS', 7),

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
