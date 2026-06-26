<?php

namespace Tests\Feature;

use App\Enums\QRInviteType;
use App\Enums\UserRole;
use App\Models\Church;
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

    public function test_rejected_user_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'rejected@test.com',
            'password' => bcrypt('Test@1234'),
            'application_status' => 'rejected',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'rejected@test.com',
            'password' => 'Test@1234',
        ]);

        $response->assertStatus(422);
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
}
