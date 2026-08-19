<?php

namespace Tests\Feature\Administration;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Models\InstitutionAccountRequest;
use App\Modules\Institution\Models\Institution;
use App\Shared\Enums\InstitutionRole;
use App\Shared\Services\InstitutionAccess;
use App\Modules\Institution\Models\InstitutionMemberInvitation;
use App\Modules\Institution\Notifications\InstitutionMemberInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InstitutionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_create_an_institution(): void
    {
        $admin = User::factory()->create();
        $this->assignSuperAdmin($admin);
        $this->actingAs($admin)->postJson('/api/v1/institutions', ['type' => 'UNIVERSITY', 'name' => 'Université Test', 'registration_number' => 'UNI-001'])->assertCreated()->assertJsonPath('data.status', 'PENDING');
    }

    public function test_institution_admin_can_update_only_own_institution(): void
    {
        $user = User::factory()->create();
        $own = Institution::factory()->create();
        $other = Institution::factory()->create();
        $this->assignInstitutionRole($user, $own, InstitutionRole::Admin->value);

        $this->actingAs($user)->putJson("/api/v1/institutions/{$own->public_id}", ['name' => 'Nouveau nom'])->assertOk();
        $this->actingAs($user)->putJson("/api/v1/institutions/{$other->public_id}", ['name' => 'Interdit'])->assertForbidden();
        $this->actingAs($user)->putJson("/api/v1/institutions/{$own->public_id}", ['type' => 'HOSPITAL', 'name' => 'Interdit'])->assertUnprocessable();
    }

    public function test_institution_admin_can_manage_details_but_not_status(): void
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->create();
        $this->assignInstitutionRole($user, $institution, InstitutionRole::Admin->value);

        $this->actingAs($user)->postJson("/api/v1/institutions/{$institution->public_id}/addresses", [
            'address_line' => '1 avenue Test', 'city' => 'Kinshasa', 'country' => 'CD', 'is_primary' => true,
        ])->assertCreated();
        $this->actingAs($user)->patchJson("/api/v1/institutions/{$institution->public_id}/status", ['status' => 'ACTIVE'])->assertForbidden();
        $this->assertDatabaseHas('institution_addresses', ['institution_id' => $institution->id, 'city' => 'Kinshasa']);
    }

    public function test_institution_admin_can_update_an_address_and_upload_a_private_logo(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $institution = Institution::factory()->create(['type' => 'UNIVERSITY']);
        $this->assignInstitutionRole($user, $institution, InstitutionRole::Admin->value);
        $address = $institution->addresses()->create(['address_line' => 'Ancienne adresse', 'city' => 'Kinshasa', 'country' => 'CD']);

        $this->actingAs($user)->putJson("/api/v1/institutions/{$institution->public_id}/addresses/{$address->id}", [
            'address_line' => '12 avenue des Cliniques', 'commune' => 'Lemba', 'city' => 'Kinshasa', 'country' => 'CD', 'is_primary' => true,
        ])->assertOk()->assertJsonPath('data.commune', 'Lemba');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        $this->post("/api/v1/institutions/{$institution->public_id}/logo", ['logo' => UploadedFile::fake()->createWithContent('logo.png', $png)], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.mime_type', 'image/png');

        $media = $institution->media()->firstOrFail();
        $this->assertSame("Logo de {$institution->name}", $media->display_name);
        Storage::disk('local')->assertExists($media->path);
        $this->assertStringStartsWith("institutions/{$institution->public_id}/logos/", $media->path);
        $this->get("/api/v1/institutions/{$institution->public_id}/logo")->assertOk()->assertHeader('content-type', 'image/png');
    }

    public function test_hospital_addresses_keep_one_primary_address_and_require_complete_coordinates(): void
    {
        $admin = User::factory()->create();
        $hospital = Institution::factory()->create(['type' => 'HOSPITAL']);
        $this->assignInstitutionRole($admin, $hospital, InstitutionRole::Admin->value);

        $firstId = $this->actingAs($admin)->postJson("/api/v1/institutions/{$hospital->public_id}/addresses", [
            'label' => 'Site principal', 'address_line' => '1 avenue de la Santé',
            'commune' => 'Lemba', 'city' => 'Kinshasa', 'province' => 'Kinshasa',
            'country' => 'CD', 'latitude' => -4.325, 'longitude' => 15.322,
        ])->assertCreated()->assertJsonPath('data.is_primary', true)->json('data.id');

        $secondId = $this->postJson("/api/v1/institutions/{$hospital->public_id}/addresses", [
            'label' => 'Annexe', 'address_line' => '2 avenue des Urgences',
            'city' => 'Kinshasa', 'country' => 'CD', 'is_primary' => true,
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('institution_addresses', ['id' => $firstId, 'is_primary' => false]);
        $this->deleteJson("/api/v1/institutions/{$hospital->public_id}/addresses/{$secondId}")->assertNoContent();
        $this->assertDatabaseHas('institution_addresses', ['id' => $firstId, 'is_primary' => true]);

        $this->postJson("/api/v1/institutions/{$hospital->public_id}/addresses", [
            'address_line' => 'Adresse incomplète', 'city' => 'Kinshasa',
            'country' => 'CD', 'latitude' => -4.325,
        ])->assertUnprocessable()->assertJsonValidationErrors('longitude');
    }

    public function test_hospital_admin_manages_primary_contacts_and_service_departments(): void
    {
        $admin = User::factory()->create();
        $hospital = Institution::factory()->create(['type' => 'HOSPITAL']);
        $this->assignInstitutionRole($admin, $hospital, InstitutionRole::Admin->value);

        $emailId = $this->actingAs($admin)->postJson("/api/v1/institutions/{$hospital->public_id}/contacts", [
            'type' => 'EMAIL', 'value' => 'direction@hopital.test', 'label' => 'Direction',
        ])->assertCreated()->assertJsonPath('data.is_primary', true)->json('data.id');
        $phoneId = $this->postJson("/api/v1/institutions/{$hospital->public_id}/contacts", [
            'type' => 'WHATSAPP', 'value' => '+243 810 000 000', 'label' => 'Urgences', 'is_primary' => true,
        ])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('institution_contacts', ['id' => $emailId, 'is_primary' => false]);
        $this->deleteJson("/api/v1/institutions/{$hospital->public_id}/contacts/{$phoneId}")->assertNoContent();
        $this->assertDatabaseHas('institution_contacts', ['id' => $emailId, 'is_primary' => true]);
        $this->postJson("/api/v1/institutions/{$hospital->public_id}/contacts", [
            'type' => 'EMAIL', 'value' => 'adresse-invalide',
        ])->assertUnprocessable()->assertJsonValidationErrors('value');

        $serviceId = $this->postJson("/api/v1/institutions/{$hospital->public_id}/units", [
            'type' => 'SERVICE', 'code' => 'PED', 'name' => 'Pédiatrie',
        ])->assertCreated()->json('data.id');
        $departmentId = $this->postJson("/api/v1/institutions/{$hospital->public_id}/units", [
            'type' => 'DEPARTMENT', 'parent_id' => $serviceId, 'code' => 'PED-HOSP', 'name' => 'Hospitalisation',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/institutions/{$hospital->public_id}/units", [
            'type' => 'DEPARTMENT', 'name' => 'Département sans service',
        ])->assertUnprocessable();
        $this->deleteJson("/api/v1/institutions/{$hospital->public_id}/units/{$serviceId}")->assertUnprocessable();
        $this->deleteJson("/api/v1/institutions/{$hospital->public_id}/units/{$departmentId}")->assertNoContent();
        $this->deleteJson("/api/v1/institutions/{$hospital->public_id}/units/{$serviceId}")->assertNoContent();
    }

    public function test_an_invited_person_can_create_an_account_and_receives_the_contextual_role(): void
    {
        Notification::fake();
        $admin = User::factory()->create();
        $institution = Institution::factory()->create(['type' => 'UNIVERSITY']);
        $this->assignInstitutionRole($admin, $institution, InstitutionRole::Admin->value);

        $this->actingAs($admin)->postJson("/api/v1/institutions/{$institution->public_id}/member-invitations", [
            'email' => 'invitee@example.test', 'role' => InstitutionRole::AcademicManager->value,
        ])->assertCreated();
        Notification::assertSentOnDemand(InstitutionMemberInvitationNotification::class);

        $token = 'known-secure-invitation-token';
        InstitutionMemberInvitation::query()->update(['token_hash' => hash('sha256', $token)]);
        $this->getJson("/api/v1/auth/member-invitations/{$token}")->assertOk()->assertJsonPath('data.email', 'invitee@example.test');
        $response = $this->postJson("/api/v1/auth/member-invitations/{$token}/register", [
            'name' => 'Membre Invité', 'phone' => '+243810000000', 'password' => 'StrongPassword123', 'password_confirmation' => 'StrongPassword123',
        ])->assertCreated();

        $user = User::where('email', 'invitee@example.test')->firstOrFail();
        $this->assertTrue($institution->members()->whereKey($user->id)->exists());
        $this->assertSame([InstitutionRole::AcademicManager->value], app(InstitutionAccess::class)->rolesFor($user, $institution->id));
        $response->assertJsonPath('data.id', $user->public_id);
    }

    public function test_institution_admin_can_manage_contacts_and_contextual_units(): void
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->create(['type' => 'UNIVERSITY']);
        $this->assignInstitutionRole($user, $institution, InstitutionRole::Admin->value);

        $departmentId = $this->actingAs($user)->postJson("/api/v1/institutions/{$institution->public_id}/units", [
            'type' => 'DEPARTMENT', 'code' => 'PED', 'name' => 'Pédiatrie',
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/institutions/{$institution->public_id}/units", [
            'type' => 'SERVICE', 'name' => 'Type incorrect',
        ])->assertUnprocessable();
        $this->putJson("/api/v1/institutions/{$institution->public_id}/units/{$departmentId}", [
            'type' => 'DEPARTMENT', 'code' => 'PED', 'name' => 'Pédiatrie clinique', 'status' => 'ACTIVE',
        ])->assertOk();

        $contactId = $this->postJson("/api/v1/institutions/{$institution->public_id}/contacts", [
            'type' => 'EMAIL', 'value' => 'contact@universite.cd', 'is_primary' => true,
        ])->assertCreated()->json('data.id');
        $this->putJson("/api/v1/institutions/{$institution->public_id}/contacts/{$contactId}", [
            'type' => 'EMAIL', 'value' => 'secretariat@universite.cd', 'label' => 'Secrétariat', 'is_primary' => true,
        ])->assertOk();

        $this->assertDatabaseHas('institution_units', ['id' => $departmentId, 'type' => 'DEPARTMENT', 'name' => 'Pédiatrie clinique']);
        $this->assertDatabaseHas('institution_contacts', ['id' => $contactId, 'value' => 'secretariat@universite.cd']);
    }

    public function test_institution_admin_can_manage_members_by_email_and_send_an_announcement(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create(['email' => 'finance.member@medtrack.test']);
        $institution = Institution::factory()->create(['type' => 'UNIVERSITY']);
        $this->assignInstitutionRole($admin, $institution, InstitutionRole::Admin->value);

        $this->actingAs($admin)->postJson("/api/v1/institutions/{$institution->public_id}/members", [
            'email' => $member->email, 'role' => InstitutionRole::FinanceOfficer->value,
        ])->assertCreated();
        $this->getJson("/api/v1/institutions/{$institution->public_id}/members")
            ->assertOk()->assertJsonFragment(['email' => $member->email])
            ->assertJsonFragment(['roles' => [InstitutionRole::FinanceOfficer->value]]);
        $this->putJson("/api/v1/institutions/{$institution->public_id}/members/{$member->public_id}", [
            'role' => InstitutionRole::Member->value,
        ])->assertOk();
        $this->postJson("/api/v1/institutions/{$institution->public_id}/notifications", [
            'title' => 'Réunion', 'message' => 'Réunion institutionnelle demain.', 'severity' => 'INFO',
        ])->assertCreated()->assertJsonPath('data.recipients_count', 2);

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $member->id]);
    }

    public function test_member_can_hold_multiple_institution_roles_and_student_role_is_exclusive(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create(['email' => 'multi.role@medtrack.test']);
        $hospital = Institution::factory()->create(['type' => 'HOSPITAL']);
        $this->assignInstitutionRole($admin, $hospital, InstitutionRole::Admin->value);

        $this->actingAs($admin)->postJson("/api/v1/institutions/{$hospital->public_id}/members", [
            'email' => $member->email,
            'roles' => [InstitutionRole::Admin->value, InstitutionRole::HospitalManager->value],
        ])->assertCreated();
        $this->assertEqualsCanonicalizing(
            [InstitutionRole::Admin->value, InstitutionRole::HospitalManager->value],
            app(InstitutionAccess::class)->rolesFor($member, $hospital->id),
        );
        $this->putJson("/api/v1/institutions/{$hospital->public_id}/members/{$member->public_id}", [
            'roles' => [InstitutionRole::HospitalManager->value],
        ])->assertOk();
        $this->assertSame([InstitutionRole::HospitalManager->value], app(InstitutionAccess::class)->rolesFor($member, $hospital->id));

        $student = User::factory()->create();
        $this->assignInstitutionRole($student, $hospital, InstitutionRole::Student->value);
        $this->actingAs($admin)->postJson("/api/v1/institutions/{$hospital->public_id}/members", [
            'email' => $student->email, 'roles' => [InstitutionRole::Member->value],
        ])->assertUnprocessable()->assertJsonValidationErrors('roles');
    }

    public function test_approved_admin_uses_request_type_when_creating_own_institution(): void
    {
        $user = User::factory()->create();
        app(InstitutionAccess::class)->assignBootstrapRole($user, InstitutionRole::Admin->value);
        InstitutionAccountRequest::create([
            'reference' => 'MTK-TEST-CONTEXT', 'institution_type' => 'HOSPITAL',
            'institution_name' => 'Hôpital Contexte', 'last_name' => 'Test', 'first_name' => 'Admin',
            'email' => $user->email, 'phone' => '+243900000001', 'job_title' => 'Directeur',
            'password_hash' => bcrypt('password'), 'status' => 'APPROVED',
            'created_user_id' => $user->id, 'reviewed_at' => now(),
        ]);

        $this->actingAs($user)->postJson('/api/v1/institutions', [
            'type' => 'UNIVERSITY', 'name' => 'Hôpital Contexte', 'legal_form' => 'Établissement public',
        ])->assertCreated()->assertJsonPath('data.type', 'HOSPITAL')->assertJsonPath('data.legal_form', 'Établissement public');

        $this->assertDatabaseHas('institutions', ['type' => 'HOSPITAL', 'name' => 'Hôpital Contexte']);
    }
    public function test_hospital_admin_can_view_a_scoped_operational_dashboard(): void
    {
        $admin = User::factory()->create();
        $supervisor = User::factory()->create();
        $hospital = Institution::factory()->create(['type' => 'HOSPITAL', 'status' => 'ACTIVE']);
        $other = Institution::factory()->create(['type' => 'HOSPITAL']);
        $this->assignInstitutionRole($admin, $hospital, InstitutionRole::Admin->value);
        $this->assignInstitutionRole($supervisor, $hospital, InstitutionRole::Supervisor->value);
        $hospital->units()->create(['type' => 'SERVICE', 'name' => 'Pédiatrie', 'status' => 'ACTIVE']);

        $this->actingAs($admin)->getJson("/api/v1/institutions/{$hospital->public_id}/dashboard")
            ->assertOk()
            ->assertJsonPath('data.institution.type', 'HOSPITAL')
            ->assertJsonPath('data.statistics.services', 1)
            ->assertJsonPath('data.statistics.members', 2)
            ->assertJsonPath('data.statistics.supervisors', 1)
            ->assertJsonStructure(['data' => [
                'configuration' => ['completion', 'missing'],
                'statistics' => ['active_internships', 'pending_applications', 'pending_d4_requests', 'total_capacity', 'reserved_capacity', 'available_capacity'],
                'recent_notifications',
            ]]);

        $this->actingAs($admin)->getJson("/api/v1/institutions/{$other->public_id}/dashboard")->assertForbidden();
    }

    public function test_hospital_admin_can_configure_supervisors_and_their_services(): void
    {
        $admin = User::factory()->create();
        $supervisor = User::factory()->create();
        $hospital = Institution::factory()->create(['type' => 'HOSPITAL']);
        $otherHospital = Institution::factory()->create(['type' => 'HOSPITAL']);
        $this->assignInstitutionRole($admin, $hospital, InstitutionRole::Admin->value);
        $this->assignInstitutionRole($supervisor, $hospital, InstitutionRole::Supervisor->value);
        $service = $hospital->units()->create(['type' => 'SERVICE', 'name' => 'Pédiatrie', 'status' => 'ACTIVE']);
        $department = $hospital->units()->create(['type' => 'DEPARTMENT', 'parent_id' => $service->id, 'name' => 'Hospitalisation', 'status' => 'ACTIVE']);

        $this->actingAs($admin)->getJson("/api/v1/institutions/{$hospital->public_id}/supervisors")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $supervisor->public_id)
            ->assertJsonPath('data.0.availability_status', 'AVAILABLE')
            ->assertJsonPath('data.0.active_internships_count', 0);

        $this->putJson("/api/v1/institutions/{$hospital->public_id}/supervisors/{$supervisor->public_id}", [
            'service_ids' => [$service->id],
            'availability_status' => 'LIMITED',
            'availability_note' => 'Disponible trois jours par semaine.',
            'stages_enabled' => true,
        ])->assertOk()->assertJsonPath('data.availability_status', 'LIMITED');
        $this->assertDatabaseHas('hospital_supervisor_profiles', [
            'institution_id' => $hospital->id,
            'user_id' => $supervisor->id,
            'availability_status' => 'LIMITED',
        ]);
        $this->assertDatabaseHas('hospital_supervisor_services', ['institution_unit_id' => $service->id]);

        $this->putJson("/api/v1/institutions/{$hospital->public_id}/supervisors/{$supervisor->public_id}", [
            'service_ids' => [$department->id],
            'availability_status' => 'AVAILABLE',
            'stages_enabled' => true,
        ])->assertUnprocessable();
        $this->getJson("/api/v1/institutions/{$otherHospital->public_id}/supervisors")->assertForbidden();
    }

    public function test_hospital_manager_reads_supervisors_but_needs_admin_role_to_modify_them(): void
    {
        $manager = User::factory()->create();
        $supervisor = User::factory()->create();
        $hospital = Institution::factory()->create(['type' => 'HOSPITAL']);
        $this->assignInstitutionRole($manager, $hospital, InstitutionRole::HospitalManager->value);
        $this->assignInstitutionRole($supervisor, $hospital, InstitutionRole::Supervisor->value);

        $payload = ['service_ids' => [], 'availability_status' => 'AVAILABLE', 'availability_note' => null, 'stages_enabled' => true];
        $this->actingAs($manager)->getJson("/api/v1/institutions/{$hospital->public_id}/supervisors")->assertOk();
        $this->putJson("/api/v1/institutions/{$hospital->public_id}/supervisors/{$supervisor->public_id}", $payload)->assertForbidden();

        app(InstitutionAccess::class)->assign($manager, $hospital->id, InstitutionRole::Admin->value);
        $this->putJson("/api/v1/institutions/{$hospital->public_id}/supervisors/{$supervisor->public_id}", $payload)->assertOk();
    }

    public function test_hospital_admin_cannot_remove_self_and_role_change_cleans_supervisor_profile(): void
    {
        $admin = User::factory()->create();
        $supervisor = User::factory()->create();
        $hospital = Institution::factory()->create(['type' => 'HOSPITAL']);
        $this->assignInstitutionRole($admin, $hospital, InstitutionRole::Admin->value);
        $this->assignInstitutionRole($supervisor, $hospital, InstitutionRole::Supervisor->value);
        $profile = \App\Modules\Institution\Models\HospitalSupervisorProfile::create([
            'institution_id' => $hospital->id,
            'user_id' => $supervisor->id,
            'availability_status' => 'AVAILABLE',
            'stages_enabled' => true,
        ]);

        $this->actingAs($admin)->deleteJson("/api/v1/institutions/{$hospital->public_id}/members/{$admin->public_id}")
            ->assertStatus(409);
        $this->putJson("/api/v1/institutions/{$hospital->public_id}/members/{$supervisor->public_id}", [
            'role' => InstitutionRole::Member->value,
        ])->assertOk();
        $this->assertDatabaseMissing('hospital_supervisor_profiles', ['id' => $profile->id]);
    }

    public function test_hospital_notification_can_target_a_role_and_service_and_is_audited(): void
    {
        Notification::fake();
        $admin = User::factory()->create();
        $supervisor = User::factory()->create();
        $otherSupervisor = User::factory()->create();
        $hospital = Institution::factory()->create(['type' => 'HOSPITAL']);
        $this->assignInstitutionRole($admin, $hospital, InstitutionRole::Admin->value);
        $this->assignInstitutionRole($supervisor, $hospital, InstitutionRole::Supervisor->value);
        $this->assignInstitutionRole($otherSupervisor, $hospital, InstitutionRole::Supervisor->value);
        $service = $hospital->units()->create(['type' => 'SERVICE', 'name' => 'Urgences', 'status' => 'ACTIVE']);
        $profile = \App\Modules\Institution\Models\HospitalSupervisorProfile::create([
            'institution_id' => $hospital->id, 'user_id' => $supervisor->id,
            'availability_status' => 'AVAILABLE', 'stages_enabled' => true,
        ]);
        $profile->services()->attach($service);

        $this->actingAs($admin)->postJson("/api/v1/institutions/{$hospital->public_id}/notifications", [
            'title' => 'Réunion des urgences',
            'message' => 'Briefing demain matin.',
            'role' => InstitutionRole::Supervisor->value,
            'service_id' => $service->id,
        ])->assertCreated()->assertJsonPath('data.recipients_count', 1);

        Notification::assertSentTo($supervisor, \App\Modules\Notification\Notifications\InstitutionNotification::class);
        Notification::assertNotSentTo($otherSupervisor, \App\Modules\Notification\Notifications\InstitutionNotification::class);
        $this->assertDatabaseHas('institution_audit_logs', [
            'institution_id' => $hospital->id,
            'actor_user_id' => $admin->id,
            'action' => 'NOTIFICATION_SENT',
        ]);
    }

    public function test_institution_admin_can_suspend_and_reactivate_a_member_without_disabling_global_account(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $hospital = Institution::factory()->create(['type' => 'HOSPITAL']);
        $this->assignInstitutionRole($admin, $hospital, InstitutionRole::Admin->value);
        $this->assignInstitutionRole($member, $hospital, InstitutionRole::Member->value);

        $this->actingAs($admin)->patchJson("/api/v1/institutions/{$hospital->public_id}/members/{$member->public_id}/status", [
            'status' => 'SUSPENDED', 'reason' => 'Accès temporairement suspendu.',
        ])->assertOk();
        $this->assertDatabaseHas('institution_memberships', [
            'institution_id' => $hospital->id, 'user_id' => $member->id,
            'status' => 'SUSPENDED', 'suspension_reason' => 'Accès temporairement suspendu.',
        ]);
        $this->actingAs($member)->getJson("/api/v1/institutions/{$hospital->public_id}")->assertForbidden();
        $this->assertSame('ACTIVE', $member->fresh()->status->value);

        $this->actingAs($admin)->patchJson("/api/v1/institutions/{$hospital->public_id}/members/{$member->public_id}/status", [
            'status' => 'ACTIVE',
        ])->assertOk();
        $this->actingAs($member)->getJson("/api/v1/institutions/{$hospital->public_id}")->assertOk();
        $this->assertDatabaseHas('institution_audit_logs', ['action' => 'MEMBER_SUSPENDED', 'subject_id' => $member->public_id]);
        $this->assertDatabaseHas('institution_audit_logs', ['action' => 'MEMBER_REACTIVATED', 'subject_id' => $member->public_id]);
    }

    public function test_hospital_admin_has_read_only_scoped_governance_views(): void
    {
        $admin = User::factory()->create();
        $hospital = Institution::factory()->create(['type' => 'HOSPITAL']);
        $otherHospital = Institution::factory()->create(['type' => 'HOSPITAL']);
        $this->assignInstitutionRole($admin, $hospital, InstitutionRole::Admin->value);

        $this->actingAs($admin)->getJson("/api/v1/institutions/{$hospital->public_id}/oversight")
            ->assertOk()->assertJsonStructure(['data' => ['internships', 'd4_requests', 'finance', 'security' => ['active_admins', 'active_members', 'suspended_members', 'institution_status', 'status_managed_by_super_admin']]])
            ->assertJsonPath('data.security.active_admins', 1);
        $this->getJson("/api/v1/institutions/{$hospital->public_id}/audit-logs")
            ->assertOk()->assertJsonStructure(['data' => ['data', 'current_page', 'total']]);
        $this->getJson("/api/v1/institutions/{$otherHospital->public_id}/oversight")->assertForbidden();
        $this->patchJson("/api/v1/institutions/{$hospital->public_id}/status", ['status' => 'SUSPENDED'])->assertForbidden();
    }
}
