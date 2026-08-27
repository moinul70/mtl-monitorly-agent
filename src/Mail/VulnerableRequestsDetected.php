<?php

namespace Mtl\RequestTracker\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class VulnerableRequestsDetected extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Collection $rows,
        public array $thresholds,
    ) {
        $this->onConnection(config('mtl-request-tracker.queue_connection'));
        $this->onQueue(config('mtl-request-tracker.queue_name', 'default'));
    }

    public function build(): self
    {
        $count = $this->rows->count();
        $projectNames = $this->rows->pluck('project_name')->unique()->join(', ');

        return $this
            ->subject("⚠️ {$count} vulnerable request(s) detected — {$projectNames}")
            ->view('mtl-request-tracker::mail.vulnerable-requests')
            ->with([
                'rows' => $this->rows,
                'thresholds' => $this->thresholds,
                'count' => $count,
            ]);
    }
}
