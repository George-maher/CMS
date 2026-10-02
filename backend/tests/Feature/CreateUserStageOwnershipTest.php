<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Church;
use App\Models\Classe;
use App\Models\Permission;
use App\Models\Stage;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `POST /api/v1/users` — ownership of the client-supplied `stage_id`.
 *
 * The defect this pins
 * --------------------
 * `CreateUserRequest` validated `stage_id` with a bare, tenant-blind
 * `exists:stages,id`. `UserController::update()` and `promote()` both
 * re-resolve the stage through `Stage::query()->find()` (the `ChurchScope`
 * global scope) and refuse anything outside the actor's authority, but
 * `store()` only performed that check inside the
 * `if ($role === UserRole::StageAdmin->value)` branch. For `member` and
 * `servant` the foreign stage id was passed straight through to
 * `UserService::create()`, which re-validates `class_id` but not `stage_id`.
 *
 * The composite tenant FK `users_church_stage_fk` therefore stopped the write,
 * but it stopped it by throwing: the caller got a 500 `INTERNAL_ERROR` with a
 * logged raw driver message instead of a 4xx, and `500 vs 422` turned the
 * endpoint into a cross-tenant stage-existence oracle (a stage id that exists
 * somewhere returned 500; one that exists nowhere returned 422).
 *
 * `update()` was already correct, so it is asserted here too: the asymmetry
 * between create and update was the bug.
 */
class CreateUserStageOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'StageOwner@1234';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Permission::clearCache();
    }

    /** @return array{0: Church, 1: Stage, 2: Classe} */
    private function tenant(string $suffix): array
    {
        $church = Church::factory()->create(['name' => 'Church '.$suffix]);
        $stage = Stage::factory()->forChurch($church)->create(['name' => 'Stage '.$suffix]);
        $classe = Classe::factory()->forChurch($church)
            ->state(['stage_id' => $stage->id])
            ->create(['name' => 'Class '.$suffix]);

        return [$church, $stage, $classe];
    }

    private function churchAdmin(Church $church): User
    {
        return User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $church->id,
            'application_status' => 'approved',
            'email_verified_at' => now(),
            'password' => Hash::make(self::PASSWORD),
        ]);
    }

    /** @return array<string, string> */
    private function auth(User $user): array
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(200)->json('data.token');

        $this->assertIsString($token);

        return ['Authorization' => 'Bearer '.$token];
    }

    /** @return array<string, mixed> */
    private function payload(string $email, string $role, ?int $classId, ?int $stageId): array
    {
        $payload = [
            'name' => 'Stage Owner Probe',
            'email' => $email,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'role' => $role,
        ];

        if ($classId !== null) {
            $payload['class_id'] = $classId;
        }

        if ($stageId !== null) {
            $payload['stage_id'] = $stageId;
        }

        return $payload;
    }

    // =================================================================
    // The defect
    // =================================================================

    /**
     * A member payload carrying a FOREIGN stage id must be refused, not
     * 500'd, and must not write a row.
     */
    public function test_church_admin_cannot_create_member_with_foreign_stage_id(): void
    {
        [$churchA, $stageA, $classA] = $this->tenant('A');
        [, $stageB] = $this->tenant('B');

        $headers = $this->auth($this->churchAdmin($churchA));

        // Snapshotted AFTER the admin exists, so the delta is attributable to
        // the request under test alone.
        $before = DB::table('users')->count();

        $response = $this->withHeaders($headers)
            ->postJson('/api/v1/users', $this->payload(
                'probe.member@login.test',
                UserRole::Member->value,
                $classA->id,
                $stageB->id,
            ));

        $this->assertContains(
            $response->getStatusCode(),
            [403, 422],
            'A foreign stage_id must be refused with a 4xx, never a 5xx.',
        );

        // The rejection must leave the database untouched. `exists:` is a type
        // check; only this assertion proves nothing was written.
        $this->assertSame($before, DB::table('users')->count());
        $this->assertDatabaseMissing('users', ['email' => 'probe.member@login.test']);
    }

    /**
     * Same for a servant target, which took the identical unguarded path.
     */
    public function test_church_admin_cannot_create_servant_with_foreign_stage_id(): void
    {
        [$churchA, , $classA] = $this->tenant('A');
        [, $stageB] = $this->tenant('B');

        $response = $this->withHeaders($this->auth($this->churchAdmin($churchA)))
            ->postJson('/api/v1/users', $this->payload(
                'probe.servant@login.test',
                UserRole::Servant->value,
                $classA->id,
                $stageB->id,
            ));

        $this->assertContains($response->getStatusCode(), [403, 422]);
        $this->assertDatabaseMissing('users', ['email' => 'probe.servant@login.test']);
    }

    /**
     * A foreign stage with NO class at all — the pure `stage_id` case, which
     * never touched the class branch.
     */
    public function test_church_admin_cannot_create_member_with_foreign_stage_id_and_no_class(): void
    {
        [$churchA] = $this->tenant('A');
        [, $stageB] = $this->tenant('B');

        $response = $this->withHeaders($this->auth($this->churchAdmin($churchA)))
            ->postJson('/api/v1/users', $this->payload(
                'probe.noclass@login.test',
                UserRole::Member->value,
                null,
                $stageB->id,
            ));

        $this->assertContains($response->getStatusCode(), [403, 422]);
        $this->assertDatabaseMissing('users', ['email' => 'probe.noclass@login.test']);
    }

    /**
     * The stage-existence oracle.
     *
     * The defect's observable half was the STATUS CODE: a foreign stage id
     * used to return 500 while a non-existent one returned 422, so a small
     * integer scan of stage ids separated "exists in some tenant" (500) from
     * "exists nowhere" (422) — on top of dumping a raw driver message.
     *
     * A foreign stage (403) is still distinguishable from a non-existent one
     * (422). That is a deliberate, pre-existing project convention shared by
     * `class_id` on the same endpoint, already pinned by
     * `TenantOwnershipBoundaryTest::test_i_nonexistent_stage_id_is_rejected_by_validation`.
     * It leaks *existence*, never content, to an already-privileged
     * `manage_users` holder, and removing it would mean rewriting the
     * validation/authorization split across the whole endpoint family. It is
     * accepted and documented rather than fixed.
     *
     * What this test pins is that neither answer is a 5xx.
     */
    public function test_foreign_stage_id_never_produces_a_server_error(): void
    {
        [$churchA, , $classA] = $this->tenant('A');
        [, $stageB] = $this->tenant('B');

        $absent = $this->withHeaders($this->auth($this->churchAdmin($churchA)))
            ->postJson('/api/v1/users', $this->payload(
                'oracle.absent@login.test',
                UserRole::Member->value,
                $classA->id,
                999_999,
            ));

        $foreign = $this->withHeaders($this->auth($this->churchAdmin($churchA)))
            ->postJson('/api/v1/users', $this->payload(
                'oracle.foreign@login.test',
                UserRole::Member->value,
                $classA->id,
                $stageB->id,
            ));

        $this->assertLessThan(
            500,
            $absent->getStatusCode(),
            'A non-existent stage must not be a server error.',
        );
        $this->assertLessThan(
            500,
            $foreign->getStatusCode(),
            'A foreign stage must not be a server error.',
        );
        $this->assertSame(403, $foreign->getStatusCode());

        // The 5xx must not have leaked the driver's constraint name.
        $body = (string) $foreign->getContent();
        $this->assertStringNotContainsString('users_church_stage_fk', $body);
        $this->assertStringNotContainsString('SQLSTATE', $body);

        $this->assertDatabaseMissing('users', ['email' => 'oracle.absent@login.test']);
        $this->assertDatabaseMissing('users', ['email' => 'oracle.foreign@login.test']);
    }

    // =================================================================
    // The fix must not break the legitimate paths
    // =================================================================

    /**
     * An own-church stage is still accepted. This is the false-positive guard:
     * a fix that simply forbade every `stage_id` would pass the tests above.
     */
    public function test_church_admin_can_still_create_member_with_own_stage_id(): void
    {
        [$churchA, $stageA, $classA] = $this->tenant('A');

        $this->withHeaders($this->auth($this->churchAdmin($churchA)))
            ->postJson('/api/v1/users', $this->payload(
                'own.member@login.test',
                UserRole::Member->value,
                $classA->id,
                $stageA->id,
            ))
            ->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'email' => 'own.member@login.test',
            'church_id' => $churchA->id,
            'stage_id' => $stageA->id,
        ]);
    }

    /**
     * `stage_id` stays optional, and omitting it keeps deriving the stage from
     * the class.
     */
    public function test_omitted_stage_id_still_derives_stage_from_class(): void
    {
        [$churchA, $stageA, $classA] = $this->tenant('A');

        $this->withHeaders($this->auth($this->churchAdmin($churchA)))
            ->postJson('/api/v1/users', $this->payload(
                'derived.member@login.test',
                UserRole::Member->value,
                $classA->id,
                null,
            ))
            ->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'email' => 'derived.member@login.test',
            'church_id' => $churchA->id,
            'stage_id' => $stageA->id,
        ]);
    }

    /**
     * A member with no class and no stage is still valid.
     */
    public function test_member_without_any_scope_is_still_created(): void
    {
        [$churchA] = $this->tenant('A');

        $this->withHeaders($this->auth($this->churchAdmin($churchA)))
            ->postJson('/api/v1/users', $this->payload(
                'bare.member@login.test',
                UserRole::Member->value,
                null,
                null,
            ))
            ->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'email' => 'bare.member@login.test',
            'church_id' => $churchA->id,
        ]);
    }

    /**
     * Stage admin creation is unchanged and still scoped to their stage.
     */
    public function test_stage_admin_cannot_create_user_with_foreign_stage_id(): void
    {
        [$churchA, $stageA, $classA] = $this->tenant('A');
        [, , $classB] = $this->tenant('B');
        [, $stageB] = $this->tenant('B');

        $stageAdmin = User::factory()->create([
            'role' => UserRole::StageAdmin,
            'church_id' => $churchA->id,
            'stage_id' => $stageA->id,
            'application_status' => 'approved',
            'email_verified_at' => now(),
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->withHeaders($this->auth($stageAdmin))
            ->postJson('/api/v1/users', $this->payload(
                'stageadmin.foreign@login.test',
                UserRole::Member->value,
                $classB->id,
                $stageB->id,
            ))
            ->assertStatus(403);

        $this->assertDatabaseMissing('users', ['email' => 'stageadmin.foreign@login.test']);
    }

    /**
     * The update path was already correct; assert it so the asymmetry cannot
     * be reintroduced.
     */
    public function test_update_still_refuses_a_foreign_stage_id(): void
    {
        [$churchA, $stageA, $classA] = $this->tenant('A');
        [, $stageB] = $this->tenant('B');

        $target = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $churchA->id,
            'class_id' => $classA->id,
            'stage_id' => $stageA->id,
            'application_status' => 'approved',
            'email_verified_at' => now(),
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->withHeaders($this->auth($this->churchAdmin($churchA)))
            ->patchJson('/api/v1/users/'.$target->id, [
                'name' => $target->name,
                'email' => $target->email,
                'stage_id' => $stageB->id,
            ])
            ->assertStatus(403);

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'stage_id' => $stageA->id,
        ]);
    }

    /**
     * A platform admin is NOT an authority on `POST /users`.
     *
     * `POST /users` sits behind `permission:manage_users`, and
     * `Permission::defaultRolePermissions()` deliberately has no
     * `platform_admin` key, so a platform admin has no `role_permission` rows
     * and gets 403. Platform authority lives on `/platform/*` and does not
     * overlap church authority (see `docs/TENANT_RULES.md` §7).
     *
     * This is pinned here because the obvious "fix" for the defect above —
     * moving the stage check out of the role branch — is a change to a shared
     * authorization path, and this test makes sure it did not accidentally
     * hand church user-management to platform admins on the way through.
     */
    public function test_platform_admin_cannot_reach_the_church_user_creation_endpoint(): void
    {
        [$churchA, $stageA] = $this->tenant('A');

        $platform = User::factory()->create([
            'role' => UserRole::PlatformAdmin,
            'application_status' => 'approved',
            'email_verified_at' => now(),
            'password' => Hash::make(self::PASSWORD),
        ]);

        Sanctum::actingAs($platform);

        $this->postJson('/api/v1/users', [
            'name' => 'Platform Created',
            'email' => 'platform.created@login.test',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'role' => UserRole::Member->value,
            'church_id' => $churchA->id,
            'stage_id' => $stageA->id,
        ])->assertStatus(403);

        $this->assertDatabaseMissing('users', ['email' => 'platform.created@login.test']);
    }
}
