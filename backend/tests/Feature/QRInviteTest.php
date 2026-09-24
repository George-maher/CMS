<?php

namespace Tests\Feature;

use App\Enums\QRInviteType;
use App\Enums\UserRole;
use App\Enums\UserScope;
use App\Models\AttendanceContext;
use App\Models\Church;
use App\Models\Classe;
use App\Models\Permission;
use App\Models\QRInvite;
use App\Models\Stage;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class QRInviteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Permission::clearCache();
    }

    /**
     * Create a servant linked to a stage + class, matching the stage-scoped
     * data model servants are expected to have in production.
     */
    private function stagedServantFor(Church $church): User
    {
        $stage = Stage::factory()->forChurch($church)->create();
        $classe = Classe::factory()->forChurch($church)->state(['stage_id' => $stage->id])->create();

        return User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $church->id,
            'stage_id' => $stage->id,
            'class_id' => $classe->id,
            'scope' => UserScope::ClassScope->value,
            'application_status' => 'approved',
        ]);
    }

    public function test_servant_can_create_member_invite(): void
    {
        $church = Church::factory()->create();
        $servant = $this->stagedServantFor($church);
        $token = $servant->createToken('test', [$servant->role->value])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/qr/invites', [
                'type' => QRInviteType::ServantToMemberInvite->value,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => ['invite' => ['id', 'type', 'url'], 'url'],
            ]);
    }

    public function test_admin_can_create_servant_invite(): void
    {
        $church = Church::factory()->create();
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $church->id,
        ]);
        $token = $admin->createToken('test', [$admin->role->value])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/qr/invites', [
                'type' => QRInviteType::AdminToServantInvite->value,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => ['invite' => ['id', 'type', 'url'], 'url'],
            ]);
    }

    public function test_servant_cannot_create_servant_invite(): void
    {
        $church = Church::factory()->create();
        $servant = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $church->id,
        ]);
        $token = $servant->createToken('test', [$servant->role->value])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/qr/invites', [
                'type' => QRInviteType::AdminToServantInvite->value,
            ]);

        $response->assertStatus(422);
    }

    public function test_member_cannot_create_invite(): void
    {
        $member = User::factory()->create(['role' => UserRole::Member]);
        $token = $member->createToken('test', [$member->role->value])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/qr/invites', [
                'type' => QRInviteType::ServantToMemberInvite->value,
            ]);

        $response->assertStatus(403);
    }

    public function test_token_not_exposed_in_list(): void
    {
        $church = Church::factory()->create();
        $servant = $this->stagedServantFor($church);
        $token = $servant->createToken('test', [$servant->role->value])->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/qr/invites', [
                'type' => QRInviteType::ServantToMemberInvite->value,
            ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/qr/invites');

        $response->assertStatus(200)
            ->assertJsonMissingPath('data.0.token');
    }

    public function test_create_invite_is_idempotent_with_same_request_id(): void
    {
        $church = Church::factory()->create();
        $servant = $this->stagedServantFor($church);
        $token = $servant->createToken('test', [$servant->role->value])->plainTextToken;

        $requestId = str_repeat('a', 30).'-'.Str::random(20);
        $payload = ['type' => QRInviteType::ServantToMemberInvite->value, 'client_request_id' => $requestId];

        $first = $this->withHeader('Authorization', "Bearer $token")->postJson('/api/v1/qr/invites', $payload);
        $first->assertStatus(201);

        $second = $this->withHeader('Authorization', "Bearer $token")->postJson('/api/v1/qr/invites', $payload);
        $second->assertStatus(201);

        $this->assertSame($first->json('data.invite.id'), $second->json('data.invite.id'));
        $this->assertDatabaseCount('qr_invites', 1);
    }

    public function test_create_invite_with_different_request_ids_creates_separate_records(): void
    {
        $church = Church::factory()->create();
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $church->id,
            'application_status' => 'approved',
        ]);
        $token = $admin->createToken('test', [$admin->role->value])->plainTextToken;

        $payload = ['type' => QRInviteType::AdminToServantInvite->value];

        $first = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/qr/invites', $payload + ['client_request_id' => 'key-one-'.Str::random(20)]);
        $first->assertStatus(201);

        $second = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/qr/invites', $payload + ['client_request_id' => 'key-two-'.Str::random(20)]);
        $second->assertStatus(201);

        $this->assertNotSame($first->json('data.invite.id'), $second->json('data.invite.id'));
        $this->assertDatabaseCount('qr_invites', 2);
    }

    public function test_duplicate_request_id_reuses_existing_invite(): void
    {
        $church = Church::factory()->create();
        $servant = $this->stagedServantFor($church);
        $token = $servant->createToken('test', [$servant->role->value])->plainTextToken;

        $requestId = 'concurrent-'.Str::random(20);

        // Simulate the race: pre-create the invite, then assert that a follow-up
        // with the same key reuses the existing record instead of duplicating it.
        $this->withToken($token)
            ->postJson('/api/v1/qr/invites', ['type' => QRInviteType::ServantToMemberInvite->value, 'client_request_id' => $requestId])
            ->assertStatus(201);

        $this->withToken($token)
            ->postJson('/api/v1/qr/invites', ['type' => QRInviteType::ServantToMemberInvite->value, 'client_request_id' => $requestId])
            ->assertStatus(201);

        // The DB unique index (created_by, client_request_id) is the final gate.
        $this->assertDatabaseCount('qr_invites', 1);
    }

    public function test_rotating_an_invite_invalidates_the_old_token_and_issues_a_new_four_hour_token(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-23 12:00:00'));

        $church = Church::factory()->create();
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $church->id,
            'application_status' => 'approved',
        ]);
        $authToken = $admin->createToken('test', [$admin->role->value])->plainTextToken;

        $created = $this->withToken($authToken)->postJson('/api/v1/qr/invites', [
            'type' => QRInviteType::AdminToServantInvite->value,
        ])->assertCreated();

        $inviteId = $created->json('data.invite.id');
        $oldToken = QRInvite::query()->findOrFail($inviteId)->token;

        $rotated = $this->withToken($authToken)
            ->postJson("/api/v1/qr/invites/{$inviteId}/rotate")
            ->assertOk();

        $newToken = QRInvite::query()->findOrFail($inviteId)->token;
        $this->assertNotSame($oldToken, $newToken);
        $this->assertSame('2026-09-23 16:00:00', QRInvite::query()->findOrFail($inviteId)->expires_at->format('Y-m-d H:i:s'));
        $this->assertStringContainsString($newToken, $rotated->json('data.url'));

        $this->getJson('/api/v1/invite/'.$oldToken)->assertStatus(422);
        $this->getJson('/api/v1/invite/'.$newToken)->assertOk();

        Carbon::setTestNow();
    }

    public function test_public_invite_details_returns_eligible_classes_and_stage(): void
    {
        $church = Church::factory()->create();
        $stage = Stage::factory()->forChurch($church)->create();
        $classe = Classe::factory()->forChurch($church)->state(['stage_id' => $stage->id])->create();
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $church->id,
            'application_status' => 'approved',
        ]);

        $invite = QRInvite::create([
            'type' => QRInviteType::ServantToMemberInvite,
            'token' => Str::random(64),
            'created_by' => $admin->id,
            'church_id' => $church->id,
            'stage_id' => $stage->id,
            'expires_at' => now()->addHours(4),
            'is_single_use' => true,
            'max_uses' => 1,
            'use_count' => 0,
        ]);

        $response = $this->getJson('/api/v1/invite/'.$invite->token);

        $response->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.stage_name', $stage->name);

        $classes = $response->json('data.classes');
        $this->assertNotEmpty($classes, 'Public invite details must list the eligible classes of the invite church/stage.');
        $this->assertSame($classe->id, $classes[0]['id']);
    }

    public function test_public_invite_details_does_not_expose_used_by_users_pii(): void
    {
        $church = Church::factory()->create();
        $stage = Stage::factory()->forChurch($church)->create();
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $church->id,
            'application_status' => 'approved',
        ]);

        $invite = QRInvite::create([
            'type' => QRInviteType::ServantToMemberInvite,
            'token' => Str::random(64),
            'created_by' => $admin->id,
            'church_id' => $church->id,
            'stage_id' => $stage->id,
            'expires_at' => now()->addHours(4),
            'use_count' => 1,
        ]);
        $invite->forceFill([
            'used_by_users' => [[
                'id' => 999,
                'name' => 'Secret Member',
                'role' => 'member',
                'phone' => '01099999999',
                'class_id' => null,
                'class_name' => null,
                'stage_name' => null,
                'used_at' => '2026-09-24T10:00:00Z',
            ]],
        ])->save();

        $response = $this->getJson('/api/v1/invite/'.$invite->token);

        $response->assertOk();
        $this->assertArrayNotHasKey(
            'used_by_users',
            $response->json('data'),
            'Public invite details must not expose the usage roster (names/phones of past users).'
        );
    }

    public function test_public_qr_validate_returns_classes_stage_and_own_church_context(): void
    {
        $church = Church::factory()->create();
        $stage = Stage::factory()->forChurch($church)->create();
        $classe = Classe::factory()->forChurch($church)->state(['stage_id' => $stage->id])->create();
        $context = AttendanceContext::factory()->create(['church_id' => $church->id]);
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $church->id,
            'application_status' => 'approved',
        ]);

        $invite = QRInvite::create([
            'type' => QRInviteType::AttendanceQR,
            'token' => Str::random(64),
            'created_by' => $admin->id,
            'church_id' => $church->id,
            'stage_id' => $stage->id,
            'attendance_context_id' => $context->id,
            'expires_at' => now()->addHours(4),
            'is_single_use' => true,
            'max_uses' => 1,
            'use_count' => 0,
        ]);

        $response = $this->getJson('/api/v1/qr/validate/'.$invite->token);

        $response->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.stage_name', $stage->name)
            ->assertJsonPath('data.attendance_context.id', $context->id);

        $this->assertNotEmpty($response->json('data.classes'));
    }
}
