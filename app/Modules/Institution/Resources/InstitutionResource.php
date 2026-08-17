<?php

namespace App\Modules\Institution\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InstitutionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id, 'type' => $this->type, 'name' => $this->name,
            'short_name' => $this->short_name, 'registration_number' => $this->registration_number,
            'legal_form' => $this->legal_form, 'ownership_type' => $this->ownership_type,
            'accreditation_number' => $this->accreditation_number, 'tax_number' => $this->tax_number,
            'founded_on' => $this->founded_on?->toDateString(), 'primary_language' => $this->primary_language,
            'timezone' => $this->timezone, 'status' => $this->status, 'description' => $this->description,
            'website' => $this->website,
            'logo_url' => $this->logo ? route('api.v1.institutions.logo.show', $this->public_id) : null,
            'verified_at' => $this->verified_at, 'created_at' => $this->created_at,
            'units_count' => $this->whenCounted('units'), 'members_count' => $this->whenCounted('members'),
            'students_count' => $this->when(isset($this->students_count), $this->students_count),
            'internships_count' => $this->when(isset($this->internships_count), $this->internships_count),
            'units' => $this->whenLoaded('units'), 'addresses' => $this->whenLoaded('addresses'),
            'contacts' => $this->whenLoaded('contacts'),
        ];
    }
}
