<?php

namespace Mtl\RequestTracker\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Mtl\RequestTracker\Models\RequestLog;

class MetricsController extends Controller
{
    public function show(): JsonResponse
    {
        // Fix #3: cache the computed result for a few seconds. If your
        // Node dashboard (or multiple browser tabs) polls every 5 seconds,
        // this means the underlying query only actually runs once per
        // cache window, no matter how many clients are polling.
        $ttl = config('request-tracker.cache_ttl', 5);

        $data = Cache::remember('request-tracker:metrics', $ttl, function () {
            $windowMinutes = config('request-tracker.window_minutes', 5);

            $logs = RequestLog::where('created_at', '>=', now()->subMinutes($windowMinutes))
                ->orderByDesc('created_at')
                ->limit(200)
                ->get();

            return [
                'logs' => $logs,
                'count' => $logs->count(),
                'avgResponseMs' => $logs->isNotEmpty() ? round($logs->avg('response_ms'), 1) : 0,
                'errorRatePercent' => $logs->isNotEmpty()
                    ? round(($logs->where('status_code', '>=', 400)->count() / $logs->count()) * 100, 2)
                    : 0,
                'timestamp' => now()->toIso8601String(),
            ];
        });

        return response()->json($data);
    }
}
