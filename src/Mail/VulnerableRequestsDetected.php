<?php

namespace Mtl\MonitorlyAgent\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class VulnerableRequestsDetected extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Collection $rows,
        public array $thresholds,
    ) {
        $this->onConnection(config('mtl-monitorly-agent.queue_connection'));
        $this->onQueue(config('mtl-monitorly-agent.queue_name', 'default'));
    }

    public function build(): self
    {
        $count = $this->rows->count();
        $projectNames = $this->rows->pluck('project_name')->unique()->join(', ');

        // Per-cause counts for the summary — a row can trip more than one
        // threshold at once, so these can add up to more than $count.
        $breakdown = [
            'status' => $this->rows->where('status_code', '>=', $this->thresholds['status_code'])->count(),
            'slow' => $this->rows->where('response_ms', '>', $this->thresholds['response_ms'])->count(),
            'memory' => $this->rows->filter(fn ($row) => $row->peak_memory_mb !== null
                && $row->peak_memory_mb > $this->thresholds['peak_memory_mb']
            )->count(),
        ];

        return $this
            ->subject("⚠️ {$count} vulnerable request(s) detected — {$projectNames}")
            ->view('mtl-monitorly-agent::mail.vulnerable-requests')
            ->with([
                'thresholds' => $this->thresholds,
                'count' => $count,
                'projectNames' => $projectNames,
                'breakdown' => $breakdown,
            ])
            ->attach($this->csvAttachment());
    }

    protected function csvAttachment(): Attachment
    {
        $csv = $this->buildCsv();
        $filename = 'vulnerable-requests-' . now()->format('Y-m-d_His') . '.csv';

        return Attachment::fromData(fn () => $csv, $filename)
            ->withMime('text/csv');
    }

    protected function buildCsv(): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, [
            'Project', 'Method', 'Path', 'Status Code', 'Response (ms)',
            'Memory (MB)', 'Peak Memory (MB)', 'IP', 'User Agent', 'Created At',
        ]);

        foreach ($this->rows as $row) {
            fputcsv($handle, [
                $row->project_name,
                $row->method,
                $row->path,
                $row->status_code,
                $row->response_ms,
                $row->memory_mb,
                $row->peak_memory_mb,
                $row->ip,
                $row->user_agent,
                $row->created_at,
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
