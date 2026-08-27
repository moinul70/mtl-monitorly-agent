<?php

namespace Mtl\RequestTracker\Http\Middleware;

use Mtl\RequestTracker\Models\RequestLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class MonitoringAgentMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Record the start time as a request attribute so terminate() can
        // read it back later — avoids relying on framework-bootstrap
        // constants that may not exist in every setup.
        $request->attributes->set('_track_start', microtime(true));
        $request->attributes->set(
        '_track_memory_start',
        memory_get_usage(false)
    );

        return $next($request);
    }

    /**
     * Runs AFTER the response has been sent to the browser, so this does
     * not add perceived latency for the person who made the request. It
     * does still consume a bit of PHP-FPM/Passenger worker time before
     * that worker is freed for the next request — see fix #1/#2 below,
     * which is why sampling and exclusions exist.
     */
    public function terminate(Request $request, Response $response): void
    {
        // Fix #1: skip configured paths entirely (health checks, metrics route, etc.) so they never take up
        // a row or any write cost.
        if ($this->isExcluded($request->path())) {
            return;
        }

        // Fix #2: sampling. At sample_rate = 1.0 (default) every request
        // is recorded, same as before. Lower it under heavy traffic to
        // cut write volume without touching any code.
        if (!$this->shouldSample()) {
            return;
        }

        $start = $request->attributes->get('_track_start', microtime(true));
        $durationMs = (int) round((microtime(true) - $start) * 1000);

        // Memory
        $memoryStart = $request->attributes->get('_track_memory_start', memory_get_usage(true));

        $memoryEnd = memory_get_usage(false);

        $memoryMb = round(max(0, $memoryEnd - $memoryStart) / 1024 / 1024, 2);

        $peakMemoryMb = round(memory_get_peak_usage(true) / 1024 / 1024, 2);

        $payload = [
            'project_name' => config('mtl-request-tracker.project_name'),
            'method' => $request->method(),
            'path' => $request->path(),
            'status_code' => $response->getStatusCode(),
            'response_ms' => $durationMs,
            'memory_mb' => $memoryMb,
            'peak_memory_mb' => $peakMemoryMb,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'created_at' => now(),
        ];

        // Optional queueing: off by default (see #6 below). When enabled,
        // the DB write happens on a queue worker instead of inline here,
        // removing the write cost from this request/worker entirely.
        if (config('mtl-request-tracker.use_queue', false)) {
            \Mtl\RequestTracker\Jobs\RecordRequestLog::dispatch(projectName: $payload['project_name'], method: $payload['method'], path: $payload['path'], statusCode: $payload['status_code'], responseMs: $payload['response_ms'], memoryMB: $payload['memory_mb'],peakMemoryMb: $payload['peak_memory_mb'], ip: $payload['ip'], userAgent: $payload['user_agent'], createdAt: $payload['created_at']->toDateTimeString());

            return;
        }

        RequestLog::create($payload);
        Cache::forget("mtl-request-tracker:metrics:{$payload['project_name']}");
    }

    protected function shouldSample(): bool
    {
        $rate = (float) config('mtl-request-tracker.sample_rate', 1.0);

        if ($rate >= 1.0) {
            return true;
        }

        if ($rate <= 0.0) {
            return false;
        }

        return mt_rand() / mt_getrandmax() <= $rate;
    }

    protected function isExcluded(string $path): bool
    {
        foreach (config('mtl-request-tracker.excluded_paths', []) as $pattern) {
            if (Str::is($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
