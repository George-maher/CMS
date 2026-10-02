<?php

namespace Tests\Feature;

use App\Http\Middleware\AssignRequestId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Correlation id behaviour.
 *
 * The security-relevant part is the validation of an inbound id: an
 * unvalidated header is written into every log line for that request, so
 * accepting arbitrary bytes is log injection and log-volume amplification.
 */
class RequestIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_request_id_is_generated_when_absent(): void
    {
        $response = $this->postJson('/api/v1/church-applications/lookup', ['phone' => '0100000000']);

        $id = $response->headers->get(AssignRequestId::HEADER);

        $this->assertNotNull($id, 'every API response carries a correlation id.');
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', (string) $id);
    }

    public function test_a_safe_client_supplied_id_is_preserved(): void
    {
        $response = $this->withHeader(AssignRequestId::HEADER, 'client-trace-42')
            ->postJson('/api/v1/church-applications/lookup', ['phone' => '0100000000']);

        $this->assertSame('client-trace-42', $response->headers->get(AssignRequestId::HEADER));
    }

    public function test_ulid_style_ids_from_other_systems_are_preserved(): void
    {
        $ulid = '01HQ8R5KJ3M9Z7X2V4B6C8D0EF';

        $response = $this->withHeader(AssignRequestId::HEADER, $ulid)
            ->postJson('/api/v1/church-applications/lookup', ['phone' => '0100000000']);

        $this->assertSame($ulid, $response->headers->get(AssignRequestId::HEADER));
    }

    /**
     * A caller must not be able to inject newlines or forge log lines through
     * the header, nor blow up log volume with an unbounded value.
     */
    public function test_unsafe_client_supplied_ids_are_replaced(): void
    {
        $hostile = [
            "abc\nERROR forged log line",
            "abc\r\nX-Injected: 1",
            str_repeat('a', 65),
            'has space',
            'semi;colon',
            '',
        ];

        foreach ($hostile as $candidate) {
            $response = $this->withHeader(AssignRequestId::HEADER, $candidate)
                ->postJson('/api/v1/church-applications/lookup', ['phone' => '0100000000']);

            $id = (string) $response->headers->get(AssignRequestId::HEADER);

            $this->assertNotSame($candidate, $id, 'a rejected inbound id must not be echoed back.');
            $this->assertMatchesRegularExpression(
                '/^[0-9A-HJKMNP-TV-Z]{26}$/',
                $id,
                'an unsafe inbound id must be replaced with a generated ULID.'
            );
        }
    }

    /**
     * The id must be present on error responses too — that is precisely when
     * an operator needs it.
     */
    public function test_the_id_is_returned_on_error_responses(): void
    {
        $response = $this->getJson('/api/v1/platform/dashboard');

        $this->assertNotNull($response->headers->get(AssignRequestId::HEADER));

        $response = $this->postJson('/api/v1/auth/login', ['email' => 'nobody@test.com', 'password' => 'x']);

        $this->assertNotNull($response->headers->get(AssignRequestId::HEADER));
    }

    /**
     * `request_id` is the correlation key.
     *
     * `AssignRequestId` publishes it through `Log::withContext()`, so every
     * log line for a request carries it. Laravel merges call-site context OVER
     * the global context, which means any service that logs its own
     * `request_id` silently replaces the correlation ULID with an unrelated
     * value — and an operator grepping `request_id` gets a mix of ULIDs and
     * integers that cannot be correlated to anything.
     *
     * Two services did exactly that, passing a database primary key under this
     * name. The keys are now `password_reset_request_id` and
     * `profile_update_request_id`.
     *
     * This is a source-level guard because the defect is a name collision; it
     * cannot be observed from a single response.
     */
    public function test_no_service_shadows_the_correlation_request_id_key(): void
    {
        $services = [
            'password_reset_request_id' => 'PasswordResetRequestService',
            'profile_update_request_id' => 'ProfileUpdateRequestService',
        ];

        foreach ($services as $key => $class) {
            $path = app_path("Services/{$class}.php");
            $this->assertFileExists($path);

            $source = (string) file_get_contents($path);

            // Only the corrected, unambiguous keys are allowed. A bare
            // `'request_id' => $request->id` is the collision itself.
            $this->assertStringNotContainsString(
                "'request_id' =>",
                $source,
                "{$class} must not log a database id under 'request_id'; that key is the "
                .'request correlation id set by Log::withContext(). Use the '
                .'entity-specific key instead.'
            );
            $this->assertStringContainsString(
                "'{$key}' =>",
                $source,
                "{$class} should still log the entity id, under '{$key}'."
            );
        }
    }

    /**
     * The platform-admin login path is a security control.
     *
     * It used to be written to the `info` log on every attempt, which ships
     * the secret path to whatever log sink the deployment uses. Debug-level
     * logging of that path must not name the path.
     */
    public function test_the_secret_platform_login_path_is_not_logged_at_info_level(): void
    {
        $source = (string) file_get_contents(
            app_path('Http/Middleware/ForceJsonResponse.php')
        );

        $this->assertStringNotContainsString(
            "Log::info('[DEBUG]",
            $source,
            'A [DEBUG] statement running at info level ships to production logs.'
        );

        // The path must not be echoed into any log context at any level that
        // is enabled by default.
        $this->assertDoesNotMatchRegularExpression(
            "/Log::(info|warning|error)\([^)]*'path'\s*=>/s",
            $source,
            'The platform login path must not be written to a production-level log context.'
        );
    }
}
