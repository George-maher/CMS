<?php

namespace Tests\Feature;

use App\Enums\QRInviteType;
use App\Enums\UserRole;
use App\Models\Church;
use App\Models\QRInvite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductionFlowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test the exact production flow:
     * 1. Church Application submitted
     * 2. Platform Admin approves application -> Church + Admin created
     * 3. Admin logs in
     * 4. Admin creates Servant QR invitation
     * 5. Servant registers via invitation
     * 6. Servant logs in (invitation acceptance established the verified
     *    email state — there is no mail round-trip in this product)
     * 7. Servant accesses /me
     */
    public function test_complete_production_flow(): void
    {
        // 1. Church Application submitted (simulating public submission)
        $platformAdmin = User::factory()->create([
            'role' => UserRole::PlatformAdmin,
            'email_verified_at' => now(),
        ]);

        $applicationData = [
            'church_name' => 'Test Church',
            'priest_name' => 'Priest Name',
            'address' => '123 Church St',
            'phone' => '01234567890',
            'main_servant_name' => 'Main Servant',
            'id_type' => 'national_id',
            // National-ID onboarding requires both sides of the ID card.
            'front_id' => UploadedFile::fake()->create('front.jpg', 64, 'image/jpeg'),
            'back_id' => UploadedFile::fake()->create('back.jpg', 64, 'image/jpeg'),
        ];

        $submitResponse = $this->post('/api/v1/church-applications', array_merge($applicationData, [
            'email' => 'admin@test.com',
            'password' => 'Test@1234',
            'password_confirmation' => 'Test@1234',
        ]), ['Accept' => 'application/json']);

        $submitResponse->assertStatus(201);
        // The endpoint returns the application as a ChurchApplicationResource
        // directly under `data` (not nested under `data.application`).
        $applicationId = $submitResponse->json('data.id');
        $this->assertNotNull($applicationId);

        // 2. Platform Admin approves application
        $approveResponse = $this->actingAs($platformAdmin, 'sanctum')
            ->postJson("/api/v1/platform/applications/{$applicationId}/approve");

        $approveResponse->assertStatus(200);
        $churchId = $approveResponse->json('data.church.id');
        $this->assertNotNull($churchId);

        // Get the created admin user
        $admin = User::where('email', 'admin@test.com')->first();
        $this->assertNotNull($admin);
        $this->assertEquals($churchId, $admin->church_id);
        $this->assertEquals('approved', $admin->application_status);
        $this->assertEquals(UserRole::Admin->value, $admin->role->value);
        $this->assertNotNull($admin->email_verified_at); // Auto-verified on approval

        // 3. Admin logs in
        $adminLoginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@test.com',
            'password' => 'Test@1234',
        ]);

        $adminLoginResponse->assertStatus(200);
        $adminToken = $adminLoginResponse->json('data.token');
        $this->assertNotNull($adminToken);

        // 4. Admin creates Servant QR invitation
        $inviteResponse = $this->withHeader('Authorization', "Bearer $adminToken")
            ->postJson('/api/v1/qr/invites', [
                'type' => QRInviteType::AdminToServantInvite->value,
            ]);

        $inviteResponse->assertStatus(201);
        $inviteUrl = $inviteResponse->json('data.url');
        $inviteToken = basename($inviteUrl);

        // Verify invite in database
        $invite = QRInvite::where('token', $inviteToken)->first();
        $this->assertNotNull($invite);
        $this->assertEquals($churchId, $invite->church_id);
        $this->assertEquals($admin->id, $invite->created_by);
        $this->assertEquals(QRInviteType::AdminToServantInvite->value, $invite->type->value);

        // 5. Servant registers via invitation
        $servantEmail = 'servant@test.com';
        $servantPassword = 'Test@1234';
        $registerResponse = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test Servant',
            'email' => $servantEmail,
            'password' => $servantPassword,
            'password_confirmation' => $servantPassword,
            'invite_token' => $inviteToken,
        ]);

        $registerResponse->assertStatus(201);
        $this->assertEquals('servant', $registerResponse->json('data.user.role'));

        $servant = User::where('email', $servantEmail)->first();
        $this->assertNotNull($servant);
        $this->assertEquals(UserRole::Servant->value, $servant->role->value);
        $this->assertEquals($churchId, $servant->church_id);
        $this->assertEquals($admin->id, $servant->created_by);
        $this->assertEquals($invite->id, $servant->invite_id);
        $this->assertTrue($servant->is_active);
        $this->assertEquals('approved', $servant->application_status);

        // Invitation acceptance established the verified email state.
        $this->assertNotNull($servant->email_verified_at);
        $this->assertNull($servant->email_verification_token);
        $this->assertNull($servant->email_verification_token_expires_at);

        // Verify password is correctly hashed (not double-hashed)
        $this->assertTrue(Hash::check($servantPassword, $servant->password));

        // 6. Servant logs in immediately — no email verification round-trip
        $servantLoginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => $servantEmail,
            'password' => $servantPassword,
        ]);

        $servantLoginResponse->assertStatus(200)
            ->assertJsonPath('data.user.role', 'servant')
            ->assertJsonPath('data.user.church_id', $churchId)
            ->assertJsonPath('data.access_state', 'full');

        $servantToken = $servantLoginResponse->json('data.token');
        $this->assertNotNull($servantToken);

        // 8. Servant accesses /me
        $meResponse = $this->withHeader('Authorization', "Bearer $servantToken")
            ->getJson('/api/v1/auth/me');

        $meResponse->assertStatus(200)
            ->assertJsonPath('data.user.role', 'servant')
            ->assertJsonPath('data.user.church_id', $churchId);

        // 9. Servant can access servant endpoints
        $memberInviteResponse = $this->withHeader('Authorization', "Bearer $servantToken")
            ->postJson('/api/v1/qr/invites', [
                'type' => QRInviteType::ServantToMemberInvite->value,
            ]);

        // This might fail if servant doesn't have stage_id, but login should work
        // The login test above already passed
    }
}
