<?php

namespace Mtl\RequestTracker\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Mtl\RequestTracker\Models\RequestLog;

class RecordRequestLog implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $method,
        public string $path,
        public int $statusCode,
        public int $responseMs,
        public ?string $ip,
        public ?string $userAgent,
        public string $createdAt,
    ) {
        // Respect config for which queue connection/name to run on, so this
        // doesn't silently land on a connection/queue the app isn't
        // actually processing.
        $this->onConnection(config('request-tracker.queue_connection'));
        $this->onQueue(config('request-tracker.queue_name', 'default'));
    }

    public function handle(): void
    {
        RequestLog::create([
            'method' => $this->method,
            'path' => $this->path,
            'status_code' => $this->statusCode,
            'response_ms' => $this->responseMs,
            'ip' => $this->ip,
            'user_agent' => $this->userAgent,
            'created_at' => $this->createdAt,
        ]);
    }
}
