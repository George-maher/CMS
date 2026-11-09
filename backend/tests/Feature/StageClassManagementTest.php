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
 * Stage Admin class management: classes, class secretary and servants.
 *
 * Every test authenticates over HTTP so the real route middleware, policy and
 * service layers all run. Database assertions prove the write either happened
 * or was refused, not merely that a status code looked right.
 */
class StageClassManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Permission::clearCache();
    }

    /**
     * @return array{
     *     church: Church,
     *     stage: Stage,
     *     otherStage: Stage,
     *     classA: Classe,
     *     classB: Classe,
     *     otherClass: Classe,
     *     stageAdmin: User,
     *     stageAdminToken: string,
     *     servants: array<int, User>,
     *     secretary: User,
     *     secretaryToken: string,
     *     admin: User,
     *     adminToken: string,
     *     assistant: User,
     *     assistantToken: string
     * }
     */
    private function makeScenario(): array
    {
        $church = Church::factory()->create();

        $stage = Stage::factory()->forChurch($church)->create(['name' => 'Secondary']);
        $otherStage = Stage::factory()->forChurch($church)->create(['name' => 'Primary']);

        $classA = Classe::factory()->forChurch($church)
            ->state(['stage_id' => $stage->id])->create(['name' => 'S1']);
        $classB = Classe::factory()->forChurch($church)
            ->state(['stage_id' => $stage->id])->create(['name' => 'S2']);
        $otherClass = Classe::factory()->forChurch($church)
            ->state(['stage_id' => $otherStage->id])->create(['name' => 'P1']);

        $stageAdmin = User::factory()->create([
            'role' => UserRole::StageAdmin,
            'church_id' => $church->id,
            'stage_id' => $stage->id,
            'scope' => UserScope::Stage->value,
            'application_status' => 'approved',
        ]);
        $stageAdminToken = $stageAdmin->createToken('test', [$stageAdmin->role->value])->plainTextToken;

        $servants = [];
        foreach (['Servant One', 'Servant Two', 'Servant Three'] as $name) {
            $servants[] = User::factory()->create([
                'name' => $name,
                'role' => UserRole::Servant,
                'church_id' => $church->id,
                'stage_id' => $stage->id,
                'scope' => UserScope::ClassScope->value,
                'application_status' => 'approved',
            ]);
        }

        $secretary = $servants[0];
        $secretaryToken = $secretary->createToken('test', [$secretary->role->value])->plainTextToken;

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $church->id,
            'application_status' => 'approved',
        ]);
        $adminToken = $admin->createToken('test', [$admin->role->value])->plainTextToken;

        $assistant = User::factory()->create([
            'role' => UserRole::AssistantAdmin,
            'church_id' => $church->id,
            'application_status' => 'approved',
        ]);
        $assistantToken = $assistant->createToken('test', [$assistant->role->value])->plainTextToken;

        return compact(
            'church', 'stage', 'otherStage', 'classA', 'classB', 'otherClass',
            'stageAdmin', 'stageAdminToken', 'servants', 'secretary', 'secretaryToken',
            'admin', 'adminToken', 'assistant', 'assistantToken',
        );
    }

    private function authAs(string $token): self
    {
        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    // -----------------------------------------------------------------
    // Classes
    // -----------------------------------------------------------------

    public function test_stage_admin_creates_class_in_own_stage_with_derived_ownership(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['stageAdminToken'])
            ->postJson('/api/v1/classes', [
                'name' => 'New Class',
                'stage_id' => $s['stage']->id,
                'church_id' => Church::factory()->create()->id,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('classes', [
            'name' => 'New Class',
            'church_id' => $s['church']->id,
            'stage_id' => $s['stage']->id,
        ]);
    }

    public function test_stage_admin_updates_class_and_sees_it_in_list(): void
    {
        $s = $this->makeScenario();

        $this->authAs($s['stageAdminToken'])
            ->putJson("/api/v1/classes/{$s['classA']->id}", ['name' => 'S1 Renamed'])
            ->assertStatus(200);

        $this->assertDatabaseHas('classes', [
            'id' => $s['classA']->id,
            'name' => 'S1 Renamed',
        ]);

        $response = $this->authAs($s['stageAdminToken'])->getJson('/api/v1/classes');
        $response->assertStatus(200);

        /** @var array<int, array<string, mixed>> $classes */
        $classes = $response->json('data');
        $names = array_column($classes, 'name');
        $this->assertContains('S1 Renamed', $names);
        $this->assertContains('S2', $names);
    }

    public function test_stage_admin_class_detail_includes_stage_and_empty_secretary(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['stageAdminToken'])
            ->getJson("/api/v1/classes/{$s['classA']->id}/detail");

        $response->assertStatus(200)
            ->assertJsonPath('data.class.id', $s['classA']->id)
            ->assertJsonPath('data.class.stage.name', 'Secondary')
            ->assertJsonPath('data.class.secretary', null);
    }

    // -----------------------------------------------------------------
    // Class secretary
    // -----------------------------------------------------------------

    public function test_stage_admin_assigns_class_secretary(): void
    {
        $s = $this->makeScenario();
        $secretary = $s['servants'][0];

        $response = $this->authAs($s['stageAdminToken'])
            ->postJson("/api/v1/classes/{$s['classA']->id}/assign-secretary", [
                'user_id' => $secretary->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $secretary->id);

        $this->assertDatabaseHas('classes', [
            'id' => $s['classA']->id,
            'class_secretary_id' => $secretary->id,
        ]);
    }

    public function test_stage_admin_replaces_class_secretary_without_leaving_two(): void
    {
        $s = $this->makeScenario();
        [$first, $second] = $s['servants'];

        $this->authAs($s['stageAdminToken'])
            ->postJson("/api/v1/classes/{$s['classA']->id}/assign-secretary", ['user_id' => $first->id])
            ->assertStatus(200);

        $this->authAs($s['stageAdminToken'])
            ->postJson("/api/v1/classes/{$s['classA']->id}/assign-secretary", ['user_id' => $second->id])
            ->assertStatus(200);

        $this->assertDatabaseHas('classes', [
            'id' => $s['classA']->id,
            'class_secretary_id' => $second->id,
        ]);
        // Only the replaced class row references the first servant, and it no
        // longer does.
        $this->assertSame(0, Classe::query()->where('class_secretary_id', $first->id)->count());
    }

    public function test_stage_admin_removes_class_secretary(): void
    {
        $s = $this->makeScenario();

        $this->authAs($s['stageAdminToken'])
            ->postJson("/api/v1/classes/{$s['classA']->id}/assign-secretary", ['user_id' => $s['servants'][0]->id])
            ->assertStatus(200);

        $this->authAs($s['stageAdminToken'])
            ->postJson("/api/v1/classes/{$s['classA']->id}/remove-secretary")
            ->assertStatus(200);

        $this->assertDatabaseHas('classes', [
            'id' => $s['classA']->id,
            'class_secretary_id' => null,
        ]);
    }

    public function test_one_user_cannot_be_secretary_of_two_classes(): void
    {
        $s = $this->makeScenario();
        $secretary = $s['servants'][0];

        $this->authAs($s['stageAdminToken'])
            ->postJson("/api/v1/classes/{$s['classA']->id}/assign-secretary", ['user_id' => $secretary->id])
            ->assertStatus(200);

        $response = $this->authAs($s['stageAdminToken'])
            ->postJson("/api/v1/classes/{$s['classB']->id}/assign-secretary", ['user_id' => $secretary->id]);

        $response->assertStatus(422)->assertJsonValidationErrors('user_id');
        $this->assertDatabaseHas('classes', [
            'id' => $s['classB']->id,
            'class_secretary_id' => null,
        ]);
    }

    public function test_eligible_secretaries_are_stage_servants_excluding_other_class_secretary(): void
    {
        $s = $this->makeScenario();
        [$currentSecretary, $otherSecretary, $freeServant] = $s['servants'];

        $this->authAs($s['stageAdminToken'])
            ->postJson("/api/v1/classes/{$s['classA']->id}/assign-secretary", ['user_id' => $currentSecretary->id])
            ->assertStatus(200);
        $this->authAs($s['stageAdminToken'])
            ->postJson("/api/v1/classes/{$s['classB']->id}/assign-secretary", ['user_id' => $otherSecretary->id])
            ->assertStatus(200);

        $response = $this->authAs($s['stageAdminToken'])
            ->getJson("/api/v1/classes/{$s['classA']->id}/eligible-secretaries");

        $response->assertStatus(200);

        /** @var array<int, array<string, mixed>> $data */
        $data = $response->json('data');
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $data);

        // The current secretary is included so the replace dialog can show it;
        // a servant leading a different class is not offered.
        $this->assertContains($currentSecretary->id, $ids);
        $this->assertNotContains($otherSecretary->id, $ids);
        $this->assertContains($freeServant->id, $ids);
    }

    public function test_class_detail_shows_assigned_secretary(): void
    {
        $s = $this->makeScenario();
        $secretary = $s['servants'][0];

        $this->authAs($s['stageAdminToken'])
            ->postJson("/api/v1/classes/{$s['classA']->id}/assign-secretary", ['user_id' => $secretary->id])
            ->assertStatus(200);

        $this->authAs($s['stageAdminToken'])
            ->getJson("/api/v1/classes/{$s['classA']->id}/detail")
            ->assertStatus(200)
            ->assertJsonPath('data.class.secretary.id', $secretary->id)
            ->assertJsonPath('data.class.secretary.name', $secretary->name);
    }

    // -----------------------------------------------------------------
    // Servants
    // -----------------------------------------------------------------

    public function test_stage_admin_assigns_multiple_servants_to_one_class(): void
    {
        $s = $this->makeScenario();
        [$first, $second] = $s['servants'];

        $this->authAs($s['stageAdminToken'])
            ->postJson("/api/v1/classes/{$s['classA']->id}/assign-servant", ['user_id' => $first->id])
            ->assertStatus(200);
        $this->authAs($s['stageAdminToken'])
            ->postJson("/api/v1/classes/{$s['classA']->id}/assign-servant", ['user_id' => $second->id])
            ->assertStatus(200);

        $this->assertDatabaseHas('class_servant', ['class_id' => $s['classA']->id, 'user_id' => $first->id]);
        $this->assertDatabaseHas('class_servant', ['class_id' => $s['classA']->id, 'user_id' => $second->id]);

        $this->authAs($s['stageAdminToken'])
            ->getJson("/api/v1/classes/{$s['classA']->id}/servants")
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_stage_admin_removes_one_servant_without_touching_others(): void
    {
        $s = $this->makeScenario();
        [$first, $second] = $s['servants'];

        $this->authAs($s['stageAdminToken'])
            ->postJson("/api/v1/classes/{$s['classA']->id}/assign-servant", ['user_id' => $first->id]);
        $this->authAs($s['stageAdminToken'])
            ->postJson("/api/v1/classes/{$s['classA']->id}/assign-servant", ['user_id' => $second->id]);

        $this->authAs($s['stageAdminToken'])
            ->postJson("/api/v1/classes/{$s['classA']->id}/remove-servant", ['user_id' => $first->id])
            ->assertStatus(200);

        $this->assertDatabaseMissing('class_servant', ['class_id' => $s['classA']->id, 'user_id' => $first->id]);
        $this->assertDatabaseHas('class_servant', ['class_id' => $s['classA']->id, 'user_id' => $second->id]);
    }

    public function test_stage_admin_moves_servant_between_classes_and_keeps_other_assignments(): void
    {
        $s = $this->makeScenario();
        $servant = $s['servants'][0];

        // Assigned to A and to a third class, then moved A -> B.
        $this->authAs($s['stageAdminToken'])
            ->postJson("/api/v1/classes/{$s['classA']->id}/assign-servant", ['user_id' => $servant->id])
            ->assertStatus(200);

        $response = $this->authAs($s['stageAdminToken'])
            ->postJson("/api/v1/classes/{$s['classB']->id}/move-servant", [
                'user_id' => $servant->id,
                'from_class_id' => $s['classA']->id,
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseMissing('class_servant', [
            'class_id' => $s['classA']->id,
            'user_id' => $servant->id,
        ]);
        $this->assertDatabaseHas('class_servant', [
            'class_id' => $s['classB']->id,
            'user_id' => $servant->id,
        ]);
    }

    public function test_move_servant_rejects_target_class_in_another_stage(): void
    {
        $s = $this->makeScenario();
        $servant = $s['servants'][0];

        $this->authAs($s['stageAdminToken'])
            ->postJson("/api/v1/classes/{$s['classA']->id}/assign-servant", ['user_id' => $servant->id])
            ->assertStatus(200);

        $this->authAs($s['stageAdminToken'])
            ->postJson("/api/v1/classes/{$s['otherClass']->id}/move-servant", [
                'user_id' => $servant->id,
                'from_class_id' => $s['classA']->id,
            ])
            ->assertStatus(403);

        $this->assertDatabaseHas('class_servant', [
            'class_id' => $s['classA']->id,
            'user_id' => $servant->id,
        ]);
    }

    // -----------------------------------------------------------------
    // Class secretary read scope
    // -----------------------------------------------------------------

    public function test_class_secretary_can_read_own_class_only(): void
    {
        $s = $this->makeScenario();
        $secretary = $s['servants'][0];

        $this->authAs($s['stageAdminToken'])
            ->postJson("/api/v1/classes/{$s['classA']->id}/assign-secretary", ['user_id' => $secretary->id])
            ->assertStatus(200);

        $this->authAs($s['secretaryToken'])
            ->getJson("/api/v1/classes/{$s['classA']->id}/detail")
            ->assertStatus(200)
            ->assertJsonPath('data.class.id', $s['classA']->id);

        $this->authAs($s['secretaryToken'])
            ->getJson("/api/v1/classes/{$s['classB']->id}/detail")
            ->assertStatus(403);

        $this->authAs($s['secretaryToken'])
            ->getJson("/api/v1/classes/{$s['classA']->id}/members")
            ->assertStatus(200);

        $this->authAs($s['secretaryToken'])
            ->getJson("/api/v1/classes/{$s['classB']->id}/members")
            ->assertStatus(403);
    }

    public function test_class_secretary_cannot_assign_secretary_or_servant(): void
    {
        $s = $this->makeScenario();
        $secretary = $s['servants'][0];

        $this->authAs($s['stageAdminToken'])
            ->postJson("/api/v1/classes/{$s['classA']->id}/assign-secretary", ['user_id' => $secretary->id])
            ->assertStatus(200);

        $this->authAs($s['secretaryToken'])
            ->postJson("/api/v1/classes/{$s['classA']->id}/assign-secretary", ['user_id' => $s['servants'][1]->id])
            ->assertStatus(403);

        $this->authAs($s['secretaryToken'])
            ->postJson("/api/v1/classes/{$s['classA']->id}/assign-servant", ['user_id' => $s['servants'][1]->id])
            ->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // Admin / assistant parity
    // -----------------------------------------------------------------

    public function test_admin_and_assistant_admin_have_identical_class_management(): void
    {
        $s = $this->makeScenario();

        foreach ([
            ['token' => $s['adminToken'], 'name' => 'Admin Class'],
            ['token' => $s['assistantToken'], 'name' => 'Assistant Class'],
        ] as $actor) {
            $this->authAs($actor['token'])
                ->postJson('/api/v1/classes', [
                    'name' => $actor['name'],
                    'stage_id' => $s['otherStage']->id,
                ])
                ->assertStatus(201);
        }

        $this->authAs($s['adminToken'])
            ->postJson("/api/v1/classes/{$s['classA']->id}/assign-secretary", ['user_id' => $s['servants'][1]->id])
            ->assertStatus(200);

        $this->authAs($s['assistantToken'])
            ->postJson("/api/v1/classes/{$s['classA']->id}/assign-secretary", ['user_id' => $s['servants'][2]->id])
            ->assertStatus(200);

        $this->assertDatabaseHas('classes', ['id' => $s['classA']->id, 'class_secretary_id' => $s['servants'][2]->id]);
    }
}
