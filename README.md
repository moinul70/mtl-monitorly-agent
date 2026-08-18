# Laravel Monitoring Agent

One middleware logs every request's timing and status into a table. One cached endpoint reads it back for a polling dashboard.

## Install

```bash
composer require moinul/mtl-monitoring-agent
php artisan migrate
```

## Attach the middleware

**Laravel 11** (`bootstrap/app.php`):
```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->api(prepend: [
        \Mtl\RequestTracker\Http\Middleware\TrackRequestMiddleware::class,
    ]);
})
```

**Laravel 10** (`app/Http/Kernel.php`):
```php
protected $middlewareGroups = [
    'api' => [
        \Mtl\RequestTracker\Http\Middleware\TrackRequestMiddleware::class,
        // ...existing middleware
    ],
];
```

## The endpoint

```
GET /api/metrics
```

```json
{
  "logs": [ { "method": "GET", "path": "api/products", "status_code": 200, "response_ms": 42, ... } ],
  "count": 87,
  "avgResponseMs": 55.3,
  "errorRatePercent": 2.3,
  "timestamp": "2026-08-19T10:15:00+00:00"
}
```

## Performance fixes applied (vs. the naive version)

| Issue | Fix |
|---|---|
| Full table scan on read | Indexes on `created_at` and `status_code` |
| Table grows forever | `request-tracker:prune` command, scheduled daily, keeps `retention_days` (default 7) |
| Every dashboard poll recomputes aggregates | `Cache::remember()` wraps the read query for `cache_ttl` seconds (default 5) |
| Every single request writes to DB | `sample_rate` config (default `1.0` = log everything; lower it under heavy traffic) |
| Noisy/irrelevant paths logged | `excluded_paths` config |
| DB write happens inline in the request cycle | Optional queueing — see below |

All tunables live in `config/request-tracker.php` after publishing:
```bash
php artisan vendor:publish --tag=request-tracker-config
```

## Scheduling the prune command

The service provider registers `request-tracker:prune` to run daily automatically — just make sure Laravel's scheduler is actually running via cron:
```
* * * * * cd /path-to-your-app && php artisan schedule:run >> /dev/null 2>&1
```

## Optional: queue the DB write

By default, the middleware writes to the database synchronously inside `terminate()` — simple, reliable, no extra moving parts.

If you'd rather remove that write cost from the request cycle entirely, enable queueing:

```env
REQUEST_TRACKER_USE_QUEUE=true
REQUEST_TRACKER_QUEUE_CONNECTION=redis   # optional, defaults to your app's default connection
REQUEST_TRACKER_QUEUE_NAME=request-logs  # optional, defaults to "default"
```

When enabled, the middleware dispatches a `RecordRequestLog` job instead of writing directly — the actual `INSERT` happens on a queue worker, off the request/response path.

**Important trade-off:** this only works if a queue worker is actually running continuously (e.g. via Supervisor or systemd on a VPS). If `use_queue` is enabled but no worker is running, jobs pile up in the queue table/driver and **logs silently stop appearing in `/api/metrics`** — there's no error, just missing data. Only enable this once you've confirmed your worker setup is reliable:

```bash
php artisan queue:work --queue=request-logs
```

(supervised by something that restarts it if it crashes — plain `nohup` is not enough for production).

## Why sync is the default, not queue

A queued write removes the DB-write cost from the request cycle entirely — but it requires a queue worker process running continuously. On shared/cPanel hosting, that's one more thing to babysit and one more way for data to silently stop recording if the worker dies. A single indexed insert is fast enough that synchronous writes are the simpler, more reliable default at this scale. Flip `use_queue` on once you've moved to (or confirmed you already have) a supervised worker.
