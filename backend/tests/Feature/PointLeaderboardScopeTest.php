<?php

namespace Tests\Feature;

use App\Enums\PointType;
use App\Enums\UserRole;
use App\Enums\UserScope;
use App\Models\Church;
use App\Models\Classe;
use App\Models\Point;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * F-02 / F-07 — GET /api/v1/points/leaderboard must be scoped to what the
 * caller may actually see, must not expose member email addresses, and must
 * bound the requested row count server-side.
 *
 * Before the fix a member (UserScope::Self) matched no branch of the
 * controller's scope switch, so `classIds` stayed null and the member received
 * a CHURCH-WIDE ranking that also contained every member's email address.
 */
class PointLeaderboardScopeTest extends TestCase
{
    use RefreshDatabase;

    private Church $church;

    private Church $otherChurch;

    private Stage $stage;

    private Stage $otherStage;

    private Classe $classOne;

    private Classe $classTwo;

    private Classe $classOtherStage;

    private User $memberOne;

    private User $memberTwo;

    private User $memberOtherStage;

    private User $foreignMember;

    protected function setUp(): void
    {
        parent::setUp();

        $this->church = Church::factory()->create();
        $this->otherChurch = Church::factory()->create();

        $this->stage = Stage::factory()->forChurch($this->church)->create();
        $this->otherStage = Stage::factory()->forChurch($this->church)->create();

        $this->classOne = Classe::factory()->forChurch($this->church)->state(['stage_id' => $this->stage->id])->create();
        $this->classTwo = Classe::factory()->forChurch($this->church)->state(['stage_id' => $this->stage->id])->create();
        $this->classOtherStage = Classe::factory()->forChurch($this->church)->state(['stage_id' => $this->otherStage->id])->create();

        $this->memberOne = $this->makeMember($this->classOne, $this->stage, 30);
        $this->memberTwo = $this->makeMember($this->classTwo, $this->stage, 20);
        $this->memberOtherStage = $this->makeMember($this->classOtherStage, $this->otherStage, 10);

        // A member of another church with the highest score: it must never
        // appear in any ranking returned to a principal of $this->church.
        $foreignStage = Stage::factory()->forChurch($this->otherChurch)->create();
        $foreignClass = Classe::factory()->forChurch($this->otherChurch)->state(['stage_id' => $foreignStage->id])->create();
        $this->foreignMember = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $this->otherChurch->id,
            'stage_id' => $foreignStage->id,
            'class_id' => $foreignClass->id,
            'scope' => UserScope::Self->value,
            'application_status' => 'approved',
        ]);
        $this->award($this->foreignMember, 999);
    }

    private function makeMember(Classe $classe, Stage $stage, int $points): User
    {
        $member = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $this->church->id,
            'stage_id' => $stage->id,
            'class_id' => $classe->id,
            'scope' => UserScope::Self->value,
            'application_status' => 'approved',
        ]);

        $this->award($member, $points);

        return $member;
    }

    private function award(User $member, int $points): void
    {
        Point::create([
            'user_id' => $member->id,
            'points' => $points,
            'type' => PointType::Attendance->value,
            'church_id' => $member->church_id,
            'description' => 'leaderboard scope test',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function staffUser(UserRole $role, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role,
            'church_id' => $this->church->id,
            'application_status' => 'approved',
            'is_active' => true,
        ], $attributes));
    }

    /**
     * Authenticate as $actor for a single request and read the leaderboard.
     *
     * `Sanctum::actingAs()` has to be re-applied before every request because
     * the project's TestCase deliberately calls `Auth::forgetGuards()` after
     * each simulated request (see Tests\TestCase::call()).
     *
     * @param  array<string, mixed>  $headers
     */
    private function fetchAs(User $actor, string $query = '', array $headers = []): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->getJson('/api/v1/points/leaderboard'.$query, $headers);
    }

    /**
     * @return array<int, int>
     */
    private function userIds(TestResponse $response): array
    {
        $response->assertOk();

        /** @var array<int, array{user_id: int}> $data */
        $data = $response->json('data');

        return array_values(array_map(static fn (array $row): int => $row['user_id'], $data));
    }

    public function test_member_only_sees_their_own_class(): void
    {
        // Exactly their own class — not the other class of the same stage, not
        // the other stage, not the other church.
        $this->assertEqualsCanonicalizing([$this->memberOne->id], $this->userIds($this->fetchAs($this->memberOne)));
    }

    public function test_member_leaderboard_never_exposes_email(): void
    {
        $response = $this->fetchAs($this->memberOne);

        $response->assertOk();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $response->json('data');

        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('email', $row, 'the leaderboard must not return member email addresses');
            $this->assertSame(['rank', 'user_id', 'name', 'total_points'], array_keys($row));
        }
    }

    public function test_member_without_a_class_gets_an_empty_ranking(): void
    {
        $classlessMember = $this->staffUser(UserRole::Member, ['class_id' => null]);

        $this->assertSame([], $this->userIds($this->fetchAs($classlessMember)));
    }

    public function test_servant_only_sees_their_assigned_class(): void
    {
        $servant = $this->staffUser(UserRole::Servant, [
            'stage_id' => $this->stage->id,
            'class_id' => $this->classOne->id,
        ]);

        $this->assertEqualsCanonicalizing([$this->memberOne->id], $this->userIds($this->fetchAs($servant)));
    }

    public function test_a_legacy_class_year_id_does_not_widen_the_ranking(): void
    {
        // `users.class_year_id` is the one remaining `class_year_id` still
        // foreign-keyed to the DEPRECATED `class_years` table (see EventPolicy
        // and 2026_06_18_000002), so it holds ids from a different sequence
        // than `classes.id`. A member of another stage whose legacy id happens
        // to equal an allowed class id must never be pulled into the ranking.
        DB::table('class_years')->insert([
            'id' => $this->classOne->id,
            'name' => 'Legacy year colliding with class '.$this->classOne->id,
            'year' => '2026',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $colliding = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $this->church->id,
            'stage_id' => $this->otherStage->id,
            'class_id' => $this->classOtherStage->id,
            'class_year_id' => $this->classOne->id,
            'scope' => UserScope::Self->value,
            'application_status' => 'approved',
        ]);
        $this->award($colliding, 500);

        $servantIds = $this->userIds($this->fetchAs($this->staffUser(UserRole::Servant, [
            'stage_id' => $this->stage->id,
            'class_id' => $this->classOne->id,
        ])));

        $this->assertContains($this->memberOne->id, $servantIds);
        $this->assertNotContains(
            $colliding->id,
            $servantIds,
            'A legacy class_years id must not widen a class-scoped ranking.'
        );

        // The same must hold for a member reading their own class.
        $this->assertSame(
            [$this->memberOne->id],
            $this->userIds($this->fetchAs($this->memberOne))
        );
    }

    public function test_stage_admin_only_sees_their_own_stage(): void
    {
        $stageAdmin = $this->staffUser(UserRole::StageAdmin, [
            'stage_id' => $this->stage->id,
            'scope' => UserScope::Stage->value,
        ]);

        $this->assertEqualsCanonicalizing(
            [$this->memberOne->id, $this->memberTwo->id],
            $this->userIds($this->fetchAs($stageAdmin))
        );
    }

    public function test_stage_admin_without_a_stage_fails_closed(): void
    {
        $stageAdmin = $this->staffUser(UserRole::StageAdmin, [
            'stage_id' => null,
            'scope' => UserScope::Stage->value,
        ]);

        $this->assertSame([], $this->userIds($this->fetchAs($stageAdmin)));
    }

    public function test_church_admin_sees_the_whole_church_but_no_other_church(): void
    {
        $ids = $this->userIds($this->fetchAs($this->staffUser(UserRole::Admin)));

        $this->assertEqualsCanonicalizing(
            [$this->memberOne->id, $this->memberTwo->id, $this->memberOtherStage->id],
            $ids
        );
        $this->assertNotContains($this->foreignMember->id, $ids);
    }

    public function test_assistant_admin_sees_exactly_what_an_admin_sees(): void
    {
        $adminIds = $this->userIds($this->fetchAs($this->staffUser(UserRole::Admin)));
        $assistantIds = $this->userIds($this->fetchAs($this->staffUser(UserRole::AssistantAdmin)));

        $this->assertEqualsCanonicalizing($adminIds, $assistantIds);
        $this->assertNotEmpty($adminIds);
    }

    public function test_limit_is_normalized_and_clamped_server_side(): void
    {
        $admin = $this->staffUser(UserRole::Admin);

        // 105 ranked members so the bounds are actually observable.
        for ($i = 0; $i < 105; $i++) {
            $member = User::factory()->create([
                'role' => UserRole::Member,
                'church_id' => $this->church->id,
                'application_status' => 'approved',
            ]);
            $this->award($member, 1);
        }

        // Default (no limit parameter at all).
        $this->assertCount(10, $this->userIds($this->fetchAs($admin)));

        // A valid explicit limit is honoured.
        $this->assertCount(5, $this->userIds($this->fetchAs($admin, '?limit=5')));

        // Missing / zero / negative / non-numeric fall back to the default.
        $this->assertCount(10, $this->userIds($this->fetchAs($admin, '?limit=0')));
        $this->assertCount(10, $this->userIds($this->fetchAs($admin, '?limit=-3')));
        $this->assertCount(10, $this->userIds($this->fetchAs($admin, '?limit=abc')));

        // An excessive limit is clamped to the documented maximum, never
        // rejected and never unbounded.
        $this->assertCount(100, $this->userIds($this->fetchAs($admin, '?limit=999')));
        $this->assertCount(100, $this->userIds($this->fetchAs($admin, '?limit=100000')));
    }
}
