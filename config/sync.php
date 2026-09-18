<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ERP document sync
    |--------------------------------------------------------------------------
    |
    | The supplier side of each company's ERP (OMC) is mirrored into the local
    | tables the invoice, supplier and bank statement pages read. The
    | scheduler runs `erp:sync` every ten minutes for the last `recent_days`
    | and nightly for the last `window_days`; every run also re-reads every
    | invoice open in OMC or locally, so payments show up at once.
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
    | "Adu tot istoricul" pulls every supplier invoice from this date on, a
    | slice at a time, remembering the last finished slice so a stopped run
    | continues where it left off.
    */
    'history_from' => env('SYNC_HISTORY_FROM', '2013-01-01'),

    /*
    | A window is pulled in slices of this many days (OMC is read a page of
    | its primary key at a time within each slice).
    */
    'slice_days' => (int) env('SYNC_SLICE_DAYS', 31),

    /*
    | The nightly pass re-reads this many days whole: OMC documents are often
    | entered or edited weeks after their date, and the cost centres are
    | tagged at month close, so thirteen months are kept current.
    */
    'window_days' => (int) env('SYNC_WINDOW_DAYS', 400),

    /*
    | Syncs started from the browser run as a detached background process.
    | When one cannot be started, a range up to this many days runs inline
    | as a last resort (a web request that takes minutes times out).
    */
    'inline_max_days' => (int) env('SYNC_INLINE_MAX_DAYS', 2),

];
