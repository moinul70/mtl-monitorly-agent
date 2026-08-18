<?php

namespace Mtl\RequestTracker\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Mtl\RequestTracker\Models\RequestLog;

class TrackRequestMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Record the start time as a request attribute so terminate() can
        // read it back later — avoids relying on framework-bootstrap
        // constants that may not exist in every setup.
        $request->attributes->set('_track_start', microtime(true));

        return $next($request);
    }

    /**
     * Runs AFTER the response has been sent to the browser, so this does
     * not add perceived latency for the person who made the request. It
     * does still consume a bit of PHP-FPM/Passenger worker time before
     * that worker is freed for the next request — see fix #4/#5 below,
     * which is why sampling and exclusions exist.
     */
    public function terminate(Request $request, Response $response): void
    {
        // Fix #5: skip configured paths entirely (health checks, assets,
        // this package's own metrics route, etc.) so they never take up
        // a row or any write cost.
        if ($this->isExcluded($request->path())) {
            return;
        }

        // Fix #4: sampling. At sample_rate = 1.0 (default) every request
        // is recorded, same as before. Lower it under heavy traffic to
        // cut write volume without touching any code.
        if (! $this->shouldSample()) {
            return;
        }

        $start = $request->attributes->get('_track_start', microtime(true));
        $durationMs = (int) round((microtime(true) - $start) * 1000);

        $payload = [
            'method' => $request->method(),
            'path' => $request->path(),
            'status_code' => $response->getStatusCode(),
            'response_ms' => $durationMs,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'created_at' => now(),
        ];

        // Optional queueing: off by default (see #6 below). When enabled,
        // the DB write happens on a queue worker instead of inline here,
        // removing the write cost from this request/worker entirely.
        if (config('request-tracker.use_queue', false)) {
            \Mtl\RequestTracker\Jobs\RecordRequestLog::dispatch(
                method: $payload['method'],
                path: $payload['path'],
                statusCode: $payload['status_code'],
                responseMs: $payload['response_ms'],
                ip: $payload['ip'],
                userAgent: $payload['user_agent'],
                createdAt: $payload['created_at']->toDateTimeString(),
            );

            return;
        }

        RequestLog::create($payload);
    }

    protected function shouldSample(): bool
    {
        $rate = (float) config('request-tracker.sample_rate', 1.0);

        if ($rate >= 1.0) {
            return true;
        }

        if ($rate <= 0.0) {
            return false;
        }

        return (mt_rand() / mt_getrandmax()) <= $rate;
    }

    protected function isExcluded(string $path): bool
    {
        foreach (config('request-tracker.excluded_paths', []) as $pattern) {
            if (Str::is($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
