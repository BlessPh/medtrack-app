<?php

use App\Modules\Internship\Controllers\InternshipController;
use Illuminate\Support\Facades\Route;

Route::get('internships/health', fn () => ['data' => ['status' => 'ok']]);
Route::middleware(['auth:api', 'account.active'])->prefix('internships')->group(function (): void {
    Route::post('templates', [InternshipController::class, 'storeTemplate']);
    Route::post('/', [InternshipController::class, 'store']);
    Route::post('{internship:public_id}/rotations', [InternshipController::class, 'storeRotation']);
    Route::patch('{internship:public_id}/status', [InternshipController::class, 'status']);
    Route::post('rotations/{rotation}/extensions', [InternshipController::class, 'extend']);
    Route::patch('rotations/{rotation}/status', [InternshipController::class, 'rotationStatus']);
});
