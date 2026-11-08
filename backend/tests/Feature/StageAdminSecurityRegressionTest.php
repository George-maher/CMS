<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserScope;
use App\Models\Church;
use App\Models\Classe;
use App\Models\Permission;
use App\Models\Stage;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Security regression tests for Stage Admin authorization.
 *
 * Covers:
 * - IDOR/BOLA prevention on single-resource endpoints
 * - List endpoint scoping (no accidental global lists)
 * - Dashboard aggregate scoping
 * - Transaction safety (denied writes produce zero mutation)
 * - Client-supplied tenant ID spoofing
 */
class StageAdminSecurityRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Permission::clearCache();
    }

    /**
     * Create a church with two stages, two classes each, and users.
     *
     * @return array{church: Church, stage1: Stage, stage2: Stage, class1a: Classe, class1b: Classe, class2a: Classe, class2b: Classe, stageAdmin: User, stageAdminToken: string, member1a: User, member2a: User, servant1a: User, servant2a: User}
     */
    private function makeScenario(): array
    {
        $church = Church::factory()->create();

        $stage1 = Stage::factory()->forChurch($church)->create(['name' => 'Stage 1']);
        $stage2 = Stage::factory()->forChurch($church)->create(['name' => 'Stage 2']);

        $class1a = Classe::factory()->forChurch($church)->state(['stage_id' => $stage1->id])->create(['name' => '1A']);
        $class1b = Classe::factory()->forChurch($church)->state(['stage_id' => $stage1->id])->create(['name' => '1B']);
        $class2a = Classe::factory()->forChurch($church)->state(['stage_id' => $stage2->id])->create(['name' => '2A']);
        $class2b = Classe::factory()->forChurch($church)->state(['stage_id' => $stage2->id])->create(['name' => '2B']);

        $stageAdmin = User::factory()->create([
            'role' => UserRole::StageAdmin,
            'church_id' => $church->id,
            'stage_id' => $stage1->id,
            'scope' => UserScope::Stage->value,
            'application_status' => 'approved',
        ]);
        $stageAdminToken = $stageAdmin->createToken('test', [$stageAdmin->role->value])->plainTextToken;

        $member1a = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $church->id,
            'stage_id' => $stage1->id,
            'class_id' => $class1a->id,
            'scope' => UserScope::Self->value,
            'application_status' => 'approved',
        ]);

        $member2a = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $church->id,
            'stage_id' => $stage2->id,
            'class_id' => $class2a->id,
            'scope' => UserScope::Self->value,
            'application_status' => 'approved',
        ]);

        $servant1a = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $church->id,
            'stage_id' => $stage1->id,
            'class_id' => $class1a->id,
            'scope' => UserScope::ClassScope->value,
            'application_status' => 'approved',
        ]);

        $servant2a = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $church->id,
            'stage_id' => $stage2->id,
            'class_id' => $class2a->id,
            'scope' => UserScope::ClassScope->value,
            'application_status' => 'approved',
        ]);

        return compact(
            'church', 'stage1', 'stage2', 'class1a', 'class1b', 'class2a', 'class2b',
            'stageAdmin', 'stageAdminToken',
            'member1a', 'member2a', 'servant1a', 'servant2a'
        );
    }

    private function authAs(string $token): self
    {
        return $this->withHeader('Authorization', "Bearer $token");
    }

    // ---------------------------------------------------------------
    // IDOR/BOLA — Single-resource endpoints
    // ---------------------------------------------------------------

    public function test_idor_stage_admin_cannot_read_other_stage_by_id(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['stageAdminToken'])
            ->getJson("/api/v1/stages/{$s['stage2']->id}");

        $response->assertStatus(403);
    }

    public function test_idor_stage_admin_cannot_read_other_stage_class_by_id(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['stageAdminToken'])
            ->getJson("/api/v1/classes/{$s['class2a']->id}");

        $response->assertStatus(403);
    }

    public function test_idor_stage_admin_cannot_read_other_stage_user_by_id(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['stageAdminToken'])
            ->getJson("/api/v1/users/{$s['member2a']->id}");

        $response->assertStatus(403);
    }

    public function test_idor_stage_admin_cannot_update_other_stage_user(): void
    {
        $s = $this->makeScenario();
        $originalName = $s['member2a']->name;

        $response = $this->authAs($s['stageAdminToken'])
            ->putJson("/api/v1/users/{$s['member2a']->id}", [
                'name' => 'Hijacked Name',
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', [
            'id' => $s['member2a']->id,
            'name' => $originalName,
        ]);
    }

    public function test_idor_stage_admin_cannot_update_other_stage_class(): void
    {
        $s = $this->makeScenario();
        $originalName = $s['class2a']->name;

        $response = $this->authAs($s['stageAdminToken'])
            ->putJson("/api/v1/classes/{$s['class2a']->id}", [
                'name' => 'Hijacked Class',
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('classes', [
            'id' => $s['class2a']->id,
            'name' => $originalName,
        ]);
    }

    public function test_idor_stage_admin_cannot_delete_other_stage_class(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['stageAdminToken'])
            ->deleteJson("/api/v1/classes/{$s['class2a']->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('classes', ['id' => $s['class2a']->id]);
    }

    // ---------------------------------------------------------------
    // List endpoint scoping — no accidental global lists
    // ---------------------------------------------------------------

    public function test_list_stage_admin_stages_returns_only_assigned_stage(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['stageAdminToken'])
            ->getJson('/api/v1/stages');

        $response->assertStatus(200);
        $stages = $response->json('data', []);
        $stageIds = array_column($stages, 'id');
        $this->assertContains($s['stage1']->id, $stageIds);
        $this->assertNotContains($s['stage2']->id, $stageIds);
    }

    public function test_list_stage_admin_classes_returns_only_stage_classes(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['stageAdminToken'])
            ->getJson('/api/v1/classes');

        $response->assertStatus(200);
        $classes = $response->json('data', []);
        $classIds = array_column($classes, 'id');
        $this->assertContains($s['class1a']->id, $classIds);
        $this->assertContains($s['class1b']->id, $classIds);
        $this->assertNotContains($s['class2a']->id, $classIds);
        $this->assertNotContains($s['class2b']->id, $classIds);
    }

    public function test_list_stage_admin_users_returns_only_stage_users(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['stageAdminToken'])
            ->getJson('/api/v1/users');

        $response->assertStatus(200);
        $users = $response->json('data', []);
        $emails = array_column($users, 'email');
        $this->assertContains($s['member1a']->email, $emails);
        $this->assertContains($s['servant1a']->email, $emails);
        $this->assertNotContains($s['member2a']->email, $emails);
        $this->assertNotContains($s['servant2a']->email, $emails);
    }

    // ---------------------------------------------------------------
    // Dashboard aggregate scoping
    // ---------------------------------------------------------------

    public function test_dashboard_aggregates_are_stage_scoped(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['stageAdminToken'])
            ->getJson('/api/v1/dashboard/stats');

        $response->assertStatus(200);
        $stats = $response->json('data');

        // Stage 1 has: 1 member (member1a), 1 servant (servant1a)
        // Stage 2 has: 1 member (member2a), 1 servant (servant2a)
        $this->assertSame(1, $stats['total_members']);
        $this->assertSame(1, $stats['total_servants']);
    }

    public function test_dashboard_aggregates_not_church_wide(): void
    {
        $s = $this->makeScenario();

        // Create a church admin to compare
        $churchAdmin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $s['church']->id,
            'application_status' => 'approved',
        ]);
        $churchAdminToken = $churchAdmin->createToken('test', [$churchAdmin->role->value])->plainTextToken;

        $churchStats = $this->authAs($churchAdminToken)
            ->getJson('/api/v1/dashboard/stats')
            ->assertStatus(200)
            ->json('data');

        $stageStats = $this->authAs($s['stageAdminToken'])
            ->getJson('/api/v1/dashboard/stats')
            ->assertStatus(200)
            ->json('data');

        // Church-wide should have 2 members, stage-scoped should have 1
        $this->assertSame(2, $churchStats['total_members']);
        $this->assertSame(1, $stageStats['total_members']);
    }

    // ---------------------------------------------------------------
    // Transaction safety — denied writes produce zero mutation
    // ---------------------------------------------------------------

    public function test_denied_write_produces_zero_mutation_on_user_update(): void
    {
        $s = $this->makeScenario();
        $originalName = $s['member2a']->name;
        $originalEmail = $s['member2a']->email;

        $this->authAs($s['stageAdminToken'])
            ->putJson("/api/v1/users/{$s['member2a']->id}", [
                'name' => 'Hijacked',
                'email' => 'hijacked@test.com',
            ])
            ->assertStatus(403);

        $this->assertDatabaseHas('users', [
            'id' => $s['member2a']->id,
            'name' => $originalName,
            'email' => $originalEmail,
        ]);
    }

    public function test_denied_write_produces_zero_mutation_on_class_update(): void
    {
        $s = $this->makeScenario();
        $originalName = $s['class2a']->name;

        $this->authAs($s['stageAdminToken'])
            ->putJson("/api/v1/classes/{$s['class2a']->id}", [
                'name' => 'Hijacked',
            ])
            ->assertStatus(403);

        $this->assertDatabaseHas('classes', [
            'id' => $s['class2a']->id,
            'name' => $originalName,
        ]);
    }

    public function test_denied_write_produces_zero_mutation_on_user_creation(): void
    {
        $s = $this->makeScenario();

        $this->authAs($s['stageAdminToken'])
            ->postJson('/api/v1/users', [
                'name' => 'Intruder',
                'email' => 'intruder@test.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'role' => UserRole::Member->value,
                'class_id' => $s['class2a']->id,
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('users', ['email' => 'intruder@test.com']);
    }

    // ---------------------------------------------------------------
    // Client-supplied tenant ID spoofing
    // ---------------------------------------------------------------

    public function test_spoofing_stage_id_in_user_creation_is_ignored(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['stageAdminToken'])
            ->postJson('/api/v1/users', [
                'name' => 'Spoofed',
                'email' => 'spoofed@test.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'role' => UserRole::Member->value,
                'class_id' => $s['class1a']->id,
                'stage_id' => $s['stage2']->id, // malicious
            ]);

        $response->assertStatus(201);
        // The stage_id must be derived from the class, not the client input
        $this->assertDatabaseHas('users', [
            'email' => 'spoofed@test.com',
            'stage_id' => $s['stage1']->id,
        ]);
    }

    public function test_spoofing_class_id_in_user_creation_is_denied(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['stageAdminToken'])
            ->postJson('/api/v1/users', [
                'name' => 'Spoofed',
                'email' => 'spoofed2@test.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'role' => UserRole::Member->value,
                'class_id' => $s['class2a']->id, // other stage's class
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('users', ['email' => 'spoofed2@test.com']);
    }

    public function test_spoofing_stage_id_in_class_creation_is_denied(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['stageAdminToken'])
            ->postJson('/api/v1/classes', [
                'name' => 'Stolen Class',
                'stage_id' => $s['stage2']->id,
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('classes', ['name' => 'Stolen Class']);
    }

    // ---------------------------------------------------------------
    // Servant class isolation
    // ---------------------------------------------------------------

    public function test_servant_cannot_access_sibling_class(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['servant1a']->createToken('test', ['servant'])->plainTextToken)
            ->getJson("/api/v1/classes/{$s['class1b']->id}");

        $response->assertStatus(403);
    }

    public function test_servant_cannot_access_other_stage_class(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['servant1a']->createToken('test', ['servant'])->plainTextToken)
            ->getJson("/api/v1/classes/{$s['class2a']->id}");

        $response->assertStatus(403);
    }

    public function test_servant_cannot_list_users_globally(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['servant1a']->createToken('test', ['servant'])->plainTextToken)
            ->getJson('/api/v1/users');

        $response->assertStatus(403);
    }

    // ---------------------------------------------------------------
    // assistant_admin == admin parity
    // ---------------------------------------------------------------

    public function test_assistant_admin_has_same_church_wide_access_as_admin(): void
    {
        $s = $this->makeScenario();

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $s['church']->id,
            'application_status' => 'approved',
        ]);
        $adminToken = $admin->createToken('test', ['admin'])->plainTextToken;

        $assistant = User::factory()->create([
            'role' => UserRole::AssistantAdmin,
            'church_id' => $s['church']->id,
            'application_status' => 'approved',
        ]);
        $assistantToken = $assistant->createToken('test', ['assistant_admin'])->plainTextToken;

        // Both should see all stages
        $adminStages = $this->authAs($adminToken)->getJson('/api/v1/stages')->json('data', []);
        $assistantStages = $this->authAs($assistantToken)->getJson('/api/v1/stages')->json('data', []);

        $adminIds = array_column($adminStages, 'id');
        $assistantIds = array_column($assistantStages, 'id');

        $this->assertContains($s['stage1']->id, $adminIds);
        $this->assertContains($s['stage2']->id, $adminIds);
        $this->assertContains($s['stage1']->id, $assistantIds);
        $this->assertContains($s['stage2']->id, $assistantIds);
        $this->assertSame($adminIds, $assistantIds);
    }

    public function test_assistant_admin_cannot_access_other_church(): void
    {
        $s = $this->makeScenario();

        $otherChurch = Church::factory()->create();
        $otherStage = Stage::factory()->forChurch($otherChurch)->create();
        $otherClass = Classe::factory()->forChurch($otherChurch)->state(['stage_id' => $otherStage->id])->create();
        $otherUser = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $otherChurch->id,
            'stage_id' => $otherStage->id,
            'class_id' => $otherClass->id,
            'application_status' => 'approved',
        ]);

        $assistant = User::factory()->create([
            'role' => UserRole::AssistantAdmin,
            'church_id' => $s['church']->id,
            'application_status' => 'approved',
        ]);
        $assistantToken = $assistant->createToken('test', ['assistant_admin'])->plainTextToken;

        $response = $this->authAs($assistantToken)
            ->getJson("/api/v1/users/{$otherUser->id}");

        $response->assertStatus(404);
    }
}
