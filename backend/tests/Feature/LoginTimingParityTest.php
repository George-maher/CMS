<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Hashing\HashManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\CountingHasher;
use Tests\Support\LegacyDoubleHashHasher;
use Tests\TestCase;

/**
 * F-01 / F-03 — the amount of password-hashing work must not depend on whether
 * an account exists.
 *
 * Account enumeration by response timing is closed by making the number of
 * bcrypt operations identical for "unknown email" and "known email, wrong
 * password". These tests assert that operation COUNT, which is deterministic.
 *
 * A call-count assertion is NOT a timing measurement. It proves the two failure
 * paths queue the same amount of hashing work — the condition the dummy hash
 * relies on — but it cannot prove real-world timing equivalence, because
 * scheduler jitter, CPU/cache state, response serialisation and I/O all
 * contribute to observed latency.
 */
class LoginTimingParityTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Test@1234';

    private const WRONG_PASSWORD = 'WrongPass1!';

    /**
     * Install a hasher that counts bcrypt operations while still delegating to
     * the real implementation. Installed AFTER fixtures are created so fixture
     * hashing is not counted.
     */
    private function countHashOperations(): CountingHasher
    {
        /** @var HashManager $real */
        $real = $this->app->make('hash');

        $counter = new CountingHasher($real);

        Hash::swap($counter);

        return $counter;
    }

    private function platformLoginPath(): string
    {
        /** @var string $path */
        $path = config('services.platform_admin_login_path', 'platform-secure-admin-login');

        return '/api/v1/auth/'.$path;
    }

    private function makeUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => UserRole::Member,
            'application_status' => 'approved',
            'is_active' => true,
            'password' => self::PASSWORD,
        ], $attributes));
    }

    private function attemptLogin(string $email, string $password)
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);
    }

    public function test_unknown_email_performs_three_bcrypt_operations(): void
    {
        $this->makeUser(['email' => 'known@test.com']);

        $counter = $this->countHashOperations();

        $this->attemptLogin('unknown@test.com', self::WRONG_PASSWORD)->assertStatus(401);

        // One verification against the dummy hash + the two-operation
        // legacy-double-hash probe.
        $this->assertSame(2, $counter->checks);
        $this->assertSame(1, $counter->makes);
        $this->assertSame(3, $counter->checks + $counter->makes);
    }

    public function test_wrong_password_performs_three_bcrypt_operations(): void
    {
        $this->makeUser(['email' => 'known@test.com']);

        $counter = $this->countHashOperations();

        $this->attemptLogin('known@test.com', self::WRONG_PASSWORD)->assertStatus(401);

        // Identical to the unknown-email path: one real verification + the
        // same two-operation probe. This parity is what closes the oracle.
        $this->assertSame(2, $counter->checks);
        $this->assertSame(1, $counter->makes);
        $this->assertSame(3, $counter->checks + $counter->makes);
    }

    public function test_successful_login_performs_a_single_verification(): void
    {
        $this->makeUser(['email' => 'known@test.com']);

        $counter = $this->countHashOperations();

        $this->attemptLogin('known@test.com', self::PASSWORD)->assertStatus(200);

        // No hashing work is added to the success path.
        $this->assertSame(1, $counter->checks);
        $this->assertSame(0, $counter->makes);
    }

    public function test_unknown_email_and_wrong_password_failures_are_identical(): void
    {
        $this->makeUser(['email' => 'known@test.com']);

        $unknown = $this->attemptLogin('unknown@test.com', self::WRONG_PASSWORD);
        $wrong = $this->attemptLogin('known@test.com', self::WRONG_PASSWORD);

        $this->assertSame(401, $unknown->getStatusCode());
        $this->assertSame(401, $wrong->getStatusCode());
        $this->assertSame($wrong->json('code'), $unknown->json('code'));
        $this->assertSame($wrong->json('message'), $unknown->json('message'));
        $this->assertSame($wrong->json('success'), $unknown->json('success'));
    }

    public function test_legacy_double_hash_is_repaired_when_detection_succeeds(): void
    {
        $hasher = new LegacyDoubleHashHasher;

        $user = $this->makeUser(['email' => 'legacy@test.com']);

        // Write the legacy double hash directly so the model's `hashed` cast
        // cannot interfere with the fixture.
        $legacyStored = $hasher->make($hasher->make(self::PASSWORD));
        DB::table('users')->where('id', $user->id)->update(['password' => $legacyStored]);
        $user->refresh();

        $this->assertSame($legacyStored, $user->getAttribute('password'));
        $this->assertFalse($hasher->check(self::PASSWORD, $legacyStored), 'precondition: plain password must not verify');

        Hash::swap($hasher);

        $this->attemptLogin('legacy@test.com', self::PASSWORD)->assertStatus(200);

        $user->refresh();
        $repaired = $user->getAttribute('password');
        $this->assertIsString($repaired);
        $this->assertNotSame($legacyStored, $repaired, 'the stored double hash must be replaced');
        $this->assertTrue($hasher->check(self::PASSWORD, $repaired), 'the repaired value must verify with one hash');
    }

    public function test_platform_login_verifies_the_password_for_unknown_accounts(): void
    {
        $counter = $this->countHashOperations();

        $this->postJson($this->platformLoginPath(), [
            'email' => 'missing-platform-admin@test.com',
            'password' => self::WRONG_PASSWORD,
        ])->assertStatus(401);

        // A missing account must still perform one bcrypt verification instead
        // of short-circuiting before the password check.
        $this->assertSame(1, $counter->checks);
        $this->assertSame(0, $counter->makes);
    }

    public function test_platform_login_wrong_password_performs_one_verification(): void
    {
        $this->makeUser([
            'email' => 'platform-admin@test.com',
            'role' => UserRole::PlatformAdmin,
        ]);

        $counter = $this->countHashOperations();

        $this->postJson($this->platformLoginPath(), [
            'email' => 'platform-admin@test.com',
            'password' => self::WRONG_PASSWORD,
        ])->assertStatus(401);

        // Same one verification as the unknown-account path above.
        $this->assertSame(1, $counter->checks);
        $this->assertSame(0, $counter->makes);
    }

    public function test_platform_login_accepts_valid_platform_admin_credentials(): void
    {
        $this->makeUser([
            'email' => 'platform-admin@test.com',
            'role' => UserRole::PlatformAdmin,
        ]);

        $this->postJson($this->platformLoginPath(), [
            'email' => 'platform-admin@test.com',
            'password' => self::PASSWORD,
        ])->assertStatus(200)->assertJsonStructure(['data' => ['user', 'token', 'token_type']]);
    }

    public function test_church_admin_cannot_authenticate_as_platform_admin(): void
    {
        $this->makeUser([
            'email' => 'church-admin@test.com',
            'role' => UserRole::Admin,
        ]);

        $this->postJson($this->platformLoginPath(), [
            'email' => 'church-admin@test.com',
            'password' => self::PASSWORD,
        ])->assertStatus(401);
    }
}
