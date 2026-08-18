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

class AdmissionController
{
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
