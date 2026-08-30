<?php

use Illuminate\Support\Facades\Route;
use Mtl\MonitorlyAgent\Http\Controllers\MtlMetricsController;

Route::get(config('mtl-monitorly-agent.route_prefix') . '/metrics/{projectName}', [MtlMetricsController::class, 'show'])->name('monitorly-agent.metrics.show');
