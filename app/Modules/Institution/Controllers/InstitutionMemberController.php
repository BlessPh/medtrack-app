<?php

namespace App\Modules\Institution\Controllers;

use App\Modules\Auth\Models\User;
use App\Modules\Institution\Models\Institution;
use App\Modules\Institution\Models\HospitalSupervisorProfile;
use App\Modules\Institution\Services\InstitutionAudit;
use App\Modules\Notification\Notifications\InstitutionNotification;
use App\Shared\Enums\InstitutionRole;
use App\Shared\Services\InstitutionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InstitutionMemberController
{
    public function index(Request $request, Institution $institution, InstitutionAccess $access): JsonResponse
    {
        $request->user()->can('manageMembers', $institution) || abort(403);
        $members = $institution->members()->with('profile')->orderBy('name')->get()->map(fn (User $user): array => [
            'id' => $user->public_id, 'name' => $user->name, 'email' => $user->email,
            'phone' => $user->phone, 'status' => $user->status->value,
            'avatar_url' => $user->profile?->avatar_url, 'roles' => $access->rolesFor($user, $institution->id),
            'joined_at' => $user->pivot->created_at, 'membership_status' => $user->pivot->status,
            'suspended_at' => $user->pivot->suspended_at, 'suspension_reason' => $user->pivot->suspension_reason,
        ]);

        return response()->json(['data' => $members]);
    }

    public function store(Request $request, Institution $institution, InstitutionAccess $access, InstitutionAudit $audit): JsonResponse
    {
        $request->user()->can('manageMembers', $institution) || abort(403);
        $data = $request->validate(['email' => ['required', 'email:rfc', 'exists:users,email'], 'role' => ['required', Rule::in($this->rolesFor($institution))]]);
        $user = User::where('email', mb_strtolower($data['email']))->firstOrFail();
        $institution->members()->syncWithoutDetaching([$user->id => ['status' => 'ACTIVE', 'suspended_at' => null, 'suspended_by' => null, 'suspension_reason' => null]]);
        $access->assign($user, $institution->id, $data['role']);
        $audit->record($request, $institution, 'MEMBER_ATTACHED', 'user', $user->public_id, null, ['role' => $data['role']]);
        if ($data['role'] !== InstitutionRole::Supervisor->value) {
            HospitalSupervisorProfile::query()->where('institution_id', $institution->id)->where('user_id', $user->id)->delete();
        }
        $user->notify(new InstitutionNotification($institution->public_id, $institution->name, 'Ajout à une institution', "Vous avez été ajouté à {$institution->name} avec le rôle {$data['role']}.", 'MEMBERSHIP', 'SUCCESS'));

        return response()->json(['message' => 'Membre ajouté.'], 201);
    }

    public function update(Request $request, Institution $institution, User $user, InstitutionAccess $access, InstitutionAudit $audit): JsonResponse
    {
        $request->user()->can('manageMembers', $institution) || abort(403);
        abort_unless($institution->members()->whereKey($user->id)->exists(), 404);
        $data = $request->validate(['role' => ['required', Rule::in($this->rolesFor($institution))]]);
        $beforeRoles = $access->rolesFor($user, $institution->id);
        $access->assign($user, $institution->id, $data['role']);
        $audit->record($request, $institution, 'MEMBER_ROLE_CHANGED', 'user', $user->public_id, ['roles' => $beforeRoles], ['role' => $data['role']]);
        if ($data['role'] !== InstitutionRole::Supervisor->value) {
            HospitalSupervisorProfile::query()->where('institution_id', $institution->id)->where('user_id', $user->id)->delete();
        }
        $user->notify(new InstitutionNotification($institution->public_id, $institution->name, 'Rôle institutionnel modifié', "Votre rôle au sein de {$institution->name} est désormais {$data['role']}.", 'MEMBERSHIP', 'INFO'));

        return response()->json(['message' => 'Rôle du membre mis à jour.']);
    }

    public function destroy(Request $request, Institution $institution, User $user, InstitutionAccess $access, InstitutionAudit $audit): JsonResponse
    {
        $request->user()->can('manageMembers', $institution) || abort(403);
        abort_if($request->user()->is($user), 409, 'Vous ne pouvez pas retirer votre propre appartenance.');
        $user->notify(new InstitutionNotification($institution->public_id, $institution->name, 'Accès institutionnel retiré', "Votre accès à {$institution->name} a été retiré.", 'MEMBERSHIP', 'WARNING'));
        $access->remove($user, $institution->id);
        HospitalSupervisorProfile::query()->where('institution_id', $institution->id)->where('user_id', $user->id)->delete();
        $institution->members()->detach($user);
        $audit->record($request, $institution, 'MEMBER_REMOVED', 'user', $user->public_id);

        return response()->json(null, 204);
    }

    public function status(Request $request, Institution $institution, User $user, InstitutionAccess $access, InstitutionAudit $audit): JsonResponse
    {
        $request->user()->can('manageMembers', $institution) || abort(403);
        abort_unless($institution->members()->whereKey($user->id)->exists(), 404);
        abort_if($request->user()->is($user), 409, 'Vous ne pouvez pas suspendre votre propre rattachement.');
        $data = $request->validate([
            'status' => ['required', Rule::in(['ACTIVE', 'SUSPENDED'])],
            'reason' => ['nullable', 'required_if:status,SUSPENDED', 'string', 'max:500'],
        ]);
        $membership = $institution->members()->whereKey($user->id)->firstOrFail()->pivot;
        $before = ['status' => $membership->status, 'suspension_reason' => $membership->suspension_reason];
        $attributes = $data['status'] === 'SUSPENDED'
            ? ['status' => 'SUSPENDED', 'suspended_at' => now(), 'suspended_by' => $request->user()->id, 'suspension_reason' => $data['reason']]
            : ['status' => 'ACTIVE', 'suspended_at' => null, 'suspended_by' => null, 'suspension_reason' => null];
        $institution->members()->updateExistingPivot($user->id, $attributes);
        $audit->record($request, $institution, $data['status'] === 'SUSPENDED' ? 'MEMBER_SUSPENDED' : 'MEMBER_REACTIVATED', 'user', $user->public_id, $before, $attributes);

        return response()->json(['message' => $data['status'] === 'SUSPENDED' ? 'Membre suspendu.' : 'Membre réactivé.']);
    }

    private function rolesFor(Institution $institution): array
    {
        return $institution->type === 'UNIVERSITY'
            ? [InstitutionRole::Admin->value, InstitutionRole::AcademicManager->value, InstitutionRole::FinanceOfficer->value, InstitutionRole::Member->value]
            : [InstitutionRole::Admin->value, InstitutionRole::HospitalManager->value, InstitutionRole::Supervisor->value, InstitutionRole::FinanceOfficer->value, InstitutionRole::Member->value];
    }
}
