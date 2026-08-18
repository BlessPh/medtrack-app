<?php

namespace App\Modules\Auth\Controllers;

use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Auth\Models\User;
use App\Modules\Institution\Models\Institution;
use App\Shared\Enums\InstitutionRole;
use App\Shared\Enums\UserStatus;
use App\Shared\Services\InstitutionAccess;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class StudentRegistrationController
{
    public function check(Request $request): JsonResponse
    {
        $data = $request->validate(['university_id' => ['required', 'uuid'], 'academic_year_id' => ['required', 'integer'], 'student_number' => ['required', 'string', 'max:100']]);
        $university = Institution::query()->where('public_id', $data['university_id'])->where('type', 'UNIVERSITY')->where('status', 'ACTIVE')->firstOrFail();
        $year = AcademicYear::query()->whereKey($data['academic_year_id'])->where('is_current', true)->where('status', 'CURRENT')->firstOrFail();
        $number = mb_strtoupper(trim($data['student_number']));
        $student = Student::query()->where('university_id', $university->id)->whereRaw('UPPER(student_number) = ?', [$number])->where('status', 'ACTIVE')->whereNull('user_id')
            ->whereHas('enrollments', fn ($query) => $query->where('status', 'ACTIVE')->whereHas('promotion', fn ($promotion) => $promotion->where('academic_year_reference_id', $year->id)->whereHas('program', fn ($program) => $program->where('university_id', $university->id))))
            ->with(['enrollments' => fn ($query) => $query->where('status', 'ACTIVE')->whereHas('promotion', fn ($promotion) => $promotion->where('academic_year_reference_id', $year->id))])
            ->first();
        if (! $student) {
            throw ValidationException::withMessages(['student_number' => 'Aucun dossier étudiant disponible ne correspond à ces informations.']);
        }
        $promotionId = $student->enrollments->firstOrFail()->promotion_id;
        $token = Crypt::encryptString(json_encode(['student_id' => $student->id, 'university_id' => $university->id, 'promotion_id' => $promotionId, 'academic_year_id' => $year->id, 'expires_at' => now()->addMinutes(15)->timestamp], JSON_THROW_ON_ERROR));

        return response()->json(['data' => ['eligible' => true, 'registration_token' => $token, 'expires_in' => 900, 'student' => ['student_number' => $student->student_number, 'first_name' => $student->first_name, 'last_name' => $student->last_name]]]);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate(['registration_token' => ['required', 'string'], 'email' => ['nullable', 'email', 'max:255', 'unique:users,email'], 'phone' => ['nullable', 'string', 'max:30', 'unique:users,phone'], 'password' => ['required', 'string', Password::min(8)->mixedCase()->symbols(), 'confirmed'], 'nationality' => ['nullable', 'string', 'max:100'], 'address' => ['nullable', 'string', 'max:1000'], 'city' => ['nullable', 'string', 'max:100'], 'country' => ['nullable', 'string', 'max:100']]);
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
            $name = trim(collect([$student->last_name, $student->middle_name, $student->first_name])->filter()->implode(' '));
            $username = $this->availableUsername($student);
            // L’adresse technique satisfait le schéma historique sans obliger
            // l’étudiant à posséder une adresse électronique personnelle.
            $email = isset($data['email']) ? mb_strtolower($data['email']) : 'student-'.$student->public_id.'@accounts.medtrack.invalid';
            $user = User::create(['name' => $name ?: $student->student_number, 'username' => $username, 'email' => $email, 'phone' => $data['phone'] ?? null, 'password' => $data['password'], 'status' => UserStatus::Active]);
            $user->profile()->create(['first_name' => $student->first_name, 'last_name' => $student->last_name, 'gender' => $student->gender, 'birth_date' => $student->birth_date, 'nationality' => $data['nationality'] ?? null, 'address' => $data['address'] ?? null, 'city' => $data['city'] ?? null, 'country' => $data['country'] ?? null, 'metadata' => ['middle_name' => $student->middle_name]]);
            $student->update(['user_id' => $user->id]);
            $user->institutions()->attach($student->university_id);
            app(InstitutionAccess::class)->assign($user, $student->university_id, InstitutionRole::Student->value);

            return $user;
        });

        return response()->json(['data' => ['id' => $user->public_id, 'login_identifier' => $user->username, 'email' => str_ends_with($user->email, '@accounts.medtrack.invalid') ? null : $user->email]], 201);
    }

    /** Le matricule reste l’identifiant principal ; un suffixe stable résout une éventuelle collision interuniversitaire. */
    private function availableUsername(Student $student): string
    {
        $base = mb_strtolower(trim($student->student_number));
        return User::query()->whereRaw('LOWER(username) = ?', [$base])->exists()
            ? $base.'.'.substr($student->university->public_id, 0, 8)
            : $base;
    }
}
