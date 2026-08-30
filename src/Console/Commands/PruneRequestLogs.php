<?php

namespace Mtl\MonitorlyAgent\Console\Commands;

use Illuminate\Console\Command;
use Mtl\MonitorlyAgent\Models\MtlRequestLog;

class PruneRequestLogs extends Command
{
    protected $signature = 'mtl-monitorly-agent:prune';

    protected $description = 'Delete request logs older than the configured retention period.';

    public function handle(): void
    {
        $days = config('mtl-monitorly-agent.retention_days', 7);

        $deleted = MtlRequestLog::where('created_at', '<', now()->subDays($days))->delete();

        $this->info("Pruned {$deleted} request log(s) older than {$days} day(s).");
    }
}
