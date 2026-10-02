<?php

namespace Tests\Feature;

use App\Contracts\ClasseServiceInterface;
use App\Contracts\UserServiceInterface;
use App\Enums\EventType;
use App\Enums\UserRole;
use App\Enums\UserScope;
use App\Models\Church;
use App\Models\Classe;
use App\Models\Event;
use App\Models\Permission;
use App\Models\Stage;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ownership boundary for the structural chain
 *
 *   Church -> Stage -> Class -> User
 *
 * A resource must never be accepted merely because its id exists. The
 * FormRequests only assert *structural* validity (`exists:stages,id`,
 * `exists:classes,id`) and are deliberately tenant-blind, so ownership has to
 * be established by resolving the row inside the actor's own tenant and
 * comparing the persisted hierarchy.
 *
 * Every rejection below is also asserted against the database, so a refusal
 * that still wrote a row cannot pass.
 */
class TenantOwnershipBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Boundary@1234';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Permission::clearCache();
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

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

    private function stageAdmin(Church $church, Stage $stage, ?UserScope $storedScope = null): User
    {
        return User::factory()->create([
            'role' => UserRole::StageAdmin,
            'church_id' => $church->id,
            'stage_id' => $stage->id,
            'scope' => ($storedScope ?? UserScope::Stage)->value,
            'application_status' => 'approved',
            'email_verified_at' => now(),
            'password' => Hash::make(self::PASSWORD),
        ]);
    }

    /**
     * @return array<string, string>
     */
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
    private function memberPayload(string $email, ?int $classId, ?int $stageId = null): array
    {
        $payload = [
            'name' => 'Boundary Member',
            'email' => $email,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'role' => UserRole::Member->value,
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
    // A / B — Church admin creating a class
    // =================================================================

    /** A. Church A admin -> Church A's stage -> class is created. */
    public function test_a_church_admin_can_create_class_in_own_stage(): void
    {
        [$churchA, $stageA] = $this->tenant('A');

        $this->withHeaders($this->auth($this->churchAdmin($churchA)))
            ->postJson('/api/v1/classes', [
                'stage_id' => $stageA->id,
                'name' => 'Own Class A',
            ])->assertStatus(201);

        $this->assertDatabaseHas('classes', [
            'name' => 'Own Class A',
            'church_id' => $churchA->id,
            'stage_id' => $stageA->id,
        ]);
    }

    /**
     * B. Church A admin -> Church B's stage is refused with 404.
     *
     * The stage is resolved inside the actor's tenant, so a foreign stage is
     * indistinguishable from a missing one and its existence is not disclosed.
     */
    public function test_b_church_admin_cannot_create_class_in_foreign_stage(): void
    {
        [$churchA] = $this->tenant('A');
        [, $stageB] = $this->tenant('B');

        $this->withHeaders($this->auth($this->churchAdmin($churchA)))
            ->postJson('/api/v1/classes', [
                'stage_id' => $stageB->id,
                'name' => 'Injected Class B',
            ])->assertStatus(404);

        $this->assertDatabaseMissing('classes', ['name' => 'Injected Class B']);

        // The only classes in the table are the two per-tenant fixtures:
        // nothing was planted in the actor's own tenant, and no row links
        // Church A to Church B's stage.
        $this->assertDatabaseCount('classes', 2);
        $this->assertDatabaseMissing('classes', [
            'church_id' => $churchA->id,
            'stage_id' => $stageB->id,
        ]);
    }

    // =================================================================
    // C / D — Church admin creating a member
    // =================================================================

    /** C. Church A admin -> Church A's class -> member is created. */
    public function test_c_church_admin_can_create_member_in_own_class(): void
    {
        [$churchA, , $classA] = $this->tenant('A');

        $this->withHeaders($this->auth($this->churchAdmin($churchA)))
            ->postJson('/api/v1/users', $this->memberPayload('own-boundary@test.com', $classA->id))
            ->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'email' => 'own-boundary@test.com',
            'church_id' => $churchA->id,
            'class_id' => $classA->id,
        ]);
    }

    /** D. Church A admin -> Church B's class is refused with 403. */
    public function test_d_church_admin_cannot_create_member_in_foreign_class(): void
    {
        [$churchA] = $this->tenant('A');
        [$churchB, $stageB, $classB] = $this->tenant('B');

        $this->withHeaders($this->auth($this->churchAdmin($churchA)))
            ->postJson('/api/v1/users', $this->memberPayload('cross-class@test.com', $classB->id))
            ->assertStatus(403);

        $this->assertDatabaseMissing('users', ['email' => 'cross-class@test.com']);
        $this->assertDatabaseCount('users', 1); // only the acting admin

        // Church B must be untouched: no member and no stage link.
        $this->assertDatabaseMissing('users', ['class_id' => $classB->id]);
        $this->assertDatabaseHas('classes', ['id' => $classB->id, 'church_id' => $churchB->id, 'stage_id' => $stageB->id]);
    }

    // =================================================================
    // E / F — Stage admin behaviour must be unchanged
    // =================================================================

    /**
     * E. Stage admin -> own stage.
     *
     * Class creation is deliberately admin-only (StagePolicy::create), so a
     * stage admin is refused even inside their own stage. This is the
     * pre-existing contract and it must stay exactly as it is — the fix for
     * the cross-tenant leak must not hand stage admins a new capability.
     */
    public function test_e_stage_admin_cannot_create_class_even_in_own_stage(): void
    {
        [$churchA, $stageA] = $this->tenant('A');
        $admin = $this->stageAdmin($churchA, $stageA);

        $this->withHeaders($this->auth($admin))
            ->postJson('/api/v1/classes', [
                'stage_id' => $stageA->id,
                'name' => 'Stage Admin Class',
            ])->assertStatus(403);

        $this->assertDatabaseMissing('classes', ['name' => 'Stage Admin Class']);
    }

    /** F. Stage admin -> another stage in the same church is refused (404). */
    public function test_f_stage_admin_cannot_create_class_in_foreign_stage(): void
    {
        [$churchA, $stageA] = $this->tenant('A');
        [, $stageB] = $this->tenant('B');
        $admin = $this->stageAdmin($churchA, $stageA);

        $this->withHeaders($this->auth($admin))
            ->postJson('/api/v1/classes', [
                'stage_id' => $stageB->id,
                'name' => 'Stage Admin Injected Class',
            ])->assertStatus(404);

        $this->assertDatabaseMissing('classes', ['name' => 'Stage Admin Injected Class']);
    }

    /** Stage admin -> own-stage member creation still succeeds. */
    public function test_f_stage_admin_can_still_create_member_in_own_stage(): void
    {
        [$churchA, $stageA, $classA] = $this->tenant('A');
        $admin = $this->stageAdmin($churchA, $stageA);

        $this->withHeaders($this->auth($admin))
            ->postJson('/api/v1/users', $this->memberPayload('stage-admin-own@test.com', $classA->id))
            ->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'email' => 'stage-admin-own@test.com',
            'church_id' => $churchA->id,
            'class_id' => $classA->id,
        ]);
    }

    /** Stage admin -> foreign class is still refused and writes nothing. */
    public function test_f_stage_admin_cannot_create_member_in_foreign_class(): void
    {
        [$churchA, $stageA] = $this->tenant('A');
        [, , $classB] = $this->tenant('B');
        $admin = $this->stageAdmin($churchA, $stageA);

        $this->withHeaders($this->auth($admin))
            ->postJson('/api/v1/users', $this->memberPayload('stage-admin-cross@test.com', $classB->id))
            ->assertStatus(403);

        $this->assertDatabaseMissing('users', ['email' => 'stage-admin-cross@test.com']);
    }

    // =================================================================
    // G — Client-supplied tenant identifiers are never trusted
    // =================================================================

    /**
     * G. Pairing a foreign stage/class with a matching client `church_id`
     * must not change the outcome: ownership comes from the persisted row.
     */
    public function test_g_client_supplied_church_id_does_not_grant_access(): void
    {
        [$churchA] = $this->tenant('A');
        [$churchB, $stageB, $classB] = $this->tenant('B');
        $adminA = $this->churchAdmin($churchA);

        // Class under Church B's stage, while claiming to act for Church B.
        $this->withHeaders($this->auth($adminA))
            ->postJson('/api/v1/classes', [
                'stage_id' => $stageB->id,
                'church_id' => $churchB->id,
                'name' => 'Spoofed Church Class',
            ])->assertStatus(404);
        $this->assertDatabaseMissing('classes', ['name' => 'Spoofed Church Class']);

        // Member bound to Church B's class, while claiming to act for Church B.
        $this->withHeaders($this->auth($adminA))
            ->postJson('/api/v1/users', $this->memberPayload('spoofed-church@test.com', $classB->id) + [
                'church_id' => $churchB->id,
            ])->assertStatus(403);
        $this->assertDatabaseMissing('users', ['email' => 'spoofed-church@test.com']);
    }

    /** G2. A member created for Church A's class is never re-homed to Church B. */
    public function test_g_class_and_church_ids_stay_consistent_on_create(): void
    {
        [$churchA, , $classA] = $this->tenant('A');
        [$churchB] = $this->tenant('B');

        $this->withHeaders($this->auth($this->churchAdmin($churchA)))
            ->postJson('/api/v1/users', $this->memberPayload('consistent@test.com', $classA->id) + [
                'church_id' => $churchB->id,
            ])->assertStatus(201);

        $user = User::where('email', 'consistent@test.com')->firstOrFail();
        $this->assertSame($churchA->id, (int) $user->church_id, 'church_id is derived from the actor, not the request.');
        $this->assertSame($classA->id, (int) $user->class_id);
    }

    // =================================================================
    // H — Stored scope must never escalate privileges
    // =================================================================

    /**
     * H. A StageAdmin whose stored `scope` column has been tampered to
     * 'church' must still be confined to their own stage.
     *
     * User::getScope() is derived from the role (+ stage_id) and never trusts
     * the stored column, so the widened value grants nothing.
     */
    public function test_h_tampered_stored_scope_does_not_escalate_privileges(): void
    {
        [$churchA, $stageA] = $this->tenant('A');
        [, $stageB, $classB] = $this->tenant('B');

        $admin = $this->stageAdmin($churchA, $stageA, UserScope::Church);

        // The stored column really is 'church' on the row...
        $this->assertSame(
            UserScope::Church,
            $admin->fresh()->getRawOriginal('scope') !== null
                ? UserScope::from((string) $admin->fresh()->getRawOriginal('scope'))
                : UserScope::Self,
            'Fixture precondition: the stored scope column is tampered to church.'
        );

        // ...but the effective scope is derived from the role and stays Stage.
        $this->assertSame(
            UserScope::Stage,
            $admin->fresh()->getScope(),
            'getScope() must ignore the stored scope column.'
        );

        // Class creation outside the stage is still refused.
        $this->withHeaders($this->auth($admin))
            ->postJson('/api/v1/classes', [
                'stage_id' => $stageB->id,
                'name' => 'Escalated Class',
            ])->assertStatus(404);
        $this->assertDatabaseMissing('classes', ['name' => 'Escalated Class']);

        // Member creation in a foreign class is still refused.
        $this->withHeaders($this->auth($admin))
            ->postJson('/api/v1/users', $this->memberPayload('escalated-member@test.com', $classB->id))
            ->assertStatus(403);
        $this->assertDatabaseMissing('users', ['email' => 'escalated-member@test.com']);
    }

    /**
     * H2. A Member whose stored `scope` is tampered to 'church' gains nothing.
     */
    public function test_h_tampered_member_scope_grants_no_write_access(): void
    {
        [$churchA, $stageA] = $this->tenant('A');

        $member = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $churchA->id,
            'scope' => UserScope::Church->value,
            'application_status' => 'approved',
            'email_verified_at' => now(),
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->withHeaders($this->auth($member))
            ->postJson('/api/v1/classes', [
                'stage_id' => $stageA->id,
                'name' => 'Member Escalated Class',
            ])->assertStatus(403);
        $this->assertDatabaseMissing('classes', ['name' => 'Member Escalated Class']);
    }

    // =================================================================
    // I / J — Non-existent ids keep normal validation behaviour
    // =================================================================

    /** I. A stage_id that does not exist is a validation failure (422). */
    public function test_i_nonexistent_stage_id_is_rejected_by_validation(): void
    {
        [$churchA] = $this->tenant('A');
        $missingStageId = 999999;

        $this->withHeaders($this->auth($this->churchAdmin($churchA)))
            ->postJson('/api/v1/classes', [
                'stage_id' => $missingStageId,
                'name' => 'Ghost Stage Class',
            ])->assertStatus(422);

        $this->assertDatabaseMissing('classes', ['name' => 'Ghost Stage Class']);
    }

    /** J. A class_id that does not exist is a validation failure (422). */
    public function test_j_nonexistent_class_id_is_rejected_by_validation(): void
    {
        [$churchA] = $this->tenant('A');

        $this->withHeaders($this->auth($this->churchAdmin($churchA)))
            ->postJson('/api/v1/users', $this->memberPayload('ghost-class@test.com', 999999))
            ->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'ghost-class@test.com']);
    }

    // =================================================================
    // Events — an event must not be targetable at another church's class
    // =================================================================

    /**
     * An event belongs to the actor's church, so a class from another church
     * must never be attached to it on create.
     */
    public function test_church_admin_cannot_create_event_targeting_foreign_class(): void
    {
        [$churchA] = $this->tenant('A');
        [, , $classB] = $this->tenant('B');

        $this->withHeaders($this->auth($this->churchAdmin($churchA)))
            ->postJson('/api/v1/events', [
                'name' => 'Cross Tenant Event',
                'type' => EventType::Service->value,
                'is_active' => true,
                'class_id' => $classB->id,
            ])->assertStatus(403);

        $this->assertDatabaseMissing('events', ['name' => 'Cross Tenant Event']);
        $this->assertDatabaseMissing('events', [
            'church_id' => $churchA->id,
            'class_year_id' => $classB->id,
        ]);
    }

    /** The same must hold for the target_class_ids array on create. */
    public function test_church_admin_cannot_create_event_with_foreign_target_class_ids(): void
    {
        [$churchA] = $this->tenant('A');
        [, , $classB] = $this->tenant('B');

        $this->withHeaders($this->auth($this->churchAdmin($churchA)))
            ->postJson('/api/v1/events', [
                'name' => 'Foreign Targets Event',
                'type' => EventType::Service->value,
                'is_active' => true,
                'target_class_ids' => [$classB->id],
            ])->assertStatus(403);

        $this->assertDatabaseMissing('events', ['name' => 'Foreign Targets Event']);
        $this->assertDatabaseMissing('event_targets', ['class_id' => $classB->id]);
    }

    /** An existing event must not be re-pointed at another church's class. */
    public function test_church_admin_cannot_retarget_event_to_foreign_class(): void
    {
        [$churchA, $stageA] = $this->tenant('A');
        $adminA = $this->churchAdmin($churchA);
        [, , $classB] = $this->tenant('B');

        $classA = Classe::factory()->forChurch($churchA)
            ->state(['stage_id' => $stageA->id])
            ->create(['name' => 'Class A Extra']);

        $eventId = $this->withHeaders($this->auth($adminA))
            ->postJson('/api/v1/events', [
                'name' => 'Retarget Base Event',
                'type' => EventType::Service->value,
                'is_active' => true,
                'class_id' => $classA->id,
            ])->assertStatus(201)->json('data.id');

        $this->withHeaders($this->auth($adminA))
            ->putJson('/api/v1/events/'.$eventId, [
                'class_id' => $classB->id,
            ])->assertStatus(403);

        $event = Event::query()->findOrFail($eventId);
        $this->assertNotSame(
            $classB->id,
            (int) $event->class_year_id,
            'The event must still point at its own class.'
        );
        $this->assertSame($churchA->id, (int) $event->church_id);
    }

    /** Targeting a class inside the actor's own church keeps working. */
    public function test_church_admin_can_create_event_targeting_own_class(): void
    {
        [$churchA, , $classA] = $this->tenant('A');

        $eventId = $this->withHeaders($this->auth($this->churchAdmin($churchA)))
            ->postJson('/api/v1/events', [
                'name' => 'Own Class Event',
                'type' => EventType::Service->value,
                'is_active' => true,
                'class_id' => $classA->id,
            ])->assertStatus(201)->json('data.id');

        $event = Event::query()->findOrFail($eventId);
        $this->assertSame($churchA->id, (int) $event->church_id);
        $this->assertSame($classA->id, (int) $event->class_year_id);
    }

    /** A stage admin still cannot create an event for another stage's class. */
    public function test_stage_admin_cannot_create_event_targeting_foreign_class(): void
    {
        [$churchA, $stageA] = $this->tenant('A');
        [, , $classB] = $this->tenant('B');

        $this->withHeaders($this->auth($this->stageAdmin($churchA, $stageA)))
            ->postJson('/api/v1/events', [
                'name' => 'Stage Admin Cross Event',
                'type' => EventType::Service->value,
                'is_active' => true,
                'class_id' => $classB->id,
            ])->assertStatus(403);

        $this->assertDatabaseMissing('events', ['name' => 'Stage Admin Cross Event']);
    }

    // =================================================================
    // Defense in depth — the service layer rejects on its own
    // =================================================================

    /**
     * The service must refuse a foreign stage even when it is invoked
     * directly, i.e. with the controller and the policy bypassed. Without
     * this guard a future caller that skips authorization would reopen the
     * cross-tenant write.
     */
    public function test_service_layer_refuses_foreign_stage_without_the_controller(): void
    {
        [$churchA] = $this->tenant('A');
        [, $stageB] = $this->tenant('B');
        $adminA = $this->churchAdmin($churchA);

        Sanctum::actingAs($adminA);

        $this->expectException(ValidationException::class);

        try {
            app(ClasseServiceInterface::class)->create([
                'stage_id' => $stageB->id,
                'name' => 'Service Bypass Class',
            ]);
        } finally {
            $this->assertDatabaseMissing('classes', ['name' => 'Service Bypass Class']);
            $this->assertDatabaseMissing('classes', [
                'church_id' => $churchA->id,
                'stage_id' => $stageB->id,
            ]);
        }
    }

    /**
     * Same guarantee for member creation: UserService::create() must refuse a
     * foreign class on its own, so the choke point holds even if the
     * controller's check is ever removed.
     */
    public function test_service_layer_refuses_foreign_class_without_the_controller(): void
    {
        [$churchA] = $this->tenant('A');
        [, , $classB] = $this->tenant('B');
        $adminA = $this->churchAdmin($churchA);

        Sanctum::actingAs($adminA);

        $this->expectException(AuthorizationException::class);

        try {
            app(UserServiceInterface::class)->create(
                $this->memberPayload('service-bypass@test.com', $classB->id),
                $adminA->id
            );
        } finally {
            $this->assertDatabaseMissing('users', ['email' => 'service-bypass@test.com']);
        }
    }
}
