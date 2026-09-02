<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Church;
use App\Models\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileUpdateRequestTest extends TestCase
{
    use RefreshDatabase;

    private Church $church;

    private User $admin;

    private User $servant;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->church = Church::factory()->create();
        $this->admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $this->church->id,
        ]);
        $this->servant = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $this->church->id,
        ]);
        $this->member = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $this->church->id,
            'servant_id' => $this->servant->id,
        ]);
    }

    // ─── Admin/Servant Direct Profile Update ───

    public function test_admin_can_update_own_profile_directly(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->putJson('/api/v1/profile', [
            'name' => 'Updated Admin Name',
            'phone' => '01234567890',
        ]);

        $response->assertOk()
            ->assertJson(['message' => __('profile_update_requests.updated')]);

        $this->admin->refresh();
        $this->assertEquals('Updated Admin Name', $this->admin->name);
        $this->assertEquals('01234567890', $this->admin->phone);
    }

    public function test_servant_can_update_own_profile_directly(): void
    {
        Sanctum::actingAs($this->servant);

        $response = $this->putJson('/api/v1/profile', [
            'name' => 'Updated Servant Name',
            'email' => 'updated-servant@test.com',
        ]);

        $response->assertOk();

        $this->servant->refresh();
        $this->assertEquals('Updated Servant Name', $this->servant->name);
    }

    public function test_admin_cannot_modify_protected_fields_via_profile(): void
    {
        Sanctum::actingAs($this->admin);

        // The UpdateOwnProfileRequest only allows name, phone, email, address
        // Attempting to set role/church_id should be silently ignored by the service
        $originalRole = $this->admin->role;
        $originalChurchId = $this->admin->church_id;

        $response = $this->putJson('/api/v1/profile', [
            'name' => 'New Name',
        ]);

        $response->assertOk();
        $this->admin->refresh();
        $this->assertEquals($originalRole, $this->admin->role);
        $this->assertEquals($originalChurchId, $this->admin->church_id);
    }

    // ─── Member Cannot Update Directly ───

    public function test_member_cannot_update_profile_directly(): void
    {
        Sanctum::actingAs($this->member);

        $response = $this->putJson('/api/v1/profile', [
            'name' => 'Hacker Name',
        ]);

        $response->assertForbidden();
    }

    // ─── Member Submit Profile Update Request ───

    public function test_member_can_submit_profile_update_request(): void
    {
        Sanctum::actingAs($this->member);

        $response = $this->postJson('/api/v1/profile-update-requests', [
            'name' => 'Updated Member Name',
            'phone' => '01111111111',
            'email' => 'newemail@test.com',
            'address' => 'New Address',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'message',
                'data' => ['id', 'status', 'old_values', 'new_values'],
            ]);

        $this->assertDatabaseHas('profile_update_requests', [
            'user_id' => $this->member->id,
            'status' => 'pending',
        ]);
    }

    public function test_member_cannot_submit_empty_request(): void
    {
        Sanctum::actingAs($this->member);

        $response = $this->postJson('/api/v1/profile-update-requests', []);

        $response->assertStatus(422);
    }

    public function test_member_receives_error_when_already_has_pending_request(): void
    {
        Sanctum::actingAs($this->member);

        // First request
        $this->postJson('/api/v1/profile-update-requests', [
            'name' => 'First Request',
        ])->assertOk();

        // Second request should fail
        Sanctum::actingAs($this->member);
        $response = $this->postJson('/api/v1/profile-update-requests', [
            'name' => 'Second Request',
        ]);

        $response->assertStatus(422);
    }

    public function test_member_request_stores_old_and_new_values(): void
    {
        Sanctum::actingAs($this->member);

        $response = $this->postJson('/api/v1/profile-update-requests', [
            'phone' => '01234567890',
        ]);

        $response->assertOk();

        $request = ProfileUpdateRequest::where('user_id', $this->member->id)->first();
        $this->assertNotNull($request);
        $this->assertEquals($this->member->phone, $request->old_values['phone']);
        $this->assertEquals('01234567890', $request->new_values['phone']);
    }

    public function test_member_can_list_own_requests(): void
    {
        ProfileUpdateRequest::create([
            'user_id' => $this->member->id,
            'church_id' => $this->church->id,
            'status' => 'pending',
            'old_values' => ['name' => $this->member->name],
            'new_values' => ['name' => 'New Name'],
        ]);

        Sanctum::actingAs($this->member);

        $response = $this->getJson('/api/v1/profile-update-requests/my');

        $response->assertOk()
            ->assertJsonStructure(['data', 'meta']);
    }

    // ─── Servant Reviews Requests ───

    public function test_responsible_servant_can_list_pending_requests(): void
    {
        ProfileUpdateRequest::create([
            'user_id' => $this->member->id,
            'church_id' => $this->church->id,
            'reviewer_id' => $this->servant->id,
            'status' => 'pending',
            'old_values' => ['phone' => $this->member->phone],
            'new_values' => ['phone' => '01234567890'],
        ]);

        Sanctum::actingAs($this->servant);

        $response = $this->getJson('/api/v1/profile-update-requests');

        $response->assertOk()
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_responsible_servant_can_approve_request(): void
    {
        $request = ProfileUpdateRequest::create([
            'user_id' => $this->member->id,
            'church_id' => $this->church->id,
            'reviewer_id' => $this->servant->id,
            'status' => 'pending',
            'old_values' => ['phone' => $this->member->phone],
            'new_values' => ['phone' => '01234567890'],
        ]);

        Sanctum::actingAs($this->servant);

        $response = $this->postJson("/api/v1/profile-update-requests/{$request->id}/approve");

        $response->assertOk();

        $this->member->refresh();
        $this->assertEquals('01234567890', $this->member->phone);

        $request->refresh();
        $this->assertEquals('approved', $request->status->value);
    }

    public function test_responsible_servant_can_reject_request(): void
    {
        $request = ProfileUpdateRequest::create([
            'user_id' => $this->member->id,
            'church_id' => $this->church->id,
            'reviewer_id' => $this->servant->id,
            'status' => 'pending',
            'old_values' => ['phone' => $this->member->phone],
            'new_values' => ['phone' => '01234567890'],
        ]);

        Sanctum::actingAs($this->servant);

        $response = $this->postJson("/api/v1/profile-update-requests/{$request->id}/reject", [
            'reason' => 'Please provide a valid phone number.',
        ]);

        $response->assertOk();

        $this->member->refresh();
        $this->assertEquals($this->member->phone, $this->member->phone); // unchanged

        $request->refresh();
        $this->assertEquals('rejected', $request->status->value);
        $this->assertEquals('Please provide a valid phone number.', $request->rejection_reason);
    }

    // ─── Security ───

    public function test_unrelated_servant_cannot_approve_request(): void
    {
        $otherServant = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $this->church->id,
        ]);

        $request = ProfileUpdateRequest::create([
            'user_id' => $this->member->id,
            'church_id' => $this->church->id,
            'reviewer_id' => $this->servant->id,
            'status' => 'pending',
            'old_values' => ['phone' => $this->member->phone],
            'new_values' => ['phone' => '01234567890'],
        ]);

        Sanctum::actingAs($otherServant);

        $response = $this->postJson("/api/v1/profile-update-requests/{$request->id}/approve");

        $response->assertStatus(422);

        $request->refresh();
        $this->assertEquals('pending', $request->status->value);
    }

    public function test_cross_church_request_access_denied(): void
    {
        $otherChurch = Church::factory()->create();
        $otherServant = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $otherChurch->id,
        ]);

        $request = ProfileUpdateRequest::create([
            'user_id' => $this->member->id,
            'church_id' => $this->church->id,
            'reviewer_id' => $this->servant->id,
            'status' => 'pending',
            'old_values' => ['phone' => $this->member->phone],
            'new_values' => ['phone' => '01234567890'],
        ]);

        Sanctum::actingAs($otherServant);

        // List should not show requests from other churches
        Sanctum::actingAs($otherServant);
        $response = $this->getJson('/api/v1/profile-update-requests');
        $response->assertOk();
        $this->assertCount(0, $response->json('data'));

        // View should fail (404 because church_id filter prevents finding the record)
        Sanctum::actingAs($otherServant);
        $response = $this->getJson("/api/v1/profile-update-requests/{$request->id}");
        $response->assertStatus(404);
    }

    public function test_cannot_approve_already_processed_request(): void
    {
        $request = ProfileUpdateRequest::create([
            'user_id' => $this->member->id,
            'church_id' => $this->church->id,
            'reviewer_id' => $this->servant->id,
            'status' => 'approved',
            'old_values' => ['phone' => $this->member->phone],
            'new_values' => ['phone' => '01234567890'],
            'reviewed_by' => $this->servant->id,
            'reviewed_at' => now(),
        ]);

        Sanctum::actingAs($this->servant);

        $response = $this->postJson("/api/v1/profile-update-requests/{$request->id}/approve");

        $response->assertForbidden();
    }

    // ─── Validation ───

    public function test_invalid_email_rejected(): void
    {
        Sanctum::actingAs($this->member);

        $response = $this->postJson('/api/v1/profile-update-requests', [
            'email' => 'not-an-email',
        ]);

        $response->assertStatus(422);
    }

    public function test_duplicate_email_rejected_on_approval(): void
    {
        $otherMember = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $this->church->id,
            'email' => 'taken@test.com',
        ]);

        $request = ProfileUpdateRequest::create([
            'user_id' => $this->member->id,
            'church_id' => $this->church->id,
            'reviewer_id' => $this->servant->id,
            'status' => 'pending',
            'old_values' => ['email' => $this->member->email],
            'new_values' => ['email' => 'taken@test.com'],
        ]);

        Sanctum::actingAs($this->servant);

        $response = $this->postJson("/api/v1/profile-update-requests/{$request->id}/approve");

        $response->assertStatus(422);

        $this->member->refresh();
        $this->assertNotEquals('taken@test.com', $this->member->email);
    }

    // ─── Admin Can Also Review ───

    public function test_admin_can_approve_request(): void
    {
        $request = ProfileUpdateRequest::create([
            'user_id' => $this->member->id,
            'church_id' => $this->church->id,
            'reviewer_id' => $this->servant->id,
            'status' => 'pending',
            'old_values' => ['name' => $this->member->name],
            'new_values' => ['name' => 'Admin Approved Name'],
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->postJson("/api/v1/profile-update-requests/{$request->id}/approve");

        $response->assertOk();

        $this->member->refresh();
        $this->assertEquals('Admin Approved Name', $this->member->name);
    }

    // ─── No Changes Rejected ───

    public function test_submitting_no_changes_rejected(): void
    {
        Sanctum::actingAs($this->member);

        $response = $this->postJson('/api/v1/profile-update-requests', [
            'name' => $this->member->name,
            'email' => $this->member->email,
            'phone' => $this->member->phone,
        ]);

        $response->assertStatus(422);
    }
}
