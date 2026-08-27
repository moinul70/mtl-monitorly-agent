<?php

namespace Mtl\RequestTracker\Console\Commands;

use Illuminate\Console\Command;
use Mtl\RequestTracker\Models\RequestLog;

class PruneRequestLogs extends Command
{
    protected $signature = 'mtl-monitoring-agent:prune';

    protected $description = 'Delete request logs older than the configured retention period.';

    public function handle(): void
    {
        $days = config('mtl-request-tracker.retention_days', 7);

        $deleted = RequestLog::where('created_at', '<', now()->subDays($days))->delete();

        $this->info("Pruned {$deleted} request log(s) older than {$days} day(s).");
    }
}
