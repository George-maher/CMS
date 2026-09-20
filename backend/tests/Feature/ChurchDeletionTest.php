<?php

namespace Tests\Feature;

use App\Enums\FeedbackCategory;
use App\Enums\PointType;
use App\Enums\QRInviteType;
use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\AttendanceContext;
use App\Models\Church;
use App\Models\Classe;
use App\Models\DailySpiritualRecord;
use App\Models\DailyVerse;
use App\Models\Event;
use App\Models\EventAccommodation;
use App\Models\EventBus;
use App\Models\EventBusSheet;
use App\Models\EventPayment;
use App\Models\EventRegistration;
use App\Models\EventRoom;
use App\Models\EventRoomCell;
use App\Models\EventSession;
use App\Models\EventSpeaker;
use App\Models\EventTarget;
use App\Models\EventView;
use App\Models\Feedback;
use App\Models\FeedbackReply;
use App\Models\MembershipRequest;
use App\Models\Notification;
use App\Models\PasswordResetRequest;
use App\Models\Point;
use App\Models\ProfileUpdateRequest;
use App\Models\QRInvite;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChurchDeletionTest extends TestCase
{
    use RefreshDatabase;

    /** Thrown by the guarded model listener when the flag is set. */
    protected static bool $simulateDeleteFailure = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Register once. Flag is false by default, so softDelete/hardDelete in
        // every other test are unaffected. Only the rollback test sets the flag.
        Church::deleting(function () {
            if (self::$simulateDeleteFailure) {
                throw new \RuntimeException('Simulated deletion failure');
            }
        });
    }

    protected function tearDown(): void
    {
        self::$simulateDeleteFailure = false;
        parent::tearDown();
    }

    /**
     * Authenticate as the given user for THIS request.
     *
     * Multi-request tests must re-sync the sanctum guard with a real Bearer
     * token before each request — a mock token set once at test start gets
     * dropped by the kernel between requests (see EventManagementTest::actAs).
     */
    private function authAs(User $user): self
    {
        $this->actingAs($user, 'sanctum');

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('test', [$user->role->value])->plainTextToken);
    }

    private function platformAdmin(): User
    {
        return User::factory()->create(['role' => UserRole::PlatformAdmin]);
    }

    private function deletePayload(string $password = 'password'): array
    {
        return ['confirmation' => 'DELETE CHURCH', 'password' => $password];
    }

    private function churchWithUsers(): array
    {
        $church = Church::factory()->create();

        $admin = User::factory()->create([
            'church_id' => $church->id,
            'role' => UserRole::Admin,
        ]);
        $servant = User::factory()->create([
            'church_id' => $church->id,
            'role' => UserRole::Servant,
        ]);
        $member = User::factory()->create([
            'church_id' => $church->id,
            'role' => UserRole::Member,
        ]);

        return [$church, $admin, $servant, $member];
    }

    /** Seed one row into every table the service touches (church-scoped). */
    private function seedFullGraph(Church $church): array
    {
        $users = collect([
            User::factory()->create(['church_id' => $church->id, 'role' => UserRole::Admin]),
            User::factory()->create(['church_id' => $church->id, 'role' => UserRole::Servant]),
            User::factory()->create(['church_id' => $church->id, 'role' => UserRole::Member]),
            User::factory()->create(['church_id' => $church->id, 'role' => UserRole::Member]),
        ]);
        $admin = $users[0];
        $servant = $users[1];
        $members = $users->slice(2)->values();

        // Structure
        $stage = Stage::factory()->create(['church_id' => $church->id, 'name' => 'First Stage']);
        $classe = Classe::factory()->create([
            'church_id' => $church->id,
            'stage_id' => $stage->id,
            'name' => 'First Class',
        ]);
        DB::table('class_servant')->insert([
            'class_id' => $classe->id,
            'user_id' => $servant->id,
        ]);
        DB::table('class_years')->insert([
            'name' => 'First Year',
            'year' => '1',
            'church_id' => $church->id,
        ]);

        // Event management subtree
        $event = Event::factory()->create(['church_id' => $church->id, 'created_by' => $admin->id]);
        $session = EventSession::create(['event_id' => $event->id, 'title' => 'Opening Session']);
        $speaker = EventSpeaker::create(['event_id' => $event->id, 'name' => 'Speaker One']);
        $bus = EventBus::create(['event_id' => $event->id, 'bus_number' => 'B1', 'capacity' => 45]);
        $room = EventRoom::create([
            'event_id' => $event->id,
            'room_number' => 101,
            'capacity' => 4,
            'member_capacity' => 3,
        ]);
        $cell = EventRoomCell::create(['room_id' => $room->id, 'cell_number' => 1, 'type' => 'member']);
        $registration = EventRegistration::factory()->create([
            'event_id' => $event->id,
            'user_id' => $members[0]->id,
        ]);
        EventPayment::create([
            'registration_id' => $registration->id,
            'amount' => 50,
            'method' => 'cash',
            'paid_at' => now(),
        ]);
        EventAccommodation::create(['registration_id' => $registration->id, 'cell_id' => $cell->id]);
        EventBusSheet::create(['registration_id' => $registration->id, 'bus_id' => $bus->id]);
        EventView::create([
            'event_id' => $event->id,
            'user_id' => $members[0]->id,
            'church_id' => $church->id,
        ]);
        EventTarget::create([
            'event_id' => $event->id,
            'class_id' => $classe->id,
            'church_id' => $church->id,
        ]);

        // Core tables
        Notification::create([
            'church_id' => $church->id,
            'user_id' => $members[0]->id,
            'title' => 'Announcement',
        ]);
        Point::create([
            'user_id' => $members[0]->id,
            'points' => 10,
            'type' => PointType::Attendance->value,
            'church_id' => $church->id,
        ]);
        DB::table('attendances')->insert([
            'user_id' => $members[0]->id,
            'recorded_by' => $servant->id,
            'attended_at' => now()->subDay()->format('Y-m-d H:i:s'),
            'attended_date' => now()->subDay()->format('Y-m-d'),
            'points_earned' => 10,
            'status' => 'present',
            'church_id' => $church->id,
        ]);
        QRInvite::create([
            'type' => QRInviteType::AttendanceQR->value,
            'token' => 'invite-'.Str::random(40),
            'created_by' => $servant->id,
            'expires_at' => now()->addDay(),
            'church_id' => $church->id,
        ]);
        $feedback = Feedback::create([
            'user_id' => $members[0]->id,
            'category' => FeedbackCategory::Complaint->value,
            'message' => 'Great service',
            'church_id' => $church->id,
        ]);
        FeedbackReply::create([
            'feedback_id' => $feedback->id,
            'user_id' => $admin->id,
            'message' => 'Thanks!',
        ]);
        DailyVerse::create([
            'verse_text' => 'The Lord is my shepherd.',
            'reference' => 'Psalm 23:1',
            'created_by' => $admin->id,
            'church_id' => $church->id,
        ]);
        AttendanceContext::create(['name' => 'Custom Context', 'slug' => 'custom-context', 'church_id' => $church->id]);
        MembershipRequest::create([
            'church_id' => $church->id,
            'name' => 'New Member',
            'email' => 'newmember-'.$church->id.'@example.com',
        ]);
        PasswordResetRequest::create([
            'user_id' => $members[0]->id,
            'email' => $members[0]->email,
        ]);
        ProfileUpdateRequest::create([
            'user_id' => $members[0]->id,
            'church_id' => $church->id,
            'old_values' => ['name' => 'Old'],
            'new_values' => ['name' => 'New'],
        ]);
        DailySpiritualRecord::create([
            'user_id' => $members[0]->id,
            'church_id' => $church->id,
            'activity_date' => now()->format('Y-m-d'),
        ]);
        DB::table('audit_logs')->insert([
            'church_id' => $church->id,
            'user_id' => $admin->id,
            'action' => 'seeded',
            'resource_type' => 'church',
            'resource_id' => $church->id,
        ]);
        $members[0]->createToken('test-token', [UserRole::Member->value]);

        return compact(
            'church', 'users', 'admin', 'servant', 'members',
            'stage', 'classe', 'event', 'session', 'speaker', 'bus', 'room', 'cell',
            'registration',
        );
    }

    public function test_platform_admin_can_soft_delete_church(): void
    {
        $admin = $this->platformAdmin();
        [$church, , $servant, $member] = $this->churchWithUsers();
        $member->createToken('member-token', [UserRole::Member->value]);

        $response = $this->authAs($admin)
            ->postJson("/api/v1/platform/churches/{$church->id}/soft-delete", $this->deletePayload());

        $response->assertOk()
            ->assertJson(['message' => __('church_deletion.soft_deleted')])
            ->assertJsonPath('data.id', $church->id);

        $trashed = Church::withTrashed()->find($church->id);
        $this->assertTrue($trashed->trashed());
        $this->assertSame('soft', $trashed->deletion_type);
        $this->assertNotNull($trashed->recoverable_until);
        $this->assertSame($admin->id, $trashed->deleted_by);
        $this->assertTrue($trashed->isRecoverable());

        // Users deactivated + tokens revoked
        $this->assertSame(0, User::where('church_id', $church->id)->where('is_active', true)->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $member->id)->count());

        // Audit row
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'church_soft_deleted',
            'resource_id' => $church->id,
        ]);
    }

    public function test_soft_delete_twice_returns_409_already_deleted(): void
    {
        $admin = $this->platformAdmin();
        [$church] = $this->churchWithUsers();

        $this->authAs($admin)->postJson("/api/v1/platform/churches/{$church->id}/soft-delete", $this->deletePayload())->assertOk();

        $this->authAs($admin)->postJson("/api/v1/platform/churches/{$church->id}/soft-delete", $this->deletePayload())
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => __('church_deletion.already_deleted'),
                'code' => 'ALREADY_DELETED',
            ]);
    }

    public function test_restore_active_church_returns_409_not_deleted(): void
    {
        $admin = $this->platformAdmin();
        [$church] = $this->churchWithUsers();

        $this->authAs($admin)->postJson("/api/v1/platform/churches/{$church->id}/restore", $this->deletePayload())
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => __('church_deletion.not_deleted'),
                'code' => 'NOT_DELETED',
            ]);
    }

    public function test_restore_expired_recovery_window_returns_422(): void
    {
        $admin = $this->platformAdmin();
        [$church] = $this->churchWithUsers();

        $this->authAs($admin)->postJson("/api/v1/platform/churches/{$church->id}/soft-delete", $this->deletePayload())->assertOk();

        Church::withTrashed()->where('id', $church->id)->update([
            'recoverable_until' => now()->subDay(),
        ]);

        $this->authAs($admin)->postJson("/api/v1/platform/churches/{$church->id}/restore", $this->deletePayload())
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => __('church_deletion.recovery_window_expired'),
                'code' => 'RECOVERY_WINDOW_EXPIRED',
            ]);
    }

    public function test_platform_admin_can_restore_church(): void
    {
        $admin = $this->platformAdmin();
        [$church] = $this->churchWithUsers();

        $this->authAs($admin)->postJson("/api/v1/platform/churches/{$church->id}/soft-delete", $this->deletePayload())->assertOk();
        $this->assertSame(0, User::where('church_id', $church->id)->where('is_active', true)->count());

        $response = $this->authAs($admin)->postJson("/api/v1/platform/churches/{$church->id}/restore", $this->deletePayload());

        $response->assertOk()
            ->assertJson(['message' => __('church_deletion.restored')])
            ->assertJsonPath('data.id', $church->id);

        $restored = Church::withTrashed()->find($church->id);
        $this->assertFalse($restored->trashed());
        $this->assertTrue($restored->is_active);
        $this->assertNull($restored->deleted_by);
        $this->assertNull($restored->deletion_type);
        $this->assertNull($restored->recoverable_until);

        // Users reactivated
        $this->assertSame(3, User::where('church_id', $church->id)->where('is_active', true)->count());
    }

    public function test_hard_delete_removes_entire_church_graph(): void
    {
        $admin = $this->platformAdmin();
        $church = Church::factory()->create();
        $graph = $this->seedFullGraph($church);

        $response = $this->authAs($admin)->postJson("/api/v1/platform/churches/{$church->id}/hard-delete", $this->deletePayload());

        $response->assertOk()
            ->assertJson(['message' => __('church_deletion.hard_deleted')]);

        // Church gone even from trashed table
        $this->assertNull(Church::withTrashed()->find($church->id));

        // Users + church tokens gone
        $this->assertSame(0, User::where('church_id', $church->id)->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->whereIn('tokenable_id', $graph['users']->pluck('id'))->count());

        // Structure
        $this->assertSame(0, Stage::where('church_id', $church->id)->count());
        $this->assertSame(0, Classe::where('church_id', $church->id)->count());
        $this->assertSame(0, DB::table('class_servant')->count());
        $this->assertSame(0, DB::table('class_years')->where('church_id', $church->id)->count());

        // Event subtree
        $this->assertSame(0, Event::where('church_id', $church->id)->count());
        $this->assertSame(0, EventSession::count());
        $this->assertSame(0, EventSpeaker::count());
        $this->assertSame(0, EventBus::count());
        $this->assertSame(0, EventRoom::count());
        $this->assertSame(0, EventRoomCell::count());
        $this->assertSame(0, EventRegistration::count());
        $this->assertSame(0, EventPayment::count());
        $this->assertSame(0, EventAccommodation::count());
        $this->assertSame(0, EventBusSheet::count());
        $this->assertSame(0, EventView::count());
        $this->assertSame(0, EventTarget::count());

        // Core tables
        $this->assertSame(0, Notification::where('church_id', $church->id)->count());
        $this->assertSame(0, Point::where('church_id', $church->id)->count());
        $this->assertSame(0, Attendance::where('church_id', $church->id)->count());
        $this->assertSame(0, QRInvite::where('church_id', $church->id)->count());
        $this->assertSame(0, Feedback::where('church_id', $church->id)->count());
        $this->assertSame(0, FeedbackReply::count());
        $this->assertSame(0, DailyVerse::where('church_id', $church->id)->count());
        $this->assertSame(0, AttendanceContext::where('church_id', $church->id)->count());
        $this->assertSame(0, MembershipRequest::where('church_id', $church->id)->count());
        $this->assertSame(0, PasswordResetRequest::whereIn('user_id', $graph['users']->pluck('id'))->count());
        $this->assertSame(0, ProfileUpdateRequest::whereIn('user_id', $graph['users']->pluck('id'))->count());
        $this->assertSame(0, DailySpiritualRecord::where('church_id', $church->id)->count());
        $this->assertSame(0, DB::table('audit_logs')->where('church_id', $church->id)->count());

        // Hard-delete marker audit row
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'church_hard_deleted',
            'resource_id' => $church->id,
        ]);
    }

    public function test_hard_delete_cross_church_registration_regression(): void
    {
        $admin = $this->platformAdmin();

        $churchA = Church::factory()->create();
        $churchB = Church::factory()->create();

        $userA = User::factory()->create(['church_id' => $churchA->id, 'role' => UserRole::Member]);
        $userB = User::factory()->create(['church_id' => $churchB->id, 'role' => UserRole::Member]);

        $eventA = Event::factory()->create(['church_id' => $churchA->id, 'created_by' => $userA->id]);
        $eventB = Event::factory()->create(['church_id' => $churchB->id, 'created_by' => $userB->id]);

        // userA (church A) registered on church B's event — classic RESTRICT FK trap
        EventRegistration::factory()->create(['event_id' => $eventB->id, 'user_id' => $userA->id]);
        // userB (church B) registered on church A's event
        EventRegistration::factory()->create(['event_id' => $eventA->id, 'user_id' => $userB->id]);

        $response = $this->authAs($admin)->postJson("/api/v1/platform/churches/{$churchA->id}/hard-delete", $this->deletePayload());

        $response->assertOk()
            ->assertJson(['message' => __('church_deletion.hard_deleted')]);

        // Church A fully gone; Church B intact
        $this->assertNull(Church::withTrashed()->find($churchA->id));
        $this->assertSame(0, User::where('church_id', $churchA->id)->count());
        $this->assertSame(0, Event::where('church_id', $churchA->id)->count());

        $this->assertNotNull(Church::withTrashed()->find($churchB->id));
        $this->assertSame(1, User::where('church_id', $churchB->id)->count());
        $this->assertSame(1, Event::where('church_id', $churchB->id)->count());

        // Both cross-church registrations removed, no FK violations
        $this->assertSame(0, EventRegistration::count());
    }

    public function test_hard_delete_rolls_back_on_failure(): void
    {
        $admin = $this->platformAdmin();
        [$church, $churchAdmin, , $member] = $this->churchWithUsers();
        $event = Event::factory()->create(['church_id' => $church->id, 'created_by' => $churchAdmin->id]);
        EventRegistration::factory()->create(['event_id' => $event->id, 'user_id' => $member->id]);

        self::$simulateDeleteFailure = true;
        try {
            $response = $this->authAs($admin)->postJson("/api/v1/platform/churches/{$church->id}/hard-delete", $this->deletePayload());
        } finally {
            self::$simulateDeleteFailure = false;
        }

        $response->assertStatus(500)
            ->assertJson([
                'success' => false,
                'message' => __('church_deletion.delete_failed'),
                'code' => 'CHURCH_DELETE_FAILED',
            ]);

        // Entire transaction rolled back
        $this->assertNotNull(Church::withTrashed()->find($church->id));
        $this->assertSame(3, User::where('church_id', $church->id)->count());
        $this->assertSame(1, Event::where('church_id', $church->id)->count());
        $this->assertSame(1, EventRegistration::count());
    }

    public function test_delete_requires_password_and_confirmation(): void
    {
        $admin = $this->platformAdmin();
        [$church] = $this->churchWithUsers();

        $this->authAs($admin)->postJson("/api/v1/platform/churches/{$church->id}/soft-delete", [
            'confirmation' => 'DELETE CHURCH',
            'password' => 'wrong-password',
        ])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');

        $this->authAs($admin)->postJson("/api/v1/platform/churches/{$church->id}/soft-delete", [
            'confirmation' => 'NOT THE PHRASE',
            'password' => 'password',
        ])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_non_platform_admin_is_forbidden(): void
    {
        $churchAdmin = User::factory()->create(['role' => UserRole::Admin]);
        [$church] = $this->churchWithUsers();

        $this->authAs($churchAdmin)->postJson("/api/v1/platform/churches/{$church->id}/soft-delete", $this->deletePayload())
            ->assertStatus(403);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $church = Church::factory()->create();

        $this->postJson("/api/v1/platform/churches/{$church->id}/soft-delete", $this->deletePayload())
            ->assertStatus(401)
            ->assertJson(['code' => 'UNAUTHORIZED']);
    }

    public function test_delete_nonexistent_church_returns_404(): void
    {
        $admin = $this->platformAdmin();

        $this->authAs($admin)->postJson('/api/v1/platform/churches/999999/soft-delete', $this->deletePayload())
            ->assertStatus(404);
    }

    public function test_deletion_summary_counts_and_trashed_metadata(): void
    {
        $admin = $this->platformAdmin();
        $church = Church::factory()->create();
        $this->seedFullGraph($church);

        $response = $this->authAs($admin)->getJson("/api/v1/platform/churches/{$church->id}/deletion-summary");

        $response->assertOk()
            ->assertJsonPath('data.church_id', $church->id)
            ->assertJsonPath('data.total_users', 4)
            ->assertJsonPath('data.total_events', 1)
            ->assertJsonPath('data.total_stages', 1)
            ->assertJsonPath('data.total_classes', 1)
            ->assertJsonPath('data.total_event_sessions', 1)
            ->assertJsonPath('data.total_event_speakers', 1)
            ->assertJsonPath('data.total_event_buses', 1)
            ->assertJsonPath('data.total_event_rooms', 1)
            ->assertJsonPath('data.total_event_room_cells', 1)
            ->assertJsonPath('data.total_event_registrations', 1)
            ->assertJsonPath('data.total_event_payments', 1)
            ->assertJsonPath('data.total_event_accommodations', 1)
            ->assertJsonPath('data.total_event_bus_sheets', 1)
            ->assertJsonPath('data.total_event_views', 1)
            ->assertJsonPath('data.total_event_targets', 1)
            ->assertJsonPath('data.total_notifications', 1)
            ->assertJsonPath('data.total_points', 1)
            ->assertJsonPath('data.total_attendances', 1)
            ->assertJsonPath('data.total_qr_invites', 1)
            ->assertJsonPath('data.total_feedback', 1)
            ->assertJsonPath('data.total_feedback_replies', 1)
            ->assertJsonPath('data.total_daily_verses', 1)
            ->assertJsonPath('data.total_attendance_contexts', 7) // 6 defaults + 1 custom
            ->assertJsonPath('data.total_membership_requests', 1)
            ->assertJsonPath('data.total_password_reset_requests', 1)
            ->assertJsonPath('data.total_profile_update_requests', 1)
            ->assertJsonPath('data.total_daily_spiritual_records', 1)
            ->assertJsonPath('data.total_class_years', 1)
            ->assertJsonPath('data.total_audit_logs', DB::table('audit_logs')->where('church_id', $church->id)->count())
            ->assertJsonPath('data.schema_warnings', false);

        // Sanity: total_records is the sum of the numeric total_* buckets
        $data = $response->json('data');
        $total = 0;
        foreach ($data as $key => $value) {
            if (str_starts_with((string) $key, 'total_') && $key !== 'total_records') {
                $total += $value;
            }
        }
        $this->assertSame($total, $data['total_records']);

        // Trashed metadata merged after soft delete
        $this->authAs($admin)->postJson("/api/v1/platform/churches/{$church->id}/soft-delete", $this->deletePayload())->assertOk();

        $trashedSummary = $this->authAs($admin)->getJson("/api/v1/platform/churches/{$church->id}/deletion-summary");
        $trashedSummary->assertOk()
            ->assertJsonPath('data.already_deleted', true)
            ->assertJsonPath('data.is_recoverable', true)
            ->assertJsonPath('data.deletion_type', 'soft')
            ->assertJsonPath('data.deleted_by', fn ($value) => is_string($value))
            ->assertJsonPath('data.days_until_purge', fn ($value) => $value >= 29);
    }

    public function test_arabic_localized_messages(): void
    {
        $admin = $this->platformAdmin();
        [$church] = $this->churchWithUsers();

        $this->authAs($admin)->postJson("/api/v1/platform/churches/{$church->id}/soft-delete", $this->deletePayload(), [
            'Accept-Language' => 'ar',
        ])->assertOk()
            ->assertJson(['message' => __('church_deletion.soft_deleted', [], 'ar')]);

        $this->authAs($admin)->postJson("/api/v1/platform/churches/{$church->id}/soft-delete", $this->deletePayload(), [
            'Accept-Language' => 'ar',
        ])->assertStatus(409)
            ->assertJson([
                'message' => __('church_deletion.already_deleted', [], 'ar'),
                'code' => 'ALREADY_DELETED',
            ]);
    }
}
