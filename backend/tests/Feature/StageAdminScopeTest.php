<?php

namespace Tests\Feature;

use App\Enums\QRInviteType;
use App\Enums\UserRole;
use App\Enums\UserScope;
use App\Models\Church;
use App\Models\Classe;
use App\Models\Event;
use App\Models\Permission;
use App\Models\Stage;
use App\Models\User;
use App\Modules\User\Services\UserService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StageAdminScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Permission::clearCache();
    }

    /**
     * Create a church with two stages and one class each, plus stage admin,
     * member and servant in each stage.
     *
     * @return array{church: Church, secStage: Stage, prepStage: Stage, secClasse: Classe, prepClasse: Classe, secAdmin: User, secAdminToken: string, secMember: User, secServant: User, prepMember: User, prepServant: User}
     */
    private function makeScenario(): array
    {
        $church = Church::factory()->create();

        $secStage = Stage::factory()->forChurch($church)->create(['name' => 'Secondary']);
        $prepStage = Stage::factory()->forChurch($church)->create(['name' => 'Preparatory']);

        $secClasse = Classe::factory()->forChurch($church)->state(['stage_id' => $secStage->id])->create(['name' => 'S1']);
        $prepClasse = Classe::factory()->forChurch($church)->state(['stage_id' => $prepStage->id])->create(['name' => 'P1']);

        $secAdmin = User::factory()->create([
            'role' => UserRole::StageAdmin,
            'church_id' => $church->id,
            'stage_id' => $secStage->id,
            'scope' => UserScope::Stage->value,
            'application_status' => 'approved',
        ]);
        $secAdminToken = $secAdmin->createToken('test', [$secAdmin->role->value])->plainTextToken;

        $secMember = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $church->id,
            'stage_id' => $secStage->id,
            'class_id' => $secClasse->id,
            'scope' => UserScope::Self->value,
            'application_status' => 'approved',
        ]);

        $secServant = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $church->id,
            'stage_id' => $secStage->id,
            'class_id' => $secClasse->id,
            'scope' => UserScope::ClassScope->value,
            'application_status' => 'approved',
        ]);

        $prepMember = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $church->id,
            'stage_id' => $prepStage->id,
            'class_id' => $prepClasse->id,
            'scope' => UserScope::Self->value,
            'application_status' => 'approved',
        ]);

        $prepServant = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $church->id,
            'stage_id' => $prepStage->id,
            'class_id' => $prepClasse->id,
            'scope' => UserScope::ClassScope->value,
            'application_status' => 'approved',
        ]);

        return compact(
            'church', 'secStage', 'prepStage', 'secClasse', 'prepClasse',
            'secAdmin', 'secAdminToken',
            'secMember', 'secServant', 'prepMember', 'prepServant'
        );
    }

    private function authAs(string $token, string $middleware = ''): self
    {
        return $this->withHeader('Authorization', "Bearer $token");
    }

    // ---------------------------------------------------------------
    // A. User creation — stage boundary
    // ---------------------------------------------------------------

    public function test_stage_admin_can_create_member_in_own_class(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['secAdminToken'])
            ->postJson('/api/v1/users', [
                'name' => 'New Member',
                'email' => 'newmember@test.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'role' => UserRole::Member->value,
                'class_id' => $s['secClasse']->id,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'newmember@test.com',
            'stage_id' => $s['secStage']->id,
            'scope' => UserScope::Self->value,
            'church_id' => $s['church']->id,
        ]);
    }

    public function test_stage_admin_cannot_create_member_in_other_stage_class(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['secAdminToken'])
            ->postJson('/api/v1/users', [
                'name' => 'Intruder',
                'email' => 'intruder@test.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'role' => UserRole::Member->value,
                'class_id' => $s['prepClasse']->id,
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('users', ['email' => 'intruder@test.com']);
    }

    public function test_stage_admin_cannot_create_admin_or_assistant(): void
    {
        $s = $this->makeScenario();

        foreach ([UserRole::Admin->value, UserRole::AssistantAdmin->value] as $role) {
            $response = $this->authAs($s['secAdminToken'])
                ->postJson('/api/v1/users', [
                    'name' => 'Fake Admin',
                    'email' => 'fakeadmin@test.com',
                    'password' => 'password123',
                    'password_confirmation' => 'password123',
                    'role' => $role,
                    'class_id' => $s['secClasse']->id,
                ]);

            $response->assertStatus(403);
        }
    }

    public function test_stage_admin_cannot_create_member_without_class(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['secAdminToken'])
            ->postJson('/api/v1/users', [
                'name' => 'Classless',
                'email' => 'classless@test.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'role' => UserRole::Member->value,
            ]);

        $response->assertStatus(403);
    }

    public function test_stage_admin_client_stage_id_ignored(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['secAdminToken'])
            ->postJson('/api/v1/users', [
                'name' => 'Stage Spoof',
                'email' => 'stagespoof@test.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'role' => UserRole::Member->value,
                'class_id' => $s['secClasse']->id,
                'stage_id' => $s['prepStage']->id, // malicious payload
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'stagespoof@test.com',
            'stage_id' => $s['secStage']->id, // must be the class's stage
        ]);
    }

    // ---------------------------------------------------------------
    // B. User listing — stage boundary
    // ---------------------------------------------------------------

    public function test_stage_admin_list_users_scoped_to_stage(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['secAdminToken'])
            ->getJson('/api/v1/users');

        $response->assertStatus(200);
        $emails = collect($response->json('data', []))->pluck('email')->toArray();
        $this->assertContains($s['secMember']->email, $emails);
        $this->assertContains($s['secServant']->email, $emails);
        $this->assertNotContains($s['prepMember']->email, $emails);
        $this->assertNotContains($s['prepServant']->email, $emails);
    }

    public function test_stage_admin_can_read_own_stage_user(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['secAdminToken'])
            ->getJson("/api/v1/users/{$s['secMember']->id}");

        $response->assertStatus(200);
    }

    public function test_stage_admin_cannot_read_other_stage_user(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['secAdminToken'])
            ->getJson("/api/v1/users/{$s['prepMember']->id}");

        $response->assertStatus(403);
    }

    public function test_stage_admin_cannot_update_other_stage_user(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['secAdminToken'])
            ->putJson("/api/v1/users/{$s['prepMember']->id}", [
                'name' => 'Hijacked',
            ]);

        $response->assertStatus(403);
    }

    // ---------------------------------------------------------------
    // C. Promote / demote — stage semantics
    // ---------------------------------------------------------------

    public function test_promote_to_stage_admin_sets_stage(): void
    {
        $s = $this->makeScenario();
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $s['church']->id,
        ]);
        $adminToken = $admin->createToken('test', [$admin->role->value])->plainTextToken;

        $response = $this->authAs($adminToken)
            ->postJson("/api/v1/users/{$s['secMember']->id}/promote", [
                'role' => UserRole::StageAdmin->value,
                'stage_id' => $s['secStage']->id,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $s['secMember']->id,
            'role' => UserRole::StageAdmin->value,
            'stage_id' => $s['secStage']->id,
            'scope' => UserScope::Stage->value,
        ]);
    }

    public function test_promote_to_admin_clears_stage(): void
    {
        $s = $this->makeScenario();
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $s['church']->id,
        ]);
        $adminToken = $admin->createToken('test', [$admin->role->value])->plainTextToken;

        $response = $this->authAs($adminToken)
            ->postJson("/api/v1/users/{$s['secAdmin']->id}/promote", [
                'role' => UserRole::Admin->value,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $s['secAdmin']->id,
            'role' => UserRole::Admin->value,
            'stage_id' => null,
            'scope' => UserScope::Church->value,
        ]);
    }

    public function test_demote_stage_admin_to_member_clears_stage(): void
    {
        $s = $this->makeScenario();
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $s['church']->id,
        ]);
        $adminToken = $admin->createToken('test', [$admin->role->value])->plainTextToken;

        $response = $this->authAs($adminToken)
            ->postJson("/api/v1/users/{$s['secAdmin']->id}/demote", [
                'role' => UserRole::Member->value,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $s['secAdmin']->id,
            'role' => UserRole::Member->value,
            'stage_id' => null,
            'scope' => UserScope::Self->value,
        ]);
    }

    // ---------------------------------------------------------------
    // D. Class management — stage boundary
    // ---------------------------------------------------------------

    public function test_stage_admin_cannot_create_class(): void
    {
        $s = $this->makeScenario();

        // Class creation is gated by StagePolicy::create (admin only), so a
        // stage admin is denied even for their own stage.
        $response = $this->authAs($s['secAdminToken'])
            ->postJson('/api/v1/classes', [
                'name' => 'New Class',
                'stage_id' => $s['secStage']->id,
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('classes', ['name' => 'New Class']);
    }

    public function test_stage_admin_cannot_create_class_in_other_stage(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['secAdminToken'])
            ->postJson('/api/v1/classes', [
                'name' => 'Stolen Class',
                'stage_id' => $s['prepStage']->id,
            ]);

        $response->assertStatus(403);
    }

    public function test_stage_admin_can_update_own_class(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['secAdminToken'])
            ->putJson("/api/v1/classes/{$s['secClasse']->id}", [
                'name' => 'S1 Updated',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('classes', ['id' => $s['secClasse']->id, 'name' => 'S1 Updated']);
    }

    public function test_stage_admin_cannot_update_other_stage_class(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['secAdminToken'])
            ->putJson("/api/v1/classes/{$s['prepClasse']->id}", [
                'name' => 'Hijacked',
            ]);

        $response->assertStatus(403);
    }

    public function test_stage_admin_cannot_reorder_class_from_other_stage(): void
    {
        $s = $this->makeScenario();
        $originalOrder = $s['prepClasse']->display_order;

        $response = $this->authAs($s['secAdminToken'])
            ->postJson('/api/v1/classes/reorder', [
                'ordered_ids' => [$s['secClasse']->id, $s['prepClasse']->id],
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('classes', [
            'id' => $s['prepClasse']->id,
            'display_order' => $originalOrder,
        ]);
    }

    // ---------------------------------------------------------------
    // E. Assignments — stage boundary
    // ---------------------------------------------------------------

    public function test_stage_admin_can_assign_member_within_stage(): void
    {
        $s = $this->makeScenario();
        $unassigned = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $s['church']->id,
            'stage_id' => $s['secStage']->id,
            'scope' => UserScope::Self->value,
            'application_status' => 'approved',
        ]);

        $response = $this->authAs($s['secAdminToken'])
            ->postJson("/api/v1/classes/{$s['secClasse']->id}/assign-member", [
                'user_id' => $unassigned->id,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $unassigned->id,
            'class_id' => $s['secClasse']->id,
            'stage_id' => $s['secStage']->id,
            'scope' => UserScope::Self->value,
        ]);
    }

    public function test_stage_admin_cannot_assign_other_stage_member(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['secAdminToken'])
            ->postJson("/api/v1/classes/{$s['secClasse']->id}/assign-member", [
                'user_id' => $s['prepMember']->id,
            ]);

        $response->assertStatus(403);
    }

    public function test_stage_admin_can_assign_servant_within_stage(): void
    {
        $s = $this->makeScenario();
        $newServant = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $s['church']->id,
            'stage_id' => $s['secStage']->id,
            'scope' => UserScope::ClassScope->value,
            'application_status' => 'approved',
        ]);

        $response = $this->authAs($s['secAdminToken'])
            ->postJson("/api/v1/classes/{$s['secClasse']->id}/assign-servant", [
                'user_id' => $newServant->id,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('class_servant', [
            'class_id' => $s['secClasse']->id,
            'user_id' => $newServant->id,
        ]);
    }

    public function test_stage_admin_cannot_assign_other_stage_servant(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['secAdminToken'])
            ->postJson("/api/v1/classes/{$s['secClasse']->id}/assign-servant", [
                'user_id' => $s['prepServant']->id,
            ]);

        $response->assertStatus(403);
    }

    // ---------------------------------------------------------------
    // F. Stage CRUD — stage admin cannot manage stages
    // ---------------------------------------------------------------

    public function test_stage_admin_cannot_create_or_update_or_delete_stages(): void
    {
        $s = $this->makeScenario();

        $create = $this->authAs($s['secAdminToken'])
            ->postJson('/api/v1/stages', ['name' => 'X']);
        $create->assertStatus(403);

        $update = $this->authAs($s['secAdminToken'])
            ->putJson("/api/v1/stages/{$s['secStage']->id}", ['name' => 'Hijacked']);
        $update->assertStatus(403);

        $delete = $this->authAs($s['secAdminToken'])
            ->deleteJson("/api/v1/stages/{$s['secStage']->id}");
        $delete->assertStatus(403);
    }

    public function test_stage_admin_can_read_own_stage(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['secAdminToken'])
            ->getJson("/api/v1/stages/{$s['secStage']->id}");

        $response->assertStatus(200);
    }

    public function test_stage_admin_cannot_read_other_stage(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['secAdminToken'])
            ->getJson("/api/v1/stages/{$s['prepStage']->id}");

        $response->assertStatus(403);
    }

    // ---------------------------------------------------------------
    // G. Invite scope — stage forced server-side
    // ---------------------------------------------------------------

    public function test_stage_admin_invite_forced_to_own_stage(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['secAdminToken'])
            ->postJson('/api/v1/qr/invites', [
                'type' => QRInviteType::ServantToMemberInvite->value,
                'stage_id' => $s['prepStage']->id, // malicious — must be ignored
            ]);

        $response->assertStatus(201);
        $inviteId = $response->json('data.invite.id');
        $this->assertDatabaseHas('qr_invites', [
            'id' => $inviteId,
            'stage_id' => $s['secStage']->id,
        ]);
    }

    public function test_stage_admin_invite_list_filtered_to_own_stage(): void
    {
        $s = $this->makeScenario();

        // Create invite as stage admin (forced to sec stage)
        $this->authAs($s['secAdminToken'])
            ->postJson('/api/v1/qr/invites', [
                'type' => QRInviteType::ServantToMemberInvite->value,
            ])->assertStatus(201);

        // Create invite as admin in prep stage
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $s['church']->id,
        ]);
        $adminToken = $admin->createToken('test', [$admin->role->value])->plainTextToken;
        $this->authAs($adminToken)
            ->postJson('/api/v1/qr/invites', [
                'type' => QRInviteType::AdminToServantInvite->value,
                'stage_id' => $s['prepStage']->id,
            ])->assertStatus(201);

        // Stage admin must see only sec-stage invites
        $response = $this->authAs($s['secAdminToken'])
            ->getJson('/api/v1/qr/invites');

        $response->assertStatus(200);
        $invites = $response->json('data', []);
        $this->assertNotEmpty($invites);
        foreach ($invites as $invite) {
            $this->assertEquals($s['secStage']->id, $invite['stage']['id'] ?? null);
        }
    }

    // ---------------------------------------------------------------
    // H. Cross-church isolation
    // ---------------------------------------------------------------

    public function test_stage_admin_cannot_access_other_church_user(): void
    {
        $s = $this->makeScenario();
        $otherChurch = Church::factory()->create();
        $otherStage = Stage::factory()->forChurch($otherChurch)->create();
        $otherClasse = Classe::factory()->forChurch($otherChurch)->state(['stage_id' => $otherStage->id])->create();
        $otherMember = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $otherChurch->id,
            'stage_id' => $otherStage->id,
            'class_id' => $otherClasse->id,
            'application_status' => 'approved',
        ]);

        $read = $this->authAs($s['secAdminToken'])
            ->getJson("/api/v1/users/{$otherMember->id}");
        // Cross-church users are invisible via the byChurch scope → 404 (prevents enumeration).
        $read->assertStatus(404);

        $update = $this->authAs($s['secAdminToken'])
            ->putJson("/api/v1/users/{$otherMember->id}", ['name' => 'Nope']);
        $update->assertStatus(404);
    }

    public function test_stage_admin_cannot_access_other_church_class(): void
    {
        $s = $this->makeScenario();
        $otherChurch = Church::factory()->create();
        $otherStage = Stage::factory()->forChurch($otherChurch)->create();
        $otherClasse = Classe::factory()->forChurch($otherChurch)->state(['stage_id' => $otherStage->id])->create();

        $response = $this->authAs($s['secAdminToken'])
            ->postJson("/api/v1/classes/{$otherClasse->id}/assign-member", [
                'user_id' => $s['secMember']->id,
            ]);

        // Cross-church classes are invisible via the church global scope → 404.
        $response->assertStatus(404);
    }

    // ---------------------------------------------------------------
    // I. Privileged role / password changes via PUT /users/{id} — P0
    //    guards mirroring promote()/destroy(): church-admin tier only.
    // ---------------------------------------------------------------

    public function test_stage_admin_cannot_grant_admin_role_via_user_update(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['secAdminToken'])
            ->putJson("/api/v1/users/{$s['secMember']->id}", [
                'role' => UserRole::Admin->value,
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', [
            'id' => $s['secMember']->id,
            'role' => UserRole::Member->value,
        ]);
    }

    public function test_stage_admin_cannot_reset_in_scope_user_password_via_user_update(): void
    {
        $s = $this->makeScenario();
        $beforeHash = User::find($s['secServant']->id)?->password;

        $response = $this->authAs($s['secAdminToken'])
            ->putJson("/api/v1/users/{$s['secServant']->id}", [
                'password' => 'Hijacked@123',
                'password_confirmation' => 'Hijacked@123',
            ]);

        $response->assertStatus(403);
        $this->assertSame($beforeHash, User::find($s['secServant']->id)?->password);
    }

    public function test_stage_admin_cannot_demote_peer_stage_admin_via_user_update(): void
    {
        $s = $this->makeScenario();
        $peer = User::factory()->create([
            'role' => UserRole::StageAdmin,
            'church_id' => $s['church']->id,
            'stage_id' => $s['secStage']->id,
            'class_id' => $s['secClasse']->id,
            'scope' => UserScope::Stage->value,
            'application_status' => 'approved',
        ]);

        $response = $this->authAs($s['secAdminToken'])
            ->putJson("/api/v1/users/{$peer->id}", [
                'role' => UserRole::Member->value,
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', [
            'id' => $peer->id,
            'role' => UserRole::StageAdmin->value,
        ]);
    }

    public function test_church_admin_can_change_role_and_password_via_user_update(): void
    {
        $s = $this->makeScenario();
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $s['church']->id,
            'application_status' => 'approved',
        ]);
        $token = $admin->createToken('test', [$admin->role->value])->plainTextToken;

        $response = $this->authAs($token)
            ->putJson("/api/v1/users/{$s['secMember']->id}", [
                'role' => UserRole::AssistantAdmin->value,
                'password' => 'Upgraded@123',
                'password_confirmation' => 'Upgraded@123',
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('users', [
            'id' => $s['secMember']->id,
            'role' => UserRole::AssistantAdmin->value,
        ]);
        $this->assertTrue(Hash::check('Upgraded@123', (string) User::find($s['secMember']->id)?->password));
    }

    public function test_user_service_update_defense_in_depth_blocks_privileged_role_grant(): void
    {
        $s = $this->makeScenario();

        // Defense-in-depth: even if a future controller forgets the guard,
        // UserService::update must refuse a non-admin-tier privileged grant.
        $this->expectException(ValidationException::class);

        app(UserService::class)->update(
            $s['secMember']->id,
            ['role' => UserRole::Admin->value],
            $s['secAdmin']->id,
        );
    }

    // ---------------------------------------------------------------
    // P2 scope hardening — absent members, points, events, demote
    // ---------------------------------------------------------------

    public function test_stage_admin_cannot_read_absent_members_of_another_stage_class(): void
    {
        $s = $this->makeScenario();

        $this->authAs($s['secAdminToken'])
            ->getJson('/api/v1/attendances/absent-members?class_id='.$s['prepClasse']->id)
            ->assertStatus(403);
    }

    public function test_absent_members_class_lookup_is_church_scoped(): void
    {
        $s = $this->makeScenario();

        $foreignChurch = Church::factory()->create();
        $foreignStage = Stage::factory()->forChurch($foreignChurch)->create();
        Classe::factory()->forChurch($foreignChurch)->state(['stage_id' => $foreignStage->id])->create();
        $foreignAdmin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $foreignChurch->id,
            'application_status' => 'approved',
        ]);
        $foreignToken = $foreignAdmin->createToken('test', ['admin'])->plainTextToken;

        // A class of another church must not resolve for this caller.
        $this->authAs($foreignToken)
            ->getJson('/api/v1/attendances/absent-members?class_id='.$s['secClasse']->id)
            ->assertStatus(404);
    }

    public function test_stage_admin_cannot_demote_peer_stage_admin_via_demote_endpoint(): void
    {
        $s = $this->makeScenario();

        $peer = User::factory()->create([
            'role' => UserRole::StageAdmin,
            'church_id' => $s['church']->id,
            'stage_id' => $s['secStage']->id,
            'scope' => UserScope::Stage->value,
            'application_status' => 'approved',
        ]);

        $this->authAs($s['secAdminToken'])
            ->postJson('/api/v1/users/'.$peer->id.'/demote', ['role' => 'member'])
            ->assertStatus(403);

        $this->assertDatabaseHas('users', [
            'id' => $peer->id,
            'role' => UserRole::StageAdmin->value,
        ]);
    }

    public function test_stage_admin_cannot_read_points_history_of_member_in_another_stage(): void
    {
        $s = $this->makeScenario();

        $this->authAs($s['secAdminToken'])
            ->getJson('/api/v1/points/user/'.$s['prepMember']->id.'/history')
            ->assertStatus(403);
    }

    public function test_stage_admin_cannot_read_points_balance_of_member_in_another_stage(): void
    {
        $s = $this->makeScenario();

        $this->authAs($s['secAdminToken'])
            ->getJson('/api/v1/points/user/'.$s['prepMember']->id.'/balance')
            ->assertStatus(403);
    }

    public function test_stage_admin_cannot_award_bonus_points_to_member_in_another_stage(): void
    {
        $s = $this->makeScenario();

        $this->authAs($s['secAdminToken'])
            ->postJson('/api/v1/points/bonus', [
                'user_id' => $s['prepMember']->id,
                'points' => 10,
                'reason' => 'Cross-stage bonus attempt',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user_id']);
    }

    public function test_event_update_cannot_target_class_in_another_church(): void
    {
        $s = $this->makeScenario();

        $churchAdmin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $s['church']->id,
            'application_status' => 'approved',
        ]);
        $churchAdminToken = $churchAdmin->createToken('test', ['admin'])->plainTextToken;

        $event = Event::factory()->create([
            'church_id' => $s['church']->id,
            'created_by' => $churchAdmin->id,
            'class_year_id' => $s['secClasse']->id,
        ]);

        $foreignChurch = Church::factory()->create();
        $foreignStage = Stage::factory()->forChurch($foreignChurch)->create();
        $foreignClasse = Classe::factory()->forChurch($foreignChurch)->state(['stage_id' => $foreignStage->id])->create();

        $this->authAs($churchAdminToken)
            ->putJson('/api/v1/events/'.$event->id, ['class_id' => $foreignClasse->id])
            ->assertStatus(403);

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'class_year_id' => $s['secClasse']->id,
        ]);
    }

    public function test_stage_admin_cannot_update_event_to_foreign_stage_class(): void
    {
        $s = $this->makeScenario();

        $event = Event::factory()->create([
            'church_id' => $s['church']->id,
            'created_by' => $s['secAdmin']->id,
            'class_year_id' => $s['secClasse']->id,
        ]);

        $this->authAs($s['secAdminToken'])
            ->putJson('/api/v1/events/'.$event->id, ['class_id' => $s['prepClasse']->id])
            ->assertStatus(403);

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'class_year_id' => $s['secClasse']->id,
        ]);
    }
}
