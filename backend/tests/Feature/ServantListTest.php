<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Church;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServantListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Permission::clearCache();
    }

    private function makeUser(Church $church, UserRole $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'church_id' => $church->id,
        ]);
    }

    private function actAs(User $user): self
    {
        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('test', [$user->role->value])->plainTextToken);
    }

    public function test_admin_gets_all_active_servants_of_own_church(): void
    {
        $church = Church::factory()->create();
        $admin = $this->makeUser($church, UserRole::Admin);

        // Servants NOT created by this admin must still be listed.
        User::factory()->count(3)->create([
            'role' => UserRole::Servant,
            'church_id' => $church->id,
            'is_active' => true,
        ]);

        $response = $this->actAs($admin)->getJson('/api/v1/users/servants');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_servants_list_is_church_isolated(): void
    {
        $churchA = Church::factory()->create();
        $churchB = Church::factory()->create();
        $adminA = $this->makeUser($churchA, UserRole::Admin);

        $servantB = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $churchB->id,
            'is_active' => true,
        ]);

        User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $churchA->id,
            'is_active' => true,
        ]);

        $response = $this->actAs($adminA)->getJson('/api/v1/users/servants');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertNotContains($servantB->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_inactive_and_non_servant_users_are_excluded(): void
    {
        $church = Church::factory()->create();
        $admin = $this->makeUser($church, UserRole::Admin);

        $inactiveServant = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $church->id,
            'is_active' => false,
        ]);
        $member = $this->makeUser($church, UserRole::Member);

        User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $church->id,
            'is_active' => true,
        ]);

        $response = $this->actAs($admin)->getJson('/api/v1/users/servants');

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertNotContains($inactiveServant->id, $ids);
        $this->assertNotContains($member->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_servant_can_access_the_list_for_event_assignment(): void
    {
        $church = Church::factory()->create();
        $servant = $this->makeUser($church, UserRole::Servant);

        User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $church->id,
            'is_active' => true,
        ]);

        $response = $this->actAs($servant)->getJson('/api/v1/users/servants');

        $response->assertStatus(200);
        // The acting servant themselves plus the other church servant.
        $this->assertCount(2, $response->json('data'));
    }

    public function test_member_cannot_access_the_servants_list(): void
    {
        $church = Church::factory()->create();
        $member = $this->makeUser($church, UserRole::Member);

        $this->actAs($member)
            ->getJson('/api/v1/users/servants')
            ->assertStatus(403);
    }
}
