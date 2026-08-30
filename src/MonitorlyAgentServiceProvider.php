<?php

namespace Mtl\MonitorlyAgent;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Mtl\MonitorlyAgent\Console\Commands\PruneRequestLogs;
use Mtl\MonitorlyAgent\Console\Commands\CheckVulnerableRequests;

class MonitorlyAgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/mtl-monitorly-agent.php', 'mtl-monitorly-agent');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

             $this->commands([
                PruneRequestLogs::class,
                CheckVulnerableRequests::class,
            ]);

            $this->publishes(
                [
                    __DIR__ . '/../config/mtl-monitorly-agent.php' => config_path('mtl-monitorly-agent.php'),
                ],
                'mtl-monitorly-agent-config',
            );

            $this->publishes(
                [
                    __DIR__ . '/../database/migrations' => database_path('migrations'),
                ],
                'mtl-monitorly-agent-migrations',
            );
        }

        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'mtl-monitorly-agent');

        $this->app->make(Router::class)->pushMiddlewareToGroup('api', \Mtl\MonitorlyAgent\Http\Middleware\MonitoringAgentMiddleware::class);

        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            $schedule->command('mtl-monitoring-agent:prune')->daily();
            // Only schedule the vulnerability check if alerts are actually
            // enabled — no wasted scheduler tick otherwise.
            if (config('mtl-monitorly-agent.alerts.enabled', false)) {
                $minutes = max(1, (int) config('mtl-monitorly-agent.alerts.check_frequency_minutes', 5));
                $schedule->command('mtl-monitoring-agent:check-vulnerable')
                    ->cron("*/{$minutes} * * * *")
                    ->withoutOverlapping();
            }
        });
    }
}
