<?php

use App\Modules\Media\Controllers\DocumentController;
use App\Modules\Media\Controllers\CampaignMediaController;
use Illuminate\Support\Facades\Route;

Route::get('documents/health', fn () => ['data' => ['status' => 'ok']]);
Route::middleware(['auth:api', 'account.active'])->prefix('documents')->group(function (): void {
    Route::post('/', [DocumentController::class, 'store']);
    Route::get('{document:public_id}/download', [DocumentController::class, 'download']);
    Route::delete('{document:public_id}', [DocumentController::class, 'destroy']);
});
Route::middleware(['auth:api', 'account.active'])->prefix('campaign-media')->group(function (): void {
    Route::post('campaigns/{campaign}/documents', [CampaignMediaController::class, 'storeDocument']);
    Route::post('campaigns/{campaign}/common-letter', [CampaignMediaController::class, 'storeCommonLetter']);
    Route::post('campaigns/{campaign}/hospitals/{campaignHospital}/letter', [CampaignMediaController::class, 'storeHospitalLetter']);
    Route::get('{media:public_id}/download', [CampaignMediaController::class, 'download']);
    Route::delete('{media:public_id}', [CampaignMediaController::class, 'destroy']);
});
