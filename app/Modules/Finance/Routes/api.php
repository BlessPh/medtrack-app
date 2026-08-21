<?php

use App\Modules\Finance\Controllers\FinanceController;
use App\Modules\Finance\Controllers\FinanceManagementController;
use Illuminate\Support\Facades\Route;

Route::get('finance/health', fn () => ['data' => ['status' => 'ok']]);
Route::post('finance/callbacks/maishapay', [FinanceController::class, 'callback'])->middleware('throttle:60,1');
Route::middleware(['auth:api', 'account.active'])->prefix('finance')->group(function (): void {
    Route::get('dashboard', [FinanceManagementController::class, 'dashboard']);
    Route::get('context', [FinanceManagementController::class, 'contextData']);
    Route::get('obligations', [FinanceManagementController::class, 'obligations']);
    Route::get('transactions', [FinanceManagementController::class, 'transactions']);
    Route::get('refunds', [FinanceManagementController::class, 'refunds']);
    Route::get('reports/export', [FinanceManagementController::class, 'export']);
    Route::post('manual-payments', [FinanceManagementController::class, 'manualPayment']);
    Route::post('transactions/{transaction:public_id}/allocations', [FinanceManagementController::class, 'allocate']);
    Route::patch('transactions/{transaction:public_id}/verify', [FinanceManagementController::class, 'verify']);
    Route::post('obligations', [FinanceController::class, 'obligation']);
    Route::post('payments', [FinanceController::class, 'pay']);
    Route::post('transactions/{transaction:public_id}/refunds', [FinanceController::class, 'refund']);
    Route::get('transactions/{transaction:public_id}/receipt', [FinanceController::class, 'receipt']);
});
