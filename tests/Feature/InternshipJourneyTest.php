<?php

namespace Tests\Feature;

use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Campaign;
use App\Modules\Academic\Models\CampaignHospital;
use App\Modules\Academic\Models\Student;
use App\Modules\Admission\Models\Application;
use App\Modules\Admission\Models\CapacityPool;
use App\Modules\Admission\Services\AdmissionService;
use App\Modules\Assessment\Models\EvaluationTemplate;
use App\Modules\Auth\Models\User;
use App\Modules\Institution\Models\Institution;
use App\Modules\Institution\Models\InstitutionUnit;
use App\Modules\Internship\Models\Internship;
use App\Modules\Internship\Models\Rotation;
use App\Modules\Notification\Notifications\AdmissionCreatedNotification;
use App\Shared\Enums\InstitutionRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InternshipJourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_capacity_cannot_be_overbooked(): void
    {
        [$application, $pool] = $this->admissionContext();
        $admission = app(AdmissionService::class)->accept($application, $pool);
        $this->assertSame('ACCEPTED', $admission->status);
        $this->assertDatabaseHas('capacity_pools', ['id' => $pool->id, 'reserved_places' => 1]);
        $this->assertDatabaseHas('notifications', ['type' => AdmissionCreatedNotification::class]);

        $secondStudent = Student::factory()->create(['university_id' => $application->campaign->university_id]);
        $second = Application::create(['campaign_id' => $application->campaign_id, 'student_id' => $secondStudent->id, 'status' => 'SUBMITTED']);
        $this->expectException(ValidationException::class);
        app(AdmissionService::class)->accept($second, $pool);
    }

    public function test_hospital_manager_creates_internship_rotation_schedule_and_attendance(): void
    {
        [$application, $pool, $hospital] = $this->admissionContext();
        $admission = app(AdmissionService::class)->accept($application, $pool);
        $manager = User::factory()->create();
        $this->assignInstitutionRole($manager, $hospital, InstitutionRole::HospitalManager->value);

        $this->actingAs($manager)->getJson("/api/v1/internships/templates?hospital_id={$hospital->public_id}")->assertOk();

        $internshipId = $this->actingAs($manager)->postJson('/api/v1/internships', ['admission_id' => $admission->public_id, 'starts_on' => '2026-09-01'])->assertCreated()->json('data.public_id');
        $rotationId = $this->postJson("/api/v1/internships/{$internshipId}/rotations", ['starts_on' => '2026-09-01', 'ends_on' => '2026-09-30'])->assertCreated()->json('data.id');
        $scheduleId = $this->postJson('/api/v1/scheduling/schedules', ['internship_id' => $internshipId, 'name' => 'Septembre', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30'])->assertCreated()->json('data.id');
        $entryId = $this->postJson("/api/v1/scheduling/schedules/{$scheduleId}/entries", ['starts_at' => '2026-09-02 08:00:00', 'ends_at' => '2026-09-02 16:00:00', 'activity_type' => 'ROTATION'])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/scheduling/schedules/{$scheduleId}/publish")->assertOk();
        $this->postJson('/api/v1/scheduling/attendance', ['student_id' => $application->student->public_id, 'schedule_entry_id' => $entryId, 'type' => 'CHECK_IN', 'recorded_at' => '2026-08-02 08:01:00', 'source' => 'MANUAL'])->assertCreated();
        $this->assertDatabaseHas('rotations', ['id' => $rotationId, 'status' => 'PLANNED']);
        $this->getJson("/api/v1/internships/monitoring?hospital_id={$hospital->public_id}")
            ->assertOk()->assertJsonPath('data.0.check_ins', 1)->assertJsonPath('data.0.evaluations_total', 0);
    }

    public function test_hospital_manager_can_review_only_standard_applications_for_own_hospital(): void
    {
        [$application, , $hospital] = $this->admissionContext();
        $application->update(['preferred_hospital_id' => $hospital->id]);
        $service = InstitutionUnit::create(['institution_id' => $hospital->id, 'type' => 'SERVICE', 'name' => 'Pédiatrie', 'code' => 'PED', 'status' => 'ACTIVE']);
        $manager = User::factory()->create();
        $this->assignInstitutionRole($manager, $hospital, InstitutionRole::HospitalManager->value);

        $this->actingAs($manager)->getJson("/api/v1/admissions/hospital-applications?hospital_id={$hospital->public_id}")
            ->assertOk()->assertJsonCount(1, 'data.data');
        $this->patchJson("/api/v1/admissions/applications/{$application->public_id}/hospital-decision", [
            'decision' => 'ACCEPTED', 'service_id' => $service->id,
            'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'internal_note' => 'Dossier complet.',
        ])->assertOk()->assertJsonPath('data.status', 'ACCEPTED');

        $this->assertDatabaseHas('admissions', ['application_id' => $application->id, 'hospital_id' => $hospital->id]);
        $this->assertDatabaseHas('applications', ['id' => $application->id, 'assigned_service_id' => $service->id, 'internal_note' => 'Dossier complet.']);
    }

    public function test_hospital_manager_cannot_review_another_hospitals_application(): void
    {
        [$application, , $hospital] = $this->admissionContext();
        $application->update(['preferred_hospital_id' => $hospital->id]);
        $otherHospital = Institution::factory()->create(['type' => 'HOSPITAL']);
        $manager = User::factory()->create();
        $this->assignInstitutionRole($manager, $otherHospital, InstitutionRole::HospitalManager->value);

        $this->actingAs($manager)->patchJson("/api/v1/admissions/applications/{$application->public_id}/hospital-decision", [
            'decision' => 'REJECTED', 'internal_note' => 'Hors périmètre.',
        ])->assertForbidden();
    }

    public function test_supervisor_can_score_and_finalize_once(): void
    {
        [$application, $pool, $hospital] = $this->admissionContext();
        $admission = app(AdmissionService::class)->accept($application, $pool);
        $supervisor = User::factory()->create();
        $internship = Internship::create(['admission_id' => $admission->id, 'student_id' => $admission->student_id, 'hospital_id' => $hospital->id, 'supervisor_id' => $supervisor->id, 'starts_on' => '2026-09-01', 'status' => 'ACTIVE']);
        $rotation = Rotation::create(['internship_id' => $internship->id, 'supervisor_id' => $supervisor->id, 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'status' => 'COMPLETED']);
        $template = EvaluationTemplate::create(['institution_id' => $hospital->id, 'name' => 'Stage', 'criteria' => [['key' => 'practice', 'maximum' => 60], ['key' => 'conduct', 'maximum' => 40]], 'maximum_score' => 100, 'status' => 'ACTIVE']);

        $evaluationId = $this->actingAs($supervisor)->postJson('/api/v1/assessments/evaluations', ['rotation_id' => $rotation->id, 'template_id' => $template->id, 'answers' => ['practice' => 50, 'conduct' => 35]])->assertCreated()->assertJsonPath('data.score', '85.00')->json('data.public_id');
        $this->patchJson("/api/v1/assessments/evaluations/{$evaluationId}/submit")->assertOk()->assertJsonPath('data.status', 'FINALIZED');
        $this->patchJson("/api/v1/assessments/evaluations/{$evaluationId}/submit")->assertStatus(409);
    }

    public function test_supervisor_dashboard_is_personal_and_observations_are_scoped(): void
    {
        [$application, $pool, $hospital] = $this->admissionContext();
        $admission = app(AdmissionService::class)->accept($application, $pool);
        $supervisor = User::factory()->create();
        $otherSupervisor = User::factory()->create();
        $this->assignInstitutionRole($supervisor, $hospital, InstitutionRole::Supervisor->value);
        $this->assignInstitutionRole($otherSupervisor, $hospital, InstitutionRole::Supervisor->value);
        $internship = Internship::create(['admission_id' => $admission->id, 'student_id' => $admission->student_id, 'hospital_id' => $hospital->id, 'supervisor_id' => $supervisor->id, 'starts_on' => '2026-09-01', 'status' => 'ACTIVE']);

        $this->actingAs($supervisor)->getJson("/api/v1/internships/supervisor/dashboard?hospital_id={$hospital->public_id}")
            ->assertOk()->assertJsonPath('data.statistics.students', 1)->assertJsonCount(1, 'data.internships');
        $this->postJson("/api/v1/internships/{$internship->public_id}/supervisor-observations", ['content' => 'Progression satisfaisante.'])
            ->assertCreated();
        $this->patchJson("/api/v1/internships/supervisor/availability?hospital_id={$hospital->public_id}", ['availability_status' => 'LIMITED', 'availability_note' => 'Disponible le matin.', 'stages_enabled' => true])
            ->assertOk()->assertJsonPath('data.availability_status', 'LIMITED');

        $this->actingAs($otherSupervisor)->postJson("/api/v1/internships/{$internship->public_id}/supervisor-observations", ['content' => 'Accès interdit.'])
            ->assertForbidden();
    }

    private function admissionContext(): array
    {
        $university = Institution::factory()->create(['type' => 'UNIVERSITY']);
        $hospital = Institution::factory()->create(['type' => 'HOSPITAL']);
        $year = AcademicYear::create(['label' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'CURRENT', 'is_current' => true]);
        $campaign = Campaign::create(['university_id' => $university->id, 'academic_year_id' => null, 'academic_year_reference_id' => $year->id, 'name' => 'Stage', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'status' => 'OPEN']);
        $campaignHospital = CampaignHospital::create(['campaign_id' => $campaign->id, 'hospital_id' => $hospital->id, 'capacity' => 1, 'status' => 'ACTIVE']);
        $student = Student::factory()->create(['university_id' => $university->id, 'user_id' => User::factory()->create()->id]);
        $application = Application::create(['campaign_id' => $campaign->id, 'student_id' => $student->id, 'status' => 'SUBMITTED']);
        $pool = CapacityPool::create(['campaign_hospital_id' => $campaignHospital->id, 'total_places' => 1, 'reserved_places' => 0]);

        return [$application, $pool, $hospital];
    }
}
