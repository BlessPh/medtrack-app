<?php

namespace App\Modules\Institution\Controllers;

use App\Modules\Auth\Models\User;
use App\Modules\Institution\Models\HospitalSupervisorProfile;
use App\Modules\Institution\Models\Institution;
use App\Shared\Enums\InstitutionRole;
use App\Shared\Services\InstitutionAccess;
use App\Modules\Institution\Services\InstitutionAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HospitalSupervisorController
{
    public function index(Request $request, Institution $institution, InstitutionAccess $access): JsonResponse
    {
        abort_unless($institution->type === 'HOSPITAL', 422, 'Cette fonctionnalité est réservée aux hôpitaux.');
        abort_unless($access->has($request->user(), $institution->id, [InstitutionRole::Admin->value, InstitutionRole::HospitalManager->value]), 403);
        $supervisorIds = DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.institution_id', $institution->id)
            ->where('model_has_roles.model_type', User::class)
            ->where('roles.name', InstitutionRole::Supervisor->value)
            ->pluck('model_has_roles.model_id');

        $profiles = HospitalSupervisorProfile::query()->where('institution_id', $institution->id)
            ->whereIn('user_id', $supervisorIds)->with('services:id,name,code,status')->get()->keyBy('user_id');
        $activeInternships = DB::table('internships')->where('hospital_id', $institution->id)
            ->whereIn('supervisor_id', $supervisorIds)->whereIn('status', ['PLANNED', 'ACTIVE'])
            ->selectRaw('supervisor_id, COUNT(*) AS total')->groupBy('supervisor_id')->pluck('total', 'supervisor_id');

        $items = $institution->members()->whereIn('users.id', $supervisorIds)->with('profile')->orderBy('users.name')->get()
            ->map(function (User $user) use ($profiles, $activeInternships): array {
                $profile = $profiles->get($user->id);
                return [
                    'id' => $user->public_id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'avatar_url' => $user->profile?->avatar_url,
                    'availability_status' => $profile?->availability_status ?? 'AVAILABLE',
                    'availability_note' => $profile?->availability_note,
                    'stages_enabled' => $profile?->stages_enabled ?? true,
                    'active_internships_count' => (int) ($activeInternships[$user->id] ?? 0),
                    'services' => $profile?->services ?? [],
                ];
            });

        return response()->json(['data' => $items]);
    }

    public function update(Request $request, Institution $institution, User $user, InstitutionAccess $access, InstitutionAudit $audit): JsonResponse
    {
        $this->authorize($request, $institution);
        abort_unless($institution->members()->whereKey($user->id)->exists(), 404);
        abort_unless(in_array(InstitutionRole::Supervisor->value, $access->rolesFor($user, $institution->id), true), 422, 'Ce membre ne possède pas le rôle de superviseur.');
        $data = $request->validate([
            'service_ids' => ['present', 'array'],
            'service_ids.*' => ['integer', 'distinct'],
            'availability_status' => ['required', Rule::in(['AVAILABLE', 'LIMITED', 'UNAVAILABLE'])],
            'availability_note' => ['nullable', 'string', 'max:1000'],
            'stages_enabled' => ['required', 'boolean'],
        ]);
        $serviceIds = $institution->units()->whereIn('id', $data['service_ids'])->where('type', 'SERVICE')->whereNull('parent_id')->pluck('id');
        abort_unless($serviceIds->count() === count($data['service_ids']), 422, 'Un ou plusieurs services sont invalides.');

        $existing = HospitalSupervisorProfile::query()->where('institution_id', $institution->id)->where('user_id', $user->id)->with('services')->first();
        $profile = DB::transaction(function () use ($institution, $user, $data, $serviceIds): HospitalSupervisorProfile {
            $profile = HospitalSupervisorProfile::updateOrCreate(
                ['institution_id' => $institution->id, 'user_id' => $user->id],
                ['availability_status' => $data['availability_status'], 'availability_note' => $data['availability_note'] ?? null, 'stages_enabled' => $data['stages_enabled']],
            );
            $profile->services()->sync($serviceIds);
            return $profile;
        });
        $audit->record($request, $institution, 'SUPERVISOR_CONFIGURATION_UPDATED', 'user', $user->public_id,
            $existing ? ['availability_status' => $existing->availability_status, 'stages_enabled' => $existing->stages_enabled, 'service_ids' => $existing->services->pluck('id')->all()] : null,
            ['availability_status' => $data['availability_status'], 'stages_enabled' => $data['stages_enabled'], 'service_ids' => $serviceIds->values()->all()]);

        return response()->json(['message' => 'Superviseur mis à jour.', 'data' => $profile->load('services:id,name,code,status')]);
    }

    private function authorize(Request $request, Institution $institution): void
    {
        abort_unless($institution->type === 'HOSPITAL', 422, 'Cette fonctionnalité est réservée aux hôpitaux.');
        $request->user()->can('manageMembers', $institution) || abort(403);
    }
}
