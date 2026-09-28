<?php

namespace Tests\Feature;

use App\Enums\QRInviteType;
use App\Enums\UserRole;
use App\Models\Church;
use App\Models\QRInvite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InviteRegistrationFlowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * End-to-end test: Invite → Register → Login
     *
     * Invitation acceptance is the proof of email ownership for this tenant,
     * so registration completes the account as already verified and the user
     * can log in immediately. No email round-trip is involved.
     */
    public function test_complete_invite_register_login_flow(): void
    {
        // 1. SETUP: Create church and admin who sends invite
        $church = Church::factory()->create();
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $church->id,
        ]);

        // 2. CREATE INVITE: Admin creates a member invite
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

        $password = 'Test@1234';
        $email = 'e2eflow@test.com';
        $name = 'E2E Test Member';

        // 3. REGISTER VIA INVITE: User registers using the invite token
        $registerResponse = $this->postJson('/api/v1/auth/register', [
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $password,
            'invite_token' => $invite->token,
        ]);

        $registerResponse->assertStatus(201)
            ->assertJsonStructure([
                'data' => ['user' => ['id', 'name', 'email', 'role']],
                'message',
            ])
            ->assertJsonPath('data.user.role', 'member')
            ->assertJsonMissingPath('data.token'); // Registration doesn't return token

        // Verify user was created with correct data
        $user = User::where('email', $email)->first();
        $this->assertNotNull($user);
        $this->assertEquals($name, $user->name);
        $this->assertEquals($email, $user->email);
        $this->assertEquals(UserRole::Member->value, $user->role->value);
        $this->assertEquals($church->id, $user->church_id);
        $this->assertTrue($user->is_active);
        $this->assertEquals('approved', $user->application_status);

        // 4. INVITATION ACCEPTANCE ESTABLISHES THE VERIFIED EMAIL STATE
        $this->assertNotNull($user->email_verified_at);
        // No pending verification work is left behind.
        $this->assertNull($user->email_verification_token);
        $this->assertNull($user->email_verification_token_expires_at);

        // 5. VERIFY PASSWORD IS CORRECTLY HASHED (not double-hashed)
        $this->assertTrue(Hash::check($password, $user->password));

        // 6. LOGIN IMMEDIATELY AFTER ACCEPTING THE INVITATION
        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);

        $loginResponse->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['user', 'token', 'token_type', 'application_status', 'access_state'],
            ])
            ->assertJsonPath('data.user.email', $email)
            ->assertJsonPath('data.user.role', 'member')
            ->assertJsonPath('data.access_state', 'full');

        // 7. VERIFY INVITE WAS CONSUMED
        $invite->refresh();
        $this->assertEquals(1, $invite->use_count);
        $this->assertNotNull($invite->used_at);
        $this->assertEquals($user->id, $invite->used_by);
    }

    /**
     * The login gate is NOT removed: any account whose email is unverified is
     * still refused, with the typed EMAIL_NOT_VERIFIED contract.
     */
    public function test_unverified_account_is_still_refused_at_login(): void
    {
        $church = Church::factory()->create();

        $user = User::factory()->create([
            'email' => 'legacy-unverified@test.com',
            'password' => Hash::make('Test@1234'),
            'application_status' => 'approved',
            'church_id' => $church->id,
            'role' => UserRole::Servant,
            'email_verified_at' => null,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'legacy-unverified@test.com',
            'password' => 'Test@1234',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'EMAIL_NOT_VERIFIED')
            ->assertJsonPath('message', __('auth.email_not_verified'));

        $this->assertNotNull($user->id);
    }

    /**
     * Test the verify-email endpoint with valid token
     */
    public function test_verify_email_endpoint_with_valid_token(): void
    {
        // Create user with known verification token
        $user = User::factory()->create([
            'email' => 'verify-test@test.com',
            'email_verified_at' => null,
            'email_verification_token' => Hash::make('a'.str_repeat('a', 63)), // raw token = 'a' x 64
            'email_verification_token_expires_at' => now()->addHours(12),
            'password' => Hash::make('Test@1234'),
        ]);

        // Verify with correct token
        $response = $this->postJson('/api/v1/auth/verify-email', [
            'email' => 'verify-test@test.com',
            'token' => 'a'.str_repeat('a', 63), // 64 chars
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Email verified successfully. You can now log in.');

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->email_verification_token);
        $this->assertNull($user->email_verification_token_expires_at);
    }

    /**
     * Test the verify-email endpoint rejects invalid token
     */
    public function test_verify_email_endpoint_rejects_invalid_token(): void
    {
        $user = User::factory()->create([
            'email' => 'verify-invalid@test.com',
            'email_verified_at' => null,
            'email_verification_token' => Hash::make('a'.str_repeat('a', 63)),
            'email_verification_token_expires_at' => now()->addHours(12),
            'password' => Hash::make('Test@1234'),
        ]);

        // Wrong token
        $response = $this->postJson('/api/v1/auth/verify-email', [
            'email' => 'verify-invalid@test.com',
            'token' => 'b'.str_repeat('b', 63), // Different 64-char token
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Invalid or expired verification link.');
    }

    /**
     * Test the verify-email endpoint rejects expired token
     */
    public function test_verify_email_endpoint_rejects_expired_token(): void
    {
        $user = User::factory()->create([
            'email' => 'verify-expired@test.com',
            'email_verified_at' => null,
            'email_verification_token' => Hash::make('a'.str_repeat('a', 63)),
            'email_verification_token_expires_at' => now()->subHour(), // Expired
            'password' => Hash::make('Test@1234'),
        ]);

        $response = $this->postJson('/api/v1/auth/verify-email', [
            'email' => 'verify-expired@test.com',
            'token' => 'a'.str_repeat('a', 63),
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Invalid or expired verification link.');
    }

    /**
     * Test the verify-email endpoint rejects token for wrong email
     */
    public function test_verify_email_endpoint_rejects_wrong_email(): void
    {
        $user = User::factory()->create([
            'email' => 'verify-email@test.com',
            'email_verified_at' => null,
            'email_verification_token' => Hash::make('a'.str_repeat('a', 63)),
            'email_verification_token_expires_at' => now()->addHours(12),
            'password' => Hash::make('Test@1234'),
        ]);

        // Correct token but wrong email
        $response = $this->postJson('/api/v1/auth/verify-email', [
            'email' => 'wrong-email@test.com',
            'token' => 'a'.str_repeat('a', 63),
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Invalid or expired verification link.');
    }

    /**
     * Test that wrong password after verification fails correctly
     */
    public function test_wrong_password_after_verification_fails(): void
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
            'expires_at' => now()->addHours(4),
            'is_single_use' => true,
            'max_uses' => 1,
            'use_count' => 0,
        ]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wrong Pass User',
            'email' => 'wrongpass@test.com',
            'password' => 'CorrectPass123',
            'password_confirmation' => 'CorrectPass123',
            'invite_token' => $invite->token,
        ])->assertStatus(201);

        $user = User::where('email', 'wrongpass@test.com')->first();
        $this->assertNotNull($user->email_verified_at);

        // Try with wrong password
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'wrongpass@test.com',
            'password' => 'WrongPass123',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('code', 'LOGIN_FAILED')
            ->assertJsonPath('message', __('auth.failed'));
    }

    /**
     * Test invite registration for servant role
     */
    public function test_invite_register_servant_then_login(): void
    {
        $church = Church::factory()->create();
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $church->id,
        ]);

        $invite = QRInvite::create([
            'type' => QRInviteType::AdminToServantInvite,
            'token' => str_repeat('c', 64),
            'created_by' => $admin->id,
            'church_id' => $church->id,
            'expires_at' => now()->addHours(4),
            'is_single_use' => true,
            'max_uses' => 1,
            'use_count' => 0,
        ]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Servant User',
            'email' => 'servant@test.com',
            'password' => 'Test@1234',
            'password_confirmation' => 'Test@1234',
            'invite_token' => $invite->token,
        ])->assertStatus(201);

        $user = User::where('email', 'servant@test.com')->first();
        $this->assertEquals(UserRole::Servant->value, $user->role->value);
        $this->assertNotNull($user->email_verified_at);

        // Login immediately — no manual verification step required.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'servant@test.com',
            'password' => 'Test@1234',
        ])->assertStatus(200)
            ->assertJsonPath('data.user.role', 'servant');
    }

    /**
     * Resend verification must stay idempotent for an account that is already
     * verified: it must not resurrect a token or silently "unverify" anyone.
     */
    public function test_resend_verification_is_a_noop_for_verified_invited_account(): void
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

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Resend User',
            'email' => 'resend@test.com',
            'password' => 'Test@1234',
            'password_confirmation' => 'Test@1234',
            'invite_token' => $invite->token,
        ])->assertStatus(201);

        $user = User::where('email', 'resend@test.com')->first();
        $verifiedAt = $user->email_verified_at;

        $response = $this->postJson('/api/v1/auth/resend-verification', [
            'email' => 'resend@test.com',
        ]);

        // The response is intentionally identical for known and unknown
        // addresses so the endpoint cannot be used to enumerate accounts.
        $response->assertStatus(200)
            ->assertJsonPath('message', 'If that email exists in our system, a verification email has been sent.');

        $user->refresh();
        $this->assertNull($user->email_verification_token);
        $this->assertNull($user->email_verification_token_expires_at);
        $this->assertTrue($verifiedAt->equalTo($user->email_verified_at));
    }
}
