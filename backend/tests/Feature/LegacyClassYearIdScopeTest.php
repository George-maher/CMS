<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserScope;
use App\Models\Church;
use App\Models\Classe;
use App\Models\Event;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * OBS-1 — `users.class_year_id` must never participate in an authorization
 * scope.
 *
 * `users.class_year_id` is the one remaining `class_year_id` still
 * foreign-keyed to the deprecated `class_years` table; every other one was
 * repointed at `classes` by 2026_06_18_000002 (see
 * EventPolicy::eventTargetsServantsClass(), which documents the same trap for
 * events). `class_years` and `classes` are separate tables with independent id
 * sequences, so comparing `users.class_year_id` against a list of
 * `classes.id` values compares two unrelated id spaces: a user in another
 * class/stage whose legacy id happens to collide with an allowed class id is
 * pulled into the scope.
 *
 * Both affected code paths are covered here:
 *
 *   - UserRepository::paginate()   → GET /api/v1/users
 *   - DashboardController::applyScope() → GET /api/v1/dashboard/stats
 *
 * Data investigation (see the remediation report): every local database has
 * zero rows with a non-null `users.class_year_id`, so no legitimate legacy
 * compatibility depends on the column. The scope is therefore `class_id`
 * only — the same column ScopeResolver::canAccessUser() authorizes against.
 */
class LegacyClassYearIdScopeTest extends TestCase
{
    use RefreshDatabase;

    private Church $church;

    private Stage $stageOne;

    private Stage $stageTwo;

    private Classe $classOne;

    private Classe $classTwo;

    private User $stageAdmin;

    private User $memberOne;

    private User $memberTwo;

    /** The user whose legacy id collides with an allowed class id. */
    private User $collidingMember;

    protected function setUp(): void
    {
        parent::setUp();

        $this->church = Church::factory()->create();

        $this->stageOne = Stage::factory()->forChurch($this->church)->create(['name' => 'Secondary']);
        $this->stageTwo = Stage::factory()->forChurch($this->church)->create(['name' => 'Preparatory']);

        $this->classOne = Classe::factory()->forChurch($this->church)
            ->state(['stage_id' => $this->stageOne->id])->create(['name' => 'S1']);
        $this->classTwo = Classe::factory()->forChurch($this->church)
            ->state(['stage_id' => $this->stageTwo->id])->create(['name' => 'P1']);

        $this->stageAdmin = $this->makeUser(UserRole::StageAdmin, [
            'stage_id' => $this->stageOne->id,
            'scope' => UserScope::Stage->value,
        ]);

        $this->memberOne = $this->makeUser(UserRole::Member, [
            'stage_id' => $this->stageOne->id,
            'class_id' => $this->classOne->id,
            'scope' => UserScope::Self->value,
        ]);

        $this->memberTwo = $this->makeUser(UserRole::Member, [
            'stage_id' => $this->stageTwo->id,
            'class_id' => $this->classTwo->id,
            'scope' => UserScope::Self->value,
        ]);

        // A deprecated `class_years` row whose id is exactly the allowed
        // `classes.id` — the collision that made the old clause fail open.
        DB::table('class_years')->insert([
            'id' => $this->classOne->id,
            'name' => 'Legacy year colliding with class '.$this->classOne->id,
            'year' => '2026',
            'church_id' => $this->church->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Member of the OTHER stage, but carrying the colliding legacy id.
        $this->collidingMember = $this->makeUser(UserRole::Member, [
            'stage_id' => $this->stageTwo->id,
            'class_id' => $this->classTwo->id,
            'class_year_id' => $this->classOne->id,
            'scope' => UserScope::Self->value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeUser(UserRole $role, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role,
            'church_id' => $this->church->id,
            'application_status' => 'approved',
            'is_active' => true,
        ], $attributes));
    }

    /**
     * @return array<int, string>
     */
    private function directoryEmails(User $actor): array
    {
        $response = $this->actingAs($actor)->getJson('/api/v1/users?per_page=100');
        $response->assertOk();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $response->json('data') ?? [];

        return array_column($rows, 'email');
    }

    // ---------------------------------------------------------------------
    // GET /users — class-scoped directory
    // ---------------------------------------------------------------------

    public function test_a_colliding_legacy_id_does_not_widen_the_user_directory(): void
    {
        $emails = $this->directoryEmails($this->stageAdmin);

        // Positive control: the caller's own stage is still visible.
        $this->assertContains($this->memberOne->email, $emails);
        $this->assertContains($this->stageAdmin->email, $emails);

        // The colliding user belongs to another stage and must stay invisible.
        $this->assertNotContains(
            $this->collidingMember->email,
            $emails,
            'A legacy class_years id must not pull a foreign-stage user into a class-scoped directory.'
        );
        $this->assertNotContains($this->memberTwo->email, $emails);
    }

    public function test_stage_scoped_classless_users_are_included_by_stage_not_by_legacy_id(): void
    {
        // Documented, intended behaviour: a stage admin also sees users scoped
        // directly to their stage whose `class_id` is null (e.g. pivot-assigned
        // servants). That inclusion comes from `stage_id` — never from the
        // legacy column.
        $ownStageClassless = $this->makeUser(UserRole::Servant, [
            'stage_id' => $this->stageOne->id,
            'class_id' => null,
            'class_year_id' => $this->classOne->id,
            'scope' => UserScope::ClassScope->value,
        ]);

        // Same shape, other stage, same colliding legacy id: must stay out.
        $foreignStageClassless = $this->makeUser(UserRole::Servant, [
            'stage_id' => $this->stageTwo->id,
            'class_id' => null,
            'class_year_id' => $this->classOne->id,
            'scope' => UserScope::ClassScope->value,
        ]);

        $emails = $this->directoryEmails($this->stageAdmin);

        $this->assertContains($ownStageClassless->email, $emails, 'A stage-scoped classless user must remain visible (positive control).');
        $this->assertNotContains($foreignStageClassless->email, $emails, 'A legacy class_years id must not grant cross-stage visibility.');
    }

    public function test_an_unresolved_scope_fails_closed(): void
    {
        // A stage admin with no stage assigned resolves to an empty class list
        // and no stage scope: the directory must match nothing rather than fall
        // back to the church-wide list.
        $stageAdminWithoutStage = $this->makeUser(UserRole::StageAdmin, [
            'stage_id' => null,
            'scope' => UserScope::Stage->value,
        ]);

        $this->assertSame(
            [],
            $this->directoryEmails($stageAdminWithoutStage),
            'An empty authorization scope must match nothing, not fall back to the church directory.'
        );
    }

    public function test_legitimate_rows_keep_working_for_the_church_admin(): void
    {
        // The fix narrows a scoped principal; it must not hide data from the
        // church-wide admin (positive control for the same rows).
        $admin = $this->makeUser(UserRole::Admin);

        $emails = $this->directoryEmails($admin);

        $this->assertContains($this->memberOne->email, $emails);
        $this->assertContains($this->memberTwo->email, $emails);
        $this->assertContains($this->collidingMember->email, $emails);
    }

    // ---------------------------------------------------------------------
    // GET /dashboard/stats — scoped aggregates
    // ---------------------------------------------------------------------

    public function test_dashboard_aggregates_are_not_widened_by_a_colliding_legacy_id(): void
    {
        $stats = $this->actingAs($this->stageAdmin)
            ->getJson('/api/v1/dashboard/stats')
            ->assertOk()
            ->json('data');

        // Stage one holds exactly two principals: the stage admin and one
        // member. The colliding member lives in stage two and must not be
        // counted, even though their legacy id equals an allowed class id.
        $this->assertSame(1, $stats['total_members'], 'A legacy class_years id widened the member aggregate.');
        $this->assertSame(0, $stats['total_servants'], 'A legacy class_years id widened the servant aggregate.');
    }

    public function test_dashboard_aggregates_keep_the_stage_boundary(): void
    {
        $stats = $this->actingAs($this->stageAdmin)
            ->getJson('/api/v1/dashboard/stats')
            ->assertOk()
            ->json('data');

        // Church-wide the admin still sees all three members (positive control
        // that the rows exist and only the scoped principal is narrowed).
        $adminStats = $this->actingAs($this->makeUser(UserRole::Admin))
            ->getJson('/api/v1/dashboard/stats')
            ->assertOk()
            ->json('data');

        $this->assertSame(3, $adminStats['total_members']);
        $this->assertSame(1, $stats['total_members']);
    }

    public function test_dashboard_fails_closed_for_an_unresolved_scope(): void
    {
        // A servant with no class and no stage resolves to an empty scope.
        $orphan = $this->makeUser(UserRole::Servant, [
            'stage_id' => null,
            'class_id' => null,
            'scope' => UserScope::ClassScope->value,
        ]);

        $stats = $this->actingAs($orphan)
            ->getJson('/api/v1/dashboard/stats')
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $stats['total_members']);
        $this->assertSame(0, $stats['total_servants']);
        $this->assertSame(0, $stats['total_attendances']);
    }

    // ---------------------------------------------------------------------
    // GET /users/members — a servant's own member list
    // ---------------------------------------------------------------------

    public function test_servant_member_list_is_not_widened_by_a_colliding_legacy_id(): void
    {
        // No pivot rows, so UserRepository::findMembersByServant() falls back
        // to the servant's own class assignment.
        $servant = $this->makeUser(UserRole::Servant, [
            'stage_id' => $this->stageOne->id,
            'class_id' => $this->classOne->id,
            'scope' => UserScope::ClassScope->value,
        ]);

        $ids = $this->actingAs($servant)
            ->getJson('/api/v1/users/members')
            ->assertOk()
            ->json('data');

        /** @var array<int, int> $memberIds */
        $memberIds = array_column($ids, 'id');

        $this->assertContains($this->memberOne->id, $memberIds, 'The servant must still see their own class.');
        $this->assertNotContains(
            $this->collidingMember->id,
            $memberIds,
            'A legacy class_years id must not add a foreign-class member to a servant\'s list.'
        );
        $this->assertNotContains($this->memberTwo->id, $memberIds);
    }

    // ---------------------------------------------------------------------
    // GET /attendances/absent-members — class-scoped member projection
    // ---------------------------------------------------------------------

    public function test_absent_member_list_is_not_widened_by_a_colliding_legacy_id(): void
    {
        $servant = $this->makeUser(UserRole::Servant, [
            'stage_id' => $this->stageOne->id,
            'class_id' => $this->classOne->id,
            'scope' => UserScope::ClassScope->value,
        ]);

        $response = $this->actingAs($servant)
            ->getJson('/api/v1/attendances/absent-members?class_id='.$this->classOne->id)
            ->assertOk();

        /** @var array<int, array<string, mixed>> $absent */
        $absent = $response->json('data.absent_members') ?? [];

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $absent);

        $this->assertContains($this->memberOne->id, $ids, 'The class member must still be listed as absent.');
        $this->assertNotContains(
            $this->collidingMember->id,
            $ids,
            'A legacy class_years id must not leak a foreign-class member into absent-members.'
        );
        $this->assertNotContains($this->memberTwo->id, $ids);
    }

    // ---------------------------------------------------------------------
    // GET /events/{id} — servant class targeting
    // ---------------------------------------------------------------------

    public function test_event_access_compares_the_servants_class_not_the_legacy_id(): void
    {
        // The servant's legacy column points at a `class_years` row whose id
        // equals classTwo.id — the collision that used to decide access.
        DB::table('class_years')->insert([
            'id' => $this->classTwo->id,
            'name' => 'Legacy year colliding with class '.$this->classTwo->id,
            'year' => '2026',
            'church_id' => $this->church->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $servant = $this->makeUser(UserRole::Servant, [
            'stage_id' => $this->stageOne->id,
            'class_id' => $this->classOne->id,
            'class_year_id' => $this->classTwo->id,
            'scope' => UserScope::ClassScope->value,
        ]);

        $ownClassEvent = Event::factory()->create([
            'church_id' => $this->church->id,
            'class_year_id' => $this->classOne->id,
            'is_all_classes' => false,
            'created_by' => $this->stageAdmin->id,
        ]);

        $otherClassEvent = Event::factory()->create([
            'church_id' => $this->church->id,
            'class_year_id' => $this->classTwo->id,
            'is_all_classes' => false,
            'created_by' => $this->stageAdmin->id,
        ]);

        // Own class: allowed (the legacy column must not deny it).
        $this->actingAs($servant)
            ->getJson('/api/v1/events/'.$ownClassEvent->id)
            ->assertOk();

        // Other class: refused (the legacy column must not grant it).
        $this->actingAs($servant)
            ->getJson('/api/v1/events/'.$otherClassEvent->id)
            ->assertStatus(403);
    }
}
