<?php

namespace Tests\Feature\Authorization;

use App\Modules\Auth\Models\User;
use App\Modules\Institution\Models\Institution;
use App\Shared\Enums\InstitutionRole;
use App\Shared\Services\InstitutionAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpatieRoleManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_have_different_roles_in_different_institutions(): void
    {
        $user = User::factory()->create();
        $university = Institution::factory()->create(['type' => 'UNIVERSITY']);
        $hospital = Institution::factory()->create(['type' => 'HOSPITAL']);

        $this->assignInstitutionRole($user, $university, InstitutionRole::AcademicManager->value);
        $this->assignInstitutionRole($user, $hospital, InstitutionRole::Supervisor->value);

        $access = app(InstitutionAccess::class);
        $this->assertTrue($access->has($user, $university->id, [InstitutionRole::AcademicManager->value]));
        $this->assertFalse($access->has($user, $university->id, [InstitutionRole::Supervisor->value]));
        $this->assertTrue($access->has($user, $hospital->id, [InstitutionRole::Supervisor->value]));
    }

    public function test_super_admin_bypasses_institution_role_checks(): void
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->create();
        $this->assignSuperAdmin($user);

        $this->assertTrue(app(InstitutionAccess::class)->has(
            $user,
            $institution->id,
            [InstitutionRole::FinanceOfficer->value],
        ));
    }

    public function test_me_exposes_all_institution_role_assignments_for_frontend_routing(): void
    {
        $user = User::factory()->create();
        $university = Institution::factory()->create(['type' => 'UNIVERSITY']);
        $hospital = Institution::factory()->create(['type' => 'HOSPITAL']);
        $this->assignInstitutionRole($user, $university, InstitutionRole::AcademicManager->value);
        $this->assignInstitutionRole($user, $hospital, InstitutionRole::Supervisor->value);

        $this->actingAs($user)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonFragment(['roles' => [InstitutionRole::AcademicManager->value]])
            ->assertJsonFragment(['roles' => [InstitutionRole::Supervisor->value]]);
    }
}
