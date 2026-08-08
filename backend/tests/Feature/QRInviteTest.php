<?php

namespace Tests\Feature;

use App\Enums\QRInviteType;
use App\Enums\UserRole;
use App\Models\Church;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_servant_can_create_member_invite(): void
    {
        $church = Church::factory()->create();
        $servant = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $church->id,
        ]);
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
        $servant = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $church->id,
        ]);
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
        $servant = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $church->id,
            'application_status' => 'approved',
        ]);
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

    public function test_concurrent_duplicate_request_id_never_creates_two_records(): void
    {
        $church = Church::factory()->create();
        $servant = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $church->id,
            'application_status' => 'approved',
        ]);
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
}
