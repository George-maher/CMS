<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Church;
use App\Models\Classe;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * PHASE 1C — C-4: abort() must render a typed 4xx envelope, not INTERNAL_ERROR.
 *
 * Why this file exists:
 *
 * Application::abort() throws a PLAIN Symfony HttpException
 * (Application.php:1440 — only 404 is special-cased into
 * NotFoundHttpException). Handler::render() runs renderViaCallbacks()
 * (Handler.php:712-725), which walks callbacks in REGISTRATION order and
 * returns the first non-null response, matching on is_a(). The typed
 * callbacks in bootstrap/app.php cover NotFound, AccessDenied and Throttle
 * SUBCLASSES, so a plain HttpException from abort(403) matched NONE of them
 * and fell through to the catch-all Throwable callback — which returns the
 * correct HTTP status but labels the body INTERNAL_ERROR / "Internal server
 * error.".
 *
 * The single production call site is LeaderboardController::byClass():
 * a scoped user requesting another class's leaderboard gets a 403 whose body
 * claims an internal server error. No test asserted the 403 BODY, which is
 * why the suite stayed green.
 *
 * These tests drive the real route (not a mock) and assert the actual JSON
 * envelope, plus the two properties the fix must NOT break:
 *   - specialised subclasses still win (registration order preserved);
 *   - 5xx still reaches the catch-all, so genuine server errors keep their
 *     error-level log entry;
 *   - non-API requests still get the framework's HTML error page.
 */
class HttpExceptionResponseShapeTest extends TestCase
{
    use RefreshDatabase;

    private Church $church;

    private Stage $stage;

    private Classe $ownClasse;

    private Classe $otherClasse;

    private User $servant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->church = Church::factory()->create();
        $this->stage = Stage::factory()->forChurch($this->church)->create();
        $this->ownClasse = Classe::factory()->forChurch($this->church)
            ->state(['stage_id' => $this->stage->id])->create(['name' => 'Own Class']);
        $this->otherClasse = Classe::factory()->forChurch($this->church)
            ->state(['stage_id' => $this->stage->id])->create(['name' => 'Other Class']);

        // A servant holds `view_users` (so it passes the route's permission
        // middleware) but is class-scoped to Own Class, so the controller's
        // scope check aborts(403) for Other Class.
        $this->servant = User::factory()->create([
            'church_id' => $this->church->id,
            'stage_id' => $this->stage->id,
            'class_id' => $this->ownClasse->id,
            'role' => UserRole::Servant,
            'application_status' => 'approved',
        ]);
    }

    /**
     * The reproduction: abort(403) on a real API route must produce the
     * FORBIDDEN envelope — not the catch-all's INTERNAL_ERROR body.
     */
    public function test_abort_403_returns_a_forbidden_envelope_not_internal_error(): void
    {
        $response = $this->actingAs($this->servant)
            ->getJson("/api/v1/leaderboard/class/{$this->otherClasse->id}");

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'code' => 'FORBIDDEN',
        ]);
        $response->assertJsonPath(
            'message',
            'You can only view leaderboards for classes within your scope.'
        );

        $this->assertNotSame(
            'INTERNAL_ERROR',
            $response->json('code'),
            'A deliberate 403 must not be labelled as an internal server error.'
        );
        $this->assertNotSame(
            'Internal server error.',
            $response->json('message'),
            'The client must not be told a scope violation is a server failure.'
        );
    }

    /**
     * Regression guard for callback registration order: the specialised
     * subclasses (NotFound, AccessDenied, Throttle) are registered BEFORE the
     * generic HttpException slot and must keep winning via
     * renderViaCallbacks()' first-non-null-wins walk.
     */
    public function test_specialised_subclasses_still_win_over_the_generic_slot(): void
    {
        $response = $this->getJson('/api/v1/definitely-does-not-exist');

        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'code' => 'NOT_FOUND',
        ]);
    }

    /**
     * The generic slot must defer 5xx to the catch-all, so genuine server
     * failures keep their INTERNAL_ERROR body AND the Log::error entry the
     * catch-all writes (an expected 4xx must not be logged as an error).
     */
    public function test_5xx_http_exceptions_still_reach_the_catch_all(): void
    {
        Route::get('/api/_probe/abort-500', fn () => abort(500, 'Probe failure.'));

        $response = $this->getJson('/api/_probe/abort-500');

        $response->assertStatus(500);
        $response->assertJson([
            'success' => false,
            'code' => 'INTERNAL_ERROR',
        ]);
    }

    /**
     * Non-API requests must be left to the framework's default error
     * rendering — the JSON envelope is an API contract only.
     */
    public function test_non_api_requests_keep_the_framework_error_page(): void
    {
        $response = $this->get('/definitely-not-a-page');

        $response->assertStatus(404);

        $contentType = $response->headers->get('Content-Type') ?? '';
        $this->assertStringContainsString(
            'text/html',
            $contentType,
            'A web 404 must stay an HTML error page, not the API JSON envelope.'
        );
    }
}
