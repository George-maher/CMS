<?php

namespace Tests\Feature;

use App\Enums\QRInviteType;
use App\Enums\UserRole;
use App\Models\Church;
use App\Models\ChurchApplication;
use App\Models\QRInvite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_via_invite(): void
    {
        $church = Church::factory()->create();
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $church->id,
        ]);

        $invite = QRInvite::create([
            'type' => QRInviteType::ServantToMemberInvite,
            'token' => str_repeat('a', 64),
            'created_by' => $admin->id,
            'church_id' => $church->id,
            'expires_at' => now()->addHours(4),
            'is_single_use' => true,
            'max_uses' => 1,
            'use_count' => 0,
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test Member',
            'email' => 'member@test.com',
            'password' => 'Test@1234',
            'password_confirmation' => 'Test@1234',
            'invite_token' => $invite->token,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => ['user' => ['id', 'name', 'email', 'role']],
            ]);

        $this->assertEquals('member', $response->json('data.user.role'));
        $this->assertArrayNotHasKey('token', $response->json('data'));
    }

    public function test_user_cannot_register_without_invite(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test Member',
            'email' => 'member@test.com',
            'password' => 'Test@1234',
            'password_confirmation' => 'Test@1234',
        ]);

        $response->assertStatus(422);
    }

    public function test_user_cannot_register_with_expired_invite(): void
    {
        $church = Church::factory()->create();
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $church->id,
        ]);

        $invite = QRInvite::create([
            'type' => QRInviteType::ServantToMemberInvite,
            'token' => str_repeat('b', 64),
            'created_by' => $admin->id,
            'church_id' => $church->id,
            'expires_at' => now()->subHour(),
            'is_single_use' => true,
            'max_uses' => 1,
            'use_count' => 0,
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test Member',
            'email' => 'member@test.com',
            'password' => 'Test@1234',
            'password_confirmation' => 'Test@1234',
            'invite_token' => $invite->token,
        ]);

        $response->assertStatus(422);
    }

    public function test_user_cannot_register_with_used_invite(): void
    {
        $church = Church::factory()->create();
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $church->id,
        ]);

        $invite = QRInvite::create([
            'type' => QRInviteType::ServantToMemberInvite,
            'token' => str_repeat('c', 64),
            'created_by' => $admin->id,
            'church_id' => $church->id,
            'expires_at' => now()->addHours(4),
            'is_single_use' => true,
            'max_uses' => 1,
            'use_count' => 1,
            'used_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test Member',
            'email' => 'member@test.com',
            'password' => 'Test@1234',
            'password_confirmation' => 'Test@1234',
            'invite_token' => $invite->token,
        ]);

        $response->assertStatus(422);
    }

    public function test_user_can_login(): void
    {
        $user = User::factory()->create([
            'email' => 'login@test.com',
            'password' => bcrypt('Test@1234'),
            'application_status' => 'approved',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'login@test.com',
            'password' => 'Test@1234',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['user', 'token', 'token_type'],
            ]);
    }

    public function test_login_fails_with_wrong_credentials(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'nonexistent@test.com',
            'password' => 'WrongPass1!',
        ]);

        $response->assertStatus(422);
    }

    public function test_user_with_placeholder_like_email_local_part_can_login(): void
    {
        // Placeholder checks belong to registration, not login. A real account
        // whose email local part looks like a placeholder must still be able
        // to authenticate.
        $user = User::factory()->create([
            'email' => 'user@mail.com',
            'password' => bcrypt('Test@1234'),
            'application_status' => 'approved',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'USER@mail.com',
            'password' => 'Test@1234',
        ])->assertStatus(200);
    }

    public function test_rejected_user_can_login(): void
    {
        User::factory()->create([
            'email' => 'rejected@test.com',
            'password' => bcrypt('Test@1234'),
            'application_status' => 'rejected',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'rejected@test.com',
            'password' => 'Test@1234',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['user', 'token', 'token_type'],
            ]);
    }

    public function test_pending_user_can_login(): void
    {
        User::factory()->create([
            'email' => 'pending@test.com',
            'password' => bcrypt('Test@1234'),
            'application_status' => 'pending',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'pending@test.com',
            'password' => 'Test@1234',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['user', 'token', 'token_type'],
            ]);
    }

    public function test_authenticated_user_can_access_protected_route(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/auth/me');

        $response->assertStatus(200);
    }

    public function test_unauthenticated_user_cannot_access_protected_route(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
    }

    public function test_registration_does_not_return_token(): void
    {
        $church = Church::factory()->create();
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $church->id,
        ]);

        $invite = QRInvite::create([
            'type' => QRInviteType::ServantToMemberInvite,
            'token' => str_repeat('d', 64),
            'created_by' => $admin->id,
            'church_id' => $church->id,
            'expires_at' => now()->addHours(4),
            'is_single_use' => true,
            'max_uses' => 1,
            'use_count' => 0,
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'NoToken',
            'email' => 'notoken@test.com',
            'password' => 'Test@1234',
            'password_confirmation' => 'Test@1234',
            'invite_token' => $invite->token,
        ]);

        $response->assertStatus(201);
        $response->assertJsonMissingPath('data.token');
    }

    public function test_member_can_login(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Member,
            'email' => 'member-login@test.com',
            'password' => bcrypt('Test@1234'),
            'application_status' => 'approved',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'member-login@test.com',
            'password' => 'Test@1234',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.user.role', 'member');
    }

    public function test_servant_can_login(): void
    {
        $church = Church::factory()->create();
        $user = User::factory()->create([
            'role' => UserRole::Servant,
            'email' => 'servant-login@test.com',
            'password' => bcrypt('Test@1234'),
            'application_status' => 'approved',
            'church_id' => $church->id,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'servant-login@test.com',
            'password' => 'Test@1234',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.user.role', 'servant');
    }

    public function test_admin_can_login(): void
    {
        $church = Church::factory()->create();
        $user = User::factory()->create([
            'role' => UserRole::Admin,
            'email' => 'admin-login@test.com',
            'password' => bcrypt('Test@1234'),
            'application_status' => 'approved',
            'church_id' => $church->id,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin-login@test.com',
            'password' => 'Test@1234',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.user.role', 'admin');
    }

    public function test_assistant_admin_can_login(): void
    {
        $church = Church::factory()->create();
        $user = User::factory()->create([
            'role' => UserRole::AssistantAdmin,
            'email' => 'assistant-admin-login@test.com',
            'password' => bcrypt('Test@1234'),
            'application_status' => 'approved',
            'church_id' => $church->id,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'assistant-admin-login@test.com',
            'password' => 'Test@1234',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.user.role', 'assistant_admin');
    }

    public function test_platform_admin_cannot_login_via_regular_endpoint(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::PlatformAdmin,
            'email' => 'platform-admin-login@test.com',
            'password' => bcrypt('Test@1234'),
            'application_status' => 'approved',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'platform-admin-login@test.com',
            'password' => 'Test@1234',
        ]);

        $response->assertStatus(422);
    }

    public function test_pending_login_returns_restricted_access_state(): void
    {
        $user = User::factory()->create([
            'email' => 'pending-access@test.com',
            'password' => bcrypt('Test@1234'),
            'application_status' => 'pending',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'pending-access@test.com',
            'password' => 'Test@1234',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.application_status', 'pending')
            ->assertJsonPath('data.access_state', 'restricted')
            ->assertJsonStructure(['data' => ['user', 'token', 'token_type']]);
    }

    public function test_rejected_login_returns_actual_rejection_reason(): void
    {
        /** @var ChurchApplication $application */
        $application = ChurchApplication::factory()->rejected('Church name does not match the ID documents.')->create();

        User::factory()->create([
            'church_application_id' => $application->id,
            'email' => 'rejected-reason@test.com',
            'password' => bcrypt('Test@1234'),
            'application_status' => 'rejected',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'rejected-reason@test.com',
            'password' => 'Test@1234',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.application_status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Church name does not match the ID documents.')
            ->assertJsonPath('data.access_state', 'restricted');
    }

    public function test_pending_user_can_access_status_but_not_dashboard(): void
    {
        $user = User::factory()->create([
            'application_status' => 'pending',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);
        $token = $user->createToken('test', ['admin'])->plainTextToken;

        $status = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/application/status');

        $status->assertStatus(200);

        $dashboard = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/dashboard/stats');

        $dashboard->assertStatus(403);
    }

    public function test_approved_user_can_access_dashboard(): void
    {
        $church = Church::factory()->create();
        $user = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $church->id,
            'application_status' => 'approved',
            'is_active' => true,
        ]);
        $token = $user->createToken('test', ['admin'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/dashboard/stats')
            ->assertStatus(200);
    }
}
