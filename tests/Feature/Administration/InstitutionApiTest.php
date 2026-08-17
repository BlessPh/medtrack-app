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

        $this->actingAs($user)->putJson("/api/v1/institutions/{$own->public_id}", ['type' => $own->type, 'name' => 'Nouveau nom'])->assertOk();
        $this->actingAs($user)->putJson("/api/v1/institutions/{$other->public_id}", ['type' => $other->type, 'name' => 'Interdit'])->assertForbidden();
    }

    public function test_institution_admin_can_manage_details_but_not_status(): void
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->create();
        $this->assignInstitutionRole($user, $institution, InstitutionRole::Admin->value);

        $this->actingAs($user)->postJson("/api/v1/institutions/{$institution->public_id}/addresses", [
            'address_line' => '1 avenue Test', 'city' => 'Kinshasa', 'is_primary' => true,
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
        Storage::disk('local')->assertExists($media->path);
        $this->assertStringStartsWith("institutions/{$institution->public_id}/logos/", $media->path);
        $this->get("/api/v1/institutions/{$institution->public_id}/logo")->assertOk()->assertHeader('content-type', 'image/png');
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
}
