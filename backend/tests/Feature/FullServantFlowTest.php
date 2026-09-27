<?php

namespace Tests\Feature;

use App\Enums\QRInviteType;
use App\Enums\UserRole;
use App\Models\Church;
use App\Models\Classe;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FullServantFlowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test the complete flow:
     * 1. Create a Church
     * 2. Create Church Admin
     * 3. Church Admin logs in
     * 4. Church Admin creates Servant QR invitation
     * 5. Servant registers via invitation
     * 6. Servant logs in (invitation acceptance already established the
     *    verified email state)
     * 7. Servant accesses /me
     * 8. Servant issues a member invitation within their own stage
     */
    public function test_complete_church_admin_to_servant_flow(): void
    {
        // 1. Create a Church
        $church = Church::factory()->create();
        $stage = Stage::factory()->forChurch($church)->create();
        $classe = Classe::factory()->forChurch($church)->state(['stage_id' => $stage->id])->create();

        // 2. Create Church Admin for that Church
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $church->id,
            'application_status' => 'approved',
            'email_verified_at' => now(),
        ]);

        // 3. Church Admin logs in
        $adminLoginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => $admin->email,
            'password' => 'password',
        ]);
        $adminLoginResponse->assertStatus(200);
        $adminToken = $adminLoginResponse->json('data.token');
        $this->assertNotNull($adminToken);

        // 4. Church Admin creates Servant QR invitation
        $inviteResponse = $this->withHeader('Authorization', "Bearer $adminToken")
            ->postJson('/api/v1/qr/invites', [
                'type' => QRInviteType::AdminToServantInvite->value,
                'class_id' => $classe->id,
            ]);
        $inviteResponse->assertStatus(201);
        $inviteToken = $inviteResponse->json('data.url');
        $this->assertStringContainsString('invite/', $inviteToken);
        // Extract token from URL
        $inviteToken = basename($inviteToken);

        // 5. Servant registers via invitation
        $servantEmail = 'servant@test.com';
        $servantPassword = 'Test@1234';
        $registerResponse = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test Servant',
            'email' => $servantEmail,
            'password' => $servantPassword,
            'password_confirmation' => $servantPassword,
            'invite_token' => $inviteToken,
            'class_id' => $classe->id,
        ]);
        $registerResponse->assertStatus(201);
        $this->assertEquals('servant', $registerResponse->json('data.user.role'));

        // Get the created servant
        $servant = User::where('email', $servantEmail)->first();
        $this->assertNotNull($servant);
        $this->assertEquals(UserRole::Servant->value, $servant->role->value);
        $this->assertEquals($church->id, $servant->church_id);
        $this->assertTrue($servant->is_active);
        $this->assertEquals('approved', $servant->application_status);

        // 6. Invitation acceptance established the verified email state, so
        //    no separate verification round-trip is required.
        $this->assertNotNull($servant->email_verified_at);
        $this->assertNull($servant->email_verification_token);
        $this->assertNull($servant->email_verification_token_expires_at);

        // 7. Servant logs in
        $servantLoginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => $servantEmail,
            'password' => $servantPassword,
        ]);
        if ($servantLoginResponse->status() !== 200) {
            dump($servantLoginResponse->json());
        }
        $servantLoginResponse->assertStatus(200)
            ->assertJsonPath('data.user.role', 'servant')
            ->assertJsonPath('data.access_state', 'full');
        $servantToken = $servantLoginResponse->json('data.token');
        $this->assertNotNull($servantToken);

        // Check the user data structure
        $userData = $servantLoginResponse->json('data.user');
        $this->assertArrayHasKey('classe', $userData);
        $this->assertSame($classe->id, $userData['classe']['id']);
        // church is a non-optional key in the frontend contract, never dropped
        $this->assertArrayHasKey('church', $userData);
        $this->assertSame($church->id, $userData['church']['id']);
        $this->assertArrayHasKey('created_by', $userData);
        $this->assertSame($admin->id, $userData['created_by']['id']);

        // 8. Servant accesses /me
        $meResponse = $this->withHeader('Authorization', "Bearer $servantToken")
            ->getJson('/api/v1/auth/me');
        $meResponse->assertStatus(200)
            ->assertJsonPath('data.user.role', 'servant')
            ->assertJsonPath('data.user.church_id', $church->id)
            ->assertJsonPath('data.user.church.id', $church->id);

        // 9. Servant can create member invites within their own stage
        $memberInviteResponse = $this->withHeader('Authorization', "Bearer $servantToken")
            ->postJson('/api/v1/qr/invites', [
                'type' => QRInviteType::ServantToMemberInvite->value,
            ]);
        $memberInviteResponse->assertStatus(201);

        // 10. A servant from another church cannot be reached with this token.
        //     The isolation is enforced server-side (UserPolicy): the response
        //     is a refusal, never another tenant's data.
        $otherChurch = Church::factory()->create();
        $otherServant = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $otherChurch->id,
            'application_status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $this->withHeader('Authorization', "Bearer $servantToken")
            ->getJson("/api/v1/users/{$otherServant->id}")
            ->assertStatus(403);
    }

    /**
     * Test the complete flow with class assignment during registration
     */
    public function test_complete_flow_with_class_assignment(): void
    {
        $church = Church::factory()->create();
        $stage = Stage::factory()->forChurch($church)->create();
        $classe = Classe::factory()->forChurch($church)->state(['stage_id' => $stage->id])->create();

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $church->id,
            'application_status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $adminLoginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => $admin->email,
            'password' => 'password',
        ]);
        $adminToken = $adminLoginResponse->json('data.token');

        // Create servant invite with class_id
        $inviteResponse = $this->withHeader('Authorization', "Bearer $adminToken")
            ->postJson('/api/v1/qr/invites', [
                'type' => QRInviteType::AdminToServantInvite->value,
                'class_id' => $classe->id,
            ]);
        $inviteResponse->assertStatus(201);
        $inviteToken = basename($inviteResponse->json('data.url'));

        $servantEmail = 'servant2@test.com';
        $servantPassword = 'Test@1234';
        $registerResponse = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test Servant 2',
            'email' => $servantEmail,
            'password' => $servantPassword,
            'password_confirmation' => $servantPassword,
            'invite_token' => $inviteToken,
            'class_id' => $classe->id,
        ]);
        $registerResponse->assertStatus(201);

        $servant = User::where('email', $servantEmail)->first();
        $this->assertEquals($classe->id, $servant->class_id);
        $this->assertEquals($stage->id, $servant->stage_id);
        $this->assertNotNull($servant->email_verified_at);

        // Login
        $servantLoginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => $servantEmail,
            'password' => $servantPassword,
        ]);
        $servantLoginResponse->assertStatus(200)
            ->assertJsonPath('data.user.role', 'servant')
            ->assertJsonPath('data.user.classe.id', $classe->id);
    }
}
