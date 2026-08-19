<?php

namespace App\Modules\Auth\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $assignments = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', $this->resource->getMorphClass())
            ->where('model_has_roles.model_id', $this->id)
            ->select('roles.name', 'model_has_roles.institution_id')
            ->get();
        $institutions = $this->institutions()->wherePivot('status', 'ACTIVE')->orderBy('name')->get()->map(function ($institution) use ($assignments): array {
            return [
                'id' => $institution->public_id,
                'name' => $institution->name,
                'type' => $institution->type,
                'roles' => $assignments->where('institution_id', $institution->id)->pluck('name')->values(),
            ];
        });
        $onboardingRequest = $institutions->isEmpty()
            ? \App\Modules\Auth\Models\InstitutionAccountRequest::query()->where('created_user_id', $this->id)
                ->where('status', 'APPROVED')->latest('reviewed_at')->first()
            : null;

        return [
            'id' => $this->public_id, 'name' => $this->name, 'username' => $this->username,
            'email' => str_ends_with((string) $this->email, '@accounts.medtrack.invalid') ? null : $this->email,
            'phone' => $this->phone,
            'roles' => $assignments->pluck('name')->unique()->values(),
            'institutions' => $institutions,
            'institution_onboarding' => $onboardingRequest ? [
                'request_id' => $onboardingRequest->public_id, 'reference' => $onboardingRequest->reference,
                'type' => $onboardingRequest->institution_type, 'name' => $onboardingRequest->institution_name,
            ] : null,
            'status' => $this->status, 'email_verified_at' => $this->email_verified_at,
            'last_login_at' => $this->last_login_at, 'created_at' => $this->created_at,
            'profile' => $this->whenLoaded('profile', function (): ?array {
                if (! $this->profile) {
                    return null;
                }

                return array_replace($this->profile->toArray(), [
                    'avatar_url' => $this->profile->avatar_url
                        ? url('/api/v1/auth/profile/avatar')
                        : null,
                ]);
            }),
        ];
    }
}
