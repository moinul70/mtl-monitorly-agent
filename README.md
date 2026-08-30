# Monitorly Agent (mtlm/monitoring-agent)

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

- One middleware, attached wherever you want, logs every request's method, path, status code, response time, memory delta, and peak memory into an `mtl_request_logs` table
- A cached `GET /metrics/{projectName}` endpoint aggregates recent logs into stats a dashboard can poll every few seconds without re-querying the database each time
- Optional scheduled scan that emails a group of recipients a batched summary (with a CSV attachment of full details) whenever requests cross configurable status/latency/memory thresholds
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
| `project_name` | `MTL_PROJECT_NAME` | `my-project` | Value stored in `project_name` for every row logged by this app |
| `route_prefix` | — | `api` | Intended URL prefix for the metrics endpoint — see [Known Limitations](#known-limitations--troubleshooting) |
| `window_minutes` | — | `50` | How far back (minutes) the metrics endpoint aggregates |
| `cache_ttl` | — | `60` | Legacy TTL value — largely superseded by write-invalidation, see [Caching Behavior](#caching-behavior) |
| `sample_rate` | — | `1.0` | Fraction of requests actually logged, `0.0`–`1.0` |
| `excluded_paths` | — | `['api/metrics/*', 'api/metrics', 'up', 'health']` | `Str::is()` patterns never logged |
| `retention_days` | — | `7` | How many days of logs the prune command keeps |
| `use_queue` | `MTL_USE_QUEUE` | `false` | Queue the DB write instead of writing inline — see [Queueing](#queueing) |
| `queue_connection` | `MTL_QUEUE_CONNECTION` | app default | Queue connection for both the log-write job and alert emails |
| `queue_name` | `MTL_QUEUE_NAME` | `default` | Queue name for both |
| `alerts.enabled` | `MTL_ALERTS_ENABLED` | `false` | Master switch for vulnerability alerts |
| `alerts.check_frequency_minutes` | `MTL_ALERTS_FREQUENCY` | `5` | How often the scan runs |
| `alerts.recipients` | `MTL_ALERT_RECIPIENTS` | *(empty)* | Comma-separated email list |
| `alerts.thresholds.status_code` | `MTL_ALERT_STATUS_CODE` | `500` | Alert if status code &ge; this |
| `alerts.thresholds.response_ms` | `MTL_ALERT_RESPONSE_MS` | `200` | Alert if response time &gt; this |
| `alerts.thresholds.peak_memory_mb` | `MTL_ALERT_PEAK_MEMORY_MB` | `1` | Alert if peak memory &gt; this — **see caution below** |
| `alerts.max_rows_per_email` | `MTL_ALERT_MAX_ROWS` | `50` | Caps rows in a single alert email |

> ⚠️ **Check `MTL_ALERT_PEAK_MEMORY_MB` before enabling alerts.** The current default is `1` (MB). A single MB of peak memory is lower than what most PHP requests use just to bootstrap a Laravel app, so at this setting almost every logged request will trip the memory alert — you'll likely get an alert email on every scheduled run rather than only on genuine outliers. A more realistic starting point is somewhere in the `64`–`256` range, tuned to your app's normal baseline.

> ⚠️ `route_prefix` is defined here but **not currently applied** by the package's route registration — see [Known Limitations](#known-limitations--troubleshooting).

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
  "timestamp": "2026-08-30T10:15:00+00:00"
}
```

> ⚠️ This route is currently mounted at the **application root** (`/metrics/{projectName}`), not under `/api/` — see [Known Limitations](#known-limitations--troubleshooting).

---

## Caching Behavior

Metrics are cached with `Cache::rememberForever()`, keyed per project (`mtl-monitorly-agent:metrics:{projectName}`), and explicitly cleared (`Cache::forget()`) every time a new row is written for that project — whether the write happens inline or via the queued job. This means:

- Reads are effectively always a cache hit between writes
- The cache is never stale by more than the time it takes the next request to log
- A quiet project with no new traffic keeps serving its last-known stats indefinitely, which is expected — there's nothing new to reflect

If you're running multiple app servers behind a load balancer, use a shared cache driver (Redis/Memcached) — the `file` driver won't invalidate consistently across servers.

---

## Vulnerability Alerts

When enabled, a scheduled command periodically scans for new `mtl_request_logs` rows that cross any of three thresholds — status code, response time, or peak memory — and emails a single batched summary (with a CSV attachment of full row details) to a group of recipients.

### Enable it

```env
MTL_ALERTS_ENABLED=true
MTL_ALERT_RECIPIENTS=you@example.com,team@example.com
MTL_ALERTS_FREQUENCY=5
MTL_ALERT_STATUS_CODE=500
MTL_ALERT_RESPONSE_MS=200
MTL_ALERT_PEAK_MEMORY_MB=128
```

*(Overriding the memory threshold above the risky `1` default — see the caution note in [Configuration](#configuration).)*

With `alerts.enabled` off (the default), the scan isn't even registered on the scheduler — not just skipped at runtime.

### How duplicate alerts are avoided

The scan tracks a cache-based watermark (`mtl-monitorly-agent:alerts:last_id`) — the highest `mtl_request_logs.id` it has already scanned. Each run only looks at rows newer than that watermark, then advances it past everything it just scanned, whether or not those rows were vulnerable. This means:

- The same row is never included in two alert emails
- Ordinary (non-vulnerable) rows are never re-scanned on the next run
- A single run is capped at 5,000 rows internally, so a traffic spike can't make one run unbounded — it just takes a couple of scheduled runs to fully catch up, with no data loss in between

### Email content

The email body is a short summary — total count, project(s) involved, and a per-threshold breakdown. Full row-level detail (project, method, path, status, response time, memory, IP, user agent, timestamp), capped at `alerts.max_rows_per_email`, is attached as a CSV file rather than rendered inline — raw tables in HTML email don't render consistently across clients. Sent via `Mtl\MonitorlyAgent\Mail\VulnerableRequestsDetected`, a queued `Mailable`.

---

## Scheduling

Two commands are registered on Laravel's scheduler automatically:

```php
$schedule->command('mtl-monitorly-agent:prune')->daily();

// only if alerts.enabled is true
$schedule->command('mtl-monitoring-agent:check-vulnerable')
    ->cron("*/{minutes} * * * *")
    ->withoutOverlapping();
```

> ⚠️ **Command name inconsistency:** the prune command is `mtl-monitorly-agent:prune` while the alert-check command is `mtl-monitoring-agent:check-vulnerable` — note "monitor**ly**-agent" vs "monitor**ing**-agent". This is documented exactly as configured; double-check this is intentional, since it's easy to typo one for the other when running commands manually or writing external cron entries.

For either to actually run, your server's cron must be calling Laravel's scheduler every minute — this is not automatic:

```
* * * * * cd /path-to-your-app && php artisan schedule:run >> /dev/null 2>&1
```

---

## Artisan Commands Reference

| Command | Description |
|---|---|
| `mtl-monitorly-agent:prune` | Deletes logs older than `retention_days` |
| `mtl-monitoring-agent:check-vulnerable` | Scans new logs for threshold breaches and queues an alert email if any are found |

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

- **`route_prefix` config is currently unused.** The metrics route is registered directly, without applying `route_prefix` or wrapping it in the `api` middleware group. If you need it under `/api/metrics/{projectName}` with API-appropriate middleware (rate limiting, etc.), wrap the route registration yourself in a service provider override, or ask for this to be wired in automatically.
- **Command naming is inconsistent** — `mtl-monitorly-agent:prune` vs `mtl-monitoring-agent:check-vulnerable`. Worth aligning both to the same prefix to avoid confusion.
- **`MTL_ALERT_PEAK_MEMORY_MB` defaults to `1`**, which is unrealistically low and will likely fire on nearly every request — raise this before enabling alerts in production.
- **No per-project alert thresholds.** Thresholds are global across all projects sharing this installation.
- **Peak memory reflects the whole PHP process**, not strictly the request handler — under heavy concurrency on some SAPIs this can be noisier than a per-request-isolated measurement.
- **`No hint path defined for [mtl-monitorly-agent]` after upgrading** — almost always a stale queue worker; see [Queueing](#queueing) above. If restarting the worker doesn't resolve it, confirm the installed package version has `loadViewsFrom()` in `MonitorlyAgentServiceProvider`, and that `composer dump-autoload` has been run.
- **Cached metrics can look "stale" during a quiet period** — this is by design (write-invalidated, not time-invalidated); see [Caching Behavior](#caching-behavior).

---

## Conclusion

This package gives you request-level observability — timing, status, memory — with minimal setup: attach one middleware, run the migrations, and point a dashboard at the metrics endpoint. The alerting layer is entirely opt-in and additive, so teams that only want passive logging can ignore it completely, while teams that want proactive notification can turn it on with a handful of environment variables. Everything expensive (writes, emails) can be pushed onto a queue, keeping the actual request/response cycle unaffected either way. Before enabling alerts in production, double-check the memory threshold and the command-name inconsistency noted above — both are easy to fix and worth resolving early.