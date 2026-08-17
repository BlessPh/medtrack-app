<?php

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInstitutionAccountRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'institution_type' => ['required', Rule::in(['UNIVERSITY', 'HOSPITAL'])],
            'institution_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'first_name' => ['required', 'string', 'max:100'],
            'gender' => ['nullable', Rule::in(['FEMALE', 'MALE', 'OTHER'])],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['required', 'string', 'max:30', Rule::unique('users', 'phone')],
            'job_title' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'accepted' => ['accepted'],
        ];
    }
}
