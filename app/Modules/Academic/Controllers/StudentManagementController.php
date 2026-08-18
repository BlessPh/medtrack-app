<?php

namespace App\Modules\Academic\Controllers;

use App\Modules\Academic\Models\Promotion;
use App\Modules\Academic\Models\Campaign;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Policies\AcademicPolicy;
use App\Modules\Academic\Resources\StudentManagementResource;
use App\Modules\Auth\Models\User;
use App\Modules\Institution\Models\Institution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StudentManagementController
{
    public function index(Request $request)
    {
        $data = $request->validate(['university_id' => ['required', 'uuid'], 'search' => ['nullable', 'string', 'max:150'], 'program_id' => ['nullable', 'integer'], 'promotion_id' => ['nullable', 'integer'], 'academic_year_id' => ['nullable', 'integer'], 'status' => ['nullable', Rule::in(['ACTIVE', 'SUSPENDED', 'ARCHIVED'])], 'account' => ['nullable', Rule::in(['WITH_ACCOUNT', 'WITHOUT_ACCOUNT'])], 'per_page' => ['nullable', 'integer', 'between:1,100']]);
        $university = $this->university($data['university_id']);
        abort_unless(app(AcademicPolicy::class)->view($request->user(), $university->id), 403);
        $base = Student::query()->where('university_id', $university->id);
        $query = (clone $base)->with($this->relations());
        if ($term = trim($data['search'] ?? '')) {
            $like = '%'.mb_strtolower($term).'%';
            $query->where(function ($builder) use ($like): void {
                foreach (['student_number', 'national_reference', 'last_name', 'middle_name', 'first_name', 'email'] as $column) {
                    $builder->orWhereRaw("LOWER(COALESCE({$column}, ?)) LIKE ?", ['', $like]);
                }
                $builder->orWhereHas('user', fn ($user) => $user->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(email) LIKE ?', [$like]));
            });
        }
        if (isset($data['promotion_id'])) $query->whereHas('enrollments', fn ($item) => $item->where('status', 'ACTIVE')->where('promotion_id', $data['promotion_id']));
        if (isset($data['program_id'])) $query->whereHas('enrollments', fn ($item) => $item->where('status', 'ACTIVE')->whereHas('promotion', fn ($promotion) => $promotion->where('program_id', $data['program_id'])));
        if (isset($data['academic_year_id'])) $query->whereHas('enrollments', fn ($item) => $item->where('status', 'ACTIVE')->whereHas('promotion', fn ($promotion) => $promotion->where('academic_year_reference_id', $data['academic_year_id'])));
        if (isset($data['status'])) $query->where('status', $data['status']);
        if (($data['account'] ?? null) === 'WITH_ACCOUNT') $query->whereNotNull('user_id');
        if (($data['account'] ?? null) === 'WITHOUT_ACCOUNT') $query->whereNull('user_id');
        $page = $query->orderBy('last_name')->orderBy('student_number')->paginate($data['per_page'] ?? 25);
        return StudentManagementResource::collection($page)->additional(['statistics' => ['total' => (clone $base)->count(), 'active' => (clone $base)->where('status', 'ACTIVE')->count(), 'suspended' => (clone $base)->where('status', 'SUSPENDED')->count(), 'archived' => (clone $base)->where('status', 'ARCHIVED')->count(), 'with_account' => (clone $base)->whereNotNull('user_id')->count(), 'without_account' => (clone $base)->whereNull('user_id')->count()]]);
    }

    public function show(Request $request, Student $student): StudentManagementResource
    {
        abort_unless(app(AcademicPolicy::class)->viewStudent($request->user(), $student), 403);
        return new StudentManagementResource($student->load($this->relations()));
    }

    public function academicRecord(Request $request, Student $student): JsonResponse
    {
        abort_unless(app(AcademicPolicy::class)->viewStudent($request->user(), $student), 403);
        $student->load($this->relations());
        $activePromotionId = $student->enrollments->firstWhere('status', 'ACTIVE')?->promotion_id;
        $campaigns = Campaign::query()->where('university_id', $student->university_id)
            ->whereHas('promotions', fn ($query) => $query->whereKey($activePromotionId ?? 0))
            ->with(['academicYear', 'hospitals.hospital'])->orderByDesc('starts_at')->get()
            ->map(fn (Campaign $campaign) => ['id' => $campaign->public_id, 'name' => $campaign->name, 'status' => $campaign->status, 'starts_at' => $campaign->starts_at, 'ends_at' => $campaign->ends_at, 'academic_year' => $campaign->academicYear?->label, 'applied' => DB::table('applications')->where('campaign_id', $campaign->id)->where('student_id', $student->id)->exists()]);
        $internships = DB::table('internships')->join('institutions', 'institutions.id', '=', 'internships.hospital_id')->where('internships.student_id', $student->id)
            ->leftJoin('academic_decisions', 'academic_decisions.internship_id', '=', 'internships.id')
            ->select(['internships.public_id as id', 'institutions.name as hospital', 'internships.starts_on', 'internships.ends_on', 'internships.status', 'academic_decisions.decision', 'academic_decisions.final_score'])->orderByDesc('internships.starts_on')->get();
        return response()->json(['data' => ['student' => (new StudentManagementResource($student))->resolve($request), 'eligible_campaigns' => $campaigns, 'internships' => $internships]]);
    }

    public function store(Request $request): StudentManagementResource
    {
        $data = $this->validateStudent($request, true);
        $university = $this->university($data['university_id']);
        abort_unless(app(AcademicPolicy::class)->manage($request->user(), $university->id), 403);
        $promotion = $this->promotion($data['promotion_id'], $university);
        $this->uniqueNumber($university, $data['student_number']);
        $student = DB::transaction(function () use ($data, $university, $promotion): Student {
            $student = Student::create($this->attributes($data, $university));
            $student->enrollments()->create(['promotion_id' => $promotion->id, 'status' => 'ACTIVE', 'enrolled_at' => now()]);
            return $student;
        });
        return new StudentManagementResource($student->load($this->relations()));
    }

    public function update(Request $request, Student $student): StudentManagementResource
    {
        abort_unless(app(AcademicPolicy::class)->manage($request->user(), $student->university_id), 403);
        $data = $this->validateStudent($request, false);
        $promotion = $this->promotion($data['promotion_id'], $student->university);
        $this->uniqueNumber($student->university, $data['student_number'], $student);
        DB::transaction(function () use ($student, $data, $promotion): void {
            $student->update($this->attributes($data, $student->university, $student));
            $active = $student->enrollments()->where('status', 'ACTIVE')->first();
            if (! $active || $active->promotion_id !== $promotion->id) {
                $student->enrollments()->where('status', 'ACTIVE')->update(['status' => 'TRANSFERRED', 'completed_at' => now()]);
                $previous = $student->enrollments()->where('promotion_id', $promotion->id)->first();
                $previous ? $previous->update(['status' => 'ACTIVE', 'enrolled_at' => now(), 'completed_at' => null]) : $student->enrollments()->create(['promotion_id' => $promotion->id, 'status' => 'ACTIVE', 'enrolled_at' => now()]);
            }
        });
        return new StudentManagementResource($student->fresh()->load($this->relations()));
    }

    public function status(Request $request, Student $student): JsonResponse
    {
        abort_unless(app(AcademicPolicy::class)->manage($request->user(), $student->university_id), 403);
        $data = $request->validate(['status' => ['required', Rule::in(['ACTIVE', 'SUSPENDED', 'ARCHIVED'])]]);
        $student->update($data);
        return response()->json(['data' => ['id' => $student->public_id, 'status' => $student->status]]);
    }

    private function validateStudent(Request $request, bool $create): array
    {
        return $request->validate(['university_id' => [$create ? 'required' : 'sometimes', 'uuid'], 'user_id' => ['nullable', 'uuid', 'exists:users,public_id'], 'national_reference' => ['nullable', 'string', 'max:150'], 'student_number' => ['required', 'string', 'max:100'], 'last_name' => ['nullable', 'string', 'max:100'], 'middle_name' => ['nullable', 'string', 'max:100'], 'first_name' => ['nullable', 'string', 'max:100'], 'gender' => ['nullable', Rule::in(['MALE', 'FEMALE'])], 'birth_date' => ['nullable', 'date', 'before:today'], 'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:30'], 'promotion_id' => ['required', 'integer', 'exists:promotions,id']]);
    }

    private function attributes(array $data, Institution $university, ?Student $student = null): array
    {
        return ['university_id' => $university->id, 'user_id' => isset($data['user_id']) ? User::where('public_id', $data['user_id'])->value('id') : $student?->user_id, 'national_reference' => $data['national_reference'] ?? null, 'student_number' => mb_strtoupper(trim($data['student_number'])), 'last_name' => isset($data['last_name']) ? trim($data['last_name']) : null, 'middle_name' => isset($data['middle_name']) ? trim($data['middle_name']) : null, 'first_name' => isset($data['first_name']) ? trim($data['first_name']) : null, 'gender' => $data['gender'] ?? null, 'birth_date' => $data['birth_date'] ?? null, 'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null, 'status' => $student?->status ?? 'ACTIVE'];
    }

    private function university(string $id): Institution { return Institution::where('public_id', $id)->where('type', 'UNIVERSITY')->firstOrFail(); }
    private function promotion(int $id, Institution $university): Promotion { $promotion = Promotion::with('program')->findOrFail($id); abort_unless($promotion->program->university_id === $university->id, 422, 'La promotion n’appartient pas à cette université.'); return $promotion; }
    private function uniqueNumber(Institution $university, string $number, ?Student $except = null): void { $query = Student::where('university_id', $university->id)->whereRaw('UPPER(student_number) = ?', [mb_strtoupper(trim($number))]); if ($except) $query->whereKeyNot($except->id); if ($query->exists()) throw ValidationException::withMessages(['student_number' => 'Ce matricule existe déjà dans cette université.']); }
    private function relations(): array { return ['user', 'university', 'enrollments' => fn ($query) => $query->with(['promotion.program', 'promotion.level', 'promotion.academicYear'])->latest('enrolled_at')]; }
}
