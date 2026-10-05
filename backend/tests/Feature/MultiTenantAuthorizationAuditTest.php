<?php

namespace Tests\Feature;

use App\Enums\QRInviteType;
use App\Enums\UserRole;
use App\Enums\UserScope;
use App\Models\AttendanceContext;
use App\Models\Church;
use App\Models\Classe;
use App\Models\Event;
use App\Models\Feedback;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\QRInvite;
use App\Models\Stage;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Multi-tenant authorization / role security audit — fail-first attack matrix.
 *
 * Every test in this file describes an ATTACK, not a feature:
 *
 *  Section 1  Anonymous access scan over every registered /api/v1 route.
 *  Section 2  Fail-closed behavior for an authenticated principal WITHOUT a
 *             church (church_id = null). AUD-0004/AUD-0005 — these tests
 *             FAILED against the unpatched code and are the fail-first
 *             evidence for the two remediations in this audit round.
 *  Section 3  Cross-church READ attacks (church A admin → church B data).
 *  Section 4  Cross-church WRITE / bulk / mass-assignment attacks.
 *  Section 5  Stage isolation (stage A1 admin → sibling stage A2 / church B).
 *  Section 6  Privilege-escalation payloads (role / church_id / stage_id).
 *
 * A green run means every attack was refused with a non-success response
 * (or produced no cross-tenant rows), verified against the database where
 * the HTTP status alone does not prove non-mutation.
 */
class MultiTenantAuthorizationAuditTest extends TestCase
{
    use RefreshDatabase;

    private Church $churchA;

    private Church $churchB;

    private Stage $stageA1;

    private Stage $stageA2;

    private Stage $stageB1;

    private Classe $classA1;

    private Classe $classA2;

    private Classe $classB1;

    private User $adminA;

    private User $stageAdminA1;

    private User $memberA1;

    private User $memberA2;

    private User $servantA1;

    private User $adminB;

    private User $stageAdminB1;

    private User $memberB1;

    private User $churchlessAdmin;

    private Event $eventB;

    private AttendanceContext $contextA;

    private AttendanceContext $contextB;

    private Feedback $feedbackB;

    private Notification $notificationB;

    private Notification $notificationA1;

    private QRInvite $inviteA;

    private QRInvite $inviteB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        Permission::clearCache();

        // --- Tenants ---------------------------------------------------
        $this->churchA = Church::factory()->create(['name' => 'Audit Church A']);
        $this->churchB = Church::factory()->create(['name' => 'Audit Church B']);

        $this->stageA1 = Stage::factory()->forChurch($this->churchA)->create(['name' => 'Audit Stage A1']);
        $this->stageA2 = Stage::factory()->forChurch($this->churchA)->create(['name' => 'Audit Stage A2']);
        $this->stageB1 = Stage::factory()->forChurch($this->churchB)->create(['name' => 'Audit Stage B1']);

        $this->classA1 = Classe::factory()->forChurch($this->churchA)
            ->state(['stage_id' => $this->stageA1->id])->create(['name' => 'Audit Class A1']);
        $this->classA2 = Classe::factory()->forChurch($this->churchA)
            ->state(['stage_id' => $this->stageA2->id])->create(['name' => 'Audit Class A2']);
        $this->classB1 = Classe::factory()->forChurch($this->churchB)
            ->state(['stage_id' => $this->stageB1->id])->create(['name' => 'Audit Class B1']);

        // --- Principals ------------------------------------------------
        $this->adminA = $this->makeUser(UserRole::Admin, $this->churchA, 'audit-admin-a@test.com');
        $this->stageAdminA1 = $this->makeUser(UserRole::StageAdmin, $this->churchA, 'audit-stage-admin-a1@test.com', [
            'stage_id' => $this->stageA1->id,
        ]);
        $this->memberA1 = $this->makeUser(UserRole::Member, $this->churchA, 'audit-member-a1@test.com', [
            'class_id' => $this->classA1->id,
            'member_id' => 'MEMBA001',
            'attendance_qr_token' => Str::random(60),
        ]);
        $this->memberA2 = $this->makeUser(UserRole::Member, $this->churchA, 'audit-member-a2@test.com', [
            'class_id' => $this->classA2->id,
            'member_id' => 'MEMBA002',
            'attendance_qr_token' => Str::random(60),
        ]);
        $this->servantA1 = $this->makeUser(UserRole::Servant, $this->churchA, 'audit-servant-a1@test.com', [
            'class_id' => $this->classA1->id,
        ]);

        $this->adminB = $this->makeUser(UserRole::Admin, $this->churchB, 'audit-admin-b@test.com');
        $this->stageAdminB1 = $this->makeUser(UserRole::StageAdmin, $this->churchB, 'audit-stage-admin-b1@test.com', [
            'stage_id' => $this->stageB1->id,
        ]);
        $this->memberB1 = $this->makeUser(UserRole::Member, $this->churchB, 'audit-member-b1@test.com', [
            'class_id' => $this->classB1->id,
            'member_id' => 'MEMBB001',
            'attendance_qr_token' => Str::random(60),
        ]);

        // Approved, valid token, church_id = null — the anomaly case that the
        // fail-closed rule (audit §14) must protect against.
        $this->churchlessAdmin = $this->makeUser(UserRole::Admin, null, 'audit-churchless@test.com');

        // --- Tenant B fixtures ----------------------------------------
        $this->eventB = Event::factory()->create([
            'name' => 'Audit Event B',
            'church_id' => $this->churchB->id,
            'class_year_id' => $this->classB1->id,
            'created_by' => $this->adminB->id,
        ]);
        $this->contextA = AttendanceContext::factory()->create([
            'church_id' => $this->churchA->id,
            'created_by' => $this->adminA->id,
        ]);
        $this->contextB = AttendanceContext::factory()->create([
            'church_id' => $this->churchB->id,
            'created_by' => $this->adminB->id,
        ]);
        $this->feedbackB = Feedback::create([
            'message' => 'Audit feedback for church B',
            'user_id' => $this->memberB1->id,
            'church_id' => $this->churchB->id,
            'is_resolved' => false,
            'is_anonymous' => false,
            'has_new_reply' => false,
        ]);
        $this->notificationB = Notification::create([
            'church_id' => $this->churchB->id,
            'user_id' => $this->memberB1->id,
            'title' => 'Audit notification B',
            'is_read' => false,
        ]);
        $this->notificationA1 = Notification::create([
            'church_id' => $this->churchA->id,
            'user_id' => $this->memberA1->id,
            'title' => 'Audit notification A1',
            'is_read' => false,
        ]);
        $this->inviteA = QRInvite::create([
            'type' => QRInviteType::ServantToMemberInvite->value,
            'token' => Str::random(60),
            'church_id' => $this->churchA->id,
            'created_by' => $this->adminA->id,
            'expires_at' => now()->addHours(4),
        ]);
        $this->inviteB = QRInvite::create([
            'type' => QRInviteType::ServantToMemberInvite->value,
            'token' => Str::random(60),
            'church_id' => $this->churchB->id,
            'created_by' => $this->adminB->id,
            'expires_at' => now()->addHours(4),
        ]);

        // Attendance rows: one foreign (church B) and one in the sibling
        // stage (A2). Both must stay invisible to the attackers below.
        DB::table('attendances')->insert([
            'user_id' => $this->memberB1->id,
            'recorded_by' => $this->adminB->id,
            'attended_at' => now()->subDay(),
            'attended_date' => now()->subDay()->toDateString(),
            'points_earned' => 0,
            'status' => 'present',
            'church_id' => $this->churchB->id,
            'class_year_id' => $this->classB1->id,
        ]);
        DB::table('attendances')->insert([
            'user_id' => $this->memberA2->id,
            'recorded_by' => $this->adminA->id,
            'attended_at' => now()->subDay(),
            'attended_date' => now()->subDay()->toDateString(),
            'points_earned' => 0,
            'status' => 'present',
            'church_id' => $this->churchA->id,
            'class_year_id' => $this->classA2->id,
        ]);
    }

    // =========================================================================
    // Section 1 — Anonymous access surface
    // =========================================================================

    public function test_non_public_api_routes_require_authentication(): void
    {
        // The scan issues one request per route; rate limiting would mask
        // authorization results with 429s, so throttling is disabled here
        // (authorization order — auth before throttle in practice — is still
        // asserted by every other test in this file).
        $this->withoutMiddleware([ThrottleRequests::class, ThrottleRequestsWithRedis::class]);

        $platformPath = (string) config('services.platform_admin_login_path', 'platform-secure-admin-login');

        /** @var array<int, string> $public */
        $public = [
            'POST api/v1/auth/login',
            'POST api/v1/auth/'.$platformPath,
            'POST api/v1/auth/register',
            'POST api/v1/auth/forgot-password',
            'POST api/v1/password-reset-requests',
            'POST api/v1/auth/verify-email',
            'POST api/v1/auth/resend-verification',
            'GET api/v1/qr/validate/{token}',
            'GET api/v1/invite/{token}',
            'POST api/v1/church-applications',
            'POST api/v1/church-applications/lookup',
            'POST api/v1/membership-requests',
        ];

        $checked = 0;
        $offenders = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if (! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                    continue;
                }
                if (in_array($method.' '.$uri, $public, true)) {
                    continue;
                }

                $path = '/'.preg_replace('/\{[^}]+\}/', '1', $uri);
                $response = match ($method) {
                    'GET' => $this->getJson($path),
                    'POST' => $this->postJson($path, []),
                    'PUT' => $this->putJson($path, []),
                    'PATCH' => $this->patchJson($path, []),
                    'DELETE' => $this->deleteJson($path),
                };

                $status = $response->getStatusCode();
                if ($status !== 401) {
                    $offenders[] = sprintf(
                        '%s %s -> %d %s',
                        $method,
                        $path,
                        $status,
                        mb_substr((string) $response->getContent(), 0, 200)
                    );
                }
                $checked++;
            }
        }

        $this->assertGreaterThan(100, $checked, 'The anonymous scan must cover the whole /api/v1 surface.');
        $this->assertSame(
            [],
            $offenders,
            "Anonymous access must be refused with 401 on every non-public route:\n".implode("\n", $offenders)
        );
    }

    // =========================================================================
    // Section 2 — Fail-closed for an authenticated principal without a church
    // (fail-first evidence for AUD-0004 / AUD-0005)
    // =========================================================================

    public function test_churchless_admin_gets_an_empty_user_directory(): void
    {
        $response = $this->getJson('/api/v1/users?per_page=100', $this->headers($this->churchlessAdmin));
        $response->assertStatus(200);

        $emails = array_column($response->json('data') ?? [], 'email');

        $this->assertSame(
            [],
            $emails,
            'A principal without a church must not read the global user directory; leaked: '.implode(', ', $emails)
        );
    }

    public function test_churchless_admin_cannot_resolve_a_foreign_attendance_qr(): void
    {
        $response = $this->getJson(
            '/api/v1/attendances/lookup/'.$this->memberB1->attendance_qr_token,
            $this->headers($this->churchlessAdmin)
        );

        $response->assertStatus(404);
    }

    public function test_churchless_admin_gets_zero_dashboard_aggregates(): void
    {
        $response = $this->getJson('/api/v1/dashboard/stats', $this->headers($this->churchlessAdmin));
        $response->assertStatus(200);

        $data = $response->json('data') ?? [];
        $this->assertSame(0, (int) ($data['total_members'] ?? -1), 'Global member counts leaked to a churchless principal.');
        $this->assertSame(0, (int) ($data['total_servants'] ?? -1), 'Global servant counts leaked to a churchless principal.');
        $this->assertSame(0, (int) ($data['total_attendances'] ?? -1), 'Global attendance counts leaked to a churchless principal.');
        $this->assertSame(0, (int) ($data['total_points'] ?? -1), 'Global point totals leaked to a churchless principal.');
    }

    // =========================================================================
    // Section 3 — Cross-church READ attacks (church A admin → church B)
    // =========================================================================

    public function test_admin_cannot_read_foreign_member_profile(): void
    {
        $this->getJson('/api/v1/member-profile/'.$this->memberB1->id, $this->headers($this->adminA))
            ->assertStatus(403);
    }

    public function test_admin_cannot_read_foreign_attendance_history(): void
    {
        $response = $this->getJson(
            '/api/v1/attendances/history/'.$this->memberB1->id,
            $this->headers($this->adminA)
        );
        $response->assertStatus(200);
        $this->assertSame(
            [],
            $response->json('data') ?? null,
            'Foreign attendance rows leaked through /attendances/history/{userId}.'
        );
    }

    public function test_admin_cannot_read_foreign_points_balance(): void
    {
        $this->assertDenied(
            $this->getJson('/api/v1/points/user/'.$this->memberB1->id.'/balance', $this->headers($this->adminA)),
            [403, 404],
            'GET /points/user/{foreign}/balance must not disclose another tenant\'s point balance'
        );
    }

    public function test_admin_cannot_read_foreign_points_history(): void
    {
        $this->assertDenied(
            $this->getJson('/api/v1/points/user/'.$this->memberB1->id.'/history', $this->headers($this->adminA)),
            [403, 404],
            'GET /points/user/{foreign}/history must not disclose another tenant\'s point history'
        );
    }

    public function test_admin_cannot_read_foreign_feedback(): void
    {
        $this->assertDenied(
            $this->getJson('/api/v1/feedback/'.$this->feedbackB->id, $this->headers($this->adminA)),
            [403, 404, 422],
            'GET /feedback/{foreign} must not disclose another tenant\'s feedback'
        );
    }

    public function test_admin_cannot_resolve_foreign_feedback(): void
    {
        $this->assertDenied(
            $this->patchJson('/api/v1/feedback/'.$this->feedbackB->id.'/resolve', [], $this->headers($this->adminA)),
            [403, 404, 422],
            'PATCH /feedback/{foreign}/resolve must not mutate another tenant\'s feedback'
        );
        $this->assertFalse(
            (bool) $this->feedbackB->fresh()->is_resolved,
            'Foreign feedback was resolved despite the denial.'
        );
    }

    public function test_admin_cannot_mark_foreign_feedback_seen(): void
    {
        $this->assertDenied(
            $this->postJson('/api/v1/feedback/'.$this->feedbackB->id.'/mark-seen', [], $this->headers($this->adminA)),
            [403, 404, 422],
            'POST /feedback/{foreign}/mark-seen must not mutate another tenant\'s feedback'
        );
        $this->assertFalse(
            (bool) $this->feedbackB->fresh()->has_new_reply,
            'Foreign feedback was mutated despite the denial.'
        );
    }

    public function test_admin_cannot_read_foreign_attendance_context(): void
    {
        $this->assertDenied(
            $this->getJson('/api/v1/attendance-contexts/'.$this->contextB->id, $this->headers($this->adminA)),
            [403, 404],
            'GET /attendance-contexts/{foreign} must not disclose another tenant\'s context'
        );
    }

    public function test_admin_cannot_delete_foreign_attendance_context(): void
    {
        $this->assertDenied(
            $this->deleteJson('/api/v1/attendance-contexts/'.$this->contextB->id, [], $this->headers($this->adminA)),
            [403, 404],
            'DELETE /attendance-contexts/{foreign} must not delete another tenant\'s context'
        );
        $this->assertSame(
            1,
            DB::table('attendance_contexts')->where('id', $this->contextB->id)->count(),
            'Foreign attendance context was deleted despite the denial.'
        );
    }

    public function test_admin_cannot_read_foreign_event(): void
    {
        $this->assertDenied(
            $this->getJson('/api/v1/events/'.$this->eventB->id, $this->headers($this->adminA)),
            [403, 404],
            'GET /events/{foreign} must not disclose another tenant\'s event'
        );
    }

    public function test_admin_cannot_update_foreign_event(): void
    {
        $this->assertDenied(
            $this->putJson(
                '/api/v1/events/'.$this->eventB->id,
                ['name' => 'Hacked by church A', 'is_active' => true],
                $this->headers($this->adminA)
            ),
            [403, 404, 422],
            'PUT /events/{foreign} must not mutate another tenant\'s event'
        );
        $this->assertSame(
            'Audit Event B',
            DB::table('events')->where('id', $this->eventB->id)->value('name'),
            'Foreign event name was mutated despite the denial.'
        );
    }

    public function test_admin_cannot_delete_foreign_event(): void
    {
        $this->assertDenied(
            $this->deleteJson('/api/v1/events/'.$this->eventB->id, [], $this->headers($this->adminA)),
            [403, 404],
            'DELETE /events/{foreign} must not delete another tenant\'s event'
        );
        $this->assertSame(
            1,
            DB::table('events')->where('id', $this->eventB->id)->count(),
            'Foreign event was deleted despite the denial.'
        );
    }

    public function test_admin_cannot_read_foreign_class_leaderboard(): void
    {
        $this->assertDenied(
            $this->getJson('/api/v1/leaderboard/class/'.$this->classB1->id, $this->headers($this->adminA)),
            [403, 404],
            'GET /leaderboard/class/{foreign} must not disclose another tenant\'s leaderboard'
        );
    }

    public function test_admin_foreign_class_attendance_returns_no_foreign_rows(): void
    {
        $response = $this->getJson(
            '/api/v1/attendances/by-class/'.$this->classB1->id,
            $this->headers($this->adminA)
        );
        $response->assertStatus(200);

        $this->assertSame(
            0,
            (int) $response->json('count'),
            'GET /attendances/by-class/{foreign class} returned another tenant\'s attendance rows.'
        );
    }

    public function test_admin_cannot_read_foreign_spiritual_records(): void
    {
        $this->getJson(
            '/api/v1/spiritual-records?member_id='.$this->memberB1->id,
            $this->headers($this->adminA)
        )->assertStatus(404);
    }

    public function test_admin_cannot_read_foreign_user(): void
    {
        $this->assertDenied(
            $this->getJson('/api/v1/users/'.$this->memberB1->id, $this->headers($this->adminA)),
            [403, 404],
            'GET /users/{foreign} must not disclose another tenant\'s user record'
        );
    }

    public function test_users_directory_is_scoped_to_own_church(): void
    {
        $response = $this->getJson('/api/v1/users?per_page=100', $this->headers($this->adminA));
        $response->assertStatus(200);

        $emails = array_column($response->json('data') ?? [], 'email');

        $this->assertContains($this->adminA->email, $emails, 'Own-tenant users must still be visible (positive control).');
        $this->assertNotContains($this->adminB->email, $emails, 'Church B admin leaked into church A\'s user directory.');
        $this->assertNotContains($this->memberB1->email, $emails, 'Church B member leaked into church A\'s user directory.');
    }

    public function test_qr_invite_list_is_scoped_to_own_church(): void
    {
        $response = $this->getJson('/api/v1/qr/invites?per_page=100', $this->headers($this->adminA));
        $response->assertStatus(200);

        $ids = array_column($response->json('data') ?? [], 'id');

        $this->assertContains($this->inviteA->id, $ids, 'Own-tenant invites must still be visible (positive control).');
        $this->assertNotContains($this->inviteB->id, $ids, 'Church B invite leaked into church A\'s invite list.');
    }

    public function test_parameterized_servants_route_is_removed(): void
    {
        // The misleading `GET /users/{id}/servants` route (its {id} was
        // ignored server-side) was removed — it must no longer resolve.
        $this->getJson('/api/v1/users/'.$this->memberB1->id.'/servants', $this->headers($this->adminA))
            ->assertStatus(404);
    }

    public function test_servants_endpoint_is_scoped_to_own_church(): void
    {
        // The supported `GET /users/servants` must contain only the caller's
        // church servants.
        $response = $this->getJson('/api/v1/users/servants', $this->headers($this->adminA));
        $response->assertStatus(200);

        $emails = array_column($response->json('data') ?? [], 'email');

        $this->assertContains($this->servantA1->email, $emails, 'Own-tenant servants must still be visible (positive control).');
        $this->assertNotContains($this->stageAdminB1->email, $emails, 'Church B staff leaked through /users/servants.');
    }

    public function test_notification_mark_read_is_scoped_to_the_owning_user(): void
    {
        // Cross-church notification.
        $this->postJson('/api/v1/notifications/'.$this->notificationB->id.'/mark-read', [], $this->headers($this->adminA));
        $this->assertFalse(
            (bool) $this->notificationB->fresh()->is_read,
            'A foreign user\'s notification was mutated by another tenant.'
        );

        // Same church, different user (horizontal ownership check).
        $this->postJson('/api/v1/notifications/'.$this->notificationA1->id.'/mark-read', [], $this->headers($this->adminA));
        $this->assertFalse(
            (bool) $this->notificationA1->fresh()->is_read,
            'A user may only mark their OWN notifications as read.'
        );
    }

    // =========================================================================
    // Section 4 — Cross-church WRITE / bulk / mass-assignment attacks
    // =========================================================================

    public function test_admin_cannot_promote_foreign_user(): void
    {
        $this->assertDenied(
            $this->postJson(
                '/api/v1/users/'.$this->memberB1->id.'/promote',
                ['role' => UserRole::Servant->value],
                $this->headers($this->adminA)
            ),
            [403, 404],
            'POST /users/{foreign}/promote must not mutate another tenant\'s user'
        );
        $this->assertSame(
            UserRole::Member->value,
            DB::table('users')->where('id', $this->memberB1->id)->value('role'),
            'Foreign user role was changed despite the denial.'
        );
    }

    public function test_admin_cannot_update_foreign_user(): void
    {
        $this->assertDenied(
            $this->patchJson(
                '/api/v1/users/'.$this->memberB1->id,
                ['name' => 'Hacked by church A'],
                $this->headers($this->adminA)
            ),
            [403, 404],
            'PATCH /users/{foreign} must not mutate another tenant\'s user'
        );
        $this->assertSame(
            $this->memberB1->name,
            DB::table('users')->where('id', $this->memberB1->id)->value('name'),
            'Foreign user name was changed despite the denial.'
        );
    }

    public function test_admin_cannot_delete_foreign_user(): void
    {
        $this->assertDenied(
            $this->deleteJson('/api/v1/users/'.$this->memberB1->id, [], $this->headers($this->adminA)),
            [403, 404],
            'DELETE /users/{foreign} must not delete another tenant\'s user'
        );
        $this->assertTrue(
            (bool) DB::table('users')->where('id', $this->memberB1->id)->exists(),
            'Foreign user was deleted despite the denial.'
        );
    }

    public function test_admin_cannot_create_class_in_foreign_stage(): void
    {
        $before = DB::table('classes')->where('stage_id', $this->stageB1->id)->count();

        $this->assertDenied(
            $this->postJson(
                '/api/v1/classes',
                ['name' => 'Injected Class', 'stage_id' => $this->stageB1->id],
                $this->headers($this->adminA)
            ),
            [403, 404],
            'POST /classes with a foreign stage_id must not plant a class in another tenant'
        );
        $this->assertSame(
            $before,
            DB::table('classes')->where('stage_id', $this->stageB1->id)->count(),
            'A class was created under a foreign stage despite the denial.'
        );
    }

    public function test_admin_cannot_bulk_create_classes_in_foreign_stage(): void
    {
        $before = DB::table('classes')->where('stage_id', $this->stageB1->id)->count();

        $this->assertDenied(
            $this->postJson(
                '/api/v1/stages/'.$this->stageB1->id.'/classes/bulk',
                ['count' => 2],
                $this->headers($this->adminA)
            ),
            [403, 404],
            'POST /stages/{foreign}/classes/bulk must not create classes in another tenant'
        );
        $this->assertSame(
            $before,
            DB::table('classes')->where('stage_id', $this->stageB1->id)->count(),
            'Classes were bulk-created under a foreign stage despite the denial.'
        );
    }

    public function test_admin_cannot_assign_member_to_foreign_class(): void
    {
        $this->assertDenied(
            $this->postJson(
                '/api/v1/classes/'.$this->classB1->id.'/assign-member',
                ['user_id' => $this->memberA1->id],
                $this->headers($this->adminA)
            ),
            [403, 404, 422],
            'POST /classes/{foreign}/assign-member must not bind a member to another tenant\'s class'
        );
        $this->assertSame(
            $this->classA1->id,
            (int) DB::table('users')->where('id', $this->memberA1->id)->value('class_id'),
            'Member class assignment was changed despite the denial.'
        );
    }

    public function test_mixed_tenant_reorder_is_rejected_wholesale(): void
    {
        $this->postJson(
            '/api/v1/classes/reorder',
            ['ordered_ids' => [$this->classA1->id, $this->classB1->id]],
            $this->headers($this->adminA)
        )->assertStatus(422);

        $this->assertSame(
            1,
            DB::table('classes')->where('id', $this->classB1->id)->count(),
            'A mixed-tenant reorder must not remove or mutate the foreign class.'
        );
    }

    public function test_admin_cannot_award_bonus_points_to_foreign_member(): void
    {
        $this->assertDenied(
            $this->postJson(
                '/api/v1/points/bonus',
                ['user_id' => $this->memberB1->id, 'points' => 50, 'reason' => 'audit'],
                $this->headers($this->adminA)
            ),
            [403, 404, 422],
            'POST /points/bonus must not award points to another tenant\'s member'
        );
        $this->assertSame(
            0,
            DB::table('points')->where('user_id', $this->memberB1->id)->count(),
            'Points were awarded to a foreign member despite the denial.'
        );
    }

    public function test_admin_cannot_record_attendance_for_foreign_member_qr(): void
    {
        $before = DB::table('attendances')->where('user_id', $this->memberB1->id)->count();

        // Non-admin actors are refused by the controller pre-check (403);
        // admins fall through to the service, which re-resolves the token
        // under the caller's church scope and refuses it (422). Either way
        // the write must not happen — the DB assertion below is the proof.
        $this->assertDenied(
            $this->postJson('/api/v1/attendances/record', [
                'qr_token' => $this->memberB1->attendance_qr_token,
                'attendance_context_id' => $this->contextA->id,
            ], $this->headers($this->adminA)),
            [403, 422],
            'POST /attendances/record with a foreign QR token must be refused'
        );

        $this->assertSame(
            $before,
            DB::table('attendances')->where('user_id', $this->memberB1->id)->count(),
            'Attendance was recorded for a foreign member despite the denial.'
        );
    }

    public function test_admin_cannot_record_attendance_for_foreign_member_id(): void
    {
        $before = DB::table('attendances')->where('user_id', $this->memberB1->id)->count();

        $this->assertDenied(
            $this->postJson('/api/v1/attendances/record-by-member-id', [
                'member_id' => 'MEMBB001',
                'attendance_context_id' => $this->contextA->id,
            ], $this->headers($this->adminA)),
            [403, 422],
            'POST /attendances/record-by-member-id must refuse a foreign member_id'
        );

        $this->assertSame(
            $before,
            DB::table('attendances')->where('user_id', $this->memberB1->id)->count(),
            'Attendance was recorded for a foreign member_id despite the denial.'
        );
    }

    public function test_admin_cannot_create_invite_for_foreign_class(): void
    {
        $this->assertDenied(
            $this->postJson('/api/v1/qr/invites', [
                'type' => QRInviteType::ServantToMemberInvite->value,
                'class_id' => $this->classB1->id,
            ], $this->headers($this->adminA)),
            [403, 422],
            'POST /qr/invites with a foreign class_id must not create an invite in another tenant'
        );
        $this->assertSame(
            0,
            DB::table('qr_invites')->where('class_id', $this->classB1->id)->count(),
            'An invite bound to a foreign class was created despite the denial.'
        );
    }

    public function test_stage_create_ignores_client_supplied_church_id(): void
    {
        $maxId = (int) DB::table('stages')->max('id');

        $this->postJson('/api/v1/stages', [
            'name' => 'Injected Stage',
            'church_id' => $this->churchB->id,
        ], $this->headers($this->adminA))->assertStatus(201);

        $row = DB::table('stages')->where('id', '>', $maxId)->first();
        $this->assertNotNull($row, 'Stage was not created (positive control).');
        $this->assertEquals(
            $this->churchA->id,
            $row->church_id,
            'Client-supplied church_id was trusted on stage creation.'
        );
    }

    public function test_stage_bulk_create_ignores_client_supplied_church_id(): void
    {
        $maxId = (int) DB::table('stages')->max('id');
        $beforeB = DB::table('stages')->where('church_id', $this->churchB->id)->count();

        $this->postJson('/api/v1/stages/bulk', [
            'count' => 2,
            'church_id' => $this->churchB->id,
        ], $this->headers($this->adminA))->assertStatus(201);

        $rows = DB::table('stages')->where('id', '>', $maxId)->get();
        $this->assertCount(2, $rows, 'Bulk stage creation must create the requested number of stages.');
        foreach ($rows as $row) {
            $this->assertEquals($this->churchA->id, $row->church_id, 'Client-supplied church_id was trusted on bulk stage creation.');
        }
        $this->assertSame(
            $beforeB,
            DB::table('stages')->where('church_id', $this->churchB->id)->count(),
            'Foreign stage count changed despite tenant-scoped creation.'
        );
    }

    // =========================================================================
    // Section 5 — Stage isolation (stage A1 admin attacks)
    // =========================================================================

    public function test_stage_admin_cannot_read_sibling_class(): void
    {
        $this->assertDenied(
            $this->getJson('/api/v1/classes/'.$this->classA2->id, $this->headers($this->stageAdminA1)),
            [403, 404],
            'GET /classes/{sibling stage} must be refused to a stage admin'
        );
    }

    public function test_stage_admin_cannot_delete_sibling_class(): void
    {
        $this->assertDenied(
            $this->deleteJson('/api/v1/classes/'.$this->classA2->id, [], $this->headers($this->stageAdminA1)),
            [403, 404],
            'DELETE /classes/{sibling stage} must be refused to a stage admin'
        );
        $this->assertSame(
            1,
            DB::table('classes')->where('id', $this->classA2->id)->count(),
            'Sibling-stage class was deleted despite the denial.'
        );
    }

    public function test_stage_admin_cannot_read_foreign_church_stage(): void
    {
        $this->assertDenied(
            $this->getJson('/api/v1/stages/'.$this->stageB1->id, $this->headers($this->stageAdminA1)),
            [403, 404],
            'GET /stages/{other church} must be refused to a stage admin'
        );
    }

    public function test_stage_admin_cannot_update_foreign_church_class(): void
    {
        $this->assertDenied(
            $this->putJson(
                '/api/v1/classes/'.$this->classB1->id,
                ['name' => 'Hacked by stage A1'],
                $this->headers($this->stageAdminA1)
            ),
            [403, 404],
            'PUT /classes/{other church} must be refused to a stage admin'
        );
        $this->assertSame(
            'Audit Class B1',
            DB::table('classes')->where('id', $this->classB1->id)->value('name'),
            'Foreign class name was changed despite the denial.'
        );
    }

    public function test_stage_admin_cannot_promote_sibling_stage_member(): void
    {
        $this->assertDenied(
            $this->postJson(
                '/api/v1/users/'.$this->memberA2->id.'/promote',
                ['role' => UserRole::Servant->value],
                $this->headers($this->stageAdminA1)
            ),
            [403, 404],
            'POST /users/{sibling stage member}/promote must be refused to a stage admin'
        );
        $this->assertSame(
            UserRole::Member->value,
            DB::table('users')->where('id', $this->memberA2->id)->value('role'),
            'Sibling-stage member role was changed despite the denial.'
        );
    }

    public function test_stage_admin_cannot_self_promote_to_admin(): void
    {
        $this->postJson(
            '/api/v1/users/'.$this->stageAdminA1->id.'/promote',
            ['role' => UserRole::Admin->value],
            $this->headers($this->stageAdminA1)
        )->assertStatus(403);

        $this->assertSame(
            UserRole::StageAdmin->value,
            DB::table('users')->where('id', $this->stageAdminA1->id)->value('role'),
            'A stage admin escalated themselves to church admin.'
        );
    }

    public function test_stage_admin_cannot_self_promote_to_sibling_stage(): void
    {
        $this->postJson(
            '/api/v1/users/'.$this->stageAdminA1->id.'/promote',
            ['role' => UserRole::StageAdmin->value, 'stage_id' => $this->stageA2->id],
            $this->headers($this->stageAdminA1)
        )->assertStatus(403);

        $this->assertEquals(
            $this->stageA1->id,
            DB::table('users')->where('id', $this->stageAdminA1->id)->value('stage_id'),
            'A stage admin moved themselves into a sibling stage.'
        );
    }

    public function test_stage_admin_attendance_history_falls_back_to_self(): void
    {
        $response = $this->getJson(
            '/api/v1/attendances/history/'.$this->memberA2->id,
            $this->headers($this->stageAdminA1)
        );
        $response->assertStatus(200);

        $this->assertSame(
            [],
            $response->json('data') ?? null,
            'Sibling-stage attendance rows leaked through /attendances/history.'
        );
    }

    public function test_stage_admin_cannot_view_sibling_stage_member_profile(): void
    {
        $this->getJson('/api/v1/member-profile/'.$this->memberA2->id, $this->headers($this->stageAdminA1))
            ->assertStatus(403);
    }

    // =========================================================================
    // Section 6 — Privilege-escalation payloads
    // =========================================================================

    public function test_member_cannot_list_users(): void
    {
        $this->getJson('/api/v1/users', $this->headers($this->memberA1))->assertStatus(403);
    }

    public function test_member_cannot_create_users(): void
    {
        $this->postJson('/api/v1/users', [
            'name' => 'Escalated',
            'email' => 'audit-escalated@test.com',
            'password' => 'Escalated1!',
            'password_confirmation' => 'Escalated1!',
            'role' => UserRole::Admin->value,
        ], $this->headers($this->memberA1))->assertStatus(403);

        $this->assertSame(
            0,
            DB::table('users')->where('email', 'audit-escalated@test.com')->count(),
            'A member created a user account despite the denial.'
        );
    }

    public function test_member_cannot_promote_users(): void
    {
        $this->postJson(
            '/api/v1/users/'.$this->memberA1->id.'/promote',
            ['role' => UserRole::Servant->value],
            $this->headers($this->memberA1)
        )->assertStatus(403);
    }

    public function test_member_cannot_change_own_role_via_user_update(): void
    {
        $this->putJson('/api/v1/users/'.$this->memberA1->id, [
            'role' => UserRole::AssistantAdmin->value,
        ], $this->headers($this->memberA1))->assertStatus(403);

        $this->assertSame(
            UserRole::Member->value,
            DB::table('users')->where('id', $this->memberA1->id)->value('role'),
            'A member escalated their own role via PUT /users/{self}.'
        );
    }

    public function test_member_cannot_change_own_role_via_profile_update(): void
    {
        $this->putJson('/api/v1/profile', [
            'role' => UserRole::Admin->value,
            'church_id' => $this->churchB->id,
        ], $this->headers($this->memberA1))->assertStatus(403);

        $row = DB::table('users')->where('id', $this->memberA1->id)->first();
        $this->assertSame(UserRole::Member->value, $row->role, 'A member escalated their own role via PUT /profile.');
        $this->assertEquals($this->churchA->id, $row->church_id, 'A member moved themselves to another tenant via PUT /profile.');
    }

    public function test_servant_cannot_promote_users(): void
    {
        $this->postJson(
            '/api/v1/users/'.$this->memberA1->id.'/promote',
            ['role' => UserRole::Servant->value],
            $this->headers($this->servantA1)
        )->assertStatus(403);
    }

    public function test_stage_admin_cannot_escalate_own_role_or_church(): void
    {
        $this->putJson('/api/v1/users/'.$this->stageAdminA1->id, [
            'role' => UserRole::Admin->value,
            'church_id' => $this->churchB->id,
        ], $this->headers($this->stageAdminA1))->assertStatus(403);

        $row = DB::table('users')->where('id', $this->stageAdminA1->id)->first();
        $this->assertSame(UserRole::StageAdmin->value, $row->role, 'A stage admin escalated their own role.');
        $this->assertEquals($this->churchA->id, $row->church_id, 'A stage admin moved themselves to another tenant.');
    }

    public function test_stage_admin_cannot_move_member_to_sibling_stage(): void
    {
        $this->putJson('/api/v1/users/'.$this->memberA1->id, [
            'stage_id' => $this->stageA2->id,
        ], $this->headers($this->stageAdminA1))->assertStatus(403);

        $this->assertNull(
            DB::table('users')->where('id', $this->memberA1->id)->value('stage_id'),
            'A stage admin planted a member into a sibling stage.'
        );
    }

    public function test_stage_admin_cannot_create_stage_admin(): void
    {
        $this->assertDenied(
            $this->postJson('/api/v1/users', [
                'name' => 'Planted Stage Admin',
                'email' => 'audit-planted-stage-admin@test.com',
                'password' => 'Planted1!',
                'password_confirmation' => 'Planted1!',
                'role' => UserRole::StageAdmin->value,
                'stage_id' => $this->stageA2->id,
                'class_id' => $this->classA1->id,
            ], $this->headers($this->stageAdminA1)),
            [403, 422],
            'A stage admin must not be able to mint another stage admin'
        );

        $this->assertSame(
            0,
            DB::table('users')->where('email', 'audit-planted-stage-admin@test.com')->count(),
            'A stage admin created a stage-admin account despite the denial.'
        );
    }

    public function test_stage_admin_cannot_plant_a_foreign_church_on_create(): void
    {
        $this->postJson('/api/v1/users', [
            'name' => 'Injected Church Member',
            'email' => 'audit-injected-church@test.com',
            'password' => 'Injected1!',
            'password_confirmation' => 'Injected1!',
            'role' => UserRole::Member->value,
            'class_id' => $this->classA1->id,
            'church_id' => $this->churchB->id,
        ], $this->headers($this->stageAdminA1))->assertStatus(201);

        $row = DB::table('users')->where('email', 'audit-injected-church@test.com')->first();
        $this->assertNotNull($row, 'User creation failed unexpectedly (positive control).');
        $this->assertEquals(
            $this->churchA->id,
            $row->church_id,
            'Client-supplied church_id was trusted on user creation.'
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @return array<string, string>
     */
    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('audit-token')->plainTextToken];
    }

    /**
     * The attack is "refused" when the response is one of the deny statuses —
     * never a 2xx and never a 5xx (a 500 on attacker input would be an oracle).
     *
     * @param  array<int, int>  $expectedStatuses
     */
    private function assertDenied(TestResponse $response, array $expectedStatuses, string $message): void
    {
        $status = $response->getStatusCode();

        $this->assertContains(
            $status,
            $expectedStatuses,
            $message.' — got '.$status.': '.mb_substr((string) $response->getContent(), 0, 300)
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function makeUser(UserRole $role, ?Church $church, string $email, array $extra = []): User
    {
        $scope = match ($role) {
            UserRole::PlatformAdmin,
            UserRole::Admin,
            UserRole::AssistantAdmin => UserScope::Church,
            UserRole::StageAdmin => UserScope::Stage,
            UserRole::Servant => UserScope::ClassScope,
            UserRole::Member => UserScope::Self,
        };

        return User::factory()->create(array_merge([
            'email' => $email,
            'role' => $role,
            'church_id' => $church?->id,
            'scope' => $scope,
            'application_status' => 'approved',
            'email_verified_at' => now(),
            'is_active' => true,
        ], $extra));
    }
}
