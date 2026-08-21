<?php

use App\Modules\Academic\Controllers\AcademicCatalogController;
use App\Modules\Academic\Controllers\CampaignController;
use App\Modules\Academic\Controllers\CampaignManagementController;
use App\Modules\Academic\Controllers\D4ReservationController;
use App\Modules\Academic\Controllers\StudentController;
use App\Modules\Academic\Controllers\StudentManagementController;
use App\Modules\Academic\Controllers\StudentImportController;
use Illuminate\Support\Facades\Route;

Route::get('academic/health', fn () => ['data' => ['status' => 'ok']]);

Route::middleware(['auth:api', 'account.active'])->prefix('academic')->name('api.v1.academic.')->group(function (): void {
    Route::get('current-context', [AcademicCatalogController::class, 'currentContext']);
    Route::get('dashboard', [AcademicCatalogController::class, 'dashboard']);
    Route::get('levels', [AcademicCatalogController::class, 'levels']);
    Route::get('departments', [AcademicCatalogController::class, 'departments']);
    Route::get('programs', [AcademicCatalogController::class, 'programs']);
    Route::post('programs', [AcademicCatalogController::class, 'storeProgram']);
    Route::put('programs/{program}', [AcademicCatalogController::class, 'updateProgram']);
    Route::delete('programs/{program}', [AcademicCatalogController::class, 'destroyProgram']);
    Route::get('years', [AcademicCatalogController::class, 'years']);
    Route::post('years', [AcademicCatalogController::class, 'storeYear']);
    Route::put('years/{year}', [AcademicCatalogController::class, 'updateYear']);
    Route::patch('years/{year}/current', [AcademicCatalogController::class, 'setCurrentYear']);
    Route::get('promotions', [AcademicCatalogController::class, 'promotions']);
    Route::post('promotions', [AcademicCatalogController::class, 'storePromotion']);
    Route::get('promotions/{promotion}', [AcademicCatalogController::class, 'showPromotion']);
    Route::put('promotions/{promotion}', [AcademicCatalogController::class, 'updatePromotion']);
    Route::get('students', [StudentManagementController::class, 'index']);
    Route::get('my-academic-record', [StudentManagementController::class, 'myAcademicRecord']);
    Route::post('students', [StudentManagementController::class, 'store']);
    Route::get('students/{student}/academic-record', [StudentManagementController::class, 'academicRecord']);
    Route::get('students/{student}', [StudentManagementController::class, 'show']);
    Route::put('students/{student}', [StudentManagementController::class, 'update']);
    Route::patch('students/{student}/status', [StudentManagementController::class, 'status']);
    Route::post('student-imports/preview', [StudentImportController::class, 'preview'])->middleware('throttle:10,1');
    Route::post('student-imports/confirm', [StudentImportController::class, 'confirm'])->middleware('throttle:10,1');
    Route::get('student-imports', [StudentImportController::class, 'index']);
    Route::get('student-imports/template', [StudentImportController::class, 'template']);
    Route::patch('student-imports/{studentImport}/cancel', [StudentImportController::class, 'cancel']);
    Route::get('student-imports/{studentImport}/errors', [StudentImportController::class, 'errors']);
    Route::get('campaigns', [CampaignManagementController::class, 'index']);
    Route::post('campaigns', [CampaignManagementController::class, 'store']);
    Route::get('campaigns/{campaign}', [CampaignManagementController::class, 'show']);
    Route::put('campaigns/{campaign}', [CampaignManagementController::class, 'update']);
    Route::post('campaigns/{campaign}/send-hospital-requests', [CampaignManagementController::class, 'sendHospitalRequests']);
    Route::patch('campaigns/{campaign}/status', [CampaignManagementController::class, 'status']);
    Route::get('campaigns/{campaign}/students/{student:public_id}/eligibility', [CampaignManagementController::class, 'eligibility'])->withoutScopedBindings();
    Route::get('student-campaigns', [D4ReservationController::class, 'studentCampaigns']);
    Route::get('campaign-requests', [D4ReservationController::class, 'requests']);
    Route::get('campaign-reservations', [D4ReservationController::class, 'hospitalReservations']);
    Route::patch('campaign-requests/{campaignHospital}/respond', [D4ReservationController::class, 'respond']);
    Route::post('campaign-reservations/{application:public_id}/admit', [D4ReservationController::class, 'admit']);
    Route::get('campaigns/{campaign}/student-view', [D4ReservationController::class, 'studentView']);
    Route::post('campaigns/{campaign}/reserve', [D4ReservationController::class, 'reserve']);
    Route::patch('campaign-reservations/{application:public_id}/cancel', [D4ReservationController::class, 'cancel']);
});
