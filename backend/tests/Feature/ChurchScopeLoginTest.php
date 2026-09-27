<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Church;
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
        $repo = app(\App\Contracts\UserRepositoryInterface::class);
        $foundUser = $repo->findByEmail('servant-login@test.com');
        
        $this->assertNotNull($foundUser, 'UserRepository::findByEmail should find the user');
        $this->assertEquals($servant->id, $foundUser->id);

        // Now try the actual login
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'servant-login@test.com',
            'password' => 'Test@1234',
        ]);
        
        $this->assertEquals(200, $response->status(), 'Login should succeed: ' . json_encode($response->json()));
        $this->assertEquals('servant', $response->json('data.user.role'));
    }

    public function test_church_scope_behavior_during_unauthenticated_request(): void
    {
        $church = Church::factory()->create();
        User::factory()->create([
            'role' => UserRole::Servant,
            'email' => 'test-scope@test.com',
            'password' => bcrypt('Test@1234'),
            'application_status' => 'approved',
            'church_id' => $church->id,
        ]);

        // Check if ChurchScope is in the User model's global scopes
        $userModel = new User();
        $globalScopes = $userModel->getGlobalScopes();
        $hasChurchScope = array_key_exists('App\\Models\\Scopes\\ChurchScope', $globalScopes);
        $this->assertTrue($hasChurchScope, 'User model should have ChurchScope global scope');

        // Make an actual HTTP request to trigger the ChurchScope in a real request context
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test-scope@test.com',
            'password' => 'Test@1234',
        ]);
        
        $this->assertEquals(200, $response->status(), 'Login should succeed: ' . json_encode($response->json()));
    }
}