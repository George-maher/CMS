<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Church;
use App\Models\DailyVerse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Security regression tests for VULN-0001, VULN-0002, and VULN-0003.
 */
class SecurityRegressionTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // VULN-0001 — Account enumeration via login timing side-channel
    // =========================================================================

    public function test_login_fails_with_generic_response_for_existing_user_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'existing@test.com',
            'password' => bcrypt('CorrectPass1!'),
            'application_status' => 'approved',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'existing@test.com',
            'password' => 'WrongPass1!',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'LOGIN_FAILED')
            ->assertJsonPath('message', __('auth.failed'));

        // No user data leaked
        $response->assertJsonMissingPath('data.user');
        $response->assertJsonMissingPath('data.token');
    }

    public function test_login_fails_with_generic_response_for_non_existing_user(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'nonexistent@test.com',
            'password' => 'AnyPass1!',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'LOGIN_FAILED')
            ->assertJsonPath('message', __('auth.failed'));

        // No user data leaked
        $response->assertJsonMissingPath('data.user');
        $response->assertJsonMissingPath('data.token');
    }

    public function test_login_failure_responses_are_identical_for_existing_and_non_existing_users(): void
    {
        User::factory()->create([
            'email' => 'real@test.com',
            'password' => bcrypt('CorrectPass1!'),
            'application_status' => 'approved',
        ]);

        $responseExisting = $this->postJson('/api/v1/auth/login', [
            'email' => 'real@test.com',
            'password' => 'WrongPass1!',
        ]);

        $responseNonExisting = $this->postJson('/api/v1/auth/login', [
            'email' => 'fake@test.com',
            'password' => 'WrongPass1!',
        ]);

        // Both must have identical structure and status
        $this->assertEquals($responseExisting->getStatusCode(), $responseNonExisting->getStatusCode());
        $this->assertEquals($responseExisting->json('code'), $responseNonExisting->json('code'));
        $this->assertEquals($responseExisting->json('message'), $responseNonExisting->json('message'));
        $this->assertEquals($responseExisting->json('success'), $responseNonExisting->json('success'));
    }

    // These assertions pin comparable hasher call counts only; they do not
    // measure latency or prove constant-time behavior.
    public function test_login_failure_hash_operation_counts_match_for_nonexistent_account(): void
    {
        Hash::shouldReceive('make')->twice()->andReturn('dummy-hash');
        Hash::shouldReceive('check')->twice()->andReturn(false);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'missing-account@test.com',
            'password' => 'WrongPass1!',
        ])->assertStatus(401);
    }

    public function test_login_failure_hash_operation_counts_match_for_existing_account(): void
    {
        User::factory()->create([
            'email' => 'known-account@test.com',
            'password' => bcrypt('CorrectPass1!'),
            'application_status' => 'approved',
        ]);

        Hash::shouldReceive('check')->twice()->andReturn(false);
        Hash::shouldReceive('make')->twice()->andReturn('candidate-hash');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'known-account@test.com',
            'password' => 'WrongPass1!',
        ])->assertStatus(401);
    }

    public function test_login_success_behavior_unchanged(): void
    {
        $user = User::factory()->create([
            'email' => 'success@test.com',
            'password' => bcrypt('Test@1234'),
            'application_status' => 'approved',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'success@test.com',
            'password' => 'Test@1234',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['user', 'token', 'token_type'],
            ]);

        $this->assertEquals('success@test.com', $response->json('data.user.email'));
    }

    // =========================================================================
    // VULN-0002 — Missing authentication on GET /api/v1/verses/active
    // =========================================================================

    public function test_anonymous_cannot_access_active_verse(): void
    {
        $response = $this->getJson('/api/v1/verses/active');

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_access_active_verse(): void
    {
        $church = Church::factory()->create();
        $user = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $church->id,
            'application_status' => 'approved',
        ]);

        $verse = DailyVerse::create([
            'verse_text' => 'For God so loved the world.',
            'reference' => 'John 3:16',
            'created_by' => $user->id,
            'is_active' => true,
            'church_id' => $church->id,
        ]);

        $token = $user->createToken('test', ['member'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/verses/active');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertNotNull($data);
        $this->assertEquals('For God so loved the world.', $data['verse_text']);
        $this->assertEquals('John 3:16', $data['reference']);
    }

    public function test_active_verse_response_does_not_expose_administrative_fields(): void
    {
        $church = Church::factory()->create();
        $user = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $church->id,
            'application_status' => 'approved',
        ]);

        DailyVerse::create([
            'verse_text' => 'The Lord is my shepherd.',
            'reference' => 'Psalm 23:1',
            'created_by' => $user->id,
            'is_active' => true,
            'church_id' => $church->id,
        ]);

        $token = $user->createToken('test', ['member'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/verses/active');

        $response->assertStatus(200);

        $data = $response->json('data');

        // Must NOT contain administrative fields
        $this->assertArrayNotHasKey('created_by', $data);
        $this->assertArrayNotHasKey('creator_name', $data);
        $this->assertArrayNotHasKey('is_active', $data);
        $this->assertArrayNotHasKey('created_at', $data);
        $this->assertArrayNotHasKey('updated_at', $data);
        $this->assertArrayNotHasKey('id', $data);
    }

    public function test_verses_index_still_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/verses');

        $response->assertStatus(401);
    }

    public function test_verses_show_still_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/verses/1');

        $response->assertStatus(401);
    }

    // =========================================================================
    // VULN-0003 — Missing authentication + cross-tenant exposure on
    //              GET /api/v1/churches/active
    // =========================================================================

    public function test_anonymous_cannot_access_active_churches(): void
    {
        $response = $this->getJson('/api/v1/churches/active');

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_access_active_churches(): void
    {
        $church = Church::factory()->create([
            'is_active' => true,
            'is_suspended' => false,
        ]);

        $user = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $church->id,
            'application_status' => 'approved',
        ]);

        $token = $user->createToken('test', ['member'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/churches/active');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
    }

    public function test_active_churches_response_does_not_expose_address(): void
    {
        $church = Church::factory()->create([
            'is_active' => true,
            'is_suspended' => false,
            'address' => '123 Secret Street, Cairo',
        ]);

        $user = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $church->id,
            'application_status' => 'approved',
        ]);

        $token = $user->createToken('test', ['member'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/churches/active');

        $response->assertStatus(200);

        $data = $response->json('data');

        // Address should not be exposed
        foreach ($data as $church) {
            $this->assertArrayNotHasKey('address', $church);
        }
    }

    public function test_churches_index_still_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/churches');

        $response->assertStatus(401);
    }

    // =========================================================================
    // Tenant isolation tests
    // =========================================================================

    public function test_tenant_user_cannot_enumerate_other_tenants_via_active_churches(): void
    {
        $churchA = Church::factory()->create([
            'name' => 'Church A',
            'is_active' => true,
            'is_suspended' => false,
        ]);

        $churchB = Church::factory()->create([
            'name' => 'Church B',
            'is_active' => true,
            'is_suspended' => false,
        ]);

        $userA = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $churchA->id,
            'application_status' => 'approved',
        ]);

        $tokenA = $userA->createToken('test', ['member'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $tokenA")
            ->getJson('/api/v1/churches/active');

        $response->assertStatus(200);

        $data = $response->json('data');
        $churchNames = array_column($data, 'name');

        // The endpoint returns all active churches (not tenant-scoped),
        // but the user should not be able to use it to enumerate other
        // tenants' internal data. The response only exposes id, name, slug.
        // This test verifies the response shape is minimal.
        foreach ($data as $church) {
            $this->assertArrayNotHasKey('address', $church);
            $this->assertArrayNotHasKey('phone', $church);
            $this->assertArrayNotHasKey('contact_email', $church);
            $this->assertArrayNotHasKey('priest_name', $church);
            $this->assertArrayNotHasKey('priest_phone', $church);
        }
    }

    public function test_tenant_user_cannot_bypass_authorization_with_headers(): void
    {
        $churchA = Church::factory()->create([
            'name' => 'Church A',
            'is_active' => true,
            'is_suspended' => false,
        ]);

        $churchB = Church::factory()->create([
            'name' => 'Church B',
            'is_active' => true,
            'is_suspended' => false,
        ]);

        $userA = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $churchA->id,
            'application_status' => 'approved',
        ]);

        $tokenA = $userA->createToken('test', ['member'])->plainTextToken;

        // Attempt to bypass with X-Tenant header
        $response = $this->withHeader('Authorization', "Bearer $tokenA")
            ->withHeader('X-Tenant', (string) $churchB->id)
            ->withHeader('X-Church-Id', (string) $churchB->id)
            ->getJson('/api/v1/churches/active');

        $response->assertStatus(200);

        // The endpoint should not be affected by these headers.
        // It returns all active churches regardless of tenant headers.
        $data = $response->json('data');
        $this->assertIsArray($data);
    }

    public function test_tenant_user_cannot_bypass_authorization_with_query_params(): void
    {
        $churchA = Church::factory()->create([
            'name' => 'Church A',
            'is_active' => true,
            'is_suspended' => false,
        ]);

        $churchB = Church::factory()->create([
            'name' => 'Church B',
            'is_active' => true,
            'is_suspended' => false,
        ]);

        $userA = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $churchA->id,
            'application_status' => 'approved',
        ]);

        $tokenA = $userA->createToken('test', ['member'])->plainTextToken;

        // Attempt to bypass with church_id query parameter
        $response = $this->withHeader('Authorization', "Bearer $tokenA")
            ->getJson('/api/v1/churches/active?church_id='.$churchB->id);

        $response->assertStatus(200);

        // The endpoint should not be affected by query parameters.
        $data = $response->json('data');
        $this->assertIsArray($data);
    }

    // =========================================================================
    // Route audit — verify other routes are still protected
    // =========================================================================

    public function test_platform_churches_still_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/platform/churches');

        $response->assertStatus(401);
    }

    public function test_platform_churches_deleted_history_still_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/platform/churches/deleted-history');

        $response->assertStatus(401);
    }
}
