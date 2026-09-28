<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\EmailVerificationServiceInterface;
use App\Contracts\MailConfigurationValidatorInterface;
use App\Enums\EmailVerificationOutcome;
use App\Exceptions\InsecureMailConfigurationException;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use App\Services\MailConfigurationValidator;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Security guarantees for the email verification credential.
 *
 * The threat this suite protects against is specific and concrete: a person
 * with read access to `storage/logs/laravel.log`, to the `jobs` table, or to an
 * audit/export of the `users` table must not be able to verify an arbitrary
 * account, nor to replay a verification.
 */
class EmailVerificationTokenSecurityTest extends TestCase
{
    use RefreshDatabase;

    // =============================================================
    // Production mail configuration guard
    // =============================================================

    /** @return array<string, array{0: string}> */
    public static function nonDeliveringDriverProvider(): array
    {
        return [
            'log transport' => ['log'],
            'array transport' => ['array'],
            'null transport' => ['null'],
            'fail transport' => ['fail'],
        ];
    }

    /**
     * A non-delivering transport in a real environment must be rejected, because
     * Laravel's `log` transport writes the rendered message — verification token
     * included — straight into the application log.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nonDeliveringDriverProvider')]
    public function test_production_rejects_non_delivering_transports(string $driver): void
    {
        $this->forceEnvironment('production');
        config(['mail.default' => $driver, 'mail.from.address' => 'noreply@example.com']);

        $validator = $this->app->make(MailConfigurationValidatorInterface::class);

        $this->assertFalse(
            $validator->isVerificationDeliveryConfigured(),
            "The {$driver} transport must not be accepted for verification delivery in production."
        );

        $this->expectException(InsecureMailConfigurationException::class);
        $validator->assertVerificationDeliveryConfigured();
    }

    public function test_production_rejects_missing_from_address(): void
    {
        $this->forceEnvironment('production');
        config(['mail.default' => 'smtp', 'mail.from.address' => '']);

        $validator = $this->app->make(MailConfigurationValidatorInterface::class);

        $this->assertFalse($validator->isVerificationDeliveryConfigured());

        $problems = $validator->problems();
        $this->assertNotEmpty($problems);
        $this->assertStringContainsString('MAIL_FROM_ADDRESS', implode(' ', $problems));
    }

    public function test_production_accepts_a_real_transport(): void
    {
        $this->forceEnvironment('production');
        config(['mail.default' => 'smtp', 'mail.from.address' => 'noreply@example.com']);

        $validator = $this->app->make(MailConfigurationValidatorInterface::class);

        $this->assertTrue($validator->isVerificationDeliveryConfigured());
        $validator->assertVerificationDeliveryConfigured();
    }

    public function test_local_and_testing_permit_the_log_transport(): void
    {
        foreach (['local', 'testing'] as $environment) {
            $this->forceEnvironment($environment);
            config(['mail.default' => 'log', 'mail.from.address' => 'noreply@example.com']);

            $validator = $this->app->make(MailConfigurationValidator::class);

            $this->assertFalse(
                $validator->requiresRealDelivery(),
                "The {$environment} environment must stay runnable without SMTP credentials."
            );
            $this->assertTrue($validator->isVerificationDeliveryConfigured());
            $this->assertFalse($validator->shouldEnforceAtBoot());
        }
    }

    /** @return array<string, array{0: bool, 1: string, 2: bool}> */
    public static function bootEnforcementProvider(): array
    {
        return [
            // The web process is the production entry point: it must refuse to start.
            'web process' => [false, 'index.php', true],
            // A worker can render a verification email, so it must refuse too.
            'queue worker' => [true, 'queue:work', true],
            'queue listener' => [true, 'queue:listen', true],
            // Deploy tooling must keep working while mail is being repaired.
            'migrate' => [true, 'migrate', false],
            'config cache' => [true, 'config:cache', false],
            'route list' => [true, 'route:list', false],
            'test suite' => [true, 'test', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bootEnforcementProvider')]
    public function test_boot_enforcement_is_scoped_to_processes_that_can_send_mail(
        bool $runningInConsole,
        string $argvCommand,
        bool $expected,
    ): void {
        $this->forceEnvironment('production');
        config(['mail.default' => 'log', 'mail.from.address' => 'noreply@example.com']);

        $this->setRunningInConsole($runningInConsole);

        $originalArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['artisan', $argvCommand, '--no-ansi'];

        try {
            $validator = new MailConfigurationValidator($this->app);
            $this->assertSame($expected, $validator->shouldEnforceAtBoot());
        } finally {
            if ($originalArgv === null) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $originalArgv;
            }
        }
    }

    // =============================================================
    // Token at rest / in flight
    // =============================================================

    public function test_token_is_hashed_at_rest_and_never_persisted_in_plaintext(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        $rawToken = $this->app->make(EmailVerificationServiceInterface::class)->issue($user);

        $this->assertSame(64, strlen($rawToken));

        $stored = $user->fresh()?->email_verification_token;

        $this->assertIsString($stored);
        $this->assertNotSame($rawToken, $stored, 'The raw token must never be stored.');
        $this->assertStringStartsWith('$2y$', $stored, 'The stored value must be a bcrypt hash.');
        $this->assertTrue(
            Hash::check($rawToken, $stored),
            'The stored hash must correspond to the issued token.'
        );
    }

    /**
     * A database dump of `users` must not yield a replayable credential.
     */
    public function test_database_dump_cannot_be_replayed_to_verify(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $service = $this->app->make(EmailVerificationServiceInterface::class);

        $rawToken = $service->issue($user);

        $this->assertSame(
            EmailVerificationOutcome::Verified,
            $service->verify($user->email, $rawToken)
        );

        $this->assertNotNull($user->fresh()?->email_verified_at);
    }

    public function test_notification_is_queued_with_an_encrypted_payload(): void
    {
        $notification = new VerifyEmailNotification(
            User::factory()->create(),
            'https://example.test/verify-email?token=abc'
        );

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertInstanceOf(
            ShouldBeEncrypted::class,
            $notification,
            'A queued verification email must carry ShouldBeEncrypted so the raw token is '
            .'never written to a readable jobs.payload column.'
        );

        // Bounded retries: a permanently failing transport must not retry a
        // secret-bearing payload forever.
        $this->assertSame(3, $notification->tries);
        $this->assertNotEmpty($notification->backoff);
    }

    public function test_dispatch_is_refused_when_the_transport_cannot_deliver(): void
    {
        $this->forceEnvironment('production');
        config(['mail.default' => 'log', 'mail.from.address' => 'noreply@example.com']);
        config(['queue.default' => 'database']);

        $user = User::factory()->create(['email_verified_at' => null]);

        $dispatched = $this->app->make(EmailVerificationServiceInterface::class)
            ->issueAndDispatch($user);

        $this->assertFalse($dispatched, 'Dispatch must be refused, not silently logged.');
        $this->assertDatabaseCount('jobs', 0);
    }

    // =============================================================
    // No secret in logs
    // =============================================================

    public function test_raw_token_never_appears_in_the_application_log(): void
    {
        /** @var array<int, string> $captured */
        $captured = [];

        Log::listen(static function (MessageLogged $event) use (&$captured): void {
            $captured[] = $event->message.' '.(string) json_encode($event->context);
        });

        $user = User::factory()->create(['email_verified_at' => null]);
        $service = $this->app->make(EmailVerificationServiceInterface::class);

        $rawToken = $service->issue($user);
        $service->verify($user->email, 'not-the-right-token-at-all-but-64-chars-long-x');
        $service->verify('nobody@example.com', $rawToken);
        $service->resendForEmail($user->email);
        $service->resendForEmail('nobody@example.com');

        $haystack = implode("\n", $captured);

        $this->assertNotSame('', $haystack, 'Expected at least the diagnostic log lines.');
        $this->assertStringNotContainsString(
            $rawToken,
            $haystack,
            'The raw verification token must never be written to a log record.'
        );
    }

    public function test_failed_notification_hook_logs_metadata_only(): void
    {
        /** @var array<int, string> $captured */
        $captured = [];

        Log::listen(static function (MessageLogged $event) use (&$captured): void {
            $captured[] = $event->message.' '.(string) json_encode($event->context);
        });

        $user = User::factory()->create(['email_verified_at' => null]);
        $rawToken = 'z'.str_repeat('z', 63);

        $notification = new VerifyEmailNotification(
            $user,
            'https://example.test/verify-email?token='.$rawToken
        );

        // A transport failure carries an exception whose message could quote the
        // rendered mail. The hook must not propagate any of it.
        $notification->failed(new \RuntimeException('SMTP refused: '.$rawToken));

        $haystack = implode("\n", $captured);

        $this->assertStringNotContainsString($rawToken, $haystack);
        $this->assertStringContainsString('verification_dispatch_failed', $haystack);
        $this->assertStringContainsString((string) $user->id, $haystack);
    }

    // =============================================================
    // Token lifecycle: expiry, single use, replay
    // =============================================================

    public function test_expired_token_is_rejected(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $service = $this->app->make(EmailVerificationServiceInterface::class);

        $rawToken = $service->issue($user);
        $user->forceFill(['email_verification_token_expires_at' => now()->subMinute()])->saveQuietly();

        $this->assertSame(EmailVerificationOutcome::Failed, $service->verify($user->email, $rawToken));
        $this->assertNull($user->fresh()?->email_verified_at);
    }

    public function test_valid_token_is_accepted_and_consumed(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $service = $this->app->make(EmailVerificationServiceInterface::class);

        $rawToken = $service->issue($user);

        $this->assertSame(EmailVerificationOutcome::Verified, $service->verify($user->email, $rawToken));

        $fresh = $user->fresh();

        $this->assertNotNull($fresh?->email_verified_at);
        $this->assertNull($fresh->email_verification_token, 'Consuming a token must clear the stored hash.');
        $this->assertNull($fresh->email_verification_token_expires_at);
    }

    public function test_token_cannot_be_replayed_after_use(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $service = $this->app->make(EmailVerificationServiceInterface::class);

        $rawToken = $service->issue($user);

        $this->assertSame(EmailVerificationOutcome::Verified, $service->verify($user->email, $rawToken));
        $this->assertSame(
            EmailVerificationOutcome::Failed,
            $service->verify($user->email, $rawToken),
            'A consumed token must not verify a second time.'
        );
    }

    public function test_issue_replaces_any_previous_token(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $service = $this->app->make(EmailVerificationServiceInterface::class);

        $first = $service->issue($user);
        $second = $service->issue($user);

        $this->assertNotSame($first, $second);
        $this->assertSame(
            EmailVerificationOutcome::Failed,
            $service->verify($user->email, $first),
            'A superseded token must stop working.'
        );
        $this->assertSame(EmailVerificationOutcome::Verified, $service->verify($user->email, $second));
    }

    public function test_wrong_token_is_rejected(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $service = $this->app->make(EmailVerificationServiceInterface::class);
        $service->issue($user);

        $this->assertSame(
            EmailVerificationOutcome::Failed,
            $service->verify($user->email, \Illuminate\Support\Str::random(64))
        );
        $this->assertNull($user->fresh()?->email_verified_at);
    }

    // =============================================================
    // HTTP contract
    // =============================================================

    /**
     * SECURITY: the unauthenticated endpoint must not distinguish any of the four
     * states an address can be in.
     */
    public function test_verify_email_endpoint_is_indistinguishable_across_account_states(): void
    {
        User::factory()->create([
            'email' => 'state-unverified@test.com',
            'email_verified_at' => null,
            'email_verification_token' => Hash::make('a'.str_repeat('a', 63)),
            'email_verification_token_expires_at' => now()->addHour(),
        ]);

        User::factory()->create([
            'email' => 'state-verified@test.com',
            'email_verified_at' => now(),
        ]);

        $unknown = $this->postJson('/api/v1/auth/verify-email', [
            'email' => 'state-unknown@test.com',
            'token' => 'a'.str_repeat('a', 63),
        ]);

        $unverifiedWrongToken = $this->postJson('/api/v1/auth/verify-email', [
            'email' => 'state-unverified@test.com',
            'token' => 'b'.str_repeat('b', 63),
        ]);

        $alreadyVerified = $this->postJson('/api/v1/auth/verify-email', [
            'email' => 'state-verified@test.com',
            'token' => 'a'.str_repeat('a', 63),
        ]);

        foreach ([$unknown, $unverifiedWrongToken, $alreadyVerified] as $response) {
            $this->assertSame(400, $response->status());
            $this->assertFalse((bool) $response->json('success'));
            $this->assertSame('VERIFICATION_FAILED', $response->json('code'));
        }

        $this->assertSame($unknown->json(), $unverifiedWrongToken->json());
        $this->assertSame($unknown->json(), $alreadyVerified->json());
    }

    /**
     * The resend endpoint must be uniform in the same way.
     */
    public function test_resend_verification_is_indistinguishable_across_account_states(): void
    {
        User::factory()->create([
            'email' => 'resend-unverified@test.com',
            'email_verified_at' => null,
        ]);
        User::factory()->create([
            'email' => 'resend-verified@test.com',
            'email_verified_at' => now(),
        ]);

        $unknown = $this->postJson('/api/v1/auth/resend-verification', ['email' => 'resend-unknown@test.com']);
        $unverified = $this->postJson('/api/v1/auth/resend-verification', ['email' => 'resend-unverified@test.com']);
        $verified = $this->postJson('/api/v1/auth/resend-verification', ['email' => 'resend-verified@test.com']);

        $this->assertSame(200, $unknown->status());
        $this->assertSame($unknown->json(), $unverified->json());
        $this->assertSame($unknown->json(), $verified->json());
    }

    // =============================================================
    // Helpers
    // =============================================================

    /**
     * Force the application environment for the duration of one assertion.
     */
    private function forceEnvironment(string $environment): void
    {
        $this->app->detectEnvironment(static fn (): string => $environment);
    }

    /**
     * Flip the console/web flag on the container.
     *
     * The test suite always runs under the CLI SAPI, so the web-process branch of
     * the guard is otherwise unreachable from a test. Laravel memoises the flag
     * in a protected property, which reflection can set.
     */
    private function setRunningInConsole(bool $runningInConsole): void
    {
        $property = new \ReflectionProperty($this->app, 'isRunningInConsole');
        $property->setAccessible(true);
        $property->setValue($this->app, $runningInConsole);
    }
}
