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
 * Tenant / stage / class isolation for the Stage Administration hierarchy.
 *
 * Every test drives the HTTP API with a real Sanctum token so the permission
 * middleware, policy and service checks all execute. Denials assert the
 * database state as well, so a request that was rejected after a write would
 * still fail the test.
 */
class ClassSecretaryScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Permission::clearCache();
    }

    /**
     * @return array<string, mixed>
     */
    private function makeScenario(): array
    {
        $churchA = Church::factory()->create();
        $churchB = Church::factory()->create();

        $stageA1 = Stage::factory()->forChurch($churchA)->create(['name' => 'A1']);
        $stageA2 = Stage::factory()->forChurch($churchA)->create(['name' => 'A2']);
        $stageB1 = Stage::factory()->forChurch($churchB)->create(['name' => 'B1']);

        $classA1 = Classe::factory()->forChurch($churchA)
            ->state(['stage_id' => $stageA1->id])->create(['name' => 'A1-C1']);
        $classA2 = Classe::factory()->forChurch($churchA)
            ->state(['stage_id' => $stageA2->id])->create(['name' => 'A2-C1']);
        $classB1 = Classe::factory()->forChurch($churchB)
            ->state(['stage_id' => $stageB1->id])->create(['name' => 'B1-C1']);

        $makeUser = function (UserRole $role, ?int $churchId, ?int $stageId, ?int $classId, string $scope): array {
            $user = User::factory()->create([
                'role' => $role,
                'church_id' => $churchId,
                'stage_id' => $stageId,
                'class_id' => $classId,
                'scope' => $scope,
                'application_status' => 'approved',
            ]);

            return [$user, $user->createToken('test', [$role->value])->plainTextToken];
        };

        [$stageAdminA1, $stageAdminA1Token] = $makeUser(UserRole::StageAdmin, $churchA->id, $stageA1->id, null, UserScope::Stage->value);
        [$stageAdminA2, $stageAdminA2Token] = $makeUser(UserRole::StageAdmin, $churchA->id, $stageA2->id, null, UserScope::Stage->value);
        [$stageAdminNoStage, $stageAdminNoStageToken] = $makeUser(UserRole::StageAdmin, $churchA->id, null, null, UserScope::Stage->value);
        [$admin, $adminToken] = $makeUser(UserRole::Admin, $churchA->id, null, null, UserScope::Church->value);
        [$assistant, $assistantToken] = $makeUser(UserRole::AssistantAdmin, $churchA->id, null, null, UserScope::Church->value);
        [$servantA1, $servantA1Token] = $makeUser(UserRole::Servant, $churchA->id, $stageA1->id, $classA1->id, UserScope::ClassScope->value);
        [$servantA2, $servantA2Token] = $makeUser(UserRole::Servant, $churchA->id, $stageA2->id, $classA2->id, UserScope::ClassScope->value);
        [$servantB1, $servantB1Token] = $makeUser(UserRole::Servant, $churchB->id, $stageB1->id, $classB1->id, UserScope::ClassScope->value);

        return compact(
            'churchA', 'churchB', 'stageA1', 'stageA2', 'stageB1',
            'classA1', 'classA2', 'classB1',
            'stageAdminA1', 'stageAdminA1Token',
            'stageAdminA2', 'stageAdminA2Token',
            'stageAdminNoStage', 'stageAdminNoStageToken',
            'admin', 'adminToken', 'assistant', 'assistantToken',
            'servantA1', 'servantA1Token', 'servantA2', 'servantA2Token',
            'servantB1', 'servantB1Token',
        );
    }

    private function authAs(string $token): self
    {
        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    // -----------------------------------------------------------------
    // Stage Admin — cross-stage reads
    // -----------------------------------------------------------------

    public function test_stage_admin_cannot_read_another_stage_class(): void
    {
        $s = $this->makeScenario();

        $this->authAs($s['stageAdminA1Token'])
            ->getJson("/api/v1/classes/{$s['classA2']->id}")
            ->assertStatus(403);

        $this->authAs($s['stageAdminA1Token'])
            ->getJson("/api/v1/classes/{$s['classA2']->id}/detail")
            ->assertStatus(403);

        $this->authAs($s['stageAdminA1Token'])
            ->getJson("/api/v1/classes/{$s['classA2']->id}/members")
            ->assertStatus(403);

        $this->authAs($s['stageAdminA1Token'])
            ->getJson("/api/v1/classes/{$s['classA2']->id}/servants")
            ->assertStatus(403);

        $this->authAs($s['stageAdminA1Token'])
            ->getJson("/api/v1/classes/{$s['classA2']->id}/eligible-secretaries")
            ->assertStatus(403);
    }

    public function test_stage_admin_can_read_own_stage_class(): void
    {
        $s = $this->makeScenario();

        $this->authAs($s['stageAdminA1Token'])
            ->getJson("/api/v1/classes/{$s['classA1']->id}/detail")
            ->assertStatus(200);
    }

    // -----------------------------------------------------------------
    // Stage Admin — cross-stage writes
    // -----------------------------------------------------------------

    public function test_stage_admin_cannot_create_class_in_another_stage(): void
    {
        $s = $this->makeScenario();

        $this->authAs($s['stageAdminA1Token'])
            ->postJson('/api/v1/classes', [
                'name' => 'Intruder',
                'stage_id' => $s['stageA2']->id,
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('classes', ['name' => 'Intruder']);
    }

    public function test_stage_admin_cannot_create_class_in_another_church(): void
    {
        $s = $this->makeScenario();

        // The foreign stage is invisible to ChurchScope, so it must look
        // exactly like a stage that does not exist.
        $this->authAs($s['stageAdminA1Token'])
            ->postJson('/api/v1/classes', [
                'name' => 'Foreign',
                'stage_id' => $s['stageB1']->id,
            ])
            ->assertStatus(404);

        $this->assertDatabaseMissing('classes', ['name' => 'Foreign']);
    }

    public function test_stage_admin_cannot_update_or_delete_another_stage_class(): void
    {
        $s = $this->makeScenario();

        $this->authAs($s['stageAdminA1Token'])
            ->putJson("/api/v1/classes/{$s['classA2']->id}", ['name' => 'Hijacked'])
            ->assertStatus(403);

        $this->authAs($s['stageAdminA1Token'])
            ->deleteJson("/api/v1/classes/{$s['classA2']->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('classes', [
            'id' => $s['classA2']->id,
            'name' => 'A2-C1',
        ]);
    }

    // -----------------------------------------------------------------
    // Stage Admin — secretary assignment boundaries
    // -----------------------------------------------------------------

    public function test_stage_admin_cannot_assign_secretary_from_another_stage(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['stageAdminA1Token'])
            ->postJson("/api/v1/classes/{$s['classA1']->id}/assign-secretary", [
                'user_id' => $s['servantA2']->id,
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('user_id');
        $this->assertDatabaseHas('classes', [
            'id' => $s['classA1']->id,
            'class_secretary_id' => null,
        ]);
    }

    public function test_stage_admin_cannot_assign_secretary_from_another_church(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['stageAdminA1Token'])
            ->postJson("/api/v1/classes/{$s['classA1']->id}/assign-secretary", [
                'user_id' => $s['servantB1']->id,
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('user_id');
        $this->assertDatabaseHas('classes', [
            'id' => $s['classA1']->id,
            'class_secretary_id' => null,
        ]);
    }

    public function test_stage_admin_cannot_assign_secretary_to_another_stage_class_by_id(): void
    {
        $s = $this->makeScenario();

        $this->authAs($s['stageAdminA1Token'])
            ->postJson("/api/v1/classes/{$s['classA2']->id}/assign-secretary", [
                'user_id' => $s['servantA1']->id,
            ])
            ->assertStatus(403);

        $this->assertDatabaseHas('classes', [
            'id' => $s['classA2']->id,
            'class_secretary_id' => null,
        ]);
    }

    public function test_stage_admin_cannot_assign_non_servant_as_secretary(): void
    {
        $s = $this->makeScenario();

        $response = $this->authAs($s['stageAdminA1Token'])
            ->postJson("/api/v1/classes/{$s['classA1']->id}/assign-secretary", [
                'user_id' => $s['stageAdminA2']->id,
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('user_id');
        $this->assertDatabaseHas('classes', [
            'id' => $s['classA1']->id,
            'class_secretary_id' => null,
        ]);
    }

    public function test_stage_admin_cannot_assign_servant_from_another_stage(): void
    {
        $s = $this->makeScenario();

        $this->authAs($s['stageAdminA1Token'])
            ->postJson("/api/v1/classes/{$s['classA1']->id}/assign-servant", [
                'user_id' => $s['servantA2']->id,
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('class_servant', [
            'class_id' => $s['classA1']->id,
            'user_id' => $s['servantA2']->id,
        ]);
    }

    public function test_stage_admin_cannot_assign_servant_from_another_church(): void
    {
        $s = $this->makeScenario();

        $this->authAs($s['stageAdminA1Token'])
            ->postJson("/api/v1/classes/{$s['classA1']->id}/assign-servant", [
                'user_id' => $s['servantB1']->id,
            ])
            ->assertStatus(404);

        $this->assertDatabaseMissing('class_servant', [
            'class_id' => $s['classA1']->id,
            'user_id' => $s['servantB1']->id,
        ]);
    }

    public function test_stage_admin_cannot_move_servant_into_another_stage(): void
    {
        $s = $this->makeScenario();

        $this->authAs($s['stageAdminA1Token'])
            ->postJson("/api/v1/classes/{$s['classA2']->id}/move-servant", [
                'user_id' => $s['servantA1']->id,
                'from_class_id' => $s['classA1']->id,
            ])
            ->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // Class secretary scope
    // -----------------------------------------------------------------

    public function test_class_secretary_cannot_read_another_class_by_url(): void
    {
        $s = $this->makeScenario();
        $s['classA1']->update(['class_secretary_id' => $s['servantA1']->id]);

        $this->authAs($s['servantA1Token'])
            ->getJson("/api/v1/classes/{$s['classA1']->id}/detail")
            ->assertStatus(200);

        $this->authAs($s['servantA1Token'])
            ->getJson("/api/v1/classes/{$s['classA2']->id}/detail")
            ->assertStatus(403);

        $this->authAs($s['servantA1Token'])
            ->getJson("/api/v1/classes/{$s['classA2']->id}")
            ->assertStatus(403);

        $this->authAs($s['servantA1Token'])
            ->getJson("/api/v1/classes/{$s['classA2']->id}/members")
            ->assertStatus(403);
    }

    public function test_class_secretary_cannot_reach_another_church_class(): void
    {
        $s = $this->makeScenario();
        $s['classA1']->update(['class_secretary_id' => $s['servantA1']->id]);

        $this->authAs($s['servantA1Token'])
            ->getJson("/api/v1/classes/{$s['classB1']->id}/detail")
            ->assertStatus(404);
    }

    public function test_class_secretary_cannot_assign_themselves_or_anyone(): void
    {
        $s = $this->makeScenario();
        $s['classA1']->update(['class_secretary_id' => $s['servantA1']->id]);

        $this->authAs($s['servantA1Token'])
            ->postJson("/api/v1/classes/{$s['classA1']->id}/assign-secretary", [
                'user_id' => $s['servantA1']->id,
            ])
            ->assertStatus(403);

        $this->authAs($s['servantA1Token'])
            ->postJson("/api/v1/classes/{$s['classA1']->id}/remove-secretary")
            ->assertStatus(403);
    }

    public function test_servant_cannot_gain_class_access_by_submitting_another_class_id(): void
    {
        $s = $this->makeScenario();

        // servantA1 is an ordinary servant of classA1, not of classA2.
        $this->authAs($s['servantA1Token'])
            ->postJson("/api/v1/classes/{$s['classA2']->id}/assign-servant", [
                'user_id' => $s['servantA1']->id,
            ])
            ->assertStatus(403);

        $this->authAs($s['servantA1Token'])
            ->getJson("/api/v1/classes/{$s['classA2']->id}/detail")
            ->assertStatus(403);

        $this->assertDatabaseMissing('class_servant', [
            'class_id' => $s['classA2']->id,
            'user_id' => $s['servantA1']->id,
        ]);
    }

    public function test_secretary_of_one_stage_cannot_be_appointed_in_another_stage(): void
    {
        $s = $this->makeScenario();
        $s['classA1']->update(['class_secretary_id' => $s['servantA1']->id]);

        $response = $this->authAs($s['stageAdminA2Token'])
            ->postJson("/api/v1/classes/{$s['classA2']->id}/assign-secretary", [
                'user_id' => $s['servantA1']->id,
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('user_id');
        $this->assertDatabaseHas('classes', [
            'id' => $s['classA1']->id,
            'class_secretary_id' => $s['servantA1']->id,
        ]);
        $this->assertDatabaseHas('classes', [
            'id' => $s['classA2']->id,
            'class_secretary_id' => null,
        ]);
    }

    // -----------------------------------------------------------------
    // Church-wide roles
    // -----------------------------------------------------------------

    public function test_admin_and_assistant_admin_keep_church_wide_class_access(): void
    {
        $s = $this->makeScenario();

        foreach ([$s['adminToken'], $s['assistantToken']] as $token) {
            $this->authAs($token)
                ->getJson("/api/v1/classes/{$s['classA1']->id}/detail")
                ->assertStatus(200);

            $this->authAs($token)
                ->getJson("/api/v1/classes/{$s['classA2']->id}/detail")
                ->assertStatus(200);

            // Cross-church stays invisible for both.
            $this->authAs($token)
                ->getJson("/api/v1/classes/{$s['classB1']->id}/detail")
                ->assertStatus(404);
        }
    }

    public function test_admin_and_assistant_admin_can_assign_secretaries_in_any_stage_of_their_church(): void
    {
        $s = $this->makeScenario();

        $this->authAs($s['adminToken'])
            ->postJson("/api/v1/classes/{$s['classA1']->id}/assign-secretary", [
                'user_id' => $s['servantA1']->id,
            ])
            ->assertStatus(200);

        $this->authAs($s['assistantToken'])
            ->postJson("/api/v1/classes/{$s['classA2']->id}/assign-secretary", [
                'user_id' => $s['servantA2']->id,
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('classes', [
            'id' => $s['classA1']->id,
            'class_secretary_id' => $s['servantA1']->id,
        ]);
        $this->assertDatabaseHas('classes', [
            'id' => $s['classA2']->id,
            'class_secretary_id' => $s['servantA2']->id,
        ]);
    }

    // -----------------------------------------------------------------
    // Fail closed
    // -----------------------------------------------------------------

    public function test_stage_admin_without_a_stage_fails_closed(): void
    {
        $s = $this->makeScenario();

        $this->authAs($s['stageAdminNoStageToken'])
            ->postJson('/api/v1/classes', [
                'name' => 'Homeless',
                'stage_id' => $s['stageA1']->id,
            ])
            ->assertStatus(403);

        $this->authAs($s['stageAdminNoStageToken'])
            ->getJson("/api/v1/classes/{$s['classA1']->id}/detail")
            ->assertStatus(403);

        $this->authAs($s['stageAdminNoStageToken'])
            ->postJson("/api/v1/classes/{$s['classA1']->id}/assign-secretary", [
                'user_id' => $s['servantA1']->id,
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('classes', ['name' => 'Homeless']);
        $this->assertDatabaseHas('classes', [
            'id' => $s['classA1']->id,
            'class_secretary_id' => null,
        ]);
    }
}
