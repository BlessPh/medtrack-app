<?php

namespace App\Modules\Institution\Controllers;

use App\Modules\Auth\Models\User;
use App\Modules\Institution\Models\Institution;
use App\Modules\Institution\Models\InstitutionMemberInvitation;
use App\Modules\Institution\Notifications\InstitutionMemberInvitationNotification;
use App\Shared\Enums\InstitutionRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InstitutionInvitationController
{
    public function index(Request $request, Institution $institution): JsonResponse
    {
        $request->user()->can('manageMembers', $institution) || abort(403);
        $items = $institution->invitations()->with('inviter:id,name')->latest()->get()->map(fn ($item) => [
            'id' => $item->public_id, 'email' => $item->email, 'role' => $item->role,
            'status' => $item->accepted_at ? 'ACCEPTED' : ($item->revoked_at ? 'REVOKED' : ($item->expires_at->isPast() ? 'EXPIRED' : 'PENDING')),
            'expires_at' => $item->expires_at, 'created_at' => $item->created_at,
            'invited_by' => $item->inviter?->name,
        ]);

        return response()->json(['data' => $items]);
    }

    public function store(Request $request, Institution $institution): JsonResponse
    {
        $request->user()->can('manageMembers', $institution) || abort(403);
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['required', Rule::in($this->rolesFor($institution))],
        ]);
        $email = mb_strtolower(trim($data['email']));
        if (User::whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['email' => 'Ce courriel appartient déjà à un compte MedTrack. Ajoutez-le comme membre existant.']);
        }
        $institution->invitations()->where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $token = Str::random(64);
        $invitation = $institution->invitations()->create([
            'email' => $email, 'role' => $data['role'], 'token_hash' => hash('sha256', $token),
            'invited_by' => $request->user()->id, 'expires_at' => now()->addDays(7),
        ]);
        $invitation->load('institution');
        Notification::route('mail', $email)->notify(new InstitutionMemberInvitationNotification($invitation, $token));

        return response()->json(['message' => 'Invitation envoyée.', 'data' => ['id' => $invitation->public_id, 'email' => $email, 'role' => $invitation->role, 'status' => 'PENDING', 'expires_at' => $invitation->expires_at]], 201);
    }

    public function destroy(Request $request, Institution $institution, InstitutionMemberInvitation $invitation): JsonResponse
    {
        $request->user()->can('manageMembers', $institution) || abort(403);
        abort_unless($invitation->institution_id === $institution->id, 404);
        abort_if($invitation->accepted_at, 409, 'Une invitation acceptée ne peut pas être révoquée.');
        $invitation->update(['revoked_at' => now()]);

        return response()->json(null, 204);
    }

    private function rolesFor(Institution $institution): array
    {
        return $institution->type === 'UNIVERSITY'
            ? [InstitutionRole::Admin->value, InstitutionRole::AcademicManager->value, InstitutionRole::FinanceOfficer->value, InstitutionRole::Member->value]
            : [InstitutionRole::Admin->value, InstitutionRole::HospitalManager->value, InstitutionRole::Supervisor->value, InstitutionRole::FinanceOfficer->value, InstitutionRole::Member->value];
    }
}
