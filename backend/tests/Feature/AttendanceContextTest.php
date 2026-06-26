<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AttendanceContext;
use App\Models\Church;
use App\Models\Classe;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceContextTest extends TestCase
{
    use RefreshDatabase;

    private Church $church1;
    private Church $church2;
    private User $admin;
    private User $assistantAdmin;
    private User $servant;
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        Permission::clearCache();

        $this->church1 = Church::factory()->create(['name' => 'Church Alpha']);
        $this->church2 = Church::factory()->create(['name' => 'Church Beta']);

        $this->admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $this->church1->id,
            'application_status' => 'approved',
        ]);

        $this->assistantAdmin = User::factory()->create([
            'role' => UserRole::AssistantAdmin,
            'church_id' => $this->church1->id,
            'application_status' => 'approved',
        ]);

        $this->servant = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $this->church1->id,
            'application_status' => 'approved',
            'class_id' => Classe::factory()->create(['church_id' => $this->church1->id])->id,
        ]);

        $this->member = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $this->church1->id,
            'application_status' => 'approved',
        ]);
    }

    private function actingAsUser(User $user): self
    {
        $token = $user->createToken('test')->plainTextToken;
        return $this->withHeader('Authorization', "Bearer $token");
    }

    // ──────────────────────────────────────────────
    // 1. Admin creates context
    // ──────────────────────────────────────────────

    public function test_admin_can_create_context(): void
    {
        $this->actingAsUser($this->admin);

        $response = $this->postJson('/api/v1/attendance-contexts', [
            'name' => 'Weekly Sunday School',
            'description' => 'Regular Sunday sessions',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Weekly Sunday School')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('attendance_contexts', [
            'name' => 'Weekly Sunday School',
            'church_id' => $this->church1->id,
            'is_active' => true,
        ]);
    }

    // ──────────────────────────────────────────────
    // 2. Assistant Admin creates context
    // ──────────────────────────────────────────────

    public function test_assistant_admin_can_create_context(): void
    {
        $this->actingAsUser($this->assistantAdmin);

        $response = $this->postJson('/api/v1/attendance-contexts', [
            'name' => 'Holiday Program',
            'description' => 'Vacation church program',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Holiday Program');
    }

    // ──────────────────────────────────────────────
    // 3. Servant creates context
    // ──────────────────────────────────────────────

    public function test_servant_can_create_context(): void
    {
        $this->actingAsUser($this->servant);

        $response = $this->postJson('/api/v1/attendance-contexts', [
            'name' => 'Prayer Meeting',
            'description' => 'Weekly prayer gathering',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Prayer Meeting');
    }

    // ──────────────────────────────────────────────
    // 4. Context appears in attendance dropdown
    // ──────────────────────────────────────────────

    public function test_active_contexts_appear_in_dropdown(): void
    {
        AttendanceContext::create([
            'church_id' => $this->church1->id,
            'name' => 'Test Sunday School',
            'slug' => 'test-sunday-school',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        AttendanceContext::create([
            'church_id' => $this->church1->id,
            'name' => 'Test Mass',
            'slug' => 'test-mass',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        AttendanceContext::create([
            'church_id' => $this->church1->id,
            'name' => 'Archived Context',
            'slug' => 'archived',
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);

        $this->actingAsUser($this->servant);

        $response = $this->getJson('/api/v1/attendance-contexts');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name');
        // 6 defaults + 2 new active test contexts = 8, Archived Context excluded
        $this->assertCount(8, $names);
        $this->assertContains('Test Mass', $names);
        $this->assertContains('Test Sunday School', $names);
        $this->assertNotContains('Archived Context', $names);
    }

    // ──────────────────────────────────────────────
    // 5. Context appears in filters (management list)
    // ──────────────────────────────────────────────

    public function test_contexts_appear_in_management_list(): void
    {
        AttendanceContext::create([
            'church_id' => $this->church1->id,
            'name' => 'Test Tasbeha',
            'slug' => 'test-tasbeha',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->actingAsUser($this->admin);

        $response = $this->getJson('/api/v1/attendance-contexts/manage');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'meta']);
        $names = collect($response->json('data'))->pluck('name');
        $this->assertContains('Test Tasbeha', $names);
    }

    // ──────────────────────────────────────────────
    // 6. Member cannot access management page
    // ──────────────────────────────────────────────

    public function test_member_cannot_access_management_list(): void
    {
        $this->actingAsUser($this->member);

        $response = $this->getJson('/api/v1/attendance-contexts/manage');

        $response->assertStatus(403);
    }

    // ──────────────────────────────────────────────
    // 7. Member cannot call management APIs
    // ──────────────────────────────────────────────

    public function test_member_cannot_create_context(): void
    {
        $this->actingAsUser($this->member);

        $response = $this->postJson('/api/v1/attendance-contexts', [
            'name' => 'Test Member Create',
        ]);

        $response->assertStatus(403);
    }

    public function test_member_cannot_update_context(): void
    {
        $context = AttendanceContext::create([
            'church_id' => $this->church1->id,
            'name' => 'Test Member Update',
            'slug' => 'test-member-update',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->actingAsUser($this->member);

        $response = $this->putJson("/api/v1/attendance-contexts/{$context->id}", [
            'name' => 'Updated Member Test',
        ]);

        $response->assertStatus(403);
    }

    public function test_member_cannot_delete_context(): void
    {
        $context = AttendanceContext::create([
            'church_id' => $this->church1->id,
            'name' => 'Test Member Delete',
            'slug' => 'test-member-delete',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->actingAsUser($this->member);

        $response = $this->deleteJson("/api/v1/attendance-contexts/{$context->id}");

        $response->assertStatus(403);
    }

    public function test_member_cannot_toggle_context_active(): void
    {
        $context = AttendanceContext::create([
            'church_id' => $this->church1->id,
            'name' => 'Test Member Toggle',
            'slug' => 'test-member-toggle',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->actingAsUser($this->member);

        $response = $this->patchJson("/api/v1/attendance-contexts/{$context->id}/toggle-active");

        $response->assertStatus(403);
    }

    // ──────────────────────────────────────────────
    // 8. Cross-church access is blocked
    // ──────────────────────────────────────────────

    public function test_cross_church_context_isolation(): void
    {
        // Church 1 has a context
        $contextChurch1 = AttendanceContext::create([
            'church_id' => $this->church1->id,
            'name' => 'Church 1 Mass',
            'slug' => 'church1-mass',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        // Church 2 has a different context
        AttendanceContext::create([
            'church_id' => $this->church2->id,
            'name' => 'Church 2 Mass',
            'slug' => 'church2-mass',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        // Admin from church 1 should only see church 1 contexts
        $this->actingAsUser($this->admin);

        $response = $this->getJson('/api/v1/attendance-contexts/manage');

        $response->assertStatus(200);
        $contextNames = collect($response->json('data'))->pluck('name');
        $this->assertContains('Church 1 Mass', $contextNames);
        $this->assertNotContains('Church 2 Mass', $contextNames);
    }

    public function test_cross_church_context_cannot_be_accessed_by_id(): void
    {
        // Church 2 context
        $contextChurch2 = AttendanceContext::create([
            'church_id' => $this->church2->id,
            'name' => 'Church 2 Mass',
            'slug' => 'church2-mass',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        // Admin from church 1 tries to access church 2's context
        $this->actingAsUser($this->admin);

        $response = $this->getJson("/api/v1/attendance-contexts/{$contextChurch2->id}");

        // Should return 404 because global scope filters to church 1 only
        $response->assertStatus(404);
    }

    // ──────────────────────────────────────────────
    // 9. Servant can edit context
    // ──────────────────────────────────────────────

    public function test_servant_can_edit_context(): void
    {
        $context = AttendanceContext::create([
            'church_id' => $this->church1->id,
            'name' => 'Old Name',
            'slug' => 'old-name',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->actingAsUser($this->servant);

        $response = $this->putJson("/api/v1/attendance-contexts/{$context->id}", [
            'name' => 'Updated Name',
            'description' => 'Updated description',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Updated Name');

        $this->assertDatabaseHas('attendance_contexts', [
            'id' => $context->id,
            'name' => 'Updated Name',
        ]);
    }

    // ──────────────────────────────────────────────
    // 10. Servant cannot delete context
    // ──────────────────────────────────────────────

    public function test_servant_cannot_delete_context(): void
    {
        $context = AttendanceContext::create([
            'church_id' => $this->church1->id,
            'name' => 'Test Context',
            'slug' => 'test-context',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->actingAsUser($this->servant);

        $response = $this->deleteJson("/api/v1/attendance-contexts/{$context->id}");

        $response->assertStatus(403);
    }

    // ──────────────────────────────────────────────
    // 11. Servant cannot toggle active
    // ──────────────────────────────────────────────

    public function test_servant_cannot_toggle_active(): void
    {
        $context = AttendanceContext::create([
            'church_id' => $this->church1->id,
            'name' => 'Test Context',
            'slug' => 'test-context',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->actingAsUser($this->servant);

        $response = $this->patchJson("/api/v1/attendance-contexts/{$context->id}/toggle-active");

        $response->assertStatus(403);
    }

    // ──────────────────────────────────────────────
    // 12. Admin can toggle active/archive
    // ──────────────────────────────────────────────

    public function test_admin_can_toggle_context_active(): void
    {
        $context = AttendanceContext::create([
            'church_id' => $this->church1->id,
            'name' => 'Test Context',
            'slug' => 'test-context',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->actingAsUser($this->admin);

        // Archive
        $response = $this->patchJson("/api/v1/attendance-contexts/{$context->id}/toggle-active");
        $response->assertStatus(200)
            ->assertJsonPath('data.is_active', false);

        // Activate again
        $response = $this->patchJson("/api/v1/attendance-contexts/{$context->id}/toggle-active");
        $response->assertStatus(200)
            ->assertJsonPath('data.is_active', true);
    }

    // ──────────────────────────────────────────────
    // 13. Assistant Admin can toggle active/archive
    // ──────────────────────────────────────────────

    public function test_assistant_admin_can_toggle_context_active(): void
    {
        $context = AttendanceContext::create([
            'church_id' => $this->church1->id,
            'name' => 'Test Context',
            'slug' => 'test-context',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->actingAsUser($this->assistantAdmin);

        $response = $this->patchJson("/api/v1/attendance-contexts/{$context->id}/toggle-active");
        $response->assertStatus(200)
            ->assertJsonPath('data.is_active', false);
    }

    // ──────────────────────────────────────────────
    // 14. Context name is unique per church
    // ──────────────────────────────────────────────

    public function test_context_name_unique_within_church(): void
    {
        AttendanceContext::create([
            'church_id' => $this->church1->id,
            'name' => 'Unique Context A',
            'slug' => 'unique-context-a',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->actingAsUser($this->admin);

        $response = $this->postJson('/api/v1/attendance-contexts', [
            'name' => 'Unique Context A',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    // ──────────────────────────────────────────────
    // 15. Same context name allowed in different churches
    // ──────────────────────────────────────────────

    public function test_same_context_name_allowed_in_different_church(): void
    {
        // Church 1 has a context named "Shared Context"
        AttendanceContext::create([
            'church_id' => $this->church1->id,
            'name' => 'Shared Context',
            'slug' => 'shared-context',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        // Create a user in church 2
        $adminChurch2 = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $this->church2->id,
            'application_status' => 'approved',
        ]);

        $this->actingAsUser($adminChurch2);

        // Church 2 should be able to create a context with same name
        $response = $this->postJson('/api/v1/attendance-contexts', [
            'name' => 'Shared Context',
        ]);

        $response->assertStatus(201);
    }

    // ──────────────────────────────────────────────
    // 16. New church gets default contexts on creation
    // ──────────────────────────────────────────────

    public function test_new_church_gets_default_contexts(): void
    {
        $church = Church::factory()->create(['name' => 'New Test Church']);

        $this->assertDatabaseHas('attendance_contexts', [
            'church_id' => $church->id,
            'name' => 'Sunday School',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('attendance_contexts', [
            'church_id' => $church->id,
            'name' => 'Mass',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('attendance_contexts', [
            'church_id' => $church->id,
            'name' => 'Holiday',
            'is_active' => true,
        ]);

        // Verify all 6 default contexts
        $count = AttendanceContext::where('church_id', $church->id)->count();
        $this->assertEquals(6, $count, 'New church should have exactly 6 default contexts');
    }
}
