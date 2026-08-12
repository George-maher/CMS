<?php

namespace Tests\Feature;

use App\Enums\PasswordResetRequestStatus;
use App\Enums\UserRole;
use App\Models\Church;
use App\Models\Notification;
use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Notifications\PasswordResetRequestApprovedNotification;
use Database\Seeders\PermissionSeeder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification as NotificationFacade;
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
        $member = User::factory()->create([
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
            'user_id' => $member->id,
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
        $servant = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $church->id,
            'email' => 'servant@example.com',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/password-reset-requests', [
            'email' => 'servant@example.com',
        ])->assertStatus(200);

        $this->assertDatabaseHas('password_reset_requests', [
            'user_id' => $servant->id,
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

        $platformAdmin = User::factory()->create([
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
        [$church, $admin] = $this->createChurchWithAdmin();
        $member = User::factory()->create([
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
            ->assertJsonPath('data.id', $requestId);
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

    public function test_admin_approve_generates_token_and_notifies_user(): void
    {
        NotificationFacade::fake();

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
        $this->assertNotNull($fresh->token, 'A secure reset token must be generated on approval.');
        $this->assertSame(64, strlen((string) $fresh->token));
        $this->assertSame($admin->id, $fresh->reviewed_by);
        $this->assertNotNull($fresh->reviewed_at);
        $this->assertNotNull($fresh->token_expires_at);

        NotificationFacade::assertSentTo($member, PasswordResetRequestApprovedNotification::class);
    }

    public function test_admin_approve_sends_mail_via_reset_notification(): void
    {
        NotificationFacade::fake();

        [$church, $admin] = $this->createChurchWithAdmin();
        $member = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $church->id,
            'email' => 'member-mail@example.com',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/password-reset-requests', ['email' => 'member-mail@example.com']);
        $request = PasswordResetRequest::first();

        $this->actingAsUser($admin)->postJson("/api/v1/password-reset-requests/{$request->id}/approve")->assertStatus(200);

        $fresh = $request->fresh();

        NotificationFacade::assertSentTo($member, PasswordResetRequestApprovedNotification::class, function ($notification) use ($member, $fresh) {
            $url = $notification->toMail($member)->actionUrl ?? '';

            return str_contains($url, (string) $fresh->token);
        });
    }

    public function test_admin_reject_marks_rejected_with_reason(): void
    {
        [$church, $admin] = $this->createChurchWithAdmin();
        $member = User::factory()->create([
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
        $this->assertNull($fresh->token, 'No reset token must be generated when rejected.');
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

        $this->assertEquals(PasswordResetRequestStatus::Pending, $request->fresh()->status);
    }

    public function test_servant_cannot_approve_request(): void
    {
        [$church, $admin] = $this->createChurchWithAdmin();
        $servant = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $church->id,
            'email' => 'servant-approve@example.com',
            'is_active' => true,
        ]);
        $member = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $church->id,
            'email' => 'member-servant@example.com',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/password-reset-requests', ['email' => 'member-servant@example.com']);
        $request = PasswordResetRequest::first();

        $this->actingAsUser($servant)
            ->postJson("/api/v1/password-reset-requests/{$request->id}/approve")
            ->assertStatus(403);

        $this->assertEquals(PasswordResetRequestStatus::Pending, $request->fresh()->status);
    }

    public function test_church_a_admin_cannot_approve_church_b_request(): void
    {
        $churchA = Church::factory()->create();
        $churchB = Church::factory()->create();
        $adminB = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $churchB->id,
            'is_active' => true,
        ]);
        $member = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $churchA->id,
            'email' => 'member-a@example.com',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/password-reset-requests', ['email' => 'member-a@example.com']);
        $request = PasswordResetRequest::first();

        $this->actingAsUser($adminB)
            ->postJson("/api/v1/password-reset-requests/{$request->id}/approve")
            ->assertStatus(403);

        // Cross-church lookups are not revealed: findById() is church-scoped → 404.
        $this->actingAsUser($adminB)
            ->getJson("/api/v1/password-reset-requests/{$request->id}")
            ->assertStatus(404);

        $this->assertEquals(PasswordResetRequestStatus::Pending, $request->fresh()->status);
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
        $memberB = User::factory()->create([
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
    }

    public function test_complete_reset_changes_password_and_invalidates_token(): void
    {
        [$church, $admin] = $this->createChurchWithAdmin();
        $member = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $church->id,
            'email' => 'member-reset@example.com',
            'password' => Hash::make('OldPass123!'),
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/password-reset-requests', ['email' => 'member-reset@example.com']);
        $request = PasswordResetRequest::first();

        $this->actingAsUser($admin)->postJson("/api/v1/password-reset-requests/{$request->id}/approve")->assertStatus(200);

        $fresh = $request->fresh();
        $token = (string) $fresh->token;

        $response = $this->postJson('/api/v1/password-reset-requests/reset', [
            'token' => $token,
            'password' => 'NewPass456!',
            'password_confirmation' => 'NewPass456!',
        ]);

        $response->assertStatus(200);

        $member->refresh();
        $this->assertTrue(Hash::check('NewPass456!', $member->password));
        $this->assertFalse(Hash::check('OldPass123!', $member->password));
        $this->assertSame(PasswordResetRequestStatus::Approved, $request->fresh()->status);
        $this->assertNotNull($request->fresh()->used_at, 'Request must be marked used after reset.');
    }

    public function test_used_token_cannot_be_reused(): void
    {
        [$church, $admin] = $this->createChurchWithAdmin();
        $member = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $church->id,
            'email' => 'member-reuse@example.com',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/password-reset-requests', ['email' => 'member-reuse@example.com']);
        $request = PasswordResetRequest::first();

        $this->actingAsUser($admin)->postJson("/api/v1/password-reset-requests/{$request->id}/approve")->assertStatus(200);

        $token = (string) $request->fresh()->token;

        $this->postJson('/api/v1/password-reset-requests/reset', [
            'token' => $token,
            'password' => 'NewPass456!',
            'password_confirmation' => 'NewPass456!',
        ])->assertStatus(200);

        $this->postJson('/api/v1/password-reset-requests/reset', [
            'token' => $token,
            'password' => 'AnotherPass456!',
            'password_confirmation' => 'AnotherPass456!',
        ])->assertStatus(422);
    }

    public function test_approve_uses_transaction_locking_to_prevent_double_approval(): void
    {
        [$church, $admin] = $this->createChurchWithAdmin();
        $member = User::factory()->create([
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

    public function test_approve_dispatches_email_notification_via_mail_channel_only_after_approval(): void
    {
        NotificationFacade::fake();

        [$church, $admin] = $this->createChurchWithAdmin();
        $member = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $church->id,
            'email' => 'member-mail-only@example.com',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/password-reset-requests', ['email' => 'member-mail-only@example.com']);

        NotificationFacade::assertNotSentTo(
            $member,
            PasswordResetRequestApprovedNotification::class
        );

        $request = PasswordResetRequest::first();
        $this->actingAsUser($admin)->postJson("/api/v1/password-reset-requests/{$request->id}/approve")->assertStatus(200);

        NotificationFacade::assertSentTo(
            $member,
            PasswordResetRequestApprovedNotification::class
        );
    }
}
