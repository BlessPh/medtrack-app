<?php

namespace App\Modules\Auth\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InstitutionAccountRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'reference' => $this->reference,
            'institution_type' => $this->institution_type,
            'institution_name' => $this->institution_name,
            'applicant' => [
                'last_name' => $this->last_name, 'middle_name' => $this->middle_name,
                'first_name' => $this->first_name, 'gender' => $this->gender,
                'email' => $this->email, 'phone' => $this->phone, 'job_title' => $this->job_title,
            ],
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'reviewed_at' => $this->reviewed_at,
            'reviewer' => $this->whenLoaded('reviewer', fn () => $this->reviewer ? ['id' => $this->reviewer->public_id, 'name' => $this->reviewer->name] : null),
            'created_user' => $this->whenLoaded('createdUser', fn () => $this->createdUser ? ['id' => $this->createdUser->public_id, 'name' => $this->createdUser->name, 'email' => $this->createdUser->email] : null),
            'created_at' => $this->created_at,
        ];
    }
}
