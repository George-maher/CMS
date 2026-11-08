<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\UserRole;
use App\Enums\UserScope;
use App\Models\AttendanceContext;
use App\Models\Church;
use App\Models\Classe;
use App\Models\Event;
use App\Models\Permission;
use App\Models\Stage;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Authorization matrix for stage-scoped access.
 *
 * Covers the four quadrants required by the stage-authorization spec:
 *
 *   A. admin == assistant_admin (church-wide parity, identical permissions)
 *   B. stage_admin ALLOW: reads limited to the assigned stage
 *   C. stage_admin DENY: IDOR, cross-church, client-supplied scope spoofing
 *   D. servant class isolation + fail-closed principals (stage admin without
 *      a stage)
 *
 * Every denial asserts BOTH the HTTP status and the database state, so a
 * request that is refused *after* persisting cannot pass. Aggregate endpoints
 * (dashboard, leaderboards) are asserted in BOTH cache orders to prove that
 * scope-aware cache keys prevent cross-scope poisoning.
 */
class StageAdminAuthorizationMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'StageMatrix@1234';

    private Church $churchA;

    private Church $churchB;

    /** @var array<string, Stage> */
    private array $stages = [];

    /** @var array<string, Classe> */
    private array $classes = [];

    /** @var array<string, User> */
    private array $users = [];

    private Event $eventA11;

    private Event $eventA21;

    private Event $eventB11;

    private AttendanceContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Permission::clearCache();

        $this->churchA = Church::factory()->create(['name' => 'Matrix Church A']);
        $this->churchB = Church::factory()->create(['name' => 'Matrix Church B']);

        $this->stages['A1'] = Stage::factory()->forChurch($this->churchA)->create(['name' => 'Stage A1']);
        $this->stages['A2'] = Stage::factory()->forChurch($this->churchA)->create(['name' => 'Stage A2']);
        $this->stages['B1'] = Stage::factory()->forChurch($this->churchB)->create(['name' => 'Stage B1']);

        $this->classes['A11'] = Classe::factory()->forChurch($this->churchA)
            ->state(['stage_id' => $this->stages['A1']->id])
            ->create(['name' => 'Class A11']);
        $this->classes['A12'] = Classe::factory()->forChurch($this->churchA)
            ->state(['stage_id' => $this->stages['A1']->id])
            ->create(['name' => 'Class A12']);
        $this->classes['A21'] = Classe::factory()->forChurch($this->churchA)
            ->state(['stage_id' => $this->stages['A2']->id])
            ->create(['name' => 'Class A21']);
        $this->classes['B11'] = Classe::factory()->forChurch($this->churchB)
            ->state(['stage_id' => $this->stages['B1']->id])
            ->create(['name' => 'Class B11']);

        $this->users['adminA'] = $this->actor(UserRole::Admin, $this->churchA, null, null, UserScope::Church);
        $this->users['assistantA'] = $this->actor(UserRole::AssistantAdmin, $this->churchA, null, null, UserScope::Church);
        $this->users['stageAdminA'] = $this->actor(UserRole::StageAdmin, $this->churchA, $this->stages['A1'], null, UserScope::Stage);
        $this->users['stageAdminA2'] = $this->actor(UserRole::StageAdmin, $this->churchA, $this->stages['A2'], null, UserScope::Stage);
        // A stage admin without a stage assignment must fail closed.
        $this->users['stageAdminNoStage'] = $this->actor(UserRole::StageAdmin, $this->churchA, null, null, UserScope::Self);
        $this->users['servantA11'] = $this->actor(UserRole::Servant, $this->churchA, $this->stages['A1'], $this->classes['A11'], UserScope::ClassScope);
        $this->users['servantA12'] = $this->actor(UserRole::Servant, $this->churchA, $this->stages['A1'], $this->classes['A12'], UserScope::ClassScope);
        $this->users['memberA11'] = $this->actor(UserRole::Member, $this->churchA, $this->stages['A1'], $this->classes['A11'], UserScope::Self);
        $this->users['memberA12'] = $this->actor(UserRole::Member, $this->churchA, $this->stages['A1'], $this->classes['A12'], UserScope::Self);
        $this->users['memberA21'] = $this->actor(UserRole::Member, $this->churchA, $this->stages['A2'], $this->classes['A21'], UserScope::Self);
        $this->users['adminB'] = $this->actor(UserRole::Admin, $this->churchB, null, null, UserScope::Church);
        $this->users['memberB11'] = $this->actor(UserRole::Member, $this->churchB, $this->stages['B1'], $this->classes['B11'], UserScope::Self);

        $this->eventA11 = Event::factory()->create([
            'name' => 'Event A11',
            'church_id' => $this->churchA->id,
            'class_year_id' => $this->classes['A11']->id,
            'status' => EventStatus::Open->value,
            'created_by' => $this->users['adminA']->id,
        ]);
        $this->eventA21 = Event::factory()->create([
            'name' => 'Event A21',
            'church_id' => $this->churchA->id,
            'class_year_id' => $this->classes['A21']->id,
            'status' => EventStatus::Open->value,
            'created_by' => $this->users['adminA']->id,
        ]);
        $this->eventB11 = Event::factory()->create([
            'name' => 'Event B11',
            'church_id' => $this->churchB->id,
            'class_year_id' => $this->classes['B11']->id,
            'status' => EventStatus::Open->value,
            'created_by' => $this->users['adminB']->id,
        ]);

        $this->context = AttendanceContext::factory()->create([
            'church_id' => $this->churchA->id,
        ]);
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function actor(UserRole $role, Church $church, ?Stage $stage, ?Classe $classe, UserScope $scope): User
    {
        return User::factory()->create([
            'role' => $role->value,
            'church_id' => $church->id,
            'stage_id' => $stage?->id,
            'class_id' => $classe?->id,
            'scope' => $scope->value,
            'application_status' => 'approved',
            'email_verified_at' => now(),
            'is_active' => true,
            'password' => Hash::make(self::PASSWORD),
        ]);
    }

    /** @return array<string, string> */
    private function auth(string $userKey): array
    {
        $user = $this->users[$userKey];
        $token = $user->createToken('test', [$user->role->value])->plainTextToken;

        return ['Authorization' => 'Bearer '.$token];
    }

    /**
     * Extract the item list from a JSON index response, tolerating both
     * {"data": [...]} and {"data": {"data": [...]}} (paginated) shapes.
     *
     * @return array<int, array<string, mixed>>
     */
    private function items(TestResponse $response, int $status = 200): array
    {
        $response->assertStatus($status);
        $json = $response->json();
        $this->assertIsArray($json);

        $data = $json['data'] ?? [];

        if (is_array($data) && ! array_is_list($data) && isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        $this->assertTrue(is_array($data), 'Expected a list payload in response data.');

        /** @var array<int, array<string, mixed>> $data */
        return $data;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, int>
     */
    private function idsOf(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            if (isset($item['id']) && is_numeric($item['id'])) {
                $ids[] = (int) $item['id'];
            }
        }
        sort($ids);

        return $ids;
    }

    // =================================================================
    // A. admin == assistant_admin parity
    // =================================================================

    public function test_admin_and_assistant_admin_have_identical_church_wide_reads(): void
    {
        $adminStages = $this->idsOf($this->items($this->withHeaders($this->auth('adminA'))->getJson('/api/v1/stages')));
        $assistantStages = $this->idsOf($this->items($this->withHeaders($this->auth('assistantA'))->getJson('/api/v1/stages')));

        $adminClasses = $this->idsOf($this->items($this->withHeaders($this->auth('adminA'))->getJson('/api/v1/classes')));
        $assistantClasses = $this->idsOf($this->items($this->withHeaders($this->auth('assistantA'))->getJson('/api/v1/classes')));

        // Church-wide: every stage and class of Church A, identical for both.
        $expectedStages = [$this->stages['A1']->id, $this->stages['A2']->id];
        sort($expectedStages);
        $expectedClasses = [
            $this->classes['A11']->id,
            $this->classes['A12']->id,
            $this->classes['A21']->id,
        ];
        sort($expectedClasses);

        $this->assertSame($expectedStages, $adminStages);
        $this->assertSame($expectedStages, $assistantStages);
        $this->assertSame($expectedClasses, $adminClasses);
        $this->assertSame($expectedClasses, $assistantClasses);

        $adminStats = $this->withHeaders($this->auth('adminA'))
            ->getJson('/api/v1/dashboard/stats')
            ->assertStatus(200)
            ->json('data');
        $assistantStats = $this->withHeaders($this->auth('assistantA'))
            ->getJson('/api/v1/dashboard/stats')
            ->assertStatus(200)
            ->json('data');

        // 3 members church-wide (memberA11, memberA12, memberA21).
        $this->assertSame(3, $adminStats['total_members']);
        $this->assertSame($adminStats, $assistantStats);
    }

    public function test_permission_matrix_is_identical_for_admin_and_assistant_admin(): void
    {
        foreach (['manage_users', 'view_users', 'view_points'] as $key) {
            $adminHas = Permission::userHasPermission($this->users['adminA'], $key);
            $assistantHas = Permission::userHasPermission($this->users['assistantA'], $key);

            $this->assertTrue($adminHas, "admin must hold {$key}");
            $this->assertSame($adminHas, $assistantHas, "admin and assistant_admin must agree on {$key}");
        }
    }

    // =================================================================
    // B. stage_admin ALLOW — own stage only
    // =================================================================

    public function test_stage_admin_reads_are_limited_to_own_stage(): void
    {
        $stageIds = $this->idsOf($this->items($this->withHeaders($this->auth('stageAdminA'))->getJson('/api/v1/stages')));
        $this->assertSame([$this->stages['A1']->id], $stageIds);

        $classIds = $this->idsOf($this->items($this->withHeaders($this->auth('stageAdminA'))->getJson('/api/v1/classes')));
        $this->assertSame([$this->classes['A11']->id, $this->classes['A12']->id], array_values(array_unique($classIds)));

        // Users list: members of the own stage present, other stage absent.
        $emails = collect($this->items($this->withHeaders($this->auth('stageAdminA'))->getJson('/api/v1/users')))
            ->pluck('email')
            ->toArray();
        $this->assertContains($this->users['memberA11']->email, $emails);
        $this->assertContains($this->users['servantA11']->email, $emails);
        $this->assertNotContains($this->users['memberA21']->email, $emails);
        $this->assertNotContains($this->users['memberB11']->email, $emails);

        // Dashboard: stage-scoped aggregate (members of A1 classes only).
        $stats = $this->withHeaders($this->auth('stageAdminA'))
            ->getJson('/api/v1/dashboard/stats')
            ->assertStatus(200)
            ->json('data');
        $this->assertSame(2, $stats['total_members']);
    }

    public function test_stage_admin_dashboard_cache_is_not_shared_with_church_admin(): void
    {
        // Cache order 1: stage principal first, then church principal, then
        // stage principal again — neither direction may poison the other.
        $stageFirst = $this->withHeaders($this->auth('stageAdminA'))
            ->getJson('/api/v1/dashboard/stats')
            ->assertStatus(200)
            ->json('data');
        $churchSecond = $this->withHeaders($this->auth('adminA'))
            ->getJson('/api/v1/dashboard/stats')
            ->assertStatus(200)
            ->json('data');
        $stageThird = $this->withHeaders($this->auth('stageAdminA'))
            ->getJson('/api/v1/dashboard/stats')
            ->assertStatus(200)
            ->json('data');

        $this->assertSame(2, $stageFirst['total_members']);
        $this->assertSame(3, $churchSecond['total_members'], 'church admin must not read the stage-scoped cache entry');
        $this->assertSame($stageFirst, $stageThird, 'stage admin must not read the church-scoped cache entry');

        // Cache order 2: church principal already cached, then a servant.
        $churchAgain = $this->withHeaders($this->auth('adminA'))
            ->getJson('/api/v1/dashboard/stats')
            ->assertStatus(200)
            ->json('data');
        $servantScoped = $this->withHeaders($this->auth('servantA11'))
            ->getJson('/api/v1/dashboard/stats')
            ->assertStatus(200)
            ->json('data');

        $this->assertSame(3, $churchAgain['total_members']);
        $this->assertSame(1, $servantScoped['total_members'], 'servant dashboard must be narrowed to own class');
    }

    public function test_stage_admin_leaderboards_are_stage_scoped(): void
    {
        // Stages leaderboard: only the assigned stage.
        $stagesData = $this->items($this->withHeaders($this->auth('stageAdminA'))->getJson('/api/v1/leaderboard/stages'));
        $stageIds = array_values(array_map(static fn (array $entry): int => (int) $entry['stage_id'], $stagesData));
        $this->assertSame([$this->stages['A1']->id], $stageIds);

        // Global leaderboard (stage principal first — cache-order sensitive).
        $stageEntries = $this->items($this->withHeaders($this->auth('stageAdminA'))->getJson('/api/v1/leaderboard/global'));
        $stageUserIds = array_values(array_map(static fn (array $entry): int => (int) $entry['user_id'], $stageEntries));
        sort($stageUserIds);
        $expected = [(int) $this->users['memberA11']->id, (int) $this->users['memberA12']->id];
        sort($expected);
        $this->assertSame($expected, $stageUserIds);

        // Church admin afterwards must still see the church-wide ranking.
        $churchEntries = $this->items($this->withHeaders($this->auth('adminA'))->getJson('/api/v1/leaderboard/global'));
        $churchUserIds = array_map(static fn (array $entry): int => (int) $entry['user_id'], $churchEntries);
        $this->assertContains((int) $this->users['memberA21']->id, $churchUserIds);

        $churchStages = $this->items($this->withHeaders($this->auth('adminA'))->getJson('/api/v1/leaderboard/stages'));
        $churchStageIds = array_values(array_map(static fn (array $entry): int => (int) $entry['stage_id'], $churchStages));
        sort($churchStageIds);
        $expectedStages = [$this->stages['A1']->id, $this->stages['A2']->id];
        sort($expectedStages);
        $this->assertSame($expectedStages, $churchStageIds);

        // And the stage principal, re-read after the church read, stays scoped.
        $stageAgain = $this->items($this->withHeaders($this->auth('stageAdminA'))->getJson('/api/v1/leaderboard/global'));
        $againIds = array_values(array_map(static fn (array $entry): int => (int) $entry['user_id'], $stageAgain));
        sort($againIds);
        $this->assertSame($expected, $againIds);
    }

    public function test_stage_admin_attendance_context_access_is_read_only(): void
    {
        // viewAny: allowed.
        $this->withHeaders($this->auth('stageAdminA'))
            ->getJson('/api/v1/attendance-contexts/manage')
            ->assertStatus(200);

        // create: denied, nothing written.
        $before = AttendanceContext::query()->count();
        $this->withHeaders($this->auth('stageAdminA'))
            ->postJson('/api/v1/attendance-contexts', [
                'name' => 'Stage Admin Context',
            ])->assertStatus(403);
        $this->assertSame($before, AttendanceContext::query()->count());

        // update: denied, unchanged.
        $this->withHeaders($this->auth('stageAdminA'))
            ->putJson('/api/v1/attendance-contexts/'.$this->context->id, [
                'name' => 'Hijacked Context',
            ])->assertStatus(403);
        $this->assertDatabaseHas('attendance_contexts', [
            'id' => $this->context->id,
            'name' => $this->context->name,
        ]);

        // delete: denied, still present.
        $this->withHeaders($this->auth('stageAdminA'))
            ->deleteJson('/api/v1/attendance-contexts/'.$this->context->id)
            ->assertStatus(403);
        $this->assertDatabaseHas('attendance_contexts', ['id' => $this->context->id]);
    }

    public function test_stage_admin_spiritual_records_follow_stage_scope(): void
    {
        $this->withHeaders($this->auth('stageAdminA'))
            ->getJson('/api/v1/spiritual-records?member_id='.$this->users['memberA11']->id)
            ->assertStatus(200);

        $this->withHeaders($this->auth('stageAdminA'))
            ->getJson('/api/v1/spiritual-records?member_id='.$this->users['memberA21']->id)
            ->assertStatus(403);

        $this->withHeaders($this->auth('stageAdminA'))
            ->getJson('/api/v1/spiritual-records?member_id='.$this->users['memberB11']->id)
            ->assertStatus(404);
    }

    // =================================================================
    // C. stage_admin DENY — IDOR, cross-church, scope spoofing
    // =================================================================

    public function test_stage_admin_cannot_read_other_stage_resources(): void
    {
        $stageAdmin = $this->withHeaders($this->auth('stageAdminA'));

        $stageAdmin->getJson('/api/v1/stages/'.$this->stages['A2']->id)->assertStatus(403);
        $stageAdmin->getJson('/api/v1/stages/'.$this->stages['A2']->id.'/classes')->assertStatus(403);
        $stageAdmin->getJson('/api/v1/classes/'.$this->classes['A21']->id)->assertStatus(403);
        $stageAdmin->getJson('/api/v1/classes/'.$this->classes['A21']->id.'/detail')->assertStatus(403);
        $stageAdmin->getJson('/api/v1/users/'.$this->users['stageAdminA2']->id)->assertStatus(403);
        $stageAdmin->getJson('/api/v1/users/'.$this->users['memberA21']->id)->assertStatus(403);
        $stageAdmin->getJson('/api/v1/member-profile/'.$this->users['memberA21']->id)->assertStatus(404);

        // Event analytics of another stage's event are blocked by scope.
        $stageAdmin->getJson('/api/v1/events/'.$this->eventA21->id.'/analytics/summary')->assertStatus(403);
    }

    public function test_stage_admin_cannot_write_other_stage_resources(): void
    {
        $stageAdmin = $this->withHeaders($this->auth('stageAdminA'));

        // Class rename in another stage: denied, unchanged.
        $stageAdmin->putJson('/api/v1/classes/'.$this->classes['A21']->id, [
            'name' => 'Hijacked Class',
        ])->assertStatus(403);
        $this->assertDatabaseHas('classes', [
            'id' => $this->classes['A21']->id,
            'name' => 'Class A21',
        ]);

        // User update in another stage: denied, unchanged.
        $stageAdmin->putJson('/api/v1/users/'.$this->users['memberA21']->id, [
            'name' => 'Hijacked Member',
        ])->assertStatus(403);
        $this->assertDatabaseHas('users', [
            'id' => $this->users['memberA21']->id,
            'name' => $this->users['memberA21']->name,
        ]);
    }

    public function test_stage_admin_cannot_access_other_church_resources(): void
    {
        $stageAdmin = $this->withHeaders($this->auth('stageAdminA'));

        $stageAdmin->getJson('/api/v1/stages/'.$this->stages['B1']->id)->assertStatus(404);
        $stageAdmin->getJson('/api/v1/classes/'.$this->classes['B11']->id)->assertStatus(404);
        $stageAdmin->getJson('/api/v1/users/'.$this->users['adminB']->id)->assertStatus(404);
        // /member-profile refuses a foreign member with the same uniform 404
        // used for a missing id (F-04) — no Church B data is returned and the
        // response no longer confirms the id exists.
        $foreignProfile = $stageAdmin->getJson('/api/v1/member-profile/'.$this->users['memberB11']->id);
        $foreignProfile->assertStatus(404);
        $this->assertNull($foreignProfile->json('data'));
        // A foreign event resolves exactly like a missing one: the scoped
        // lookup fails validation ("Event not found") and no Church B data
        // is returned — no existence oracle.
        $foreignEvent = $stageAdmin->getJson('/api/v1/events/'.$this->eventB11->id.'/analytics/summary');
        $foreignEvent->assertStatus(422);
        $this->assertSame('Event not found.', $foreignEvent->json('errors.event.0'));

        $stageAdmin->putJson('/api/v1/users/'.$this->users['memberB11']->id, [
            'name' => 'Cross Church Hijack',
        ])->assertStatus(404);
        $this->assertDatabaseHas('users', [
            'id' => $this->users['memberB11']->id,
            'name' => $this->users['memberB11']->name,
        ]);
    }

    public function test_client_supplied_scope_identifiers_cannot_widen_stage_admin(): void
    {
        $stageAdmin = $this->withHeaders($this->auth('stageAdminA'));

        // Creating a member inside another stage: denied, nothing written.
        $stageAdmin->postJson('/api/v1/users', [
            'name' => 'Spoofed Member',
            'email' => 'spoofed.matrix@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => UserRole::Member->value,
            'stage_id' => $this->stages['A2']->id,
            'class_id' => $this->classes['A21']->id,
        ])->assertStatus(403);
        $this->assertDatabaseMissing('users', ['email' => 'spoofed.matrix@test.com']);

        // Moving an own-stage member into another stage: denied, unchanged.
        $stageAdmin->putJson('/api/v1/users/'.$this->users['memberA11']->id, [
            'stage_id' => $this->stages['A2']->id,
            'class_id' => $this->classes['A21']->id,
        ])->assertStatus(403);
        $this->assertDatabaseHas('users', [
            'id' => $this->users['memberA11']->id,
            'class_id' => $this->classes['A11']->id,
        ]);

        // Attendance context summary: a foreign class_id must be ignored
        // (the stage principal is always narrowed to its own classes) and a
        // foreign-church class must be rejected outright.
        $narrowed = $stageAdmin->getJson('/api/v1/attendances/context-summary?class_id='.$this->classes['A21']->id)
            ->assertStatus(200)
            ->json();
        $own = $stageAdmin->getJson('/api/v1/attendances/context-summary?class_id='.$this->classes['A11']->id)
            ->assertStatus(200)
            ->json();
        $this->assertSame($own, $narrowed, 'stage admin must be narrowed to own classes regardless of class_id');

        // Event analytics cannot be re-targeted at another stage by id.
        $stageAdmin->getJson('/api/v1/events/'.$this->eventA21->id.'/analytics/viewed')->assertStatus(403);
    }

    // =================================================================
    // D. servant class isolation
    // =================================================================

    public function test_servant_is_isolated_to_own_class(): void
    {
        // Dashboard: only own class members.
        $stats = $this->withHeaders($this->auth('servantA11'))
            ->getJson('/api/v1/dashboard/stats')
            ->assertStatus(200)
            ->json('data');
        $this->assertSame(1, $stats['total_members']);

        // A sibling class_id on the context summary is ignored (narrowed).
        $sibling = $this->withHeaders($this->auth('servantA11'))
            ->getJson('/api/v1/attendances/context-summary?class_id='.$this->classes['A12']->id)
            ->assertStatus(200)
            ->json();
        $own = $this->withHeaders($this->auth('servantA11'))
            ->getJson('/api/v1/attendances/context-summary?class_id='.$this->classes['A11']->id)
            ->assertStatus(200)
            ->json();
        $this->assertSame($own, $sibling, 'a servant must never read a sibling class summary');

        // Another stage's class_id is likewise ignored (narrowed to own).
        $foreignStage = $this->withHeaders($this->auth('servantA11'))
            ->getJson('/api/v1/attendances/context-summary?class_id='.$this->classes['A21']->id)
            ->assertStatus(200)
            ->json();
        $this->assertSame($own, $foreignStage, 'a servant must never read another stage class summary');

        // Context details: another servant in the same stage (other class)
        // is out of scope.
        $this->withHeaders($this->auth('servantA11'))
            ->getJson('/api/v1/attendances/context-details?context_id='.$this->context->id.'&servant_id='.$this->users['servantA12']->id)
            ->assertStatus(403);

        // Own servant id is allowed (self).
        $this->withHeaders($this->auth('servantA11'))
            ->getJson('/api/v1/attendances/context-details?context_id='.$this->context->id.'&servant_id='.$this->users['servantA11']->id)
            ->assertStatus(200);

        // User management listing is not a servant capability at all
        // (manage_users permission) — the request never reaches a query.
        $this->withHeaders($this->auth('servantA11'))
            ->getJson('/api/v1/users')
            ->assertStatus(403);
    }

    // =================================================================
    // E. fail-closed principals
    // =================================================================

    public function test_stage_admin_without_assigned_stage_fails_closed(): void
    {
        $noStage = $this->users['stageAdminNoStage'];

        // Dashboard: zeros, never church-wide aggregates.
        $stats = $this->withHeaders($this->auth('stageAdminNoStage'))
            ->getJson('/api/v1/dashboard/stats')
            ->assertStatus(200)
            ->json('data');
        $this->assertSame(0, $stats['total_members']);
        $this->assertSame(0, $stats['total_servants']);
        $this->assertSame(0, $stats['total_attendances']);
        $this->assertSame(0, $stats['total_points']);

        // Stage/class listings: empty, never the church structure.
        $this->assertSame([], $this->idsOf($this->items($this->withHeaders($this->auth('stageAdminNoStage'))->getJson('/api/v1/stages'))));
        $this->assertSame([], $this->idsOf($this->items($this->withHeaders($this->auth('stageAdminNoStage'))->getJson('/api/v1/classes'))));

        // Leaderboards: empty, never church-wide rankings.
        $this->assertSame([], $this->items($this->withHeaders($this->auth('stageAdminNoStage'))->getJson('/api/v1/leaderboard/global')));
        $this->assertSame([], $this->items($this->withHeaders($this->auth('stageAdminNoStage'))->getJson('/api/v1/leaderboard/stages')));

        // Users list: no other-stage or cross-church users.
        $emails = collect($this->items($this->withHeaders($this->auth('stageAdminNoStage'))->getJson('/api/v1/users')))
            ->pluck('email')
            ->toArray();
        $this->assertNotContains($this->users['memberA11']->email, $emails);
        $this->assertNotContains($this->users['memberA21']->email, $emails);
        $this->assertNotContains($this->users['memberB11']->email, $emails);

        // Stage-scoped IDOR still denied for this principal (uniform 404, F-04).
        $this->withHeaders($this->auth('stageAdminNoStage'))
            ->getJson('/api/v1/member-profile/'.$this->users['memberA11']->id)
            ->assertStatus(404);

        $this->assertNotNull($noStage);
    }
}
