<?php

return [

    // Project name to use in the UI. Defaults to "my-project" if not set.
    'project_name' => env('REQUEST_TRACKER_PROJECT_NAME', 'my-project'),

    // Route prefix the /metrics endpoint is registered under, e.g. "api"
    // means the final URL is /api/metrics
    'route_prefix' => 'api',

    // How many minutes of recent logs to include when computing metrics
    'window_minutes' => 5,

    // Fix #3: how long (seconds) the computed metrics response is cached.
    // Prevents every dashboard poll from recomputing aggregates from scratch.
    'cache_ttl' => 5,

    // Fix #4: fraction of requests to actually log, 0.0–1.0. Leave at 1.0
    // (log everything) unless you're on high traffic and want to reduce
    // write volume — lowering this trades precision for less DB load.
    'sample_rate' => 1.0,

    // Fix #5: path patterns (Str::is syntax) that should never be logged
    // at all — keeps noise out of the table.
    'excluded_paths' => [
    'api/metrics/*',   // matches api/metrics/ followed by anything
    'api/metrics',     // matches the bare path too, no trailing segment
    'up',
    'health',
],

    // Fix #2: how many days of logs to keep. Old rows are deleted by the
    // request-tracker:prune command — remember to schedule it (see README).
    'retention_days' => 7,

    // Fix #6 (optional): queue the DB write instead of writing inline in
    // terminate(). OFF by default — only enable this if you have a queue
    // worker actually running continuously (e.g. Supervisor on a VPS).
    // On shared/cPanel hosting without a supervised worker, enabling this
    // means logs silently stop recording the moment the worker isn't
    // running — the synchronous default is safer unless you've confirmed
    // your worker setup is reliable.
    'use_queue' => (bool) env('REQUEST_TRACKER_USE_QUEUE', false),

    // Which queue connection to dispatch on. Null = use the app's default
    // connection (config('queue.default')).
    'queue_connection' => env('REQUEST_TRACKER_QUEUE_CONNECTION'),

    // Which named queue to dispatch onto.
    'queue_name' => env('REQUEST_TRACKER_QUEUE_NAME', 'default'),

];
