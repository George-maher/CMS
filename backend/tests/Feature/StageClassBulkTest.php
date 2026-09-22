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

class StageClassBulkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Permission::clearCache();
    }

    /** @return array{church: Church, admin: User, adminToken: string, stageA: Stage, stageB: Stage, stageAdmin: User, stageAdminToken: string, memberToken: string} */
    private function makeScenario(): array
    {
        $church = Church::factory()->create();
        $otherChurch = Church::factory()->create();

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $church->id,
            'application_status' => 'approved',
        ]);
        $adminToken = $admin->createToken('test', [$admin->role->value])->plainTextToken;

        $stageA = Stage::factory()->forChurch($church)->create(['name' => 'Stage A']);
        $stageB = Stage::factory()->forChurch($church)->create(['name' => 'Stage B']);
        Stage::factory()->forChurch($otherChurch)->create(['name' => 'Other Stage']);

        $stageAdmin = User::factory()->create([
            'role' => UserRole::StageAdmin,
            'church_id' => $church->id,
            'stage_id' => $stageA->id,
            'scope' => UserScope::Stage->value,
            'application_status' => 'approved',
        ]);
        $stageAdminToken = $stageAdmin->createToken('test', [$stageAdmin->role->value])->plainTextToken;

        $member = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $church->id,
            'stage_id' => $stageA->id,
            'application_status' => 'approved',
        ]);
        $memberToken = $member->createToken('test', [$member->role->value])->plainTextToken;

        return compact('church', 'admin', 'adminToken', 'stageA', 'stageB', 'stageAdmin', 'stageAdminToken', 'memberToken');
    }

    // ---------------- Stages ----------------

    public function test_admin_can_bulk_create_stages_in_own_church(): void
    {
        $s = $this->makeScenario();

        $response = $this->withHeader('Authorization', "Bearer {$s['adminToken']}")
            ->postJson('/api/v1/stages/bulk', ['count' => 3]);

        $response->assertStatus(201);
        $this->assertCount(3, $response->json('data'));
        $this->assertEquals(5, Stage::where('church_id', $s['church']->id)->count());
        foreach (Stage::where('church_id', $s['church']->id)->where('name', 'like', 'Stage %')->get() as $stage) {
            $this->assertEquals($s['church']->id, $stage->church_id);
        }
    }

    public function test_stage_bulk_rejects_invalid_count(): void
    {
        $s = $this->makeScenario();

        $this->withHeader('Authorization', "Bearer {$s['adminToken']}")
            ->postJson('/api/v1/stages/bulk', ['count' => 0])->assertStatus(422);
        $this->withHeader('Authorization', "Bearer {$s['adminToken']}")
            ->postJson('/api/v1/stages/bulk', ['count' => 51])->assertStatus(422);
        $this->withHeader('Authorization', "Bearer {$s['adminToken']}")
            ->postJson('/api/v1/stages/bulk', [])->assertStatus(422);
    }

    public function test_stage_admin_cannot_bulk_create_stages(): void
    {
        $s = $this->makeScenario();

        $this->withHeader('Authorization', "Bearer {$s['stageAdminToken']}")
            ->postJson('/api/v1/stages/bulk', ['count' => 2])->assertStatus(403);
    }

    public function test_member_cannot_bulk_create_stages(): void
    {
        $s = $this->makeScenario();

        $this->withHeader('Authorization', "Bearer {$s['memberToken']}")
            ->postJson('/api/v1/stages/bulk', ['count' => 2])->assertStatus(403);
    }

    // ---------------- Classes ----------------

    public function test_admin_can_bulk_create_classes_in_stage(): void
    {
        $s = $this->makeScenario();

        $response = $this->withHeader('Authorization', "Bearer {$s['adminToken']}")
            ->postJson("/api/v1/stages/{$s['stageA']->id}/classes/bulk", ['count' => 5]);

        $response->assertStatus(201);
        $this->assertCount(5, $response->json('data'));
        $classes = Classe::where('stage_id', $s['stageA']->id)->get();
        $this->assertCount(5, $classes);
        foreach ($classes as $class) {
            $this->assertEquals($s['church']->id, $class->church_id);
            $this->assertEquals($s['stageA']->id, $class->stage_id);
        }
        // Unique names within the batch (unique church/stage/name constraint).
        $this->assertCount(5, $classes->pluck('name')->unique());
    }

    public function test_stage_admin_cannot_bulk_create_classes_even_in_own_stage(): void
    {
        $s = $this->makeScenario();

        // Mirrors the existing single-create rule (StageAdminScopeTest::
        // test_stage_admin_cannot_create_class): class creation is gated by
        // StagePolicy::create (admin only). Bulk must not widen permissions.
        $this->withHeader('Authorization', "Bearer {$s['stageAdminToken']}")
            ->postJson("/api/v1/stages/{$s['stageA']->id}/classes/bulk", ['count' => 2])
            ->assertStatus(403);

        $this->assertEquals(0, Classe::where('stage_id', $s['stageA']->id)->count());
    }

    public function test_stage_admin_cannot_bulk_create_in_other_stage(): void
    {
        $s = $this->makeScenario();

        // Cross-stage attack: stage admin of A tries stage B via URL.
        $this->withHeader('Authorization', "Bearer {$s['stageAdminToken']}")
            ->postJson("/api/v1/stages/{$s['stageB']->id}/classes/bulk", ['count' => 2])
            ->assertStatus(403);

        $this->assertEquals(0, Classe::where('stage_id', $s['stageB']->id)->count());
    }

    public function test_class_bulk_rejects_invalid_count(): void
    {
        $s = $this->makeScenario();

        $this->withHeader('Authorization', "Bearer {$s['adminToken']}")
            ->postJson("/api/v1/stages/{$s['stageA']->id}/classes/bulk", ['count' => 0])->assertStatus(422);
        $this->withHeader('Authorization', "Bearer {$s['adminToken']}")
            ->postJson("/api/v1/stages/{$s['stageA']->id}/classes/bulk", ['count' => 51])->assertStatus(422);
        $this->withHeader('Authorization', "Bearer {$s['adminToken']}")
            ->postJson("/api/v1/stages/{$s['stageA']->id}/classes/bulk", [])->assertStatus(422);
    }

    public function test_member_cannot_bulk_create_classes(): void
    {
        $s = $this->makeScenario();

        $this->withHeader('Authorization', "Bearer {$s['memberToken']}")
            ->postJson("/api/v1/stages/{$s['stageA']->id}/classes/bulk", ['count' => 2])
            ->assertStatus(403);
    }

    public function test_class_bulk_avoids_existing_names_atomically(): void
    {
        $s = $this->makeScenario();

        Classe::factory()->forChurch($s['church'])->state(['stage_id' => $s['stageA']->id])->create(['name' => 'Class 1']);

        $response = $this->withHeader('Authorization', "Bearer {$s['adminToken']}")
            ->postJson("/api/v1/stages/{$s['stageA']->id}/classes/bulk", ['count' => 2]);

        $response->assertStatus(201);
        $names = Classe::where('stage_id', $s['stageA']->id)->pluck('name');
        // Pre-existing Class 1 kept; bulk generated Class 2 + Class 3 (no duplicates).
        $this->assertContains('Class 1', $names);
        $this->assertContains('Class 2', $names);
        $this->assertContains('Class 3', $names);
        $this->assertCount(3, $names->unique());
    }

    public function test_class_bulk_unknown_stage_returns_404(): void
    {
        $s = $this->makeScenario();

        $this->withHeader('Authorization', "Bearer {$s['adminToken']}")
            ->postJson('/api/v1/stages/999999/classes/bulk', ['count' => 2])
            ->assertStatus(404);
    }
}
