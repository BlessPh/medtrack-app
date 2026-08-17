<?php

namespace App\Modules\Academic\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->public_id, 'user_id' => $this->user?->public_id, 'user' => $this->whenLoaded('user', fn () => $this->user ? ['name' => $this->user->name, 'email' => $this->user->email] : null), 'university_id' => $this->university?->public_id, 'national_reference' => $this->national_reference, 'student_number' => $this->student_number, 'status' => $this->status, 'enrollments' => $this->whenLoaded('enrollments')];
    }
}
