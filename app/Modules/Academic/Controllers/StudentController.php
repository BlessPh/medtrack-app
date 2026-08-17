<?php

namespace App\Modules\Academic\Controllers;

use App\Modules\Academic\Models\Promotion;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Policies\AcademicPolicy;
use App\Modules\Academic\Resources\StudentResource;
use App\Modules\Auth\Models\User;
use App\Modules\Institution\Models\Institution;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentController
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'university_id' => ['required', 'uuid', 'exists:institutions,public_id'],
            'promotion_id' => ['nullable', 'integer', 'exists:promotions,id'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $institution = Institution::where('public_id', $data['university_id'])->firstOrFail();
        abort_unless(app(AcademicPolicy::class)->manage($request->user(), $institution->id), 403);

        $query = Student::where('university_id', $institution->id)
            ->with(['user', 'university', 'enrollments.promotion']);
        if (isset($data['promotion_id'])) {
            $query->whereHas('enrollments', fn ($builder) => $builder->where('promotion_id', $data['promotion_id']));
        }

        return StudentResource::collection($query->orderBy('student_number')->paginate($data['per_page'] ?? 25));
    }

    public function store(Request $request): StudentResource
    {
        $data = $request->validate(['university_id' => ['required', 'uuid', 'exists:institutions,public_id'], 'user_id' => ['nullable', 'uuid', 'exists:users,public_id'], 'national_reference' => ['nullable', 'string', 'max:150'], 'student_number' => ['required', 'string', 'max:100'], 'promotion_id' => ['nullable', 'integer', 'exists:promotions,id']]);
        $institution = Institution::where('public_id', $data['university_id'])->firstOrFail();
        abort_unless(app(AcademicPolicy::class)->manage($request->user(), $institution->id), 403);
        if (! empty($data['promotion_id'])) {
            abort_unless(Promotion::whereKey($data['promotion_id'])->whereHas('program', fn ($query) => $query->where('university_id', $institution->id))->exists(), 422, 'La promotion n’appartient pas à cette université.');
        }
        $student = DB::transaction(function () use ($data, $institution): Student {
            $student = Student::create(['university_id' => $institution->id, 'user_id' => isset($data['user_id']) ? User::where('public_id', $data['user_id'])->value('id') : null, 'national_reference' => $data['national_reference'] ?? null, 'student_number' => $data['student_number'], 'status' => 'ACTIVE']);
            if (! empty($data['promotion_id'])) {
                $student->enrollments()->create(['promotion_id' => Promotion::findOrFail($data['promotion_id'])->id, 'status' => 'ACTIVE', 'enrolled_at' => now()]);
            }

            return $student;
        });

        return new StudentResource($student->load(['user', 'university', 'enrollments']));
    }
}
