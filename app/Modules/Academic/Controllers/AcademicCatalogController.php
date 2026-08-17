<?php

namespace App\Modules\Academic\Controllers;

use App\Modules\Academic\Models\AcademicLevel;
use App\Modules\Academic\Models\AcademicProgram;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Promotion;
use App\Modules\Academic\Policies\AcademicPolicy;
use App\Modules\Institution\Models\Institution;
use App\Modules\Institution\Models\InstitutionUnit;
use App\Shared\Services\InstitutionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AcademicCatalogController
{
    public function currentContext(Request $request): JsonResponse
    {
        $institution = $this->readableUniversity($request);
        $year = AcademicYear::query()->where('is_current', true)->first();

        if (! $year) {
            return response()->json(['data' => [
                'university' => ['id' => $institution->public_id, 'name' => $institution->name],
                'academic_year' => null, 'programs' => [], 'promotions' => [],
            ]]);
        }

        $programs = AcademicProgram::query()->where('university_id', $institution->id)
            ->where('status', 'ACTIVE')->orderBy('name')->get();
        $promotions = Promotion::query()->with(['level', 'program'])
            ->where('academic_year_reference_id', $year->id)
            ->whereIn('program_id', $programs->pluck('id'))
            ->where('status', 'ACTIVE')->orderBy('name')->get();

        return response()->json(['data' => [
            'university' => ['id' => $institution->public_id, 'name' => $institution->name],
            'academic_year' => $year,
            'programs' => $programs,
            'promotions' => $promotions,
        ]]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $institution = $this->readableUniversity($request);
        $current = AcademicYear::query()->where('is_current', true)->first();
        $next = $current ? AcademicYear::query()->where('starts_on', '>', $current->starts_on)->orderBy('starts_on')->first() : null;
        $promotions = Promotion::query()->with(['level', 'program', 'academicYear'])
            ->whereHas('program', fn ($query) => $query->where('university_id', $institution->id))->where('status', 'ACTIVE')->get();
        $currentCount = $current ? $promotions->where('academic_year_reference_id', $current->id)->count() : 0;
        $delayed = $current ? $promotions->filter(fn (Promotion $promotion) => $promotion->academicYear && $promotion->academicYear->starts_on->lt($current->starts_on))->values() : collect();

        return response()->json(['data' => [
            'university' => ['id' => $institution->public_id, 'name' => $institution->name],
            'current_year' => $current, 'next_year' => $next,
            'stats' => [
                'programs' => AcademicProgram::where('university_id', $institution->id)->where('status', 'ACTIVE')->count(),
                'promotions' => $promotions->count(), 'promotions_current' => $currentCount,
                'promotions_delayed' => $delayed->count(),
                'students' => DB::table('students')->where('university_id', $institution->id)->whereNull('deleted_at')->count(),
                'students_without_account' => DB::table('students')->where('university_id', $institution->id)->whereNull('deleted_at')->whereNull('user_id')->count(),
            ],
            'year_distribution' => $promotions->groupBy(fn (Promotion $promotion) => $promotion->academicYear?->label ?? 'Non définie')->map(fn ($items, $label) => ['label' => $label, 'count' => $items->count(), 'is_current' => $current?->label === $label])->values(),
            'delayed_promotions' => $delayed->map(fn (Promotion $promotion) => ['id' => $promotion->id, 'name' => $promotion->name, 'level' => $promotion->level?->code, 'program' => $promotion->program?->name, 'academic_year' => $promotion->academicYear?->label]),
            'warnings' => array_values(array_filter([
                $current ? null : 'Aucune année académique officielle n’est définie.',
                $current && $currentCount === 0 ? 'Aucune promotion n’est encore rattachée à l’année académique en cours.' : null,
                $delayed->isNotEmpty() ? $delayed->count().' promotion(s) sont encore rattachées à une année précédente.' : null,
            ])),
        ]]);
    }

    public function levels(): JsonResponse
    {
        return response()->json(['data' => AcademicLevel::query()->where('status', 'ACTIVE')->orderBy('display_order')->get()]);
    }

    public function programs(Request $request): JsonResponse
    {
        $institution = $this->readableUniversity($request);

        $filters = $request->validate([
            'university_id' => ['required', 'uuid', 'exists:institutions,public_id'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
        ]);

        $query = AcademicProgram::query()
            ->with('department')
            ->withCount('promotions')
            ->where('university_id', $institution->id);
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return response()->json(['data' => $query->orderBy('name')->get()]);
    }

    public function departments(Request $request): JsonResponse
    {
        $institution = $this->readableUniversity($request);

        return response()->json(['data' => InstitutionUnit::query()
            ->where('institution_id', $institution->id)
            ->where('type', 'DEPARTMENT')
            ->withCount('academicPrograms')
            ->orderBy('name')
            ->get()]);
    }

    public function years(Request $request): JsonResponse
    {
        return response()->json(['data' => AcademicYear::query()->orderByDesc('starts_on')->get()]);
    }

    public function promotions(Request $request): JsonResponse
    {
        $institution = $this->readableUniversity($request);
        $filters = $request->validate([
            'program_id' => ['nullable', 'integer'],
            'academic_year_id' => ['nullable', 'integer'],
        ]);
        $query = Promotion::query()->with(['level', 'program.department', 'academicYear'])
            ->withCount('enrollments')
            ->whereHas('program', fn ($builder) => $builder->where('university_id', $institution->id))
            ;
        if (isset($filters['program_id'])) {
            $query->where('program_id', $filters['program_id']);
        }
        if (isset($filters['academic_year_id'])) {
            $query->where('academic_year_reference_id', $filters['academic_year_id']);
        }

        return response()->json(['data' => $query->orderBy('name')->get()]);
    }

    public function showPromotion(Request $request, Promotion $promotion): JsonResponse
    {
        $this->authorizePromotion($request, $promotion);

        return response()->json(['data' => $promotion
            ->load(['level', 'program.department', 'academicYear'])
            ->loadCount('enrollments')]);
    }

    public function storeProgram(Request $request): JsonResponse
    {
        $data = $request->validate(['university_id' => ['required', 'uuid', 'exists:institutions,public_id'], 'faculty_unit_id' => ['nullable', 'integer', 'exists:institution_units,id'], 'code' => ['required', 'string', 'max:100'], 'name' => ['required', 'string', 'max:255'], 'degree_type' => ['nullable', 'string', 'max:50'], 'duration_years' => ['nullable', 'integer', 'between:1,20']]);
        $institution = Institution::where('public_id', $data['university_id'])->firstOrFail();
        abort_unless(app(AcademicPolicy::class)->manage($request->user(), $institution->id), 403);
        $this->ensureDepartmentBelongsTo($data['faculty_unit_id'] ?? null, $institution);
        $data['university_id'] = $institution->id;

        return response()->json(['data' => AcademicProgram::create($data + ['status' => 'ACTIVE'])], 201);
    }

    public function updateProgram(Request $request, AcademicProgram $program): JsonResponse
    {
        abort_unless(app(AcademicPolicy::class)->manage($request->user(), $program->university_id), 403);
        $data = $request->validate(['faculty_unit_id' => ['nullable', 'integer', 'exists:institution_units,id'], 'code' => ['required', 'string', 'max:100'], 'name' => ['required', 'string', 'max:255'], 'degree_type' => ['nullable', 'string', 'max:50'], 'duration_years' => ['nullable', 'integer', 'between:1,20'], 'status' => ['sometimes', 'in:ACTIVE,INACTIVE']]);
        $institution = Institution::findOrFail($program->university_id);
        $this->ensureDepartmentBelongsTo($data['faculty_unit_id'] ?? null, $institution);
        $program->update($data);

        return response()->json(['data' => $program->fresh()]);
    }

    public function destroyProgram(Request $request, AcademicProgram $program): JsonResponse
    {
        abort_unless(app(AcademicPolicy::class)->manage($request->user(), $program->university_id), 403);
        abort_if($program->promotions()->exists(), 422, 'Ce programme est déjà utilisé par une promotion.');
        $program->delete();

        return response()->json(status: 204);
    }

    public function storeYear(Request $request): JsonResponse
    {
        abort_unless(app(InstitutionAccess::class)->isSuperAdmin($request->user()), 403);
        $data = $request->validate(['label' => ['required', 'string', 'max:30', 'unique:academic_year_references,label'], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after:starts_on']]);

        return response()->json(['data' => AcademicYear::create($data + ['status' => 'UPCOMING', 'is_current' => false])], 201);
    }

    public function updateYear(Request $request, AcademicYear $year): JsonResponse
    {
        abort_unless(app(InstitutionAccess::class)->isSuperAdmin($request->user()), 403);
        $data = $request->validate(['label' => ['required', 'string', 'max:30', 'unique:academic_year_references,label,'.$year->id], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after:starts_on']]);
        $year->update($data);

        return response()->json(['data' => $year->fresh()]);
    }

    public function setCurrentYear(Request $request, AcademicYear $year): JsonResponse
    {
        abort_unless(app(InstitutionAccess::class)->isSuperAdmin($request->user()), 403);
        DB::transaction(function () use ($year): void {
            AcademicYear::query()->whereKeyNot($year->id)->update(['is_current' => false]);
            AcademicYear::query()->where('starts_on', '<', $year->starts_on)->update(['status' => 'PAST']);
            AcademicYear::query()->where('starts_on', '>', $year->starts_on)->update(['status' => 'UPCOMING']);
            $year->update(['is_current' => true, 'status' => 'CURRENT']);
        });

        return response()->json(['data' => $year->fresh()]);
    }

    public function storePromotion(Request $request): JsonResponse
    {
        $data = $request->validate(['program_id' => ['required', 'integer', 'exists:academic_programs,id'], 'level_id' => ['required', 'integer', 'exists:academic_levels,id'], 'academic_year_id' => ['required', 'integer', 'exists:academic_year_references,id'], 'name' => ['required', 'string', 'max:150']]);
        $program = AcademicProgram::findOrFail($data['program_id']);
        abort_unless(app(AcademicPolicy::class)->manage($request->user(), $program->university_id), 403);
        $data['academic_year_reference_id'] = AcademicYear::findOrFail($data['academic_year_id'])->id;
        unset($data['academic_year_id']);

        return response()->json(['data' => Promotion::create($data + ['academic_year_id' => null, 'status' => 'ACTIVE'])], 201);
    }

    public function updatePromotion(Request $request, Promotion $promotion): JsonResponse
    {
        $this->authorizePromotion($request, $promotion, true);
        $data = $request->validate([
            'program_id' => ['required', 'integer', 'exists:academic_programs,id'],
            'level_id' => ['required', 'integer', 'exists:academic_levels,id'],
            'academic_year_id' => ['required', 'integer', 'exists:academic_year_references,id'],
            'name' => ['required', 'string', 'max:150'],
            'status' => ['sometimes', 'in:ACTIVE,INACTIVE'],
        ]);
        $program = AcademicProgram::findOrFail($data['program_id']);
        abort_unless(app(AcademicPolicy::class)->manage($request->user(), $program->university_id), 403);
        abort_unless($program->university_id === $promotion->program->university_id, 422, 'Le programme sélectionné n’appartient pas à cette université.');
        $data['academic_year_reference_id'] = $data['academic_year_id'];
        unset($data['academic_year_id']);
        $promotion->update($data);

        return response()->json(['data' => $promotion->fresh()->load(['level', 'program.department', 'academicYear'])->loadCount('enrollments')]);
    }

    private function authorizePromotion(Request $request, Promotion $promotion, bool $manage = false): Institution
    {
        $institution = $promotion->program->university;
        abort_unless(
            $manage
                ? app(AcademicPolicy::class)->manage($request->user(), $institution->id)
                : app(AcademicPolicy::class)->viewCatalog($request->user(), $institution->id),
            403
        );

        return $institution;
    }

    private function readableUniversity(Request $request): Institution
    {
        $data = $request->validate(['university_id' => ['required', 'uuid', 'exists:institutions,public_id']]);
        $institution = Institution::query()->where('public_id', $data['university_id'])->where('type', 'UNIVERSITY')->firstOrFail();
        abort_unless(app(AcademicPolicy::class)->viewCatalog($request->user(), $institution->id), 403);

        return $institution;
    }

    private function ensureDepartmentBelongsTo(?int $unitId, Institution $institution): void
    {
        if ($unitId === null) {
            return;
        }
        abort_unless(InstitutionUnit::query()->whereKey($unitId)->where('institution_id', $institution->id)->where('type', 'DEPARTMENT')->exists(), 422, 'Le département sélectionné n’appartient pas à cette université.');
    }
}
