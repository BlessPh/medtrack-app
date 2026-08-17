<?php

namespace App\Modules\Academic\Controllers;

use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Campaign;
use App\Modules\Academic\Models\Promotion;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Policies\AcademicPolicy;
use App\Modules\Academic\Services\EligibilityService;
use App\Modules\Institution\Models\Institution;
use App\Shared\Services\InstitutionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CampaignController
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => Campaign::query()->where('status', 'OPEN')->with(['promotions', 'hospitals.hospital'])->orderBy('starts_at')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['university_id' => ['required', 'uuid', 'exists:institutions,public_id'], 'academic_year_id' => ['required', 'integer', 'exists:academic_year_references,id'], 'name' => ['required', 'string', 'max:200'], 'regime' => ['nullable', 'string', 'max:40'], 'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'], 'promotion_ids' => ['required', 'array', 'min:1'], 'promotion_ids.*' => ['integer', 'exists:promotions,id']]);
        $institution = Institution::where('public_id', $data['university_id'])->firstOrFail();
        abort_unless(app(AcademicPolicy::class)->manage($request->user(), $institution->id), 403);
        $year = AcademicYear::findOrFail($data['academic_year_id']);
        $validPromotions = Promotion::query()->whereIn('id', $data['promotion_ids'])
            ->whereHas('program', fn ($query) => $query->where('university_id', $institution->id))->count();
        abort_unless($validPromotions === count(array_unique($data['promotion_ids'])), 422, 'Une promotion n’appartient pas à cette université.');
        $campaign = Campaign::create(['university_id' => $institution->id, 'academic_year_id' => null, 'academic_year_reference_id' => $year->id, 'name' => $data['name'], 'regime' => $data['regime'] ?? null, 'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at'], 'status' => 'DRAFT']);
        $campaign->promotions()->sync($data['promotion_ids']);

        return response()->json(['data' => $campaign->load('promotions')], 201);
    }

    public function status(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless(app(AcademicPolicy::class)->manage($request->user(), $campaign->university_id), 403);
        $data = $request->validate(['status' => ['required', 'in:OPEN,CLOSED,CANCELLED']]);
        $allowed = ['DRAFT' => ['OPEN', 'CANCELLED'], 'OPEN' => ['CLOSED', 'CANCELLED']];
        abort_unless(in_array($data['status'], $allowed[$campaign->status] ?? [], true), 409, 'Transition de campagne invalide.');
        $campaign->update($data);

        return response()->json(['data' => $campaign]);
    }

    public function eligibility(Request $request, Campaign $campaign, Student $student, EligibilityService $service): JsonResponse
    {
        abort_unless(app(InstitutionAccess::class)->isSuperAdmin($request->user()) || $student->user_id === $request->user()->id || app(AcademicPolicy::class)->manage($request->user(), $campaign->university_id), 403);

        return response()->json(['data' => ['eligible' => $service->isEligible($student, $campaign)]]);
    }
}
