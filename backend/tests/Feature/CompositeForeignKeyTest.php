<?php

/**
 * Validates the composite foreign keys that make cross-tenant rows
 * unrepresentable at the database layer.
 *
 * This is a database-level test: it proves the invariant holds even when
 * application code is bypassed entirely, which no HTTP test can show.
 */

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Church;
use App\Models\Classe;
use App\Models\Permission;
use App\Models\Scopes\ChurchScope;
use App\Models\Stage;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CompositeForeignKeyTest extends TestCase
{
    use RefreshDatabase;

    private Church $churchA;

    private Church $churchB;

    private Stage $stageA;

    private Stage $stageB;

    private Classe $classA;

    private Classe $classB;

    protected function setUp(): void
    {
        parent::setUp();

        // The HTTP flow below goes through permission middleware, which reads
        // seeded permissions.
        $this->seed(PermissionSeeder::class);
        Permission::clearCache();

        $this->churchA = Church::factory()->create();
        $this->churchB = Church::factory()->create();

        $this->stageA = Stage::factory()->forChurch($this->churchA)->create();
        $this->stageB = Stage::factory()->forChurch($this->churchB)->create();

        $this->classA = Classe::factory()->forChurch($this->churchA)
            ->state(['stage_id' => $this->stageA->id])->create();
        $this->classB = Classe::factory()->forChurch($this->churchB)
            ->state(['stage_id' => $this->stageB->id])->create();
    }

    /**
     * The supporting UNIQUE keys must exist, otherwise the composite foreign
     * keys cannot be created and silently do nothing.
     */
    public function test_supporting_unique_keys_exist_for_composite_foreign_keys(): void
    {
        // These are the parent keys the composite FKs depend on. Without them
        // the constraint cannot be created, and would silently not protect
        // anything.
        $this->assertTrue(
            Schema::hasIndex('stages', 'stages_church_id_id_unique'),
            'stages needs UNIQUE(church_id, id) so classes can reference (church_id, stage_id).'
        );
        $this->assertTrue(
            Schema::hasIndex('classes', 'classes_church_id_id_unique'),
            'classes needs UNIQUE(church_id, id) so users can reference (church_id, class_id).'
        );
    }

    // =================================================================
    // classes(church_id, stage_id) -> stages(church_id, id)
    // =================================================================

    public function test_class_cannot_reference_another_churchs_stage(): void
    {
        // Bypasses every application layer on purpose: raw DB insert.
        $this->expectException(QueryException::class);

        DB::table('classes')->insert([
            'church_id' => $this->churchA->id,
            'stage_id' => $this->stageB->id,
            'name' => 'Cross Tenant Class',
            'display_order' => 0,
        ]);
    }

    public function test_class_within_own_church_still_inserts(): void
    {
        DB::table('classes')->insert([
            'church_id' => $this->churchA->id,
            'stage_id' => $this->stageA->id,
            'name' => 'Legitimate Class',
            'display_order' => 0,
        ]);

        $this->assertDatabaseHas('classes', [
            'name' => 'Legitimate Class',
            'church_id' => $this->churchA->id,
            'stage_id' => $this->stageA->id,
        ]);
    }

    public function test_a_stage_may_hold_multiple_classes(): void
    {
        // Guards against over-tightening: the constraint must not imply
        // one-class-per-stage.
        DB::table('classes')->insert([
            'church_id' => $this->churchA->id,
            'stage_id' => $this->stageA->id,
            'name' => 'Class One',
            'display_order' => 0,
        ]);
        DB::table('classes')->insert([
            'church_id' => $this->churchA->id,
            'stage_id' => $this->stageA->id,
            'name' => 'Class Two',
            'display_order' => 1,
        ]);

        // setUp() already created classA and classB; the two inserts above add
        // two more inside church A's stage.
        $this->assertSame(4, Classe::withoutGlobalScope(ChurchScope::class)->count());
    }

    // =================================================================
    // users(church_id, class_id) -> classes(church_id, id)
    // users(church_id, stage_id) -> stages(church_id, id)
    // =================================================================

    public function test_user_cannot_reference_another_churchs_class(): void
    {
        $this->expectException(QueryException::class);

        DB::table('users')->insert([
            'church_id' => $this->churchA->id,
            'class_id' => $this->classB->id,
            'name' => 'Cross Tenant Member',
            'email' => 'cross-tenant-member@test.com',
            'password' => 'x',
            'role' => UserRole::Member->value,
            'application_status' => 'approved',
        ]);
    }

    public function test_user_cannot_reference_another_churchs_stage(): void
    {
        $this->expectException(QueryException::class);

        DB::table('users')->insert([
            'church_id' => $this->churchA->id,
            'stage_id' => $this->stageB->id,
            'name' => 'Cross Tenant Stage User',
            'email' => 'cross-tenant-stage@test.com',
            'password' => 'x',
            'role' => UserRole::StageAdmin->value,
            'application_status' => 'approved',
        ]);
    }

    public function test_user_within_own_church_class_still_inserts(): void
    {
        $user = User::factory()->create([
            'church_id' => $this->churchA->id,
            'class_id' => $this->classA->id,
            'stage_id' => $this->stageA->id,
        ]);

        // Compare against classA->id, NOT churchA->id.
        //
        // The original assertion compared the user's class_id with the CHURCH
        // id and passed — but only because SQLite assigns rowids from a single
        // database-wide counter, so classes.id and churches.id happened to
        // coincide. PostgreSQL uses a per-table sequence, so the two diverge
        // and the assertion failed there. The assertion was therefore never
        // testing what it looked like it was testing.
        $this->assertSame($this->classA->id, (int) $user->class_id);
        $this->assertSame($this->churchA->id, (int) $user->church_id);
    }

    /** A member with no class/stage is legitimate and must not be blocked. */
    public function test_user_without_class_or_stage_is_allowed(): void
    {
        $user = User::factory()->create([
            'church_id' => $this->churchA->id,
            'class_id' => null,
            'stage_id' => null,
        ]);

        $this->assertNull($user->class_id);
    }

    // =================================================================
    // events(church_id, class_year_id) -> classes(church_id, id)
    // =================================================================

    public function test_event_cannot_reference_another_churchs_class(): void
    {
        $this->expectException(QueryException::class);

        DB::table('events')->insert([
            'church_id' => $this->churchA->id,
            'class_year_id' => $this->classB->id,
            'name' => 'Cross Tenant Event',
            'type' => 'service',
            'status' => 'draft',
            'is_active' => true,
        ]);
    }

    /**
     * A stage must not be movable into another church while classes still
     * reference it in the old one.
     *
     * This is a regression guard for a real defect in the first draft of this
     * migration, which used ON UPDATE CASCADE. The cascade silently
     * re-homed every class of the stage into the new church, so a single
     * UPDATE on `stages` moved tenant data across a boundary without any
     * application code being involved. The constraint now uses the default
     * NO ACTION and the database refuses instead.
     */
    public function test_stage_cannot_be_silently_moved_to_another_church(): void
    {
        // churchA / stageA / classA are wired together from setUp().
        $this->expectException(QueryException::class);

        DB::table('stages')
            ->where('id', $this->stageA->id)
            ->update(['church_id' => $this->churchB->id]);
    }

    /**
     * The same applies to a class: it cannot be pointed at a foreign stage even
     * when the actor keeps its own church_id.
     */
    public function test_class_cannot_be_repointed_at_a_foreign_stage(): void
    {
        $this->expectException(QueryException::class);

        DB::table('classes')
            ->where('id', $this->classA->id)
            ->update(['stage_id' => $this->stageB->id]);
    }

    // =================================================================
    // The invariants hold regardless of which code path writes
    // =================================================================

    public function test_no_cross_tenant_relationship_exists_after_normal_flows(): void
    {
        $adminA = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $this->churchA->id,
            'application_status' => 'approved',
            'email_verified_at' => now(),
            'password' => Hash::make('Matrix@1234'),
        ]);

        // Legitimate same-church writes still work end to end. A real bearer
        // token is used so the permission and approval middleware run exactly
        // as they do in production.
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $adminA->email,
            'password' => 'Matrix@1234',
        ])->assertStatus(200)->json('data.token');

        $this->withHeader('Authorization', 'Bearer '.$token);

        $this->postJson('/api/v1/classes', [
            'stage_id' => $this->stageA->id,
            'name' => 'Api Created Class',
        ])->assertStatus(201);

        $newClass = Classe::withoutGlobalScope(ChurchScope::class)
            ->where('name', 'Api Created Class')->firstOrFail();

        $this->postJson('/api/v1/users', [
            'name' => 'Api Created Member',
            'email' => 'api-created@test.com',
            'password' => 'ApiCreated@1234',
            'password_confirmation' => 'ApiCreated@1234',
            'role' => UserRole::Member->value,
            'class_id' => $newClass->id,
        ])->assertStatus(201);

        // And the database agrees there is no inconsistency anywhere.
        $this->assertSame(0, $this->violationCount(
            'SELECT COUNT(*) AS c FROM classes c JOIN stages s ON s.id = c.stage_id WHERE c.church_id <> s.church_id'
        ));
        $this->assertSame(0, $this->violationCount(
            'SELECT COUNT(*) AS c FROM users u JOIN classes c ON c.id = u.class_id WHERE u.church_id <> c.church_id'
        ));
        $this->assertSame(0, $this->violationCount(
            'SELECT COUNT(*) AS c FROM users u JOIN stages s ON s.id = u.stage_id WHERE u.church_id <> s.church_id'
        ));
    }

    private function violationCount(string $sql): int
    {
        $row = DB::selectOne($sql);

        return (int) $row->c;
    }
}
