<?php

namespace Mtl\RequestTracker;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Mtl\RequestTracker\Console\Commands\PruneRequestLogs;

class RequestTrackerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/request-tracker.php', 'request-tracker');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

            $this->commands([
                PruneRequestLogs::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/request-tracker.php' => config_path('request-tracker.php'),
            ], 'request-tracker-config');

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'request-tracker-migrations');
        }

        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

        $this->app['router']->aliasMiddleware(
            'track-requests',
            \Mtl\RequestTracker\Http\Middleware\TrackRequestMiddleware::class
        );

        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            $schedule->command('request-tracker:prune')->daily();
        });
    }
}
