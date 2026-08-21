<?php

use App\Modules\Admission\Controllers\AdmissionController;
use Illuminate\Support\Facades\Route;

Route::get('admissions/health', fn () => ['data' => ['status' => 'ok']]);
Route::middleware(['auth:api', 'account.active'])->prefix('admissions')->group(function (): void {
    Route::post('applications', [AdmissionController::class, 'store']);
    Route::get('hospital-applications', [AdmissionController::class, 'hospitalApplications']);
    Route::get('hospital-admissions', [AdmissionController::class, 'hospitalAdmissions']);
    Route::patch('applications/{application:public_id}/hospital-decision', [AdmissionController::class, 'hospitalDecision']);
    Route::patch('applications/{application:public_id}/withdraw', [AdmissionController::class, 'withdraw']);
    Route::patch('applications/{application:public_id}/reject', [AdmissionController::class, 'reject']);
    Route::post('applications/{application:public_id}/accept', [AdmissionController::class, 'accept']);
    Route::post('capacities', [AdmissionController::class, 'storeCapacity']);
});
