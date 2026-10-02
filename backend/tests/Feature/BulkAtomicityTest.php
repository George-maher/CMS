<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Church;
use App\Models\Classe;
use App\Models\Permission;
use App\Models\Stage;
use App\Models\User;
use App\Modules\User\Services\UserService;
use App\Services\ClasseService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Bulk-operation atomicity.
 *
 * The rule under test: a bulk write that FAILS must leave NO partial state.
 * A caller that receives an error must be able to trust that nothing changed.
 *
 * The defect this pins
 * --------------------
 * `UserService::bulkUpdatePermissions()` authorized and wrote each user in the
 * same loop iteration, with no transaction. A request naming
 * [allowed, forbidden] therefore left the FIRST user's permissions already
 * rewritten at the moment the SECOND threw — a silent partial mutation
 * reported to the caller as a failure.
 *
 * The HTTP controller masked this by pre-validating every id before calling
 * the service, so it was not reachable through the endpoint. The service is
 * public and is written to be safe on its own, which is the same
 * defense-in-depth rule the tenant boundaries follow.
 *
 * The other bulk writers in the codebase are already atomic and are asserted
 * as such at the bottom of this file, so a future edit that removes one of
 * those transactions also fails here.
 */
class BulkAtomicityTest extends TestCase
{
    use RefreshDatabase;

    private Church $church;

    private Stage $stage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        Permission::clearCache();

        $this->church = Church::factory()->create();
        $this->stage = Stage::factory()->forChurch($this->church)->create();
    }

    /**
     * The core property: a mid-batch authorization failure leaves the
     * already-processed user untouched.
     */
    public function test_a_failed_bulk_permission_update_changes_nothing(): void
    {
        // A stage admin: confined to one stage, so it can reach a member of
        // that stage but NOT a church-wide admin (who has no stage).
        // An assistant admin would not work here — User::isAdmin() is true for
        // BOTH admin and assistant_admin, so it could rewrite an admin's
        // permissions and the batch would legitimately succeed.
        $actor = $this->makeUser(UserRole::StageAdmin);
        $this->actingAs($actor);

        // Created first, so it has the lower id and is processed first.
        $target = $this->makeUser(UserRole::Member);

        $forbidden = $this->makeUser(UserRole::Admin);

        $before = DB::table('role_permission')
            ->where('role_name', UserRole::Member->value)
            ->count();

        $failed = false;

        try {
            app(UserService::class)->bulkUpdatePermissions(
                [$target->id, $forbidden->id],
                ['view_users'],
                (int) $actor->id,
            );
        } catch (ValidationException) {
            $failed = true;
        }

        $this->assertTrue($failed, 'The batch was expected to be refused.');

        $after = DB::table('role_permission')
            ->where('role_name', UserRole::Member->value)
            ->count();

        $this->assertSame(
            $before,
            $after,
            'The first user in the batch was mutated even though the batch failed. '
            .'bulkUpdatePermissions() is not atomic.'
        );
    }

    /**
     * A cross-tenant id must not widen the batch, and must not be applied.
     */
    public function test_a_cross_tenant_id_in_the_batch_is_not_applied(): void
    {
        $actor = $this->makeUser(UserRole::Admin);
        $this->actingAs($actor);

        $ownMember = $this->makeUser(UserRole::Member);

        $otherChurch = Church::factory()->create();
        $foreignMember = User::factory()->create([
            'church_id' => $otherChurch->id,
            'role' => UserRole::Servant,
        ]);

        $result = app(UserService::class)->bulkUpdatePermissions(
            [$ownMember->id, (int) $foreignMember->id],
            ['view_users'],
            (int) $actor->id,
        );

        // findByIds() is church-scoped for an authenticated non-platform actor,
        // so the foreign user never reaches the write loop.
        $this->assertStringContainsString('1 users', $result['message']);
        $this->assertStringNotContainsString('2 users', $result['message']);
    }

    public function test_a_fully_permitted_batch_applies_to_every_user(): void
    {
        $actor = $this->makeUser(UserRole::Admin);
        $a = $this->makeUser(UserRole::Member);
        $b = $this->makeUser(UserRole::Member);

        $result = app(UserService::class)->bulkUpdatePermissions(
            [$a->id, $b->id],
            ['view_users'],
            (int) $actor->id,
        );

        $this->assertStringContainsString('2 users', $result['message']);
    }

    public function test_an_empty_batch_is_a_no_op(): void
    {
        $actor = $this->makeUser(UserRole::Admin);

        $result = app(UserService::class)->bulkUpdatePermissions([], ['view_users'], (int) $actor->id);

        $this->assertStringContainsString('0 users', $result['message']);
    }

    /**
     * Guard the bulk writers that are ALREADY atomic, so a future edit that
     * drops one of their transactions fails here instead of shipping a
     * half-written batch.
     */
    public function test_class_bulk_creation_is_atomic_on_a_name_collision(): void
    {
        $admin = $this->makeUser(UserRole::Admin);
        $this->actingAs($admin);

        // Occupy the generated name range so the batch collides partway.
        Classe::factory()->forChurch($this->church)
            ->state(['stage_id' => $this->stage->id, 'name' => 'Class 1'])
            ->create();

        $before = Classe::withoutGlobalScopes()->count();

        $failed = false;

        try {
            app(ClasseService::class)->create(
                $this->church->id,
                $this->stage->id,
                'Class ',
                3,
                0,
            );
        } catch (\Throwable) {
            $failed = true;
        }

        $after = Classe::withoutGlobalScopes()->count();

        $this->assertSame(
            $before,
            $after,
            'A failed bulk class creation left rows behind — the batch is not atomic.'
        );
        $this->assertTrue($failed || $after === $before + 3);
    }

    private function makeUser(UserRole $role, ?Church $church = null): User
    {
        return User::factory()->create([
            'role' => $role,
            'church_id' => ($church ?? $this->church)->id,
            'stage_id' => $role === UserRole::Member ? $this->stage->id : null,
            'application_status' => 'approved',
            'email_verified_at' => now(),
            'password' => Hash::make('Matrix@1234'),
        ]);
    }
}
