<?php

use App\Modules\Assessment\Controllers\AssessmentController;
use Illuminate\Support\Facades\Route;

Route::get('assessments/health', fn () => ['data' => ['status' => 'ok']]);
Route::middleware(['auth:api', 'account.active'])->prefix('assessments')->group(function (): void {
    Route::get('supervisor', [AssessmentController::class, 'supervisorIndex']);
    Route::post('templates', [AssessmentController::class, 'storeTemplate']);
    Route::post('evaluations', [AssessmentController::class, 'store']);
    Route::patch('evaluations/{evaluation:public_id}/submit', [AssessmentController::class, 'submit']);
    Route::post('evaluations/{evaluation:public_id}/disputes', [AssessmentController::class, 'dispute']);
    Route::patch('disputes/{dispute}', [AssessmentController::class, 'resolve']);
    Route::post('internships/{internship:public_id}/decision', [AssessmentController::class, 'decision']);
});
