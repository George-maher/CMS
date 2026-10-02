<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Full cross-tenant matrix for the write surface.
 *
 * Two independent tenants, each with two stages and two classes:
 *
 *   Church A -> Stage A1 (Class A1, Class A2), Stage A2 (Class A3, Class A4)
 *   Church B -> Stage B1 (Class B1, Class B2), Stage B2 (Class B3, Class B4)
 *
 * Every rejection asserts BOTH the HTTP status and the database state, so a
 * request that is refused *after* persisting cannot pass.
 */
class TenantIsolationMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Matrix@1234';

    private Church $churchA;

    private Church $churchB;

    /** @var array<string, Stage> */
    private array $stages = [];

    /** @var array<string, Classe> */
    private array $classes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Permission::clearCache();

        foreach ([['A', 'churchA'], ['B', 'churchB']] as [$suffix, $prop]) {
            $church = Church::factory()->create(['name' => 'Church '.$suffix]);
            $this->{$prop} = $church;

            foreach ([1, 2] as $n) {
                $stage = Stage::factory()->forChurch($church)->create(['name' => 'Stage '.$suffix.$n]);
                $this->stages[$suffix.$n] = $stage;

                foreach ([1, 2] as $c) {
                    $this->classes[$suffix.$n.$c] = Classe::factory()->forChurch($church)
                        ->state(['stage_id' => $stage->id])
                        ->create(['name' => 'Class '.$suffix.$n.$c]);
                }
            }
        }
    }

    // -----------------------------------------------------------------
    // Actors
    // -----------------------------------------------------------------

    private function actor(string $role, string $churchProp, ?string $stageKey = null, ?string $classKey = null): User
    {
        $church = $this->{$churchProp};

        return User::factory()->create([
            'role' => $role,
            'church_id' => $church->id,
            'stage_id' => $stageKey !== null ? $this->stages[$stageKey]->id : null,
            'class_id' => $classKey !== null ? $this->classes[$classKey]->id : null,
            'scope' => $this->scopeFor($role, $stageKey)->value,
            'application_status' => 'approved',
            'email_verified_at' => now(),
            'password' => Hash::make(self::PASSWORD),
        ]);
    }

    private function scopeFor(string $role, ?string $stageKey): UserScope
    {
        return match ($role) {
            UserRole::Admin->value, UserRole::AssistantAdmin->value => UserScope::Church,
            UserRole::StageAdmin->value => $stageKey !== null ? UserScope::Stage : UserScope::Self,
            UserRole::Servant->value => UserScope::ClassScope,
            default => UserScope::Self,
        };
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

    private function adminA(): User
    {
        return $this->actor(UserRole::Admin->value, 'churchA');
    }

    private function memberA(string $classKey = 'A11'): User
    {
        return $this->actor(UserRole::Member->value, 'churchA', null, $classKey);
    }

    private function eventA(string $classKey = 'A11'): Event
    {
        return Event::factory()->create([
            'name' => 'Event '.$classKey,
            'church_id' => $this->churchA->id,
            'class_year_id' => $this->classes[$classKey]->id,
            'created_by' => $this->adminA()->id,
        ]);
    }

    // =================================================================
    // A. Same-church happy paths must keep working
    // =================================================================

    public function test_church_admin_can_create_class_in_own_stage(): void
    {
        $admin = $this->adminA();

        $this->withHeaders($this->auth($admin))
            ->postJson('/api/v1/classes', [
                'stage_id' => $this->stages['A1']->id,
                'name' => 'New A1 Class',
            ])->assertStatus(201);

        $this->assertDatabaseHas('classes', [
            'name' => 'New A1 Class',
            'church_id' => $this->churchA->id,
            'stage_id' => $this->stages['A1']->id,
        ]);
    }

    public function test_church_admin_can_move_member_within_own_church(): void
    {
        $admin = $this->adminA();
        $member = $this->memberA('A11');

        $this->withHeaders($this->auth($admin))
            ->putJson('/api/v1/users/'.$member->id, [
                'class_id' => $this->classes['A12']->id,
            ])->assertStatus(200);

        $this->assertSame($this->classes['A12']->id, (int) $member->fresh()->class_id);
    }

    public function test_church_admin_can_create_event_targeting_own_class(): void
    {
        $admin = $this->adminA();

        $eventId = $this->withHeaders($this->auth($admin))
            ->postJson('/api/v1/events', [
                'name' => 'Own Class Event',
                'type' => EventType::Service->value,
                'is_active' => true,
                'class_id' => $this->classes['A11']->id,
            ])->assertStatus(201)->json('data.id');

        $event = Event::query()->findOrFail($eventId);
        $this->assertSame($this->classes['A11']->id, (int) $event->class_year_id);
        $this->assertSame($this->churchA->id, (int) $event->church_id);
    }

    // =================================================================
    // B. Cross-tenant writes must be rejected with no DB change
    // =================================================================

    /** A Admin -> B Stage: rejected, nothing written. */
    public function test_church_admin_cannot_create_class_in_foreign_stage(): void
    {
        $admin = $this->adminA();
        // Counted unscoped: the assertion is about total row count, and
        // ChurchScope would otherwise hide Church B's own fixtures and make
        // the before/after comparison meaningless.
        $before = DB::table('classes')->count();

        $this->withHeaders($this->auth($admin))
            ->postJson('/api/v1/classes', [
                'stage_id' => $this->stages['B1']->id,
                'name' => 'Injected B Class',
            ])->assertStatus(404);

        $this->assertDatabaseMissing('classes', ['name' => 'Injected B Class']);
        $this->assertSame($before, DB::table('classes')->count(), 'No class row may be created.');
        $this->assertDatabaseMissing('classes', [
            'church_id' => $this->churchA->id,
            'stage_id' => $this->stages['B1']->id,
        ]);
    }

    /** A Admin -> B Class on user create: rejected, nothing written. */
    public function test_church_admin_cannot_create_member_in_foreign_class(): void
    {
        $admin = $this->adminA();
        $before = DB::table('users')->count();

        $this->withHeaders($this->auth($admin))
            ->postJson('/api/v1/users', $this->userPayload('x-member@test.com', $this->classes['B1'.'1']->id))
            ->assertStatus(403);

        $this->assertDatabaseMissing('users', ['email' => 'x-member@test.com']);
        $this->assertSame($before, DB::table('users')->count(), 'No user row may be created.');
    }

    /**
     * A Admin -> B Class on user UPDATE: rejected, member unchanged.
     *
     * This is the update-path twin of the create-path check.
     */
    public function test_church_admin_cannot_move_member_into_foreign_class(): void
    {
        $admin = $this->adminA();
        $member = $this->memberA('A11');
        $originalClassId = (int) $member->class_id;

        $this->withHeaders($this->auth($admin))
            ->putJson('/api/v1/users/'.$member->id, [
                'class_id' => $this->classes['B1'.'1']->id,
            ])->assertStatus(403);

        $member->refresh();
        $this->assertSame(
            $originalClassId,
            (int) $member->class_id,
            'The member must not be re-homed into a foreign class.'
        );
        $this->assertSame($this->churchA->id, (int) $member->church_id);
        $this->assertDatabaseMissing('users', [
            'id' => $member->id,
            'class_id' => $this->classes['B1'.'1']->id,
        ]);
    }

    /** A Admin -> B Class on event create: rejected, nothing written. */
    public function test_church_admin_cannot_create_event_targeting_foreign_class(): void
    {
        $admin = $this->adminA();
        $before = DB::table('events')->count();

        $this->withHeaders($this->auth($admin))
            ->postJson('/api/v1/events', [
                'name' => 'Foreign Class Event',
                'type' => EventType::Service->value,
                'is_active' => true,
                'class_id' => $this->classes['B1'.'1']->id,
            ])->assertStatus(403);

        $this->assertDatabaseMissing('events', ['name' => 'Foreign Class Event']);
        $this->assertSame($before, DB::table('events')->count(), 'No event row may be created.');
    }

    /**
     * Bug D: a mixed array [own class, foreign class] must be rejected
     * ENTIRELY — no partial foreign target.
     */
    public function test_mixed_target_class_ids_rejects_entire_operation(): void
    {
        $admin = $this->adminA();
        $before = DB::table('events')->count();

        $this->withHeaders($this->auth($admin))
            ->postJson('/api/v1/events', [
                'name' => 'Mixed Targets Event',
                'type' => EventType::Service->value,
                'is_active' => true,
                'target_class_ids' => [$this->classes['A1'.'1']->id, $this->classes['B1'.'1']->id],
            ])->assertStatus(403);

        $this->assertDatabaseMissing('events', ['name' => 'Mixed Targets Event']);
        $this->assertSame($before, DB::table('events')->count(), 'No partial event may be created.');
        $this->assertDatabaseMissing('event_targets', ['class_id' => $this->classes['B1'.'1']->id]);
        $this->assertDatabaseMissing('event_targets', ['class_id' => $this->classes['A1'.'1']->id]);
    }

    // =================================================================
    // C. Cross-stage within the same church
    // =================================================================

    /**
     * A sibling stage is inside the actor's own church, so it resolves rather
     * than 404-ing; the refusal comes from StagePolicy::create, which is
     * admin-only by design. The class-admin restriction must not be relaxed.
     */
    public function test_stage_admin_cannot_create_class_in_sibling_stage(): void
    {
        $stageAdmin = $this->actor(UserRole::StageAdmin->value, 'churchA', 'A1');
        $before = DB::table('classes')->count();

        $this->withHeaders($this->auth($stageAdmin))
            ->postJson('/api/v1/classes', [
                'stage_id' => $this->stages['A2']->id,
                'name' => 'Stage Admin Sibling Class',
            ])->assertStatus(403);

        $this->assertDatabaseMissing('classes', ['name' => 'Stage Admin Sibling Class']);
        $this->assertSame($before, DB::table('classes')->count());
    }

    public function test_stage_admin_cannot_create_member_in_sibling_stage_class(): void
    {
        $stageAdmin = $this->actor(UserRole::StageAdmin->value, 'churchA', 'A1');

        $this->withHeaders($this->auth($stageAdmin))
            ->postJson('/api/v1/users', $this->userPayload('sibling-stage@test.com', $this->classes['A2'.'1']->id))
            ->assertStatus(403);

        $this->assertDatabaseMissing('users', ['email' => 'sibling-stage@test.com']);
    }

    public function test_stage_admin_can_still_create_member_in_own_stage(): void
    {
        $stageAdmin = $this->actor(UserRole::StageAdmin->value, 'churchA', 'A1');

        $this->withHeaders($this->auth($stageAdmin))
            ->postJson('/api/v1/users', $this->userPayload('own-stage@test.com', $this->classes['A1'.'1']->id))
            ->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'email' => 'own-stage@test.com',
            'church_id' => $this->churchA->id,
            'class_id' => $this->classes['A1'.'1']->id,
        ]);
    }

    // =================================================================
    // D. Read isolation
    // =================================================================

    public function test_church_admin_cannot_read_foreign_user(): void
    {
        $adminA = $this->adminA();
        $foreignMember = $this->actor(UserRole::Member->value, 'churchB', null, 'B1'.'1');

        // /users/{id} resolves through the church scope, so a foreign member
        // is indistinguishable from a missing one.
        $this->withHeaders($this->auth($adminA))
            ->getJson('/api/v1/users/'.$foreignMember->id)
            ->assertStatus(404);

        // /member-profile/{id} resolves the member first and then applies
        // MemberProfileService::canViewProfile(), which compares churches and
        // refuses with 403. The 403 vs 404 difference reveals only that the id
        // exists — no Church B data is returned, which is what matters here.
        $profile = $this->withHeaders($this->auth($adminA))
            ->getJson('/api/v1/member-profile/'.$foreignMember->id)
            ->assertStatus(403);

        $this->assertNull($profile->json('data'));
        $this->assertStringNotContainsString(
            (string) $foreignMember->email,
            (string) $profile->getContent(),
            'No foreign member attributes may be disclosed.'
        );
    }

    public function test_church_admin_cannot_read_or_write_foreign_stage_or_class(): void
    {
        $adminA = $this->adminA();

        $this->withHeaders($this->auth($adminA))
            ->getJson('/api/v1/stages/'.$this->stages['B1']->id)
            ->assertStatus(404);

        $this->withHeaders($this->auth($adminA))
            ->getJson('/api/v1/classes/'.$this->classes['B1'.'1']->id)
            ->assertStatus(404);

        // And the foreign stage cannot be reassigned or deleted.
        $this->withHeaders($this->auth($adminA))
            ->putJson('/api/v1/stages/'.$this->stages['B1']->id, ['name' => 'Hijacked'])
            ->assertStatus(404);

        $this->withHeaders($this->auth($adminA))
            ->deleteJson('/api/v1/stages/'.$this->stages['B1']->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('stages', ['id' => $this->stages['B1']->id, 'name' => 'Stage B1']);
    }

    public function test_church_admin_cannot_retarget_event_to_foreign_class(): void
    {
        $adminA = $this->adminA();
        $event = $this->eventA('A11');

        $this->withHeaders($this->auth($adminA))
            ->putJson('/api/v1/events/'.$event->id, [
                'class_id' => $this->classes['B1'.'1']->id,
            ])->assertStatus(403);

        $event->refresh();
        $this->assertSame($this->classes['A11']->id, (int) $event->class_year_id);
    }

    public function test_church_admin_cannot_touch_foreign_event(): void
    {
        $adminA = $this->adminA();
        $foreignEvent = Event::factory()->create([
            'name' => 'Church B Event',
            'church_id' => $this->churchB->id,
            'status' => EventStatus::Open->value,
        ]);

        $this->withHeaders($this->auth($adminA))
            ->getJson('/api/v1/events/'.$foreignEvent->id)
            ->assertStatus(404);

        $this->withHeaders($this->auth($adminA))
            ->deleteJson('/api/v1/events/'.$foreignEvent->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('events', ['id' => $foreignEvent->id, 'name' => 'Church B Event']);
    }

    // =================================================================
    // E. Manipulated identifiers
    // =================================================================

    public function test_client_supplied_church_id_cannot_override_ownership(): void
    {
        $adminA = $this->adminA();

        $this->withHeaders($this->auth($adminA))
            ->postJson('/api/v1/classes', [
                'stage_id' => $this->stages['B1']->id,
                'church_id' => $this->churchB->id,
                'name' => 'Spoofed Church Class',
            ])->assertStatus(404);

        $this->assertDatabaseMissing('classes', ['name' => 'Spoofed Church Class']);
    }

    public function test_tampered_stored_scope_grants_no_extra_tenancy(): void
    {
        // A member whose stored scope is tampered to 'church' must not gain
        // write access: getScope() is role-derived and ignores the column.
        $member = $this->actor(UserRole::Member->value, 'churchA', null, 'A1'.'1');
        $member->forceFill(['scope' => UserScope::Church->value])->save();

        $this->assertSame(UserScope::Self, $member->fresh()->getScope());

        $this->withHeaders($this->auth($member))
            ->postJson('/api/v1/classes', [
                'stage_id' => $this->stages['A1']->id,
                'name' => 'Member Escalated Class',
            ])->assertStatus(403);

        $this->assertDatabaseMissing('classes', ['name' => 'Member Escalated Class']);
    }

    // -----------------------------------------------------------------

    /** @return array<string, mixed> */
    private function userPayload(string $email, int $classId): array
    {
        return [
            'name' => 'Matrix Member',
            'email' => $email,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'role' => UserRole::Member->value,
            'class_id' => $classId,
        ];
    }
}
