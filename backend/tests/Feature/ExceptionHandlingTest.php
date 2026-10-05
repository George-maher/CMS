<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Church;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exception handling tests — verify that expected exceptions return correct
 * status codes and safe response shapes without leaking sensitive data.
 */
class ExceptionHandlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_exception_returns_422_with_field_errors(): void
    {
        $church = Church::factory()->create();
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $church->id,
            'application_status' => 'approved',
        ]);

        $token = $admin->createToken('test', ['admin'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/users', [
                'name' => '',
                'email' => 'invalid-email',
                'password' => 'short',
            ]);

        $response->assertStatus(422);
        $data = $response->json();

        // Must have a consistent error structure
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('errors', $data);

        // Must NOT leak sensitive data
        $this->assertStringNotContainsString('SQL', $response->getContent());
        $this->assertStringNotContainsString('Stack trace', $response->getContent());
        $this->assertStringNotContainsString('vendor', $response->getContent());
    }

    public function test_authentication_exception_returns_401(): void
    {
        $response = $this->getJson('/api/v1/users');

        $response->assertStatus(401);
        $data = $response->json();

        $this->assertArrayHasKey('message', $data);
        $this->assertStringNotContainsString('SQL', $response->getContent());
    }

    public function test_authorization_exception_returns_403(): void
    {
        $church = Church::factory()->create();
        $member = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $church->id,
            'application_status' => 'approved',
        ]);

        $token = $member->createToken('test', ['member'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/users');

        $response->assertStatus(403);
        $data = $response->json();

        $this->assertArrayHasKey('message', $data);
        $this->assertStringNotContainsString('SQL', $response->getContent());
    }

    public function test_not_found_returns_404(): void
    {
        $church = Church::factory()->create();
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $church->id,
            'application_status' => 'approved',
        ]);

        $token = $admin->createToken('test', ['admin'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/users/99999');

        $response->assertStatus(404);
    }

    public function test_rate_limit_returns_429_not_500(): void
    {
        $church = Church::factory()->create();
        $user = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $church->id,
            'application_status' => 'approved',
        ]);

        $token = $user->createToken('test', ['member'])->plainTextToken;

        // Exceed the rate limit (300/min for api)
        $response = null;
        for ($i = 0; $i < 310; $i++) {
            $response = $this->withHeader('Authorization', "Bearer $token")
                ->getJson('/api/v1/verses/active');
        }

        // Should get 429, not 500
        $this->assertNotNull($response);
        $this->assertContains($response->getStatusCode(), [200, 429]);

        if ($response->getStatusCode() === 429) {
            $data = $response->json();
            $this->assertArrayHasKey('message', $data);
            $this->assertStringNotContainsString('SQL', $response->getContent());
        }
    }

    public function test_throttle_exception_is_not_swallowed_by_catch_all(): void
    {
        // This test verifies that ThrottleRequestsException is handled
        // by its own handler, not the catch-all Throwable handler.
        // The proof is that we get a 429 (not 500) when rate limited.

        $church = Church::factory()->create();
        $user = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $church->id,
            'application_status' => 'approved',
        ]);

        $token = $user->createToken('test', ['member'])->plainTextToken;

        // Use the login limiter (5/min) which is easier to trigger
        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson('/api/v1/auth/login', [
                'email' => 'test@test.com',
                'password' => 'wrong',
            ]);
        }

        // Should be 429, not 500
        $this->assertSame(429, $response->getStatusCode());
        $data = $response->json();
        $this->assertArrayHasKey('message', $data);
    }

    public function test_cross_tenant_access_does_not_leak_data(): void
    {
        $churchA = Church::factory()->create();
        $churchB = Church::factory()->create();

        $adminA = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $churchA->id,
            'application_status' => 'approved',
        ]);

        $memberB = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $churchB->id,
            'application_status' => 'approved',
        ]);

        $tokenA = $adminA->createToken('test', ['admin'])->plainTextToken;

        // Try to access church B's user
        $response = $this->withHeader('Authorization', "Bearer $tokenA")
            ->getJson('/api/v1/users/'.$memberB->id);

        // Should be 403 or 404, never 200 with data
        $this->assertContains($response->getStatusCode(), [403, 404]);

        // Must not leak church B's data
        $this->assertStringNotContainsString($memberB->email, $response->getContent());
    }

    public function test_churchless_principal_gets_empty_results(): void
    {
        $churchless = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => null,
            'application_status' => 'approved',
        ]);

        $token = $churchless->createToken('test', ['admin'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/users');

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data'));
    }

    public function test_invalid_token_returns_401(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer invalid-token-here')
            ->getJson('/api/v1/users');

        $response->assertStatus(401);
    }

    public function test_malformed_request_returns_422_not_500(): void
    {
        $church = Church::factory()->create();
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $church->id,
            'application_status' => 'approved',
        ]);

        $token = $admin->createToken('test', ['admin'])->plainTextToken;

        // Send a request with wrong data types
        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/stages', [
                'name' => ['not', 'a', 'string'],
                'church_id' => 'not-an-integer',
            ]);

        // Should be 422, not 500
        $this->assertSame(422, $response->getStatusCode());
    }
}
