<?php

namespace App\Modules\Admission\Controllers;

use App\Modules\Academic\Models\Campaign;
use App\Modules\Academic\Models\CampaignHospital;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Services\EligibilityService;
use App\Modules\Admission\Models\Application;
use App\Modules\Admission\Models\CapacityPool;
use App\Modules\Admission\Services\AdmissionService;
use App\Modules\Institution\Models\Institution;
use App\Shared\Enums\InstitutionRole;
use App\Shared\Services\InstitutionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdmissionController
{
    /** Admissions confirmées, toutes stratégies confondues, disponibles pour créer un stage. */
    public function hospitalAdmissions(Request $request, InstitutionAccess $access): JsonResponse
    {
        $data = $request->validate(['hospital_id' => ['required', 'uuid']]);
        $hospital = Institution::where('public_id', $data['hospital_id'])->where('type', 'HOSPITAL')->firstOrFail();
        abort_unless($access->has($request->user(), $hospital->id, [InstitutionRole::HospitalManager->value]), 403);

        $admissions = \App\Modules\Admission\Models\Admission::query()
            ->where('hospital_id', $hospital->id)
            ->where('status', 'ACCEPTED')
            ->with(['student.user.profile', 'student.university', 'application.campaign.academicYear', 'internship'])
            ->latest('admitted_at')
            ->get();

        return response()->json(['data' => $admissions]);
    }

    public function hospitalApplications(Request $request, InstitutionAccess $access): JsonResponse
    {
        $data = $request->validate(['hospital_id' => ['required', 'uuid'], 'status' => ['nullable', 'string']]);
        $hospital = Institution::where('public_id', $data['hospital_id'])->where('type', 'HOSPITAL')->firstOrFail();
        abort_unless($access->has($request->user(), $hospital->id, [InstitutionRole::HospitalManager->value]), 403);
        $query = Application::query()->where('preferred_hospital_id', $hospital->id)
            ->whereHas('campaign', fn ($query) => $query->where('strategy', 'STANDARD'))
            ->with(['student.user.profile', 'student.university', 'campaign.academicYear', 'assignedService', 'admission']);
        $query->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status));

        return response()->json(['data' => $query->latest('submitted_at')->paginate(min($request->integer('per_page', 25), 100))]);
    }

    public function hospitalDecision(Request $request, Application $application, InstitutionAccess $access): JsonResponse
    {
        abort_unless($application->preferred_hospital_id && $access->has($request->user(), $application->preferred_hospital_id, [InstitutionRole::HospitalManager->value]), 403);
        abort_unless($application->campaign->strategy === 'STANDARD', 409, 'Utilisez le workflow D4 pour cette candidature.');
        $data = $request->validate([
            'decision' => ['required', Rule::in(['PENDING', 'ACCEPTED', 'REJECTED'])],
            'service_id' => ['nullable', 'integer'],
            'starts_on' => ['nullable', 'required_if:decision,ACCEPTED', 'date'],
            'ends_on' => ['nullable', 'required_if:decision,ACCEPTED', 'date', 'after_or_equal:starts_on'],
            'internal_note' => ['nullable', 'string', 'max:3000'],
        ]);
        if (isset($data['service_id'])) {
            abort_unless(DB::table('institution_units')->where('id', $data['service_id'])->where('institution_id', $application->preferred_hospital_id)->where('type', 'SERVICE')->where('status', 'ACTIVE')->exists(), 422, 'Service hospitalier invalide.');
        }
        abort_if(in_array($application->status, ['WITHDRAWN', 'CANCELLED'], true), 409, 'Cette candidature n’est plus active.');
        $application->update([
            'status' => $data['decision'] === 'PENDING' ? 'UNDER_REVIEW' : $data['decision'],
            'assigned_service_id' => $data['service_id'] ?? null,
            'proposed_starts_on' => $data['starts_on'] ?? null,
            'proposed_ends_on' => $data['ends_on'] ?? null,
            'internal_note' => $data['internal_note'] ?? null,
            'reviewed_at' => $data['decision'] === 'PENDING' ? null : now(),
            'reviewed_by' => $request->user()->id,
        ]);
        if ($data['decision'] === 'ACCEPTED') {
            \App\Modules\Admission\Models\Admission::firstOrCreate(
                ['application_id' => $application->id],
                ['student_id' => $application->student_id, 'hospital_id' => $application->preferred_hospital_id, 'status' => 'ACCEPTED', 'admitted_at' => now()],
            );
        }

        return response()->json(['data' => $application->fresh()->load(['student.user', 'campaign', 'assignedService', 'admission'])]);
    }
    public function store(Request $request, EligibilityService $eligibility): JsonResponse
    {
        $data = $request->validate(['campaign_id' => ['required', 'uuid', 'exists:campaigns,public_id'], 'preferred_hospital_id' => ['nullable', 'uuid', 'exists:institutions,public_id'], 'motivation' => ['nullable', 'string', 'max:3000']]);
        $student = Student::where('user_id', $request->user()->id)->firstOrFail();
        $campaign = Campaign::where('public_id', $data['campaign_id'])->firstOrFail();
        abort_unless($eligibility->isEligible($student, $campaign), 422, 'Étudiant non éligible à cette campagne.');
        $hospitalId = isset($data['preferred_hospital_id']) ? Institution::where('public_id', $data['preferred_hospital_id'])->value('id') : null;
        if ($hospitalId) {
            if ($campaign->strategy === 'D4_RESERVATION') {
                abort_unless($campaign->hospitals()->where('hospital_id', $hospitalId)->where('request_status', 'ACCEPTED')->exists(), 422, 'Hôpital non disponible pour cette campagne D4.');
            } else {
                abort_unless(Institution::whereKey($hospitalId)->where('type', 'HOSPITAL')->exists(), 422, 'Établissement de santé invalide.');
            }
        }
        $application = Application::create(['campaign_id' => $campaign->id, 'student_id' => $student->id, 'preferred_hospital_id' => $hospitalId, 'motivation' => $data['motivation'] ?? null, 'status' => 'SUBMITTED', 'submitted_at' => now()]);

        return response()->json(['data' => $application], 201);
    }

    public function withdraw(Request $request, Application $application): JsonResponse
    {
        abort_unless($application->student()->where('user_id', $request->user()->id)->exists(), 403);
        abort_unless($application->status === 'SUBMITTED', 409);
        $application->update(['status' => 'WITHDRAWN']);

        return response()->json(['data' => $application]);
    }

    public function reject(Request $request, Application $application, InstitutionAccess $access): JsonResponse
    {
        abort_unless($access->has($request->user(), $application->campaign->university_id, [InstitutionRole::AcademicManager->value]), 403);
        $data = $request->validate(['review_note' => ['required', 'string', 'max:2000']]);
        abort_unless($application->status === 'SUBMITTED', 409);
        $application->update(['status' => 'REJECTED', 'reviewed_at' => now(), 'reviewed_by' => $request->user()->id, 'review_note' => $data['review_note']]);

        return response()->json(['data' => $application]);
    }

    public function accept(Request $request, Application $application, AdmissionService $service, InstitutionAccess $access): JsonResponse
    {
        $data = $request->validate(['capacity_pool_id' => ['required', 'integer', 'exists:capacity_pools,id']]);
        abort_unless($access->has($request->user(), $application->campaign->university_id, [InstitutionRole::AcademicManager->value]), 403);
        $application->forceFill(['reviewed_by' => $request->user()->id])->save();
        $admission = $service->accept($application, CapacityPool::findOrFail($data['capacity_pool_id']));

        return response()->json(['data' => $admission], 201);
    }

    public function storeCapacity(Request $request): JsonResponse
    {
        $data = $request->validate(['campaign_hospital_id' => ['required', 'uuid', 'exists:campaign_hospitals,public_id'], 'level_id' => ['nullable', 'integer', 'exists:academic_levels,id'], 'total_places' => ['required', 'integer', 'min:1']]);
        $hospital = CampaignHospital::where('public_id', $data['campaign_hospital_id'])->firstOrFail();
        abort_unless(app(InstitutionAccess::class)->has($request->user(), $hospital->hospital_id, [InstitutionRole::HospitalManager->value]), 403);

        return response()->json(['data' => CapacityPool::updateOrCreate(['campaign_hospital_id' => $hospital->id, 'level_id' => $data['level_id'] ?? null], ['total_places' => $data['total_places']])], 201);
    }
}
