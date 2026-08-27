<?php

return [
    // Fix #1: Project name to use in the UI. Defaults to "my-project" if not set.
    'project_name' => env('MTL_PROJECT_NAME', 'my-project'),

    // Fix #2: Route prefix the /metrics endpoint is registered under, e.g. "api"
    // means the final URL is /api/metrics
    'route_prefix' => 'api',

    // Fix #3: How many minutes of recent logs to include when computing metrics
    'window_minutes' => 50,

    // Fix #4: how long (seconds) the computed metrics response is cached.
    // Prevents every dashboard poll from recomputing aggregates from scratch.
    'cache_ttl' => 60,

    // Fix #5: fraction of requests to actually log, 0.0–1.0. Leave at 1.0
    // (log everything) unless you're on high traffic and want to reduce
    // write volume — lowering this trades precision for less DB load.
    'sample_rate' => 1.0,

    // Fix #6: path patterns (Str::is syntax) that should never be logged
    // at all — keeps noise out of the table.
    'excluded_paths' => [
        'api/metrics/*', // matches api/metrics/ followed by anything
        'api/metrics', // matches the bare path too, no trailing segment
        'up',
        'health',
    ],

    // Fix #7: how many days of logs to keep. Old rows are deleted by the
    // request-tracker:prune command — remember to schedule it (see README).
    'retention_days' => 7,

    // Fix #8 (optional): queue the DB write instead of writing inline in
    // terminate(). OFF by default — only enable this if you have a queue
    // worker actually running continuously (e.g. Supervisor on a VPS).
    // On shared/cPanel hosting without a supervised worker, enabling this
    // means logs silently stop recording the moment the worker isn't
    // running — the synchronous default is safer unless you've confirmed
    // your worker setup is reliable.
    'use_queue' => (bool) env('MTL_USE_QUEUE', false),

    // Fix #9:Which queue connection to dispatch on. Null = use the app's default
    // connection (config('queue.default')).
    'queue_connection' => env('MTL_QUEUE_CONNECTION'),

    // Fix #10: Which named queue to dispatch onto.
    'queue_name' => env('MTL_QUEUE_NAME', 'default'),

    /*
    |--------------------------------------------------------------------
    | Vulnerability Alerts (optional)
    |--------------------------------------------------------------------
    | Periodically scans request_logs for entries that look risky (server
    | errors, slow responses, high memory) and emails a group of
    | recipients a summary — queued, not sent inline.
    */
    'alerts' => [
        // Master switch. OFF by default — nothing runs, nothing is
        // scheduled, until this is explicitly enabled.
        'enabled' => (bool) env('MTL_ALERTS_ENABLED', false),

        // How often the check runs, in minutes.
        'check_frequency_minutes' => (int) env('MTL_ALERTS_FREQUENCY', 5),

        // Group recipients — every address in this list gets the same
        // summary email. Comma-separated in .env.
        'recipients' => array_filter(array_map(
            'trim',
            explode(',', env('MTL_ALERT_RECIPIENTS', ''))
        )),

        // A request is "vulnerable" if it trips ANY of these thresholds.
        'thresholds' => [
            'status_code' => (int) env('MTL_ALERT_STATUS_CODE', 500),
            'response_ms' => (int) env('MTL_ALERT_RESPONSE_MS', 200),
            'peak_memory_mb' => (float) env('MTL_ALERT_PEAK_MEMORY_MB', 1),
        ],

        // Safety cap: never include more than this many rows in a single
        // alert email, even if far more were found in one run.
        'max_rows_per_email' => (int) env('MTL_ALERT_MAX_ROWS', 50),
    ],
];
