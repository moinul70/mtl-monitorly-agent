# Monitoring Agent (mtlm/monitoring-agent)

A lightweight Laravel package that logs request timing, status, and memory usage into a table, serves it back through a cached JSON endpoint for a polling dashboard, and optionally emails a group of recipients when requests cross configurable "vulnerable" thresholds — all queued, not sent inline.

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Attach the Middleware](#attach-the-middleware)
- [Configuration](#configuration)
- [The Metrics Endpoint](#the-metrics-endpoint)
- [Caching Behavior](#caching-behavior)
- [Vulnerability Alerts](#vulnerability-alerts)
- [Scheduling](#scheduling)
- [Artisan Commands Reference](#artisan-commands-reference)
- [Queueing](#queueing)
- [Known Limitations & Troubleshooting](#known-limitations--troubleshooting)
- [Conclusion](#conclusion)

---

## Features

- One middleware, attached wherever you want, logs every request's method, path, status code, response time, memory delta, and peak memory into a `request_logs` table
- A cached `GET /metrics/{projectName}` endpoint aggregates recent logs into stats a dashboard can poll every few seconds without re-querying the database each time
- Optional scheduled scan that emails a group of recipients a batched summary whenever requests cross configurable status/latency/memory thresholds
- Sampling, path exclusions, and retention pruning so logging stays cheap even on modest hosting
- Optional queueing for both the DB write and the alert email, so neither adds cost to the request/response cycle

---

## Requirements

- PHP 8.1+
- Laravel 10.x, 11.x, or 12.x
- A working mail configuration if you use the alerting feature
- A supervised queue worker (Supervisor, systemd, etc.) if you enable `use_queue` or use alerts — see [Queueing](#queueing)

---

## Installation

```bash
composer require mtlm/monitoring-agent
```

Publish the config and migrations:

```bash
php artisan vendor:publish --tag=mtl-monitorly-agent-config
php artisan vendor:publish --tag=mtl-monitorly-agent-migrations
```

Run the migrations:

```bash
php artisan migrate
```

This creates the `mtl_request_logs` table with the following columns:

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | Primary key |
| `project_name` | string(200) | Which project/app this row belongs to |
| `method` | string(10) | HTTP method |
| `path` | string | Request path |
| `status_code` | unsigned smallint | Response status code |
| `response_ms` | unsigned int | Response time in milliseconds |
| `memory_mb` | float, nullable | Memory used during this request |
| `peak_memory_mb` | float, nullable | Peak memory during this request |
| `ip` | string(45), nullable | Requester IP |
| `user_agent` | string, nullable | Requester user agent |
| `created_at` | timestamp | When the request was logged |

---

## Attach the Middleware

The middleware is registered under the alias `track-requests`.

**Laravel 11 (`bootstrap/app.php`):**
```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->api(prepend: [
        'track-requests',
    ]);
})
```

**Laravel 10 (`app/Http/Kernel.php`):**
```php
protected $middlewareGroups = [
    'api' => [
        \Mtl\MonitorlyAgent\Http\Middleware\MonitoringAgentMiddleware::class,
        // ...existing middleware
    ],
];
```

Or attach it to specific routes only:
```php
Route::middleware('track-requests')->group(function () {
    // ...
});
```

---

## Configuration

Published to `config/mtl-monitorly-agent.php`.

| Key | Env var | Default | Purpose |
|---|---|---|---|
| `project_name` | `REQUEST_TRACKER_PROJECT_NAME` | `my-project` | Value stored in `project_name` for every row logged by this app |
| `window_minutes` | — | `5` | How far back the metrics endpoint aggregates |
| `cache_ttl` | — | `60` | Legacy TTL value — largely superseded by write-invalidation, see [Caching Behavior](#caching-behavior) |
| `sample_rate` | — | `1.0` | Fraction of requests actually logged, `0.0`–`1.0` |
| `excluded_paths` | — | `['api/metrics/*', 'up', 'health']` | `Str::is()` patterns never logged |
| `retention_days` | — | `7` | How many days of logs `mtl-monitorly-agent:prune` keeps |
| `use_queue` | `REQUEST_TRACKER_USE_QUEUE` | `false` | Queue the DB write instead of writing inline — see [Queueing](#queueing) |
| `queue_connection` | `REQUEST_TRACKER_QUEUE_CONNECTION` | app default | Queue connection for both the log-write job and alert emails |
| `queue_name` | `REQUEST_TRACKER_QUEUE_NAME` | `default` | Queue name for both |
| `alerts.enabled` | `REQUEST_TRACKER_ALERTS_ENABLED` | `false` | Master switch for vulnerability alerts |
| `alerts.check_frequency_minutes` | `REQUEST_TRACKER_ALERTS_FREQUENCY` | `5` | How often the scan runs |
| `alerts.recipients` | `REQUEST_TRACKER_ALERT_RECIPIENTS` | *(empty)* | Comma-separated email list |
| `alerts.thresholds.status_code` | `REQUEST_TRACKER_ALERT_STATUS_CODE` | `500` | Alert if status code &ge; this |
| `alerts.thresholds.response_ms` | `REQUEST_TRACKER_ALERT_RESPONSE_MS` | `2000` | Alert if response time &gt; this |
| `alerts.thresholds.peak_memory_mb` | `REQUEST_TRACKER_ALERT_PEAK_MEMORY_MB` | `256` | Alert if peak memory &gt; this |
| `alerts.max_rows_per_email` | `REQUEST_TRACKER_ALERT_MAX_ROWS` | `50` | Caps rows in a single alert email |

> ⚠️ `route_prefix` also exists in the config file but is **not currently applied anywhere** in the package's route registration — see [Known Limitations](#known-limitations--troubleshooting).

---

## The Metrics Endpoint

```
GET /metrics/{projectName}
```

Returns cached, aggregated stats for the given project:

```json
{
  "projectName": "likelab-api",
  "endpoints": [ { "method": "GET", "path": "/api/products", "status_code": 200, "response_ms": 42 } ],
  "count": 87,
  "avgResponseMs": 55.3,
  "errorRatePercent": 2.3,
  "timestamp": "2026-08-27T10:15:00+00:00"
}
```

> ⚠️ As currently registered via `loadRoutesFrom()`, this route is mounted at the **application root** (`/metrics/{projectName}`), not under `/api/`. If you expected the `route_prefix` config to apply an `/api` prefix automatically, it doesn't yet — see [Known Limitations](#known-limitations--troubleshooting).

---

## Caching Behavior

Metrics are cached with `Cache::rememberForever()`, keyed per project (`mtl-monitorly-agent:metrics:{projectName}`), and explicitly cleared (`Cache::forget()`) every time a new row is written for that project — whether the write happens inline or via the queued job. This means:

- Reads are effectively always a cache hit between writes
- The cache is never stale by more than the time it takes the next request to log
- A quiet project with no new traffic keeps serving its last-known stats indefinitely, which is expected — there's nothing new to reflect

If you're running multiple app servers behind a load balancer, use a shared cache driver (Redis/Memcached) — the `file` driver won't invalidate consistently across servers.

---

## Vulnerability Alerts

When enabled, a scheduled command periodically scans for new `request_logs` rows that cross any of three thresholds — status code, response time, or peak memory — and emails a single batched summary to a group of recipients.

### Enable it

```env
REQUEST_TRACKER_ALERTS_ENABLED=true
REQUEST_TRACKER_ALERT_RECIPIENTS=you@example.com,team@example.com
REQUEST_TRACKER_ALERTS_FREQUENCY=5
REQUEST_TRACKER_ALERT_STATUS_CODE=500
REQUEST_TRACKER_ALERT_RESPONSE_MS=2000
REQUEST_TRACKER_ALERT_PEAK_MEMORY_MB=256
```

With `alerts.enabled` off (the default), the scan isn't even registered on the scheduler — not just skipped at runtime.

### How duplicate alerts are avoided

The scan tracks a cache-based watermark (`mtl-monitorly-agent:alerts:last_id`) — the highest `request_logs.id` it has already scanned. Each run only looks at rows newer than that watermark, then advances it past everything it just scanned, whether or not those rows were vulnerable. This means:

- The same row is never included in two alert emails
- Ordinary (non-vulnerable) rows are never re-scanned on the next run
- A single run is capped at 5,000 rows internally, so a traffic spike can't make one run unbounded — it just takes a couple of scheduled runs to fully catch up, with no data loss in between

### Email content

One email per run (not one per vulnerable row), capped at `alerts.max_rows_per_email`, listing project, method, path, status, response time, peak memory, and timestamp for each flagged request — sent via `Mtl\MonitorlyAgent\Mail\VulnerableRequestsDetected`, a queued `Mailable`.

---

## Scheduling

Two commands are registered on Laravel's scheduler automatically:

```php
$schedule->command('mtl-monitorly-agent:prune')->daily();

// only if alerts.enabled is true
$schedule->command('mtl-monitorly-agent:check-vulnerable')
    ->cron("*/{minutes} * * * *")
    ->withoutOverlapping();
```

For either to actually run, your server's cron must be calling Laravel's scheduler every minute — this is not automatic:

```
* * * * * cd /path-to-your-app && php artisan schedule:run >> /dev/null 2>&1
```

---

## Artisan Commands Reference

| Command | Description |
|---|---|
| `mtl-monitorly-agent:prune` | Deletes logs older than `retention_days` |
| `mtl-monitorly-agent:check-vulnerable` | Scans new logs for threshold breaches and queues an alert email if any are found |

Both can be run manually at any time, independent of the scheduler.

---

## Queueing

Two separate things can be queued, independently configured:

1. **The log write itself** — controlled by `use_queue`. Off by default; a single indexed insert is cheap enough that synchronous writes are the safer default on shared hosting without a reliably supervised worker.
2. **Alert emails** — always queued (`VulnerableRequestsDetected implements ShouldQueue`), since these should never block the scheduler run.

Both use the same `queue_connection`/`queue_name` config. **If you enable `use_queue` or `alerts.enabled` without a queue worker actually running continuously, logs or alerts will silently stop appearing — there's no error, just missing data.** Run:

```bash
php artisan queue:work
```

supervised by something that restarts it if it crashes (plain `nohup`/manual runs are not sufficient for production).

**After deploying any package update, restart your worker(s):**
```bash
php artisan queue:restart
```
A long-running worker process does not pick up code changes — including new service-provider registrations like view namespaces — until it's restarted. Skipping this is the most common cause of errors like `No hint path defined for [mtl-monitorly-agent]` right after an upgrade.

---

## Known Limitations & Troubleshooting

- **`route_prefix` config is currently unused.** The metrics route is registered via `loadRoutesFrom()` directly, without applying `route_prefix` or wrapping it in the `api` middleware group. If you need it under `/api/metrics/{projectName}` with API-appropriate middleware (rate limiting, etc.), wrap the route registration yourself in a service provider override, or open an issue/PR to have the package apply it automatically.
- **No per-project alert thresholds.** Thresholds are global across all projects sharing this installation — a project with naturally slower endpoints will alert at the same `response_ms` threshold as a fast one.
- **Peak memory reflects the whole PHP process**, not strictly the request handler — under heavy concurrency on some SAPIs this can be noisier than a per-request-isolated measurement.
- **`No hint path defined for [mtl-monitorly-agent]` after upgrading** — almost always a stale queue worker; see [Queueing](#queueing) above. If restarting the worker doesn't resolve it, confirm the installed package version actually has `loadViewsFrom()` in its service provider (`grep -n "loadViewsFrom" vendor/mtlm/monitoring-agent/src/RequestTrackerServiceProvider.php`) and that `composer dump-autoload` has been run.
- **Cached metrics can look "stale" during a quiet period** — this is by design (write-invalidated, not time-invalidated); see [Caching Behavior](#caching-behavior).

---

## Conclusion

This package gives you request-level observability — timing, status, memory — with minimal setup: attach one middleware, run the migrations, and point a dashboard at the metrics endpoint. The alerting layer is entirely opt-in and additive, so teams that only want passive logging can ignore it completely, while teams that want proactive notification can turn it on with a handful of environment variables. Everything expensive (writes, emails) can be pushed onto a queue, keeping the actual request/response cycle unaffected either way.
