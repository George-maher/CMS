<?php

namespace Tests\Feature;

use App\Enums\QRInviteType;
use App\Enums\UserRole;
use App\Models\Church;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
