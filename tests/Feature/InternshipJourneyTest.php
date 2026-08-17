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

        $internshipId = $this->actingAs($manager)->postJson('/api/v1/internships', ['admission_id' => $admission->public_id, 'starts_on' => '2026-09-01'])->assertCreated()->json('data.public_id');
        $rotationId = $this->postJson("/api/v1/internships/{$internshipId}/rotations", ['starts_on' => '2026-09-01', 'ends_on' => '2026-09-30'])->assertCreated()->json('data.id');
        $scheduleId = $this->postJson('/api/v1/scheduling/schedules', ['internship_id' => $internshipId, 'name' => 'Septembre', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30'])->assertCreated()->json('data.id');
        $entryId = $this->postJson("/api/v1/scheduling/schedules/{$scheduleId}/entries", ['starts_at' => '2026-09-02 08:00:00', 'ends_at' => '2026-09-02 16:00:00', 'activity_type' => 'ROTATION'])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/scheduling/schedules/{$scheduleId}/publish")->assertOk();
        $this->postJson('/api/v1/scheduling/attendance', ['student_id' => $application->student->public_id, 'schedule_entry_id' => $entryId, 'type' => 'CHECK_IN', 'recorded_at' => '2026-08-02 08:01:00', 'source' => 'MANUAL'])->assertCreated();
        $this->assertDatabaseHas('rotations', ['id' => $rotationId, 'status' => 'PLANNED']);
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
