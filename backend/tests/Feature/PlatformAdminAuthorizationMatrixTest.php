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
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 3 — Platform Admin authorization semantics, stated explicitly.
 *
 * The rule this file pins is:
 *
 *   role == platform_admin  does NOT mean "can do anything anywhere".
 *
 * Platform authority is a SEPARATE authority domain from church authority,
 * and the two do not overlap in either direction:
 *
 *   - Platform endpoints are gated by `role:platform_admin` middleware. A
 *     church admin, stage admin or member is refused with 403 regardless of
 *     which church it addresses.
 *   - Church endpoints are gated by `permission:*` and by `ChurchScope`. A
 *     platform admin holds NO role_permission rows and `Permission::
 *     defaultRolePermissions()` deliberately has no `platform_admin` key, so
 *     it is refused with 403 on `manage_users`-class endpoints.
 *
 * That second half is the surprising one, and it is the reason this test
 * exists: a well-meaning change that adds `platform_admin` to the permission
 * defaults would silently hand every platform admin church-wide access to
 * user management. That is a privilege escalation, not a bug fix, so it is
 * pinned here.
 *
 * Where a platform admin IS allowed to cross tenants (its own endpoints), the
 * test asserts the cross-tenant access is INTENDED and works, rather than
 * restricting it to make the matrix look tidy.
 */
class PlatformAdminAuthorizationMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Matrix@1234';

    private Church $churchA;

    private Church $churchB;

    private Stage $stageA;

    private Stage $stageB;

    private Classe $classA;

    private Classe $classB;

    private User $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Permission::clearCache();

        $this->churchA = Church::factory()->create(['name' => 'Church A']);
        $this->churchB = Church::factory()->create(['name' => 'Church B']);

        $this->stageA = Stage::factory()->forChurch($this->churchA)->create(['name' => 'Stage A']);
        $this->stageB = Stage::factory()->forChurch($this->churchB)->create(['name' => 'Stage B']);

        $this->classA = Classe::factory()->forChurch($this->churchA)
            ->state(['stage_id' => $this->stageA->id])->create(['name' => 'Class A']);
        $this->classB = Classe::factory()->forChurch($this->churchB)
            ->state(['stage_id' => $this->stageB->id])->create(['name' => 'Class B']);

        $this->platformAdmin = $this->makeUser(UserRole::PlatformAdmin, null);
    }

    // =================================================================
    // Direction 1 — only a platform admin reaches platform endpoints
    // =================================================================

    /**
     * @return array<int, array{0: string}>
     */
    public static function platformEndpointProvider(): array
    {
        return [
            ['/platform/dashboard'],
            ['/platform/churches'],
            ['/platform/applications'],
        ];
    }

    #[DataProvider('platformEndpointProvider')]
    public function test_platform_endpoint_rejects_every_non_platform_role(string $path): void
    {
        foreach ([UserRole::Admin, UserRole::AssistantAdmin, UserRole::StageAdmin, UserRole::Servant, UserRole::Member] as $role) {
            $actor = $role === UserRole::StageAdmin
                ? $this->makeUser($role, $this->churchA, ['stage_id' => $this->stageA->id])
                : ($role === UserRole::Servant
                    ? $this->makeUser($role, $this->churchA, ['class_id' => $this->classA->id])
                    : $this->makeUser($role, $this->churchA));

            $this->getJson('/api/v1'.$path, $this->authHeaders($actor))
                ->assertForbidden();

            unset($actor);
        }
    }

    // =================================================================
    // Direction 2 — a platform admin does NOT inherit church authority
    // =================================================================

    /**
     * `manage_users`-gated endpoints are closed to platform admins.
     *
     * If this ever starts returning 200, platform admins have been given
     * church-wide user management. That is an escalation and must be a
     * deliberate, reviewed decision — not a side effect of seeding changes.
     */
    public function test_platform_admin_holds_no_church_level_permissions(): void
    {
        foreach (['/users', '/churches', '/password-reset-requests', '/membership-requests'] as $path) {
            $this->getJson('/api/v1'.$path, $this->authHeaders($this->platformAdmin))
                ->assertForbidden();
        }
    }

    /**
     * The underlying reason, asserted directly so a future seeding change
     * cannot quietly invert the matrix above.
     */
    public function test_permission_lookup_has_no_platform_admin_default(): void
    {
        $this->assertArrayNotHasKey(
            UserRole::PlatformAdmin->value,
            Permission::defaultRolePermissions(),
            'Adding a platform_admin entry to the permission defaults would grant '
            .'every platform admin church-wide permissions. Platform authority is '
            .'gated by role: middleware on /platform/* instead.'
        );

        // And there are no seeded rows either.
        $this->assertFalse(
            Permission::userHasPermission($this->platformAdmin, 'manage_users'),
            'A platform admin must not resolve to the manage_users permission.'
        );
    }

    // =================================================================
    // Direction 3 — platform admin may legitimately cross tenants,
    //               but only inside its own authority domain
    // =================================================================

    /**
     * Cross-tenant access on a platform endpoint is INTENDED and must keep
     * working. This guards against "fixing" the matrix by crippling platform
     * functionality.
     */
    public function test_platform_admin_sees_every_church_on_its_own_endpoints(): void
    {
        $response = $this->getJson('/api/v1/platform/churches', $this->authHeaders($this->platformAdmin))
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($this->churchA->id, $ids);
        $this->assertContains($this->churchB->id, $ids);
    }

    /**
     * A platform admin addresses any church by id on a platform endpoint,
     * and the id is NOT filtered by ChurchScope. That is the intent of
     * church decommission.
     */
    public function test_platform_admin_can_address_either_church_by_id(): void
    {
        foreach ([$this->churchA, $this->churchB] as $church) {
            $this->getJson("/api/v1/platform/churches/{$church->id}/deletion-summary", $this->authHeaders($this->platformAdmin))
                ->assertOk();
        }
    }

    /**
     * Deletion is destructive and irreversible, so it carries extra proof:
     * password re-check plus a literal confirmation, per DeleteChurchRequest.
     * The endpoint being reachable is not enough; it must not act without them.
     */
    public function test_platform_church_deletion_requires_reauthentication_and_confirmation(): void
    {
        $this->postJson("/api/v1/platform/churches/{$this->churchA->id}/soft-delete", [], $this->authHeaders($this->platformAdmin))
            ->assertStatus(422);

        $this->assertDatabaseHas('churches', [
            'id' => $this->churchA->id,
            'deleted_at' => null,
        ]);

        // A wrong password is refused even with a correct confirmation string.
        $this->postJson("/api/v1/platform/churches/{$this->churchA->id}/soft-delete", [
            'password' => 'WrongPassword@1',
            'confirmation' => 'DELETE CHURCH',
        ], $this->authHeaders($this->platformAdmin))->assertStatus(422);

        $this->assertDatabaseHas('churches', [
            'id' => $this->churchA->id,
            'deleted_at' => null,
        ]);
    }

    // =================================================================
    // Direction 4 — church roles cannot reach another church
    // =================================================================

    /**
     * @return array<int, array{0: string, 1: string, 2: int}>
     */
    public static function foreignResourceProvider(): array
    {
        return [
            ['admin', 'GET /stages/{id}', 0],
            ['admin', 'GET /classes/{id}', 0],
            ['admin', 'GET /users/{id}', 0],
            ['stage_admin', 'GET /stages/{id}', 0],
            ['stage_admin', 'GET /classes/{id}', 0],
            ['member', 'GET /stages/{id}', 0],
            ['member', 'GET /classes/{id}', 0],
        ];
    }

    #[DataProvider('foreignResourceProvider')]
    public function test_church_role_cannot_read_another_churchs_resource(string $role, string $route, int $_): void
    {
        $actor = match ($role) {
            'stage_admin' => $this->makeUser(UserRole::StageAdmin, $this->churchA, ['stage_id' => $this->stageA->id]),
            default => $this->makeUser(UserRole::Admin, $this->churchA),
        };

        $path = str_replace(
            '{id}',
            (string) match (true) {
                str_contains($route, 'stages') => $this->stageB->id,
                str_contains($route, 'classes') => $this->classB->id,
                default => $this->makeUser(UserRole::Member, $this->churchB)->id,
            },
            $route
        );

        $this->getJson('/api/v1'.$path, $this->authHeaders($actor))
            ->assertStatus(404);
    }

    // =================================================================
    // Direction 5 — a user with no church must see nothing (fail closed)
    // =================================================================

    /**
     * The fail-open this pins was real: `ChurchScope::resolveChurchId()`
     * returned null both for "platform admin" and for "user with no
     * church_id", and null meant "apply no filter". An approved non-platform
     * user with church_id = NULL therefore received every church's stages
     * from `GET /stages`.
     */
    public function test_authenticated_user_without_a_church_sees_no_tenant_data(): void
    {
        $churchless = $this->makeUser(UserRole::Admin, null);

        $stages = $this->getJson('/api/v1/stages', $this->authHeaders($churchless))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertSame([], $stages->json('data'));

        // And no single-resource read leaks either.
        $this->getJson('/api/v1/stages/'.$this->stageA->id, $this->authHeaders($churchless))
            ->assertNotFound();
        $this->getJson('/api/v1/stages/'.$this->stageB->id, $this->authHeaders($churchless))
            ->assertNotFound();
    }

    /**
     * The platform admin bypass is explicit and must survive the fail-closed
     * change above — it is the only actor allowed to see across tenants.
     */
    public function test_platform_admin_bypass_is_preserved(): void
    {
        $this->getJson('/api/v1/stages/'.$this->stageA->id, $this->authHeaders($this->platformAdmin))
            ->assertOk();
        $this->getJson('/api/v1/stages/'.$this->stageB->id, $this->authHeaders($this->platformAdmin))
            ->assertOk();
    }

    // =================================================================
    // helpers
    // =================================================================

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeUser(UserRole $role, ?Church $church, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role,
            'church_id' => $church?->id,
            'application_status' => 'approved',
            'email_verified_at' => now(),
            'password' => Hash::make(self::PASSWORD),
        ], $overrides));
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('matrix')->plainTextToken];
    }
}
