<?php

namespace Mtl\RequestTracker;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Mtl\RequestTracker\Console\Commands\PruneRequestLogs;
use Mtl\RequestTracker\Console\Commands\CheckVulnerableRequests;

class RequestTrackerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/mtl-request-tracker.php', 'mtl-request-tracker');
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
                    __DIR__ . '/../config/mtl-request-tracker.php' => config_path('mtl-request-tracker.php'),
                ],
                'mtl-request-tracker-config',
            );

            $this->publishes(
                [
                    __DIR__ . '/../database/migrations' => database_path('migrations'),
                ],
                'mtl-request-tracker-migrations',
            );
        }

        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'mtl-request-tracker');

        $this->app->make(Router::class)->pushMiddlewareToGroup('api', \Mtl\RequestTracker\Http\Middleware\MonitoringAgentMiddleware::class);

        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            $schedule->command('mtl-monitoring-agent:prune')->daily();
            // Only schedule the vulnerability check if alerts are actually
            // enabled — no wasted scheduler tick otherwise.
            if (config('mtl-request-tracker.alerts.enabled', false)) {
                $minutes = max(1, (int) config('mtl-request-tracker.alerts.check_frequency_minutes', 5));
                $schedule->command('mtl-monitoring-agent:check-vulnerable')
                    ->cron("*/{$minutes} * * * *")
                    ->withoutOverlapping();
            }
        });
    }
}
