# Monitorly Agent (mtlm/monitoring-agent)

A lightweight Laravel package that logs request timing, status, and memory usage into a table, serves it back through a cached JSON endpoint for a polling dashboard, and optionally emails a group of recipients when requests cross configurable "vulnerable" thresholds — all queued, not sent inline.

Pairs with the companion Node.js dashboard, **[mtl-monitorly-app](https://github.com/moinul70/mtl-monitorly-app)**, which gives you a graphical interface to register projects and view their live monitoring data — see [The Companion Dashboard](#the-companion-dashboard).

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Middleware](#middleware)
- [Configuration](#configuration)
- [The Metrics Endpoint](#the-metrics-endpoint)
- [The Companion Dashboard](#the-companion-dashboard)
- [Caching Behavior](#caching-behavior)
- [Vulnerability Alerts](#vulnerability-alerts)
- [Scheduling](#scheduling)
- [Artisan Commands Reference](#artisan-commands-reference)
- [Queueing](#queueing)
- [Known Limitations & Troubleshooting](#known-limitations--troubleshooting)
- [Conclusion](#conclusion)

---

## Features

- A middleware, applied automatically to every route in your `api` middleware group, logs method, path, status code, response time, memory delta, and peak memory into an `mtl_request_logs` table
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

## Middleware

**No manual attachment step is required.** The service provider registers `MonitoringAgentMiddleware` directly onto Laravel's built-in `api` middleware group during boot:

```php
$this->app->make(Router::class)->pushMiddlewareToGroup(
    'api',
    \Mtl\MonitorlyAgent\Http\Middleware\MonitoringAgentMiddleware::class
);
```

This means **every route that goes through the `api` middleware group is tracked automatically** the moment the package is installed — you don't add it to `bootstrap/app.php`, `Kernel.php`, or any individual route yourself.

### What this means in practice

- If you only want *some* API routes tracked, use `excluded_paths` in the config (see below) rather than trying to remove the middleware from specific routes — there's currently no per-route opt-out, since it's pushed onto the whole group unconditionally.
- To fully silence logging without touching route files, set `sample_rate` to `0` — the middleware still runs on every request, but `terminate()` returns immediately without writing a row.
- Routes outside the `api` group (e.g. plain `web` routes) are **not** tracked unless you separately push the middleware onto that group yourself.

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
| `excluded_paths` | — | `['api/metrics/*', 'api/metrics', 'up', 'health']` | `Str::is()` patterns never logged — the main way to exclude specific routes since the middleware is applied group-wide |
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

> ⚠️ **Check `MTL_ALERT_PEAK_MEMORY_MB` before enabling alerts.** The current default is `1` (MB) — lower than what most PHP requests use just to bootstrap Laravel, so almost every request will trip this threshold. A more realistic starting point is `64`–`256`, tuned to your app's normal baseline.

> ⚠️ `route_prefix` is defined here but **not currently applied** by the package's route registration — see [Known Limitations](#known-limitations--troubleshooting).

---

## The Metrics Endpoint

```
GET /metrics/{projectName}
```

Returns cached, aggregated stats for the given project:

```json
 "projectName": "my-project",
    "endpoints": [
        {
            "id": 1,
            "project_name": "my-project",
            "method": "GET",
            "path": "api/products",
            "status_code": 200,
            "response_ms": 93,
            "memory_mb": "0.91",
            "peak_memory_mb": "10.00",
            "ip": "127.0.0.1",
            "user_agent": "PostmanRuntime/7.49.1",
            "created_at": "2026-08-30T20:18:13.000000Z"
        }
    ],
    "count": 1,
    "avgResponseMs": 93,
    "errorRatePercent": 0,
    "timestamp": "2026-08-30T20:18:35+00:00"
```

> ⚠️ This route is currently mounted at the **application root** (`/metrics/{projectName}`), not under `/api/` — see [Known Limitations](#known-limitations--troubleshooting).

This is the endpoint the companion dashboard app polls — see below.

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

The email body is a short summary — total count, project(s) involved, and a per-threshold breakdown. Full row-level detail (project, method, path, status, response time, memory, IP, user agent, timestamp), capped at `alerts.max_rows_per_email`, is attached as a CSV file rather than rendered inline. Sent via `Mtl\MonitorlyAgent\Mail\VulnerableRequestsDetected`, a queued `Mailable`.

---

## Scheduling

The service provider registers:

```php
$schedule->command('mtl-monitoring-agent:prune')->daily();

// only if alerts.enabled is true
$schedule->command('mtl-monitoring-agent:check-vulnerable')
    ->cron("*/{minutes} * * * *")
    ->withoutOverlapping();
```

> 🐛 **Known bug — pruning currently never runs.** The line above schedules `mtl-monitoring-agent:prune`, but the actual prune command's signature is `mtl-monitorly-agent:prune` (**"monitor-ly"**, not **"monitor-ing"**). Since no command named `mtl-monitoring-agent:prune` exists, Laravel's scheduler will fail to run it on every attempt, and `mtl_request_logs` will never be pruned automatically until this is fixed. The `check-vulnerable` line is fine — its name does match the real command signature.
>
> **Fix:** change the scheduled name in the service provider to match the real command:
> ```php
> $schedule->command('mtl-monitorly-agent:prune')->daily();
> ```
> (or rename the command itself to `mtl-monitoring-agent:prune` if you'd rather standardize on "monitoring" — either works, they just need to match.)

For the scheduler to run at all, your server's cron must be calling it every minute — this is not automatic:

```
* * * * * cd /path-to-your-app && php artisan schedule:run >> /dev/null 2>&1
```

---

## Artisan Commands Reference

| Command | Description |
|---|---|
| `mtl-monitorly-agent:prune` | Deletes logs older than `retention_days`. **Not currently scheduled correctly — see [Scheduling](#scheduling).** Can still be run manually. |
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

- **Pruning is not actually scheduled correctly** — see the bug callout in [Scheduling](#scheduling). Run `php artisan mtl-monitorly-agent:prune` manually (or via your own cron entry) until the name mismatch is fixed.
- **The middleware applies to the entire `api` group with no per-route opt-out** — use `excluded_paths` for exclusions rather than trying to remove it from specific routes.
- **`route_prefix` config is currently unused.** The metrics route is registered directly, without applying `route_prefix` or wrapping it in the `api` middleware group.
- **`MTL_ALERT_PEAK_MEMORY_MB` defaults to `1`**, which is unrealistically low and will likely fire on nearly every request — raise this before enabling alerts in production.
- **No per-project alert thresholds.** Thresholds are global across all projects sharing this installation.
- **Peak memory reflects the whole PHP process**, not strictly the request handler — under heavy concurrency on some SAPIs this can be noisier than a per-request-isolated measurement.
- **`No hint path defined for [mtl-monitorly-agent]` after upgrading** — almost always a stale queue worker; see [Queueing](#queueing) above.
- **Cached metrics can look "stale" during a quiet period** — this is by design (write-invalidated, not time-invalidated); see [Caching Behavior](#caching-behavior).
- **Companion dashboard link currently 404s** — verify the [mtl-monitorly-app](https://github.com/moinul70/mtl-monitorly-app) repository URL and its own README before relying on the description in [The Companion Dashboard](#the-companion-dashboard).

---

## The Companion Dashboard

**[mtl-monitorly-app](https://github.com/moinul70/mtl-monitorly-app)** is a separate Node.js/Express application that gives you a graphical interface for this package's data: a project list, an "add project" flow, and a live per-project dashboard (CPU/memory/uptime cards, an API endpoint table, and status indicators) that polls this package's `/metrics/{projectName}` endpoint on an interval.

- **It's a separate install** — a different Node.js codebase/repository from this Laravel package, typically deployed alongside it (or pointed at it over the network).
- **Project configuration happens in its own UI** — you add a project there (giving it a name/link), and it uses that identifier when calling this package's metrics endpoint for that project.
- **It's read-only against this package** — it only consumes `/metrics/{projectName}`; it doesn't write to `mtl_request_logs` or configure this package's `.env`/thresholds. All alerting and logging configuration still lives in this Laravel package's config, per [Configuration](#configuration).

---

## Conclusion

This package gives you request-level observability — timing, status, memory — with essentially zero setup: install it, migrate, and every `api`-group route is tracked automatically. Pair it with the companion Node.js dashboard for a graphical view, or query `/metrics/{projectName}` directly from your own tooling. The alerting layer is entirely opt-in and additive. Before relying on this in production, resolve the two flagged issues above — the prune-command name mismatch and the overly sensitive memory threshold default — since both silently produce wrong behavior rather than throwing a visible error.