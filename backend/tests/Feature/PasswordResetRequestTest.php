<?php

namespace Tests\Feature;

use App\Enums\PasswordResetRequestStatus;
use App\Enums\UserRole;
use App\Models\Church;
use App\Models\Notification;
use App\Models\PasswordResetRequest;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PasswordResetRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function createChurchWithAdmin(): array
    {
        $church = Church::factory()->create();
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $church->id,
            'is_active' => true,
        ]);

        return [$church, $admin];
    }

    private function actingAsUser(User $user): self
    {
        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('test')->plainTextToken);
    }

    public function test_member_submit_creates_request_and_notifies_church_admin(): void
    {
        [$church, $admin] = $this->createChurchWithAdmin();
        User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $church->id,
            'email' => 'member@example.com',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/password-reset-requests', [
            'email' => 'member@example.com',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('password_reset_requests', [
            'user_id' => User::where('email', 'member@example.com')->first()->id,
            'status' => 'pending',
        ]);

        $notification = Notification::where('user_id', $admin->id)->first();
        $this->assertNotNull($notification, 'Church Admin should have an in-app notification.');
        $this->assertSame('password_reset', $notification->type);
        $this->assertSame($church->id, $notification->church_id);
        $this->assertStringContainsString('Password Reset Request', (string) $notification->title);
    }

    public function test_servant_submit_creates_request_and_notifies_church_admin(): void
    {
        [$church, $admin] = $this->createChurchWithAdmin();
        User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $church->id,
            'email' => 'servant@example.com',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/password-reset-requests', [
            'email' => 'servant@example.com',
        ])->assertStatus(200);

        $this->assertDatabaseHas('password_reset_requests', [
            'user_id' => User::where('email', 'servant@example.com')->first()->id,
            'status' => 'pending',
        ]);

        $this->assertNotNull(
            Notification::where('user_id', $admin->id)->first(),
            'Church Admin should be notified when a servant requests a reset.'
        );
    }

    public function test_request_is_not_created_for_unknown_email(): void
    {
        $this->postJson('/api/v1/password-reset-requests', [
            'email' => 'nobody@example.com',
        ])->assertStatus(200);

        $this->assertDatabaseCount('password_reset_requests', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_request_is_not_created_for_admin_or_platform_admin(): void
    {
        [$church, $admin] = $this->createChurchWithAdmin();

        $this->postJson('/api/v1/password-reset-requests', [
            'email' => $admin->email,
        ])->assertStatus(200);

        $this->assertDatabaseCount('password_reset_requests', 0);

        User::factory()->create([
            'role' => UserRole::PlatformAdmin,
            'email' => 'platform@example.com',
        ]);

        $this->postJson('/api/v1/password-reset-requests', [
            'email' => 'platform@example.com',
        ])->assertStatus(200);

        $this->assertDatabaseCount('password_reset_requests', 0);
    }

    public function test_duplicate_pending_request_is_blocked(): void
    {
        [$church] = $this->createChurchWithAdmin();
        User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $church->id,
            'email' => 'member-dup@example.com',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/password-reset-requests', ['email' => 'member-dup@example.com']);
        $this->postJson('/api/v1/password-reset-requests', ['email' => 'member-dup@example.com']);
        $this->postJson('/api/v1/password-reset-requests', ['email' => 'member-dup@example.com']);

        $this->assertDatabaseCount('password_reset_requests', 1);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_submit_is_rate_limited(): void
    {
        [$church] = $this->createChurchWithAdmin();
        User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $church->id,
            'email' => 'member-rl@example.com',
            'is_active' => true,
        ]);

        RateLimiter::for('login', fn () => Limit::perMinute(2));

        $this->postJson('/api/v1/password-reset-requests', ['email' => 'member-rl@example.com'])->assertStatus(200);
        $this->postJson('/api/v1/password-reset-requests', ['email' => 'member-rl@example.com'])->assertStatus(200);
        $this->postJson('/api/v1/password-reset-requests', ['email' => 'member-rl@example.com'])->assertStatus(429);
    }

    public function test_admin_can_list_and_view_pending_requests(): void
    {
        [$church, $admin] = $this->createChurchWithAdmin();
        $member = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $church->id,
            'email' => 'member-list@example.com',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/password-reset-requests', ['email' => 'member-list@example.com']);

        $this->actingAsUser($admin)
            ->getJson('/api/v1/password-reset-requests')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.user_id', $member->id);

        $requestId = PasswordResetRequest::first()->id;

        $this->actingAsUser($admin)
            ->getJson("/api/v1/password-reset-requests/{$requestId}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $requestId)
            ->assertJsonMissing(['token']);
    }

    public function test_member_cannot_access_admin_list(): void
    {
        [$church, $admin] = $this->createChurchWithAdmin();
        $member = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $church->id,
            'email' => 'member-denied@example.com',
            'is_active' => true,
        ]);

        $this->actingAsUser($member)
            ->getJson('/api/v1/password-reset-requests')
            ->assertStatus(403);
    }

    public function test_admin_approve_marks_approved_with_reviewer(): void
    {
        [$church, $admin] = $this->createChurchWithAdmin();
        $member = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $church->id,
            'email' => 'member-approve@example.com',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/password-reset-requests', ['email' => 'member-approve@example.com']);
        $request = PasswordResetRequest::first();

        $response = $this->actingAsUser($admin)
            ->postJson("/api/v1/password-reset-requests/{$request->id}/approve");

        $response->assertStatus(200);

        $fresh = $request->fresh();
        $this->assertEquals(PasswordResetRequestStatus::Approved, $fresh->status);
        $this->assertNull($fresh->token, 'No reset token exists — the admin sets the new password directly.');
        $this->assertSame((string) $admin->id, (string) $fresh->reviewed_by);
        $this->assertNotNull($fresh->reviewed_at);

        // Requester is notified in-app.
        $this->assertNotNull(
            Notification::where('user_id', $member->id)
                ->where('type', 'password_reset')
                ->where('title', __('password_reset_requests.approved_notification_title'))
                ->first()
        );
    }

    public function test_admin_reject_marks_rejected_with_reason(): void
    {
        [$church, $admin] = $this->createChurchWithAdmin();
        User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $church->id,
            'email' => 'member-reject@example.com',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/password-reset-requests', ['email' => 'member-reject@example.com']);
        $request = PasswordResetRequest::first();

        $response = $this->actingAsUser($admin)
            ->postJson("/api/v1/password-reset-requests/{$request->id}/reject", [
                'reason' => 'Please contact the church office directly.',
            ]);

        $response->assertStatus(200);

        $fresh = $request->fresh();
        $this->assertEquals(PasswordResetRequestStatus::Rejected, $fresh->status);
        $this->assertSame('Please contact the church office directly.', $fresh->rejection_reason);
    }

    public function test_member_cannot_approve_their_own_request(): void
    {
        [$church, $admin] = $this->createChurchWithAdmin();
        $member = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $church->id,
            'email' => 'member-self@example.com',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/password-reset-requests', ['email' => 'member-self@example.com']);
        $request = PasswordResetRequest::first();

        $this->actingAsUser($member)
            ->postJson("/api/v1/password-reset-requests/{$request->id}/approve")
            ->assertStatus(403);

        $this->actingAsUser($member)
            ->postJson("/api/v1/password-reset-requests/{$request->id}/reject", ['reason' => 'no'])
            ->assertStatus(403);

        $this->actingAsUser($member)
            ->postJson("/api/v1/password-reset-requests/{$request->id}/reset-password", [
                'password' => 'HackedPass123!',
                'password_confirmation' => 'HackedPass123!',
            ])
            ->assertStatus(403);

        $this->assertEquals(PasswordResetRequestStatus::Pending, $request->fresh()->status);
    }

    public function test_servant_cannot_approve_request(): void
    {
        [$church, $admin] = $this->createChurchWithAdmin();
        User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $church->id,
            'email' => 'servant-approve@example.com',
            'is_active' => true,
        ]);
        User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $church->id,
            'email' => 'member-servant@example.com',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/password-reset-requests', ['email' => 'member-servant@example.com']);
        $request = PasswordResetRequest::first();

        $this->actingAsUser(User::where('email', 'servant-approve@example.com')->first())
            ->postJson("/api/v1/password-reset-requests/{$request->id}/approve")
            ->assertStatus(403);

        $this->assertEquals(PasswordResetRequestStatus::Pending, $request->fresh()->status);
    }

    public function test_church_a_admin_cannot_review_church_b_request(): void
    {
        $churchA = Church::factory()->create();
        $churchB = Church::factory()->create();
        $adminB = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $churchB->id,
            'is_active' => true,
        ]);
        $memberA = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $churchA->id,
            'email' => 'member-a@example.com',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/password-reset-requests', ['email' => 'member-a@example.com']);
        $request = PasswordResetRequest::first();

        $adminBToken = $adminB->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$adminBToken)
            ->postJson("/api/v1/password-reset-requests/{$request->id}/approve")
            ->assertStatus(403);

        // Cross-church lookups are not revealed: findById() is church-scoped → 404.
        $this->withHeader('Authorization', 'Bearer '.$adminBToken)
            ->getJson("/api/v1/password-reset-requests/{$request->id}")
            ->assertStatus(404);

        // Cross-church password reset is also forbidden.
        $this->actingAsUser($adminB)
            ->postJson("/api/v1/password-reset-requests/{$request->id}/reset-password", [
                'password' => 'EvilPass123!',
                'password_confirmation' => 'EvilPass123!',
            ])
            ->assertStatus(403);

        $this->assertEquals(PasswordResetRequestStatus::Pending, $request->fresh()->status);
        $this->assertFalse(Hash::check('EvilPass123!', $memberA->fresh()->password));
    }

    public function test_church_a_admin_does_not_see_church_b_requests_in_list(): void
    {
        $churchA = Church::factory()->create();
        $churchB = Church::factory()->create();
        $adminA = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $churchA->id,
            'is_active' => true,
        ]);
        User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $churchB->id,
            'email' => 'member-b@example.com',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/password-reset-requests', ['email' => 'member-b@example.com']);

        $this->actingAsUser($adminA)
            ->getJson('/api/v1/password-reset-requests')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 0);
    }

    public function test_unauthenticated_user_cannot_access_admin_endpoints(): void
    {
        $this->getJson('/api/v1/password-reset-requests')->assertStatus(401);
        $this->postJson('/api/v1/password-reset-requests/1/approve')->assertStatus(401);
        $this->postJson('/api/v1/password-reset-requests/1/reject', ['reason' => 'x'])->assertStatus(401);
        $this->postJson('/api/v1/password-reset-requests/1/reset-password', [
            'password' => 'Whatever123!',
            'password_confirmation' => 'Whatever123!',
        ])->assertStatus(401);
    }

    public function test_public_token_reset_endpoint_no_longer_exists(): void
    {
        $this->postJson('/api/v1/password-reset-requests/reset', [
            'token' => str_repeat('a', 64),
            'password' => 'NewPass456!',
            'password_confirmation' => 'NewPass456!',
        ])->assertStatus(405); // route removed — only GET /{id} exists on this prefix

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'anything',
            'email' => 'someone@example.com',
            'password' => 'NewPass456!',
            'password_confirmation' => 'NewPass456!',
        ])->assertStatus(404);
    }

    public function test_admin_reset_password_changes_password_and_completes_request(): void
    {
        [$church, $admin] = $this->createChurchWithAdmin();
        $member = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $church->id,
            'email' => 'member-reset@example.com',
            'password' => Hash::make('OldPass123!'),
            'is_active' => true,
        ]);
        $member->createToken('mobile');

        $this->postJson('/api/v1/password-reset-requests', ['email' => 'member-reset@example.com']);
        $request = PasswordResetRequest::first();

        // Reset before approval must be refused.
        $this->actingAsUser($admin)
            ->postJson("/api/v1/password-reset-requests/{$request->id}/reset-password", [
                'password' => 'NewPass456!',
                'password_confirmation' => 'NewPass456!',
            ])
            ->assertStatus(403);

        $this->actingAsUser($admin)->postJson("/api/v1/password-reset-requests/{$request->id}/approve")->assertStatus(200);

        $response = $this->actingAsUser($admin)
            ->postJson("/api/v1/password-reset-requests/{$request->id}/reset-password", [
                'password' => 'NewPass456!',
                'password_confirmation' => 'NewPass456!',
            ]);

        $response->assertStatus(200);

        $member->refresh();
        $this->assertTrue(Hash::check('NewPass456!', $member->password));
        $this->assertFalse(Hash::check('OldPass123!', $member->password));
        $this->assertSame(PasswordResetRequestStatus::Completed, $request->fresh()->status);

        // The user's pre-existing tokens were revoked.
        $this->assertSame(0, $member->tokens()->count());
    }

    public function test_completed_request_cannot_be_reset_again(): void
    {
        [$church, $admin] = $this->createChurchWithAdmin();
        $member = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $church->id,
            'email' => 'member-once@example.com',
            'password' => Hash::make('OldPass123!'),
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/password-reset-requests', ['email' => 'member-once@example.com']);
        $request = PasswordResetRequest::first();

        $this->actingAsUser($admin)->postJson("/api/v1/password-reset-requests/{$request->id}/approve")->assertStatus(200);
        $this->actingAsUser($admin)
            ->postJson("/api/v1/password-reset-requests/{$request->id}/reset-password", [
                'password' => 'FirstPass456!',
                'password_confirmation' => 'FirstPass456!',
            ])->assertStatus(200);

        // Second reset on a completed request is forbidden.
        $this->actingAsUser($admin)
            ->postJson("/api/v1/password-reset-requests/{$request->id}/reset-password", [
                'password' => 'SecondPass789!',
                'password_confirmation' => 'SecondPass789!',
            ])->assertStatus(403);

        $member->refresh();
        $this->assertTrue(Hash::check('FirstPass456!', $member->password));
        $this->assertFalse(Hash::check('SecondPass789!', $member->password));

        // A new request can be submitted again after completion.
        $this->postJson('/api/v1/password-reset-requests', ['email' => 'member-once@example.com'])->assertStatus(200);
        $this->assertDatabaseCount('password_reset_requests', 2);
    }

    public function test_approve_prevents_double_approval_via_locking(): void
    {
        [$church, $admin] = $this->createChurchWithAdmin();
        User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $church->id,
            'email' => 'member-lock@example.com',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/password-reset-requests', ['email' => 'member-lock@example.com']);
        $request = PasswordResetRequest::first();

        $this->actingAsUser($admin)->postJson("/api/v1/password-reset-requests/{$request->id}/approve")->assertStatus(200);

        // Second approve is rejected by the policy (request no longer pending).
        $this->actingAsUser($admin)->postJson("/api/v1/password-reset-requests/{$request->id}/approve")->assertStatus(403);

        $this->assertEquals(PasswordResetRequestStatus::Approved, $request->fresh()->status);
    }
}
