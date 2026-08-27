<?php

use Illuminate\Support\Facades\Route;
use Mtl\RequestTracker\Http\Controllers\MetricsController;

Route::get(config('mtl-request-tracker.route_prefix') . '/metrics/{projectName}', [MetricsController::class, 'show'])->name('request-tracker.metrics.show');
