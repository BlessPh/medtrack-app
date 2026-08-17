<?php

use App\Modules\Auth\Controllers\AuthController;
use App\Modules\Auth\Controllers\AvatarController;
use App\Modules\Auth\Controllers\EmailVerificationController;
use App\Modules\Auth\Controllers\PasswordController;
use App\Modules\Auth\Controllers\ProfileController;
use App\Modules\Auth\Controllers\StudentRegistrationController;
use App\Modules\Auth\Controllers\UserAdminController;
use App\Modules\Auth\Controllers\InstitutionAccountRequestController;
use App\Modules\Institution\Controllers\InstitutionInvitationRegistrationController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('api.v1.auth.')->group(function (): void {
    Route::get('health', fn () => ['data' => ['status' => 'ok']]);
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('password/forgot', [PasswordController::class, 'forgot'])->middleware('throttle:password');
    Route::post('password/reset', [PasswordController::class, 'reset'])->middleware('throttle:password');
    Route::post('student-registration/check', [StudentRegistrationController::class, 'check'])->middleware('throttle:student-registration');
    Route::post('student-registration', [StudentRegistrationController::class, 'register'])->middleware('throttle:student-registration');
    Route::post('institution-registration', [InstitutionAccountRequestController::class, 'store'])->middleware('throttle:5,1');
    Route::get('member-invitations/{token}', [InstitutionInvitationRegistrationController::class, 'show'])->middleware('throttle:10,1');
    Route::post('member-invitations/{token}/register', [InstitutionInvitationRegistrationController::class, 'store'])->middleware('throttle:5,1');
    Route::middleware(['auth:api', 'account.active'])->group(function (): void {
        Route::get('me', [AuthController::class, 'me']);
        Route::put('profile', [ProfileController::class, 'update']);
        Route::get('profile/avatar', [AvatarController::class, 'show']);
        Route::post('profile/avatar', [AvatarController::class, 'store']);
        Route::delete('profile/avatar', [AvatarController::class, 'destroy']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('refresh', [AuthController::class, 'refresh']);
        Route::post('email/verification-notification', [EmailVerificationController::class, 'send'])->middleware('throttle:verification');
        Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])->middleware('signed')->name('verification.verify');
        Route::get('users', [UserAdminController::class, 'index']);
        Route::get('users/{user:public_id}', [UserAdminController::class, 'show']);
        Route::get('users/{user:public_id}/avatar', [UserAdminController::class, 'avatar']);
        Route::patch('users/{user:public_id}/status', [UserAdminController::class, 'status']);
        Route::get('institution-requests', [InstitutionAccountRequestController::class, 'index']);
        Route::get('institution-requests/{institutionAccountRequest:public_id}', [InstitutionAccountRequestController::class, 'show']);
        Route::patch('institution-requests/{institutionAccountRequest:public_id}/approve', [InstitutionAccountRequestController::class, 'approve']);
        Route::patch('institution-requests/{institutionAccountRequest:public_id}/reject', [InstitutionAccountRequestController::class, 'reject']);
    });
});

