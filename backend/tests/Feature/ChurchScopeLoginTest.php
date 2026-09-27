<?php

namespace Tests\Feature;

use App\Contracts\UserRepositoryInterface;
use App\Enums\UserRole;
use App\Models\Church;
use App\Models\Scopes\ChurchScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChurchScopeLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_church_scope_does_not_block_login_query(): void
    {
        $church = Church::factory()->create();
        $servant = User::factory()->create([
            'role' => UserRole::Servant,
            'email' => 'servant-login@test.com',
            'password' => bcrypt('Test@1234'),
            'application_status' => 'approved',
            'church_id' => $church->id,
        ]);

        // Verify the user exists in the database
        $this->assertDatabaseHas('users', ['email' => 'servant-login@test.com']);

        // Try to find the user via the repository (simulating login)
        $repo = app(UserRepositoryInterface::class);
        $foundUser = $repo->findByEmail('servant-login@test.com');

        $this->assertNotNull($foundUser, 'UserRepository::findByEmail should find the user');
        $this->assertEquals($servant->id, $foundUser->id);

        // Now try the actual login
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'servant-login@test.com',
            'password' => 'Test@1234',
        ]);

        $this->assertEquals(200, $response->status(), 'Login should succeed: '.json_encode($response->json()));
        $this->assertEquals('servant', $response->json('data.user.role'));
    }

    /**
     * The User model must NOT carry the fail-closed ChurchScope global scope.
     *
     * Login has to resolve a user by email before any tenant context exists.
     * ChurchScope fail-closes to `1 = 0` for unauthenticated requests, so
     * scoping User globally would make findByEmail() return null and lock
     * every account out. Tenant isolation for users is therefore applied
     * explicitly per query (UserRepository::findById/paginate call byChurch())
     * rather than as a global scope.
     */
    public function test_user_model_has_no_fail_closed_global_church_scope(): void
    {
        $userModel = new User;
        $globalScopes = $userModel->getGlobalScopes();

        $this->assertArrayNotHasKey(
            ChurchScope::class,
            $globalScopes,
            'User must not carry ChurchScope: login resolves the user before a tenant context exists.'
        );
    }

    /**
     * A servant must be able to log in from an unauthenticated request even
     * though every tenant-scoped model fails closed at that point.
     */
    public function test_church_scope_does_not_block_servant_login(): void
    {
        $church = Church::factory()->create();
        User::factory()->create([
            'role' => UserRole::Servant,
            'email' => 'test-scope@test.com',
            'password' => bcrypt('Test@1234'),
            'application_status' => 'approved',
            'church_id' => $church->id,
            'email_verified_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test-scope@test.com',
            'password' => 'Test@1234',
        ]);

        $this->assertEquals(200, $response->status(), 'Login should succeed: '.json_encode($response->json()));
        $this->assertEquals('servant', $response->json('data.user.role'));
    }
}
