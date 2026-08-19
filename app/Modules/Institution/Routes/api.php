<?php

use App\Modules\Institution\Controllers\InstitutionController;
use App\Modules\Institution\Controllers\InstitutionDetailsController;
use App\Modules\Institution\Controllers\InstitutionMemberController;
use App\Modules\Media\Controllers\InstitutionLogoController;
use App\Modules\Institution\Controllers\InstitutionInvitationController;
use App\Modules\Notification\Controllers\InstitutionNotificationController;
use App\Modules\Institution\Controllers\HospitalSupervisorController;
use App\Modules\Institution\Controllers\InstitutionGovernanceController;
use Illuminate\Support\Facades\Route;

Route::get('institutions/health', fn () => ['data' => ['status' => 'ok']]);

Route::middleware(['auth:api', 'account.active'])->prefix('institutions')->name('api.v1.institutions.')->group(function (): void {
    Route::get('/', [InstitutionController::class, 'index']);
    Route::post('/', [InstitutionController::class, 'store']);
    Route::get('{institution:public_id}', [InstitutionController::class, 'show']);
    Route::get('{institution:public_id}/dashboard', [InstitutionController::class, 'dashboard']);
    Route::put('{institution:public_id}', [InstitutionController::class, 'update']);
    Route::patch('{institution:public_id}/status', [InstitutionController::class, 'status']);
    Route::post('{institution:public_id}/units', [InstitutionDetailsController::class, 'storeUnit']);
    Route::put('{institution:public_id}/units/{id}', [InstitutionDetailsController::class, 'updateUnit'])->whereNumber('id');
    Route::post('{institution:public_id}/addresses', [InstitutionDetailsController::class, 'storeAddress']);
    Route::put('{institution:public_id}/addresses/{id}', [InstitutionDetailsController::class, 'updateAddress'])->whereNumber('id');
    Route::post('{institution:public_id}/contacts', [InstitutionDetailsController::class, 'storeContact']);
    Route::put('{institution:public_id}/contacts/{id}', [InstitutionDetailsController::class, 'updateContact'])->whereNumber('id');
    Route::delete('{institution:public_id}/{resource}/{id}', [InstitutionDetailsController::class, 'destroy'])
        ->whereIn('resource', ['units', 'addresses', 'contacts'])->whereNumber('id');
    Route::post('{institution:public_id}/members', [InstitutionMemberController::class, 'store']);
    Route::get('{institution:public_id}/members', [InstitutionMemberController::class, 'index']);
    Route::put('{institution:public_id}/members/{user:public_id}', [InstitutionMemberController::class, 'update'])->withoutScopedBindings();
    Route::patch('{institution:public_id}/members/{user:public_id}/status', [InstitutionMemberController::class, 'status'])->withoutScopedBindings();
    Route::delete('{institution:public_id}/members/{user:public_id}', [InstitutionMemberController::class, 'destroy'])->withoutScopedBindings();
    Route::get('{institution:public_id}/supervisors', [HospitalSupervisorController::class, 'index']);
    Route::put('{institution:public_id}/supervisors/{user:public_id}', [HospitalSupervisorController::class, 'update'])->withoutScopedBindings();
    Route::post('{institution:public_id}/notifications', [InstitutionNotificationController::class, 'store']);
    Route::get('{institution:public_id}/audit-logs', [InstitutionGovernanceController::class, 'auditLogs']);
    Route::get('{institution:public_id}/oversight', [InstitutionGovernanceController::class, 'oversight']);
    Route::get('{institution:public_id}/logo', [InstitutionLogoController::class, 'show'])->name('logo.show');
    Route::post('{institution:public_id}/logo', [InstitutionLogoController::class, 'store']);
    Route::delete('{institution:public_id}/logo', [InstitutionLogoController::class, 'destroy']);
    Route::get('{institution:public_id}/member-invitations', [InstitutionInvitationController::class, 'index']);
    Route::post('{institution:public_id}/member-invitations', [InstitutionInvitationController::class, 'store']);
    Route::delete('{institution:public_id}/member-invitations/{invitation:public_id}', [InstitutionInvitationController::class, 'destroy'])->withoutScopedBindings();
});
