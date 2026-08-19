<?php

use App\Modules\Internship\Controllers\InternshipController;
use App\Modules\Internship\Controllers\SupervisorController;
use Illuminate\Support\Facades\Route;

Route::get('internships/health', fn () => ['data' => ['status' => 'ok']]);
Route::middleware(['auth:api', 'account.active'])->prefix('internships')->group(function (): void {
    Route::get('dashboard', [InternshipController::class, 'dashboard']);
    Route::get('templates', [InternshipController::class, 'templates']);
    Route::get('/', [InternshipController::class, 'index']);
    Route::get('monitoring', [InternshipController::class, 'monitoring']);
    Route::get('supervisor/dashboard', [SupervisorController::class, 'dashboard']);
    Route::post('{internship:public_id}/supervisor-observations', [SupervisorController::class, 'observe']);
    Route::patch('supervisor/availability', [SupervisorController::class, 'availability']);
    Route::post('templates', [InternshipController::class, 'storeTemplate']);
    Route::post('/', [InternshipController::class, 'store']);
    Route::post('{internship:public_id}/rotations', [InternshipController::class, 'storeRotation']);
    Route::put('{internship:public_id}', [InternshipController::class, 'update']);
    Route::post('{internship:public_id}/notifications', [InternshipController::class, 'notify']);
    Route::patch('{internship:public_id}/status', [InternshipController::class, 'status']);
    Route::post('rotations/{rotation}/extensions', [InternshipController::class, 'extend']);
    Route::patch('rotations/{rotation}/status', [InternshipController::class, 'rotationStatus']);
});
