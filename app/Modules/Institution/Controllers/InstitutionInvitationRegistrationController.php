<?php

namespace App\Modules\Institution\Controllers;

use App\Modules\Auth\Models\User;
use App\Modules\Institution\Models\InstitutionMemberInvitation;
use App\Shared\Enums\UserStatus;
use App\Shared\Services\InstitutionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class InstitutionInvitationRegistrationController
{
    public function show(string $token): JsonResponse
    {
        $invitation = $this->validInvitation($token);

        return response()->json(['data' => [
            'email' => $invitation->email, 'roles' => $invitation->roles ?: [$invitation->role],
            'institution' => ['id' => $invitation->institution->public_id, 'name' => $invitation->institution->name, 'type' => $invitation->institution->type],
            'expires_at' => $invitation->expires_at,
        ]]);
    }

    public function store(Request $request, string $token, InstitutionAccess $access): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', 'unique:users,phone'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()],
        ]);
        $invitationId = $this->validInvitation($token)->id;

        $user = DB::transaction(function () use ($invitationId, $data, $access): User {
            $invitation = InstitutionMemberInvitation::with('institution')->lockForUpdate()->findOrFail($invitationId);
            $this->ensurePending($invitation);
            if (User::whereRaw('LOWER(email) = ?', [$invitation->email])->exists()) {
                throw ValidationException::withMessages(['email' => 'Un compte MedTrack utilise déjà cette adresse. Connectez-vous puis demandez son ajout à l’institution.']);
            }
            $user = User::create(['name' => trim($data['name']), 'email' => $invitation->email, 'phone' => $data['phone'] ?? null, 'password' => $data['password'], 'status' => UserStatus::Active]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $invitation->institution->members()->attach($user->id);
            foreach (($invitation->roles ?: [$invitation->role]) as $role) {
                $access->assign($user, $invitation->institution_id, $role);
            }
            $invitation->update(['accepted_user_id' => $user->id, 'accepted_at' => now()]);

            return $user;
        });

        return response()->json(['message' => 'Compte créé. Vous pouvez maintenant vous connecter.', 'data' => ['id' => $user->public_id, 'email' => $user->email]], 201);
    }

    private function validInvitation(string $token): InstitutionMemberInvitation
    {
        $invitation = InstitutionMemberInvitation::with('institution')->where('token_hash', hash('sha256', $token))->first();
        if (! $invitation) {
            throw ValidationException::withMessages(['invitation' => 'Invitation invalide.']);
        }
        $this->ensurePending($invitation);

        return $invitation;
    }

    private function ensurePending(InstitutionMemberInvitation $invitation): void
    {
        if ($invitation->accepted_at || $invitation->revoked_at || $invitation->expires_at->isPast()) {
            throw ValidationException::withMessages(['invitation' => 'Cette invitation n’est plus valide.']);
        }
    }
}
