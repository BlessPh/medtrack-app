<?php

namespace App\Modules\Notification\Controllers;

use App\Modules\Auth\Models\User;
use App\Modules\Institution\Models\HospitalSupervisorProfile;
use App\Modules\Institution\Models\Institution;
use App\Modules\Institution\Services\InstitutionAudit;
use App\Modules\Notification\Notifications\InstitutionNotification;
use App\Shared\Enums\InstitutionRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

class InstitutionNotificationController
{
    public function store(Request $request, Institution $institution, InstitutionAudit $audit): JsonResponse
    {
        $request->user()->can('manageMembers', $institution) || abort(403);
        $roles = $institution->type === 'HOSPITAL'
            ? [InstitutionRole::Admin->value, InstitutionRole::HospitalManager->value, InstitutionRole::Supervisor->value, InstitutionRole::FinanceOfficer->value, InstitutionRole::Member->value]
            : [InstitutionRole::Admin->value, InstitutionRole::AcademicManager->value, InstitutionRole::FinanceOfficer->value, InstitutionRole::Member->value];
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:1000'],
            'severity' => ['nullable', Rule::in(['INFO', 'SUCCESS', 'WARNING', 'CRITICAL'])],
            'url' => ['nullable', 'string', 'max:500'],
            'role' => ['nullable', Rule::in($roles)],
            'service_id' => ['nullable', 'integer'],
        ]);
        $recipients = $institution->members()->where('users.status', 'ACTIVE');
        if ($role = ($data['role'] ?? null)) {
            $roleUserIds = DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('model_has_roles.institution_id', $institution->id)
                ->where('model_has_roles.model_type', User::class)
                ->where('roles.name', $role)
                ->pluck('model_has_roles.model_id');
            $recipients->whereIn('users.id', $roleUserIds);
        }
        if ($serviceId = ($data['service_id'] ?? null)) {
            abort_unless($institution->type === 'HOSPITAL', 422, 'Le ciblage par service est réservé aux hôpitaux.');
            abort_unless($institution->units()->whereKey($serviceId)->where('type', 'SERVICE')->whereNull('parent_id')->exists(), 422, 'Service hospitalier invalide.');
            $serviceUserIds = HospitalSupervisorProfile::query()
                ->where('institution_id', $institution->id)
                ->whereHas('services', fn ($query) => $query->whereKey($serviceId))
                ->pluck('user_id');
            $recipients->whereIn('users.id', $serviceUserIds);
        }
        $recipients = $recipients->get();
        Notification::send($recipients, new InstitutionNotification($institution->public_id, $institution->name, $data['title'], $data['message'], 'ANNOUNCEMENT', $data['severity'] ?? 'INFO', $data['url'] ?? null));
        $audit->record($request, $institution, 'NOTIFICATION_SENT', 'notification', null, null, [
            'title' => $data['title'], 'role' => $data['role'] ?? null,
            'service_id' => $data['service_id'] ?? null, 'recipients_count' => $recipients->count(),
        ]);

        return response()->json(['message' => 'Notification envoyée.', 'data' => ['recipients_count' => $recipients->count()]], 201);
    }
}
