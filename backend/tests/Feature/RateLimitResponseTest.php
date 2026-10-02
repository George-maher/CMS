<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * PHASE 1C — P0: rate limiting must return 429, never 500.
 *
 * Why this file exists:
 *
 * Phase 1B proved that exceeding a named rate limiter returned
 * 500 INTERNAL_ERROR instead of 429. The existing suite did not catch it
 * because the ONLY rate-limit assertion in the whole suite
 * (PasswordResetRequestTest::test_submit_is_rate_limited) REDEFINES the limiter:
 *
 *     RateLimiter::for('login', fn () => Limit::perMinute(2));
 *
 * A re-registered limiter carries no ->response(...) callback, so the framework
 * throws ThrottleRequestsException, which bootstrap/app.php already renders as
 * 429. The application's REAL limiters all declare ->response(...) and take a
 * different code path — the one that was returning 500.
 *
 * Every test below therefore drives the REAL registered limiter and asserts the
 * ACTUAL HTTP status line and body, not merely "throttling occurred".
 *
 * The defect is reproducible regardless of database driver because it lives in
 * the exception-rendering layer, not in SQL — so these tests are meaningful on
 * both SQLite and PostgreSQL.
 */
class RateLimitResponseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Rate-limit counters live in the cache, so state MUST not carry over
     * between test methods.
     *
     * phpunit.xml runs the suite on the `array` store, which is rebuilt per
     * application instance and therefore isolates automatically. Production
     * runs on `CACHE_STORE=file`, whose data directory survives across tests
     * AND across runs — without this flush the counters accumulate and the
     * "below the limit" assertions become order-dependent.
     *
     * Flushing in setUp makes every test here valid on BOTH stores, so the
     * suite can be re-run against the production cache mechanism without
     * tripping over its own leftovers.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /**
     * Drive the REAL 'login' limiter (Limit::perMinute(5), keyed by IP|email).
     *
     * @return array<int, TestResponse>
     */
    private function attemptLogin(int $attempts): array
    {
        $responses = [];

        for ($i = 1; $i <= $attempts; $i++) {
            $responses[] = $this->postJson('/api/v1/auth/login', [
                'email' => 'does-not-exist@example.com',
                'password' => 'definitely-the-wrong-password',
            ]);
        }

        return $responses;
    }

    public function test_a_request_below_the_limit_reaches_the_handler(): void
    {
        $response = $this->attemptLogin(1)[0];

        // The login fails (no such user) but must not be throttled or 5xx.
        $this->assertSame(401, $response->getStatusCode());
        $this->assertNotSame(429, $response->getStatusCode());
    }

    public function test_requests_up_to_the_configured_limit_are_still_allowed(): void
    {
        // 5 is the configured maximum. All 5 must reach the handler: this
        // proves the fix did not weaken, remove or lower the limiter.
        $responses = $this->attemptLogin(5);

        foreach ($responses as $index => $response) {
            $this->assertSame(
                401,
                $response->getStatusCode(),
                sprintf('Attempt %d should reach the handler, got %d', $index + 1, $response->getStatusCode())
            );
        }
    }

    public function test_the_next_throttled_request_returns_429_and_not_500(): void
    {
        $throttled = $this->attemptLogin(6)[5];

        $this->assertSame(
            429,
            $throttled->getStatusCode(),
            'Exceeding a named limiter must return 429, got '.$throttled->getStatusCode()
                .': '.$throttled->getContent()
        );

        $this->assertNotSame(500, $throttled->getStatusCode());
    }

    public function test_the_throttled_response_uses_the_application_json_error_structure(): void
    {
        $throttled = $this->attemptLogin(6)[5];

        $throttled->assertHeader('Content-Type', 'application/json');
        $throttled->assertJson([
            'success' => false,
            'code' => 'RATE_LIMITED',
        ]);

        $payload = $throttled->json();

        $this->assertArrayHasKey('message', $payload);
        $this->assertNotEmpty($payload['message']);
        $this->assertArrayHasKey('retry_after', $payload);
        $this->assertIsInt($payload['retry_after']);
        $this->assertGreaterThan(0, $payload['retry_after']);

        // A throttled request must never surface as an internal error.
        $this->assertNotSame('INTERNAL_ERROR', $payload['code']);
        $this->assertStringNotContainsString('Internal server error', (string) $payload['message']);
    }

    public function test_the_throttled_response_preserves_rate_limit_headers(): void
    {
        $throttled = $this->attemptLogin(6)[5];

        $retryAfter = $throttled->headers->get('Retry-After');
        $this->assertNotNull($retryAfter, 'Retry-After must be present so clients can back off');
        $this->assertMatchesRegularExpression('/^\d+$/', $retryAfter);
        $this->assertLessThanOrEqual(60, (int) $retryAfter);

        $this->assertSame('5', $throttled->headers->get('X-RateLimit-Limit'));
        $this->assertSame('0', $throttled->headers->get('X-RateLimit-Remaining'));
    }

    public function test_the_throttled_response_leaks_no_internal_exception_details(): void
    {
        $throttled = $this->attemptLogin(6)[5];

        $content = (string) $throttled->getContent();

        foreach (['SQLSTATE', 'ThrottleRequests', 'HttpResponseException', 'stack trace', 'vendor/'] as $needle) {
            $this->assertStringNotContainsString($needle, $content);
        }
    }

    public function test_other_named_limiters_also_return_429_instead_of_500(): void
    {
        // 'verify-email' = Limit::perMinute(10): a different named limiter with
        // a different max, proving the fix is not login-specific.
        $responses = [];

        for ($i = 1; $i <= 11; $i++) {
            $responses[] = $this->postJson('/api/v1/auth/verify-email', ['token' => 'not-a-real-token']);
        }

        foreach ($responses as $index => $response) {
            $this->assertNotSame(
                500,
                $response->getStatusCode(),
                sprintf('Attempt %d returned 500', $index + 1)
            );
        }

        $this->assertNotSame(429, $responses[0]->getStatusCode());

        $throttled = $responses[10];
        $this->assertSame(429, $throttled->getStatusCode(), 'verify-email limiter must return 429');
        $throttled->assertJson(['success' => false, 'code' => 'RATE_LIMITED']);
    }

    public function test_the_429_is_not_logged_as_an_unhandled_exception(): void
    {
        // The bug logged every throttled request as "Unhandled API exception",
        // so 5xx alerting fired on routine throttling and drowned real
        // incidents. Assert that path is no longer taken.
        Log::spy();

        $throttled = $this->attemptLogin(6)[5];

        $this->assertSame(429, $throttled->getStatusCode());

        Log::shouldNotHaveReceived('error', ['Unhandled API exception', Mockery::any()]);
    }
}
