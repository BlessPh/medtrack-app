<?php

use App\Modules\Scheduling\Controllers\SchedulingController;
use Illuminate\Support\Facades\Route;

Route::get('scheduling/health', fn () => ['data' => ['status' => 'ok']]);
Route::middleware(['auth:api', 'account.active'])->prefix('scheduling')->group(function (): void {
    Route::post('schedules', [SchedulingController::class, 'storeSchedule']);
    Route::post('schedules/{schedule}/entries', [SchedulingController::class, 'storeEntry']);
    Route::patch('schedules/{schedule}/publish', [SchedulingController::class, 'publish']);
    Route::patch('schedules/{schedule}/cancel', [SchedulingController::class, 'cancel']);
    Route::post('biometric-devices', [SchedulingController::class, 'storeDevice']);
    Route::post('attendance', [SchedulingController::class, 'record']);
    Route::post('attendance/{record}/corrections', [SchedulingController::class, 'correction']);
    Route::patch('corrections/{correction}', [SchedulingController::class, 'reviewCorrection']);
    Route::get('students/{student:public_id}/attendance-summary', [SchedulingController::class, 'summary']);
});
