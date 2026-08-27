<?php

namespace Mtl\RequestTracker\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Mtl\RequestTracker\Mail\VulnerableRequestsDetected;
use Mtl\RequestTracker\Models\RequestLog;

class CheckVulnerableRequests extends Command
{
    protected $signature = 'mtl-monitoring-agent:check-vulnerable';

    protected $description = 'Scan new request logs for vulnerability thresholds and email an alert if any are found.';

    // How many new rows to pull per run — keeps one run bounded even
    // during a traffic spike; the watermark just advances more slowly
    // and the next scheduled run picks up where this one left off.
    protected const SCAN_BATCH_SIZE = 5000;

    protected const WATERMARK_KEY = 'mtl-request-tracker:alerts:last_id';

    public function handle(): void
    {
        if (! config('mtl-request-tracker.alerts.enabled', false)) {
            $this->info('Vulnerability alerts are disabled (mtl-request-tracker.alerts.enabled).');
            return;
        }

        $recipients = config('mtl-request-tracker.alerts.recipients', []);

        if (empty($recipients)) {
            $this->warn('Vulnerability alerts are enabled but no recipients are configured — skipping.');
            return;
        }

        $lastId = (int) Cache::get(self::WATERMARK_KEY, 0);

        $newRows = RequestLog::where('id', '>', $lastId)
            ->orderBy('id')
            ->limit(self::SCAN_BATCH_SIZE)
            ->get();

        if ($newRows->isEmpty()) {
            $this->info('No new request logs since last check.');
            return;
        }

        // Advance the watermark past everything we scanned, regardless of
        // whether it was vulnerable — otherwise ordinary rows would be
        // re-scanned (and vulnerable ones re-alerted) on every single run.
        Cache::forever(self::WATERMARK_KEY, (int) $newRows->max('id'));

        $thresholds = config('mtl-request-tracker.alerts.thresholds');
        $maxRows = config('mtl-request-tracker.alerts.max_rows_per_email', 50);

        $vulnerable = $newRows->filter(function ($row) use ($thresholds) {
            return $row->status_code >= $thresholds['status_code']
                || $row->response_ms > $thresholds['response_ms']
                || ($row->peak_memory_mb !== null && $row->peak_memory_mb > $thresholds['peak_memory_mb']);
        })->take($maxRows);

        if ($vulnerable->isEmpty()) {
            $this->info("Scanned {$newRows->count()} new request log(s) — none crossed alert thresholds.");
            return;
        }

        Mail::to($recipients)->queue(new VulnerableRequestsDetected($vulnerable, $thresholds));

        $this->info("Found {$vulnerable->count()} vulnerable request(s) — alert email queued to " . count($recipients) . ' recipient(s).');
    }
}
