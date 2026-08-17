<?php

use App\Modules\Finance\Controllers\FinanceController;
use Illuminate\Support\Facades\Route;

Route::get('finance/health', fn () => ['data' => ['status' => 'ok']]);
Route::post('finance/callbacks/maishapay', [FinanceController::class, 'callback'])->middleware('throttle:60,1');
Route::middleware(['auth:api', 'account.active'])->prefix('finance')->group(function (): void {
    Route::post('obligations', [FinanceController::class, 'obligation']);
    Route::post('payments', [FinanceController::class, 'pay']);
    Route::post('transactions/{transaction:public_id}/refunds', [FinanceController::class, 'refund']);
    Route::get('transactions/{transaction:public_id}/receipt', [FinanceController::class, 'receipt']);
});
