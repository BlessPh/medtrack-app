<?php

namespace Tests\Feature\Administration;

use App\Modules\Auth\Models\User;
use App\Shared\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_active_user_can_login_read_profile_update_and_logout(): void
    {
        $user = User::factory()->create(['password' => 'correct-password-123', 'status' => UserStatus::Active]);

        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct-password-123'])
            ->assertOk()
            ->assertJsonPath('data.token_type', 'bearer')
            ->assertJsonPath('data.user.email', $user->email)
            ->json('data.access_token');
        $this->withToken($token);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $user->public_id);
        $this->putJson('/api/v1/auth/profile', ['name' => 'Nom modifié', 'city' => 'Kinshasa'])->assertOk()->assertJsonPath('data.name', 'Nom modifié');
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_a_suspended_user_cannot_login(): void
    {
        $user = User::factory()->create(['password' => 'correct-password-123', 'status' => UserStatus::Suspended]);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct-password-123'])->assertForbidden();
    }

    public function test_forgot_password_never_reveals_if_account_exists(): void
    {
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'unknown@example.com'])->assertOk();
    }

    public function test_user_can_upload_replace_read_and_delete_a_private_avatar(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->post('/api/v1/auth/profile/avatar', [
            'avatar' => $this->png('avatar.png'),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.profile.avatar_url', url('/api/v1/auth/profile/avatar'));

        $storedPath = $user->fresh()->profile->avatar_url;
        Storage::disk('local')->assertExists($storedPath);
        $this->get('/api/v1/auth/profile/avatar')->assertOk()->assertHeader('content-type', 'image/png');

        $this->post('/api/v1/auth/profile/avatar', [
            'avatar' => $this->png('replacement.png'),
        ], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('local')->assertMissing($storedPath);

        $replacementPath = $user->fresh()->profile->avatar_url;
        $this->deleteJson('/api/v1/auth/profile/avatar')->assertNoContent();
        Storage::disk('local')->assertMissing($replacementPath);
        $this->assertNull($user->fresh()->profile->avatar_url);
    }

    public function test_avatar_rejects_invalid_files_and_requires_authentication(): void
    {
        Storage::fake('local');
        $this->post('/api/v1/auth/profile/avatar', [
            'avatar' => $this->png('avatar.png'),
        ], ['Accept' => 'application/json'])->assertUnauthorized();

        $this->actingAs(User::factory()->create())->post('/api/v1/auth/profile/avatar', [
            'avatar' => UploadedFile::fake()->create('script.svg', 10, 'image/svg+xml'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    private function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true),
        );
    }
}
