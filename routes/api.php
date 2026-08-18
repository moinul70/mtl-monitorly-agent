<?php

use Illuminate\Support\Facades\Route;
use Mtl\RequestTracker\Http\Controllers\MetricsController;

Route::get('/metrics', [MetricsController::class, 'show'])
    ->name('request-tracker.metrics.show');
