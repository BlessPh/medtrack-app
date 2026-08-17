<?php

namespace App\Modules\Institution\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveInstitutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('institution')?->id;

        return [
            'type' => ['required', Rule::in(['UNIVERSITY', 'HOSPITAL', 'CLINIC', 'OTHER'])],
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:100'],
            'registration_number' => ['nullable', 'string', 'max:100', Rule::unique('institutions')->ignore($id)],
            'legal_form' => ['nullable', 'string', 'max:80'],
            'ownership_type' => ['nullable', Rule::in(['PUBLIC', 'PRIVATE', 'FAITH_BASED', 'MIXED', 'OTHER'])],
            'accreditation_number' => ['nullable', 'string', 'max:100', Rule::unique('institutions')->ignore($id)],
            'tax_number' => ['nullable', 'string', 'max:100', Rule::unique('institutions')->ignore($id)],
            'founded_on' => ['nullable', 'date', 'before_or_equal:today'],
            'primary_language' => ['nullable', 'string', 'max:10'],
            'timezone' => ['nullable', 'timezone'],
            'description' => ['nullable', 'string', 'max:3000'],
            'website' => ['nullable', 'url', 'max:255'],
        ];
    }
}
