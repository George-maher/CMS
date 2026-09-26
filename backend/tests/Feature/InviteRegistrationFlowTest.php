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
     * End-to-end test: Invite → Register → Verify Email → Login
     * This test verifies the complete user journey from invite acceptance to successful login.
     */
    public function test_complete_invite_register_verify_login_flow(): void
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
        $this->assertNull($user->email_verified_at); // Not verified yet
        $this->assertNotNull($user->email_verification_token); // Has verification token

        // 4. VERIFY PASSWORD IS CORRECTLY HASHED (not double-hashed)
        $this->assertTrue(Hash::check($password, $user->password));

        // 5. TRY LOGIN BEFORE EMAIL VERIFICATION - Should fail with specific message
        $loginResponseBeforeVerify = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);

        $loginResponseBeforeVerify->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Please verify your email address before logging in.');

        // 6. VERIFY EMAIL: Simulate clicking the verification link
        $verifyResponse = $this->postJson('/api/v1/auth/verify-email', [
            'email' => $email,
            'token' => $user->email_verification_token,
        ]);

        // Debug output
        echo 'Verify Response Status: '.$verifyResponse->status()."\n";
        echo 'Verify Response: '.$verifyResponse->getContent()."\n";

        $verifyResponse->assertStatus(200)
            ->assertJsonPath('message', 'Email verified successfully. You can now log in.');

        // Verify user now has verified email
        $user->refresh();

        // Debug output
        echo 'User email_verified_at after refresh: '.($user->email_verified_at ? $user->email_verified_at->toString() : 'NULL')."\n";
        echo 'User email_verification_token after refresh: '.($user->email_verification_token ?? 'NULL')."\n";

        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->email_verification_token);

        // 7. LOGIN AFTER EMAIL VERIFICATION - Should succeed
        $loginResponseAfterVerify = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);

        $loginResponseAfterVerify->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['user', 'token', 'token_type', 'application_status', 'access_state'],
            ])
            ->assertJsonPath('data.user.email', $email)
            ->assertJsonPath('data.user.role', 'member')
            ->assertJsonPath('data.access_state', 'full');

        // 8. VERIFY INVITE WAS CONSUMED
        $invite->refresh();
        $this->assertEquals(1, $invite->use_count);
        $this->assertNotNull($invite->used_at);
        $this->assertEquals($user->id, $invite->used_by);
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
        $user->email_verified_at = now();
        $user->email_verification_token = null;
        $user->save();

        // Try with wrong password
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'wrongpass@test.com',
            'password' => 'WrongPass123',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Incorrect email or password.');
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

        // Verify email
        $user->email_verified_at = now();
        $user->save();

        // Login
        $this->postJson('/api/v1/auth/login', [
            'email' => 'servant@test.com',
            'password' => 'Test@1234',
        ])->assertStatus(200)
            ->assertJsonPath('data.user.role', 'servant');
    }

    /**
     * Test that resend verification works for unregistered user
     */
    public function test_resend_verification_after_invite_registration(): void
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
        $originalToken = $user->email_verification_token;

        // Resend verification
        $response = $this->postJson('/api/v1/auth/resend-verification', [
            'email' => 'resend@test.com',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'If that email exists in our system, a verification email has been sent.');

        // Token should be regenerated
        $user->refresh();
        $this->assertNotEquals($originalToken, $user->email_verification_token);
        $this->assertNotNull($user->email_verification_token);
    }
}
