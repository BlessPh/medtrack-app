<?php

namespace App\Modules\Academic\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentManagementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $history = $this->relationLoaded('enrollments') ? $this->enrollments : collect();
        $metadata = $this->metadata ?? [];
        return [
            'id' => $this->public_id, 'university_id' => $this->university?->public_id,
            'student_number' => $this->student_number, 'national_reference' => $this->national_reference,
            'last_name' => $this->last_name ?? ($metadata['last_name'] ?? null), 'middle_name' => $this->middle_name ?? ($metadata['middle_name'] ?? null),
            'first_name' => $this->first_name ?? ($metadata['first_name'] ?? null),
            'full_name' => trim(collect([$this->last_name ?? ($metadata['last_name'] ?? null), $this->middle_name ?? ($metadata['middle_name'] ?? null), $this->first_name ?? ($metadata['first_name'] ?? null)])->filter()->implode(' ')),
            'gender' => $this->gender ?? ($metadata['gender'] ?? null), 'birth_date' => $this->birth_date?->format('Y-m-d') ?? ($metadata['birth_date'] ?? null),
            'email' => $this->email ?? ($metadata['email'] ?? null), 'phone' => $this->phone ?? ($metadata['phone'] ?? null),
            'status' => $this->status, 'has_account' => $this->user_id !== null,
            'user' => $this->whenLoaded('user', fn () => $this->user ? ['id' => $this->user->public_id, 'name' => $this->user->name, 'email' => $this->user->email, 'status' => $this->user->status] : null),
            'current_assignment' => $history->firstWhere('status', 'ACTIVE'),
            'enrollments' => $this->whenLoaded('enrollments'), 'created_at' => $this->created_at,
        ];
    }
}
