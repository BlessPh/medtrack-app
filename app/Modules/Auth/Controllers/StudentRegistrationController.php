<?php

namespace App\Modules\Auth\Controllers;

use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Services\AcademicImportContext;
use App\Modules\Auth\Models\User;
use App\Shared\Enums\InstitutionRole;
use App\Shared\Enums\UserStatus;
use App\Shared\Services\InstitutionAccess;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StudentRegistrationController
{
    public function check(Request $request, AcademicImportContext $context): JsonResponse
    {
        $data = $request->validate(['university_id' => ['required', 'uuid'], 'promotion_id' => ['required', 'integer'], 'academic_year_id' => ['required', 'integer'], 'student_number' => ['required', 'string', 'max:100']]);
        [$university, $promotion, $year] = $context->resolve($data['university_id'], $data['promotion_id'], $data['academic_year_id']);
        $number = mb_strtoupper(trim($data['student_number']));
        $student = Student::where('university_id', $university->id)->whereRaw('UPPER(student_number) = ?', [$number])->where('status', 'ACTIVE')->whereNull('user_id')->whereHas('enrollments', fn ($query) => $query->where('promotion_id', $promotion->id)->where('status', 'ACTIVE'))->first();
        if (! $student) {
            throw ValidationException::withMessages(['student_number' => 'Aucun dossier étudiant disponible ne correspond à ces informations.']);
        }
        $token = Crypt::encryptString(json_encode(['student_id' => $student->id, 'university_id' => $university->id, 'promotion_id' => $promotion->id, 'academic_year_id' => $year->id, 'expires_at' => now()->addMinutes(15)->timestamp], JSON_THROW_ON_ERROR));

        return response()->json(['data' => ['eligible' => true, 'registration_token' => $token, 'expires_in' => 900, 'student' => ['student_number' => $student->student_number, 'first_name' => $student->metadata['first_name'] ?? null, 'last_name' => $student->metadata['last_name'] ?? null]]]);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate(['registration_token' => ['required', 'string'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'], 'phone' => ['nullable', 'string', 'max:30', 'unique:users,phone'], 'password' => ['required', 'string', 'min:12', 'confirmed'], 'nationality' => ['nullable', 'string', 'max:100'], 'address' => ['nullable', 'string', 'max:1000'], 'city' => ['nullable', 'string', 'max:100'], 'country' => ['nullable', 'string', 'max:100']]);
        try {
            $claim = json_decode(Crypt::decryptString($data['registration_token']), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw ValidationException::withMessages(['registration_token' => 'Jeton d’inscription invalide.']);
        }
        if (($claim['expires_at'] ?? 0) < now()->timestamp) {
            throw ValidationException::withMessages(['registration_token' => 'Jeton d’inscription expiré.']);
        }

        $user = DB::transaction(function () use ($claim, $data): User {
            $student = Student::lockForUpdate()->findOrFail($claim['student_id']);
            abort_unless($student->university_id === $claim['university_id'] && $student->user_id === null && $student->enrollments()->where('promotion_id', $claim['promotion_id'])->where('status', 'ACTIVE')->exists(), 422, 'Le dossier étudiant n’est plus disponible.');
            $metadata = $student->metadata ?? [];
            $name = trim(implode(' ', array_filter([$metadata['first_name'] ?? null, $metadata['middle_name'] ?? null, $metadata['last_name'] ?? null])));
            $user = User::create(['name' => $name, 'email' => mb_strtolower($data['email']), 'phone' => $data['phone'] ?? null, 'password' => $data['password'], 'status' => UserStatus::Active]);
            $user->profile()->create(['first_name' => $metadata['first_name'] ?? null, 'last_name' => $metadata['last_name'] ?? null, 'gender' => $metadata['gender'] ?? null, 'birth_date' => $metadata['birth_date'] ?? null, 'nationality' => $data['nationality'] ?? null, 'address' => $data['address'] ?? null, 'city' => $data['city'] ?? null, 'country' => $data['country'] ?? null, 'metadata' => ['middle_name' => $metadata['middle_name'] ?? null]]);
            $student->update(['user_id' => $user->id]);
            $user->institutions()->attach($student->university_id);
            app(InstitutionAccess::class)->assign($user, $student->university_id, InstitutionRole::Student->value);

            return $user;
        });

        return response()->json(['data' => ['id' => $user->public_id, 'email' => $user->email]], 201);
    }
}
