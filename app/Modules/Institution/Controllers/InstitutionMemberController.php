<?php

namespace App\Modules\Institution\Controllers;

use App\Modules\Auth\Models\User;
use App\Modules\Institution\Models\Institution;
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
            'joined_at' => $user->pivot->created_at,
        ]);

        return response()->json(['data' => $members]);
    }

    public function store(Request $request, Institution $institution, InstitutionAccess $access): JsonResponse
    {
        $request->user()->can('manageMembers', $institution) || abort(403);
        $data = $request->validate(['email' => ['required', 'email:rfc', 'exists:users,email'], 'role' => ['required', Rule::in($this->rolesFor($institution))]]);
        $user = User::where('email', mb_strtolower($data['email']))->firstOrFail();
        $institution->members()->syncWithoutDetaching([$user->id]);
        $access->assign($user, $institution->id, $data['role']);
        $user->notify(new InstitutionNotification($institution->public_id, $institution->name, 'Ajout à une institution', "Vous avez été ajouté à {$institution->name} avec le rôle {$data['role']}.", 'MEMBERSHIP', 'SUCCESS'));

        return response()->json(['message' => 'Membre ajouté.'], 201);
    }

    public function update(Request $request, Institution $institution, User $user, InstitutionAccess $access): JsonResponse
    {
        $request->user()->can('manageMembers', $institution) || abort(403);
        abort_unless($institution->members()->whereKey($user->id)->exists(), 404);
        $data = $request->validate(['role' => ['required', Rule::in($this->rolesFor($institution))]]);
        $access->assign($user, $institution->id, $data['role']);
        $user->notify(new InstitutionNotification($institution->public_id, $institution->name, 'Rôle institutionnel modifié', "Votre rôle au sein de {$institution->name} est désormais {$data['role']}.", 'MEMBERSHIP', 'INFO'));

        return response()->json(['message' => 'Rôle du membre mis à jour.']);
    }

    public function destroy(Request $request, Institution $institution, User $user, InstitutionAccess $access): JsonResponse
    {
        $request->user()->can('manageMembers', $institution) || abort(403);
        abort_if($request->user()->is($user), 409, 'Vous ne pouvez pas retirer votre propre appartenance.');
        $user->notify(new InstitutionNotification($institution->public_id, $institution->name, 'Accès institutionnel retiré', "Votre accès à {$institution->name} a été retiré.", 'MEMBERSHIP', 'WARNING'));
        $access->remove($user, $institution->id);
        $institution->members()->detach($user);

        return response()->json(null, 204);
    }

    private function rolesFor(Institution $institution): array
    {
        return $institution->type === 'UNIVERSITY'
            ? [InstitutionRole::Admin->value, InstitutionRole::AcademicManager->value, InstitutionRole::FinanceOfficer->value, InstitutionRole::Member->value]
            : [InstitutionRole::Admin->value, InstitutionRole::HospitalManager->value, InstitutionRole::Supervisor->value, InstitutionRole::FinanceOfficer->value, InstitutionRole::Member->value];
    }
}
