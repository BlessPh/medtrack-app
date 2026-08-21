<?php

namespace Tests\Feature\Academic;

use App\Modules\Academic\Models\AcademicLevel;
use App\Modules\Academic\Models\AcademicProgram;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Campaign;
use App\Modules\Academic\Models\CampaignHospital;
use App\Modules\Academic\Models\Promotion;
use App\Modules\Academic\Models\Student;
use App\Modules\Auth\Models\User;
use App\Modules\Institution\Models\Institution;
use App\Shared\Enums\InstitutionRole;
use Database\Seeders\AcademicLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class D4CampaignReservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_d4_workflow_cancels_pending_requests_and_creates_an_internship(): void
    {
        $this->seed(AcademicLevelSeeder::class);
        $university = Institution::factory()->create(['type' => 'UNIVERSITY']);
        $hospital = Institution::factory()->create(['type' => 'HOSPITAL']);
        $pendingHospital = Institution::factory()->create(['type' => 'HOSPITAL']);
        $academicManager = User::factory()->create();
        $hospitalManager = User::factory()->create();
        $this->assignInstitutionRole($academicManager, $university, InstitutionRole::AcademicManager->value);
        $this->assignInstitutionRole($hospitalManager, $hospital, InstitutionRole::HospitalManager->value);
        $year = AcademicYear::create(['label' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31', 'status' => 'CURRENT', 'is_current' => true]);
        $program = AcademicProgram::create(['university_id' => $university->id, 'code' => 'MED', 'name' => 'Médecine', 'status' => 'ACTIVE']);
        $level = AcademicLevel::where('code', 'D4')->firstOrFail();
        $promotion = Promotion::create(['program_id' => $program->id, 'level_id' => $level->id, 'academic_year_reference_id' => $year->id, 'academic_year_id' => null, 'name' => 'D4 Médecine', 'status' => 'ACTIVE']);

        $campaignId = $this->actingAs($academicManager)->postJson('/api/v1/academic/campaigns', [
            'university_id' => $university->public_id, 'academic_year_id' => $year->id, 'name' => 'Stage D4',
            'strategy' => 'D4_RESERVATION', 'instructions' => 'Choisir un hôpital.', 'starts_at' => now()->addWeek(),
            'ends_at' => now()->addMonths(3), 'promotion_ids' => [$promotion->id],
            'hospital_ids' => [$hospital->public_id, $pendingHospital->public_id],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/academic/campaigns/{$campaignId}/send-hospital-requests")
            ->assertOk()->assertJsonPath('data.status', 'HOSPITAL_REQUESTS');
        $campaign = Campaign::where('public_id', $campaignId)->firstOrFail();
        $acceptedRequest = CampaignHospital::where('campaign_id', $campaign->id)->where('hospital_id', $hospital->id)->firstOrFail();
        $this->actingAs($hospitalManager)->patchJson("/api/v1/academic/campaign-requests/{$acceptedRequest->public_id}/respond", ['decision' => 'ACCEPTED', 'capacity' => 1])->assertOk();
        $this->actingAs($academicManager)->patchJson("/api/v1/academic/campaigns/{$campaignId}/status", ['status' => 'OPEN'])->assertOk();
        $this->assertDatabaseHas('campaign_hospitals', ['campaign_id' => $campaign->id, 'hospital_id' => $pendingHospital->id, 'request_status' => 'CANCELLED']);

        $first = $this->studentUser($university, $promotion);
        $second = $this->studentUser($university, $promotion);
        $applicationId = $this->actingAs($first)->postJson("/api/v1/academic/campaigns/{$campaignId}/reserve", ['hospital_id' => $hospital->public_id])->assertCreated()->json('data.public_id');
        $this->actingAs($second)->postJson("/api/v1/academic/campaigns/{$campaignId}/reserve", ['hospital_id' => $hospital->public_id])->assertUnprocessable();
        $this->actingAs($hospitalManager)->getJson("/api/v1/academic/campaign-reservations?hospital_id={$hospital->public_id}")->assertOk()->assertJsonPath('data.data.0.public_id', $applicationId);
        $admissionId = $this->postJson("/api/v1/academic/campaign-reservations/{$applicationId}/admit")->assertCreated()->assertJsonPath('data.status', 'ACCEPTED')->json('data.public_id');
        $internshipId = $this->postJson('/api/v1/internships', ['admission_id' => $admissionId, 'starts_on' => now()->toDateString()])->assertCreated()->json('data.public_id');
        $this->patchJson("/api/v1/internships/{$internshipId}/status", ['status' => 'ACTIVE'])->assertOk();

        $this->assertDatabaseHas('capacity_pools', ['total_places' => 1, 'reserved_places' => 1]);
        $this->assertDatabaseHas('capacity_reservations', ['status' => 'CONFIRMED']);
        $this->assertDatabaseHas('internships', ['public_id' => $internshipId, 'status' => 'ACTIVE']);
    }

    private function studentUser(Institution $university, Promotion $promotion): User
    {
        $user = User::factory()->create();
        $student = Student::factory()->create(['user_id' => $user->id, 'university_id' => $university->id]);
        $student->enrollments()->create(['promotion_id' => $promotion->id, 'status' => 'ACTIVE', 'enrolled_at' => now()]);

        return $user;
    }
}
