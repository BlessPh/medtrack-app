<?php

namespace Tests\Feature\Administration;

use App\Modules\Academic\Models\AcademicLevel;
use App\Modules\Academic\Models\AcademicProgram;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Campaign;
use App\Modules\Academic\Models\Promotion;
use App\Modules\Academic\Models\Student;
use App\Modules\Auth\Models\User;
use App\Modules\Institution\Models\Institution;
use App\Modules\Institution\Models\InstitutionUnit;
use App\Shared\Enums\InstitutionRole;
use Database\Seeders\AcademicLevelSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_academic_catalog_can_fill_selection_lists(): void
    {
        $this->seed(DatabaseSeeder::class);
        $manager = User::query()->where('email', 'academic.manager@medtrack.test')->firstOrFail();
        $university = Institution::query()->where('registration_number', 'DEMO-UNIVERSITY-001')->firstOrFail();

        $this->actingAs($manager)
            ->getJson("/api/v1/academic/years?university_id={$university->public_id}")
            ->assertOk()->assertJsonCount(3, 'data');
        $this->getJson("/api/v1/academic/programs?university_id={$university->public_id}")
            ->assertOk()->assertJsonCount(3, 'data');
        $this->getJson("/api/v1/academic/promotions?university_id={$university->public_id}")
            ->assertOk()->assertJsonCount(13, 'data');
        $this->getJson("/api/v1/academic/current-context?university_id={$university->public_id}")
            ->assertOk()
            ->assertJsonPath('data.university.id', $university->public_id)
            ->assertJsonPath('data.academic_year.label', '2026-2027')
            ->assertJsonCount(3, 'data.programs')
            ->assertJsonCount(13, 'data.promotions');
    }

    public function test_only_super_admin_manages_the_global_current_academic_year(): void
    {
        $manager = User::factory()->create();
        [$manager, $university] = $this->academicManager();
        $this->actingAs($manager)->postJson('/api/v1/academic/years', [
            'label' => '2028-2029', 'starts_on' => '2028-09-01', 'ends_on' => '2029-08-31',
        ])->assertForbidden();

        $admin = User::factory()->create();
        $this->assignSuperAdmin($admin);
        $year = $this->actingAs($admin)->postJson('/api/v1/academic/years', [
            'label' => '2028-2029', 'starts_on' => '2028-09-01', 'ends_on' => '2029-08-31',
        ])->assertCreated()->json('data');
        $this->patchJson("/api/v1/academic/years/{$year['id']}/current")->assertOk()->assertJsonPath('data.is_current', true);
        $this->assertDatabaseHas('academic_year_references', ['label' => '2028-2029', 'is_current' => true]);
    }

    public function test_dashboard_distinguishes_current_and_delayed_promotions(): void
    {
        $this->seed(AcademicLevelSeeder::class);
        [$manager, $university] = $this->academicManager();
        $past = AcademicYear::create(['label' => '2025-2026', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31', 'status' => 'PAST', 'is_current' => false]);
        $current = AcademicYear::create(['label' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31', 'status' => 'CURRENT', 'is_current' => true]);
        $program = AcademicProgram::create(['university_id' => $university->id, 'code' => 'BIO', 'name' => 'Sciences biomédicales', 'status' => 'ACTIVE']);
        $levels = AcademicLevel::query()->take(2)->get();
        Promotion::create(['program_id' => $program->id, 'level_id' => $levels[0]->id, 'academic_year_id' => null, 'academic_year_reference_id' => $current->id, 'name' => 'L1 Biomed', 'status' => 'ACTIVE']);
        Promotion::create(['program_id' => $program->id, 'level_id' => $levels[1]->id, 'academic_year_id' => null, 'academic_year_reference_id' => $past->id, 'name' => 'L2 Biomed', 'status' => 'ACTIVE']);

        $this->actingAs($manager)->getJson("/api/v1/academic/dashboard?university_id={$university->public_id}")
            ->assertOk()->assertJsonPath('data.current_year.label', '2026-2027')
            ->assertJsonPath('data.stats.promotions_current', 1)
            ->assertJsonPath('data.stats.promotions_delayed', 1)
            ->assertJsonPath('data.delayed_promotions.0.name', 'L2 Biomed');
    }

    public function test_academic_manager_can_create_student_with_enrollment(): void
    {
        $this->seed(AcademicLevelSeeder::class);
        [$manager, $university] = $this->academicManager();
        $year = AcademicYear::create(['label' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31', 'status' => 'CURRENT', 'is_current' => true]);
        $program = AcademicProgram::create(['university_id' => $university->id, 'code' => 'MED', 'name' => 'Médecine', 'status' => 'ACTIVE']);
        $promotion = Promotion::create(['program_id' => $program->id, 'level_id' => AcademicLevel::first()->id, 'academic_year_reference_id' => $year->id, 'academic_year_id' => null, 'name' => 'L1', 'status' => 'ACTIVE']);

        $this->actingAs($manager)->postJson('/api/v1/academic/students', ['university_id' => $university->public_id, 'student_number' => 'ST-001', 'promotion_id' => $promotion->id])->assertCreated()->assertJsonPath('data.student_number', 'ST-001');
        $this->assertDatabaseHas('enrollments', ['promotion_id' => $promotion->id, 'status' => 'ACTIVE']);
    }

    public function test_eligibility_requires_active_enrollment_and_open_campaign(): void
    {
        $this->seed(AcademicLevelSeeder::class);
        [$manager, $university] = $this->academicManager();
        $year = AcademicYear::create(['label' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'CURRENT', 'is_current' => true]);
        $program = AcademicProgram::create(['university_id' => $university->id, 'code' => 'MED', 'name' => 'Médecine', 'status' => 'ACTIVE']);
        $promotion = Promotion::create(['program_id' => $program->id, 'level_id' => AcademicLevel::first()->id, 'academic_year_reference_id' => $year->id, 'academic_year_id' => null, 'name' => 'L1', 'status' => 'ACTIVE']);
        $student = Student::factory()->create(['university_id' => $university->id]);
        $student->enrollments()->create(['promotion_id' => $promotion->id, 'status' => 'ACTIVE', 'enrolled_at' => now()]);
        $campaign = Campaign::create(['university_id' => $university->id, 'academic_year_reference_id' => $year->id, 'academic_year_id' => null, 'name' => 'Stage', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'status' => 'OPEN']);
        $campaign->promotions()->attach($promotion);

        $this->actingAs($manager)->getJson("/api/v1/academic/campaigns/{$campaign->id}/students/{$student->public_id}/eligibility")->assertOk()->assertJsonPath('data.eligible', true);
    }

    public function test_manager_cannot_enroll_student_in_another_university_promotion(): void
    {
        $this->seed(AcademicLevelSeeder::class);
        [$manager, $university] = $this->academicManager();
        $other = Institution::factory()->create(['type' => 'UNIVERSITY']);
        $year = AcademicYear::create(['label' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'CURRENT', 'is_current' => true]);
        $program = AcademicProgram::create(['university_id' => $other->id, 'code' => 'MED', 'name' => 'Médecine', 'status' => 'ACTIVE']);
        $promotion = Promotion::create(['program_id' => $program->id, 'level_id' => AcademicLevel::first()->id, 'academic_year_reference_id' => $year->id, 'academic_year_id' => null, 'name' => 'L1', 'status' => 'ACTIVE']);

        $this->actingAs($manager)->postJson('/api/v1/academic/students', ['university_id' => $university->public_id, 'student_number' => 'ST-X', 'promotion_id' => $promotion->id])->assertUnprocessable();
    }

    public function test_program_can_only_use_a_department_from_its_university(): void
    {
        [$manager, $university] = $this->academicManager();
        $department = InstitutionUnit::create(['institution_id' => $university->id, 'type' => 'DEPARTMENT', 'code' => 'GYNE', 'name' => 'Gynécologie', 'status' => 'ACTIVE']);
        $other = Institution::factory()->create(['type' => 'UNIVERSITY']);
        $foreignDepartment = InstitutionUnit::create(['institution_id' => $other->id, 'type' => 'DEPARTMENT', 'code' => 'CHIR', 'name' => 'Chirurgie', 'status' => 'ACTIVE']);

        $programId = $this->actingAs($manager)->postJson('/api/v1/academic/programs', [
            'university_id' => $university->public_id, 'faculty_unit_id' => $department->id,
            'code' => 'MED-GYNE', 'name' => 'Médecine - Gynécologie',
        ])->assertCreated()->json('data.id');
        $this->putJson("/api/v1/academic/programs/{$programId}", [
            'faculty_unit_id' => $foreignDepartment->id, 'code' => 'MED-GYNE', 'name' => 'Programme invalide',
        ])->assertUnprocessable();
        $this->putJson("/api/v1/academic/programs/{$programId}", [
            'faculty_unit_id' => $department->id, 'code' => 'MED-GYNE', 'name' => 'Programme actualisé', 'status' => 'ACTIVE',
        ])->assertOk();

        $this->assertDatabaseHas('academic_programs', ['id' => $programId, 'faculty_unit_id' => $department->id, 'name' => 'Programme actualisé']);
    }

    public function test_manager_can_consult_and_update_a_promotion_without_deleting_it(): void
    {
        $this->seed(AcademicLevelSeeder::class);
        [$manager, $university] = $this->academicManager();
        $year = AcademicYear::create(['label' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31', 'status' => 'CURRENT', 'is_current' => true]);
        $program = AcademicProgram::create(['university_id' => $university->id, 'code' => 'MED', 'name' => 'Médecine', 'status' => 'ACTIVE']);
        $promotion = Promotion::create(['program_id' => $program->id, 'level_id' => AcademicLevel::first()->id, 'academic_year_reference_id' => $year->id, 'academic_year_id' => null, 'name' => 'L1 Médecine', 'status' => 'ACTIVE']);
        $student = Student::factory()->create(['university_id' => $university->id]);
        $student->enrollments()->create(['promotion_id' => $promotion->id, 'status' => 'ACTIVE', 'enrolled_at' => now()]);

        $this->actingAs($manager)->getJson("/api/v1/academic/promotions/{$promotion->id}")
            ->assertOk()->assertJsonPath('data.enrollments_count', 1);
        $this->putJson("/api/v1/academic/promotions/{$promotion->id}", [
            'program_id' => $program->id, 'level_id' => AcademicLevel::first()->id,
            'academic_year_id' => $year->id, 'name' => 'L1 Médecine A', 'status' => 'INACTIVE',
        ])->assertOk()->assertJsonPath('data.status', 'INACTIVE');
        $this->getJson("/api/v1/academic/students?university_id={$university->public_id}&promotion_id={$promotion->id}")
            ->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson("/api/v1/academic/promotions/{$promotion->id}")->assertMethodNotAllowed();
        $this->assertDatabaseHas('promotions', ['id' => $promotion->id, 'name' => 'L1 Médecine A']);
    }

    private function academicManager(): array
    {
        $manager = User::factory()->create();
        $university = Institution::factory()->create(['type' => 'UNIVERSITY']);
        $this->assignInstitutionRole($manager, $university, InstitutionRole::AcademicManager->value);

        return [$manager, $university];
    }
}
