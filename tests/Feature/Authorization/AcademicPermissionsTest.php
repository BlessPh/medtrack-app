<?php

namespace Tests\Feature\Authorization;

use App\Modules\Academic\Models\Student;
use App\Modules\Auth\Models\User;
use App\Modules\Institution\Models\Institution;
use App\Shared\Enums\InstitutionRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_academic_manager_and_institution_admin_manage_only_their_university(): void
    {
        $own = Institution::factory()->create(['type' => 'UNIVERSITY']);
        $other = Institution::factory()->create(['type' => 'UNIVERSITY']);

        foreach ([InstitutionRole::AcademicManager, InstitutionRole::Admin] as $role) {
            $user = User::factory()->create();
            $this->assignInstitutionRole($user, $own, $role->value);

            $this->actingAs($user)->postJson('/api/v1/academic/programs', [
                'university_id' => $own->public_id,
                'code' => 'MED-'.$role->name,
                'name' => 'Médecine',
            ])->assertCreated();

            $this->getJson("/api/v1/academic/programs?university_id={$other->public_id}")
                ->assertForbidden();
        }
    }

    public function test_super_admin_supervises_but_cannot_manage_daily_academic_data(): void
    {
        $superAdmin = User::factory()->create();
        $university = Institution::factory()->create(['type' => 'UNIVERSITY']);
        $this->assignSuperAdmin($superAdmin);

        $this->actingAs($superAdmin)
            ->getJson("/api/v1/academic/programs?university_id={$university->public_id}")
            ->assertOk();

        $this->postJson('/api/v1/academic/programs', [
            'university_id' => $university->public_id,
            'code' => 'MED',
            'name' => 'Médecine',
        ])->assertForbidden();
    }

    public function test_finance_officer_has_limited_catalog_read_access(): void
    {
        $officer = User::factory()->create();
        $university = Institution::factory()->create(['type' => 'UNIVERSITY']);
        $this->assignInstitutionRole($officer, $university, InstitutionRole::FinanceOfficer->value);

        $this->actingAs($officer)
            ->getJson("/api/v1/academic/programs?university_id={$university->public_id}")
            ->assertOk();
        $this->getJson("/api/v1/academic/students?university_id={$university->public_id}")
            ->assertForbidden();
        $this->postJson('/api/v1/academic/programs', [
            'university_id' => $university->public_id,
            'code' => 'MED',
            'name' => 'Médecine',
        ])->assertForbidden();
    }

    public function test_student_can_read_only_their_own_academic_record(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $university = Institution::factory()->create(['type' => 'UNIVERSITY']);
        $student = Student::factory()->create(['user_id' => $user->id, 'university_id' => $university->id]);
        $otherStudent = Student::factory()->create(['user_id' => $otherUser->id, 'university_id' => $university->id]);
        $this->assignInstitutionRole($user, $university, InstitutionRole::Student->value);

        $this->actingAs($user)
            ->getJson("/api/v1/academic/students/{$student->public_id}")
            ->assertOk();
        $this->getJson("/api/v1/academic/students/{$student->public_id}/academic-record")
            ->assertOk();
        $this->getJson("/api/v1/academic/students/{$otherStudent->public_id}")
            ->assertForbidden();
        $this->getJson("/api/v1/academic/programs?university_id={$university->public_id}")
            ->assertForbidden();
    }
}
