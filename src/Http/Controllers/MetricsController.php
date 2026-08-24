<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use App\Models\RequestLog;

class MetricsController extends Controller
{
    public function show(string $projectName): JsonResponse
    {
        // Fix #3: cache the computed result for a few seconds. If your

        $data = Cache::rememberForever("request-tracker:metrics:$projectName", function () use ($projectName) {
            $windowMinutes = config('request-tracker.window_minutes', 5);

            $logs = RequestLog::where('project_name', $projectName)
                ->where('created_at', '>=', now()->subMinutes($windowMinutes))
                ->orderByDesc('created_at')
                ->get();

            return [
                'projectName' => $projectName,
                'endpoints' => $logs,
                'count' => $logs->count(),
                'avgResponseMs' => $logs->isNotEmpty() ? round($logs->avg('response_ms'), 1) : 0,
                'errorRatePercent' => $logs->isNotEmpty() ? round(($logs->where('status_code', '>=', 400)->count() / $logs->count()) * 100, 2) : 0,
                'timestamp' => now()->toIso8601String(),
            ];
        });
        return response()->json($data);
    }
}
