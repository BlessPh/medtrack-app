<?php

use App\Modules\Reporting\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'account.active'])->prefix('reporting')->group(function (): void {
    Route::get('dashboard', [DashboardController::class, 'index']);
    Route::get('search', [DashboardController::class, 'search']);
    Route::get('export', [DashboardController::class, 'export'])->middleware('throttle:10,1');
});
