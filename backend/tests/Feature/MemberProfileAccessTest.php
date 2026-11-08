<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserScope;
use App\Models\Church;
use App\Models\Classe;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pins the access contract of GET /api/v1/member-profile/{id}.
 *
 * The endpoint answers:
 *
 *   - 200 + the profile, when the caller is allowed to read the member;
 *   - 404 + `{"message":"Member not found."}`, in every other case — a missing
 *     id, a member of another church, a member of another stage or class, or
 *     any other existing-but-forbidden target.
 *
 * F-04: the forbidden and the missing case are intentionally indistinguishable.
 * A 403/404 split would confirm that an id exists even though no attribute of
 * the member is returned, which is an existence oracle over the tenant
 * boundary. The authorization decision is unchanged (it is still made by
 * MemberProfileService::canViewProfile()); only the observable response is
 * unified, so this endpoint cannot be used to enumerate members.
 *
 * These tests therefore prove that a denial is byte-identical to a not-found
 * response and never discloses an attribute of the foreign member — for
 * same-church, cross-stage and cross-church callers alike — while authorized
 * callers keep their existing access.
 */
class MemberProfileAccessTest extends TestCase
{
    use RefreshDatabase;

    private const NOT_FOUND_MESSAGE = 'Member not found.';

    private Church $church;

    private Church $otherChurch;

    private Stage $stage;

    private Stage $otherStage;

    private Classe $classOne;

    private Classe $classTwo;

    private User $memberOne;

    private User $memberTwo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->church = Church::factory()->create();
        $this->otherChurch = Church::factory()->create();

        $this->stage = Stage::factory()->forChurch($this->church)->create();
        $this->otherStage = Stage::factory()->forChurch($this->church)->create();

        $this->classOne = Classe::factory()->forChurch($this->church)
            ->state(['stage_id' => $this->stage->id])->create();
        $this->classTwo = Classe::factory()->forChurch($this->church)
            ->state(['stage_id' => $this->stage->id])->create();

        $this->memberOne = $this->member($this->church, $this->classOne, $this->stage);
        $this->memberTwo = $this->member($this->church, $this->classTwo, $this->stage);
    }

    private function member(Church $church, Classe $classe, Stage $stage): User
    {
        return User::factory()->create([
            'name' => 'Member '.Str::random(8),
            'email' => 'member.'.uniqid().'@example.test',
            'phone' => '0100'.random_int(1000000, 9999999),
            'role' => UserRole::Member,
            'church_id' => $church->id,
            'stage_id' => $stage->id,
            'class_id' => $classe->id,
            'scope' => UserScope::Self->value,
            'application_status' => 'approved',
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function actor(UserRole $role, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role,
            'church_id' => $this->church->id,
            'application_status' => 'approved',
            'is_active' => true,
        ], $attributes));
    }

    private function fetchAs(User $actor, int $id): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->getJson('/api/v1/member-profile/'.$id);
    }

    /**
     * A denial must be indistinguishable from a missing row and must not carry
     * a single attribute of the member it refuses.
     */
    private function assertUniformNotFound(TestResponse $response, User $target): void
    {
        $response->assertStatus(404);
        $response->assertJsonPath('message', self::NOT_FOUND_MESSAGE);

        /** @var array<string, mixed> $body */
        $body = $response->json();

        $this->assertSame(['message'], array_keys($body), 'A denial returned more than a constant message.');

        $encoded = (string) $response->getContent();
        $this->assertStringNotContainsString($target->email, $encoded);
        $this->assertStringNotContainsString((string) $target->phone, $encoded);
        $this->assertStringNotContainsString($target->name, $encoded);
    }

    public function test_member_can_view_their_own_profile(): void
    {
        $this->fetchAs($this->memberOne, $this->memberOne->id)
            ->assertOk()
            ->assertJsonPath('data.member.id', $this->memberOne->id);
    }

    public function test_member_cannot_view_another_member_of_the_same_church(): void
    {
        $this->assertUniformNotFound(
            $this->fetchAs($this->memberOne, $this->memberTwo->id),
            $this->memberTwo
        );
    }

    public function test_a_missing_member_answers_404_and_never_403(): void
    {
        $this->fetchAs($this->actor(UserRole::Member), 999999)
            ->assertStatus(404)
            ->assertJsonPath('message', self::NOT_FOUND_MESSAGE);
    }

    public function test_a_forbidden_profile_is_byte_identical_to_a_missing_one(): void
    {
        $actor = $this->actor(UserRole::Member);

        $forbidden = $this->fetchAs($actor, $this->memberTwo->id);
        $missing = $this->fetchAs($actor, 999999);

        $this->assertSame(404, $forbidden->getStatusCode());
        $this->assertSame(404, $missing->getStatusCode());
        $this->assertSame(
            $missing->getContent(),
            $forbidden->getContent(),
            'A forbidden profile must not be distinguishable from a missing one.'
        );
    }

    public function test_church_admin_can_view_a_member_of_their_own_church(): void
    {
        $this->fetchAs($this->actor(UserRole::Admin), $this->memberOne->id)->assertOk();
    }

    public function test_church_admin_cannot_read_a_member_of_another_church(): void
    {
        $foreignStage = Stage::factory()->forChurch($this->otherChurch)->create();
        $foreignClass = Classe::factory()->forChurch($this->otherChurch)
            ->state(['stage_id' => $foreignStage->id])->create();
        $foreignMember = $this->member($this->otherChurch, $foreignClass, $foreignStage);

        $this->assertUniformNotFound(
            $this->fetchAs($this->actor(UserRole::Admin), $foreignMember->id),
            $foreignMember
        );
    }

    public function test_servant_can_view_their_own_class_but_not_another_class(): void
    {
        $servant = $this->actor(UserRole::Servant, [
            'stage_id' => $this->stage->id,
            'class_id' => $this->classOne->id,
        ]);

        $this->fetchAs($servant, $this->memberOne->id)->assertOk();

        $this->assertUniformNotFound(
            $this->fetchAs($servant, $this->memberTwo->id),
            $this->memberTwo
        );
    }

    public function test_stage_admin_can_view_their_own_stage_but_not_another_stage(): void
    {
        $stageAdmin = $this->actor(UserRole::StageAdmin, [
            'stage_id' => $this->stage->id,
            'scope' => UserScope::Stage->value,
        ]);

        $this->fetchAs($stageAdmin, $this->memberOne->id)->assertOk();

        $otherStageClass = Classe::factory()->forChurch($this->church)
            ->state(['stage_id' => $this->otherStage->id])->create();
        $otherStageMember = $this->member($this->church, $otherStageClass, $this->otherStage);

        $this->assertUniformNotFound(
            $this->fetchAs($stageAdmin, $otherStageMember->id),
            $otherStageMember
        );
    }

    public function test_denial_body_does_not_distinguish_same_tenant_from_cross_tenant(): void
    {
        $foreignStage = Stage::factory()->forChurch($this->otherChurch)->create();
        $foreignClass = Classe::factory()->forChurch($this->otherChurch)
            ->state(['stage_id' => $foreignStage->id])->create();
        $foreignMember = $this->member($this->otherChurch, $foreignClass, $foreignStage);

        $member = $this->actor(UserRole::Member);

        // Forbidden inside the tenant ...
        $insideTenant = $this->fetchAs($member, $this->memberOne->id);
        // ... and forbidden across the tenant boundary.
        $outsideTenant = $this->fetchAs($member, $foreignMember->id);

        $this->assertSame(404, $insideTenant->getStatusCode());
        $this->assertSame(404, $outsideTenant->getStatusCode());
        $this->assertSame($insideTenant->getContent(), $outsideTenant->getContent());
    }
}
