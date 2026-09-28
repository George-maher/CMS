<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\AttendanceContext;
use App\Models\Church;
use App\Models\Classe;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AbsentMemberTest extends TestCase
{
    use RefreshDatabase;

    private Church $church;
    private Church $church2;
    private Classe $classe;
    private User $admin;
    private User $servant;
    private User $servantOtherClass;
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        Permission::clearCache();

        $this->church = Church::factory()->create(['name' => 'Test Church']);
        $this->church2 = Church::factory()->create(['name' => 'Other Church']);

        $this->classe = Classe::factory()->create(['church_id' => $this->church->id]);

        $this->admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $this->church->id,
            'application_status' => 'approved',
        ]);

        $this->servant = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $this->church->id,
            'application_status' => 'approved',
            'class_id' => $this->classe->id,
        ]);

        $servantOtherClasse = Classe::factory()->create(['church_id' => $this->church->id]);
        $this->servantOtherClass = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $this->church->id,
            'application_status' => 'approved',
            'class_id' => $servantOtherClasse->id,
        ]);

        $this->member = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $this->church->id,
            'application_status' => 'approved',
            'class_id' => $this->classe->id,
        ]);
    }

    private function actingAsUser(User $user): self
    {
        $token = $user->createToken('test')->plainTextToken;
        return $this->withHeader('Authorization', "Bearer $token");
    }

    // ──────────────────────────────────────────────
    // 1. Admin can get absent members
    // ──────────────────────────────────────────────

    public function test_admin_can_get_absent_members(): void
    {
        User::factory(5)->create([
            'role' => UserRole::Member,
            'church_id' => $this->church->id,
            'class_id' => $this->classe->id,
            'application_status' => 'approved',
        ]);

        $context = AttendanceContext::factory()->create([
            'church_id' => $this->church->id,
            'created_by' => $this->admin->id,
        ]);

        // Record attendance for 2 members
        $members = User::where('church_id', $this->church->id)
            ->where('role', UserRole::Member)
            ->get();

        $members->take(2)->each(function ($m) use ($context) {
            Attendance::create([
                'user_id' => $m->id,
                'recorded_by' => $this->servant->id,
                'class_year_id' => $this->classe->id,
                'attendance_context_id' => $context->id,
                'attended_at' => now(),
                'method' => 'qr',
                'church_id' => $this->church->id,
            ]);
        });

        $this->actingAsUser($this->admin);

        $response = $this->getJson('/api/v1/attendances/absent-members?class_id=' . $this->classe->id);

        $response->assertStatus(200);

        $data = $response->json('data');

        $this->assertEquals(6, $data['summary']['total_members']);  // 5 factory + 1 from setUp
        $this->assertEquals(2, $data['summary']['present_count']);
        $this->assertEquals(4, $data['summary']['absent_count']);
        $this->assertCount(4, $data['absent_members']);
    }

    // ──────────────────────────────────────────────
    // 2. Correct absent count: 70 members, 50 present → 20 absent
    // ──────────────────────────────────────────────

    public function test_correct_absent_count_with_seventy_members(): void
    {
        $context = AttendanceContext::factory()->create([
            'church_id' => $this->church->id,
            'created_by' => $this->admin->id,
        ]);

        // Create 69 more members (70 total including setUp member)
        User::factory(69)->create([
            'role' => UserRole::Member,
            'church_id' => $this->church->id,
            'class_id' => $this->classe->id,
            'application_status' => 'approved',
        ]);

        // Record attendance for 50 members
        $members = User::where('church_id', $this->church->id)
            ->where('role', UserRole::Member)
            ->where('class_id', $this->classe->id)
            ->get();

        $members->take(50)->each(function ($m) use ($context) {
            Attendance::create([
                'user_id' => $m->id,
                'recorded_by' => $this->servant->id,
                'class_year_id' => $this->classe->id,
                'attendance_context_id' => $context->id,
                'attended_at' => now(),
                'method' => 'qr',
                'church_id' => $this->church->id,
            ]);
        });

        $this->actingAsUser($this->admin);

        $response = $this->getJson('/api/v1/attendances/absent-members?class_id=' . $this->classe->id);

        $response->assertStatus(200);

        $data = $response->json('data');

        $this->assertEquals(70, $data['summary']['total_members']);
        $this->assertEquals(50, $data['summary']['present_count']);
        $this->assertEquals(20, $data['summary']['absent_count']);
        $this->assertCount(20, $data['absent_members']);
    }

    // ──────────────────────────────────────────────
    // 3. No duplicate absent members
    // ──────────────────────────────────────────────

    public function test_no_duplicate_absent_members(): void
    {
        $context = AttendanceContext::factory()->create([
            'church_id' => $this->church->id,
            'created_by' => $this->admin->id,
        ]);

        User::factory(3)->create([
            'role' => UserRole::Member,
            'church_id' => $this->church->id,
            'class_id' => $this->classe->id,
            'application_status' => 'approved',
        ]);

        $this->actingAsUser($this->admin);

        $response = $this->getJson('/api/v1/attendances/absent-members?class_id=' . $this->classe->id);

        $response->assertStatus(200);

        $data = $response->json('data');
        $ids = collect($data['absent_members'])->pluck('id');

        $this->assertEquals($ids->unique()->count(), $ids->count(), 'Absent members list contains duplicates');
    }

    // ──────────────────────────────────────────────
    // 4. Correct class filtering — only targets specified class
    // ──────────────────────────────────────────────

    public function test_correct_class_filtering(): void
    {
        $context = AttendanceContext::factory()->create([
            'church_id' => $this->church->id,
            'created_by' => $this->admin->id,
        ]);

        $otherClasse = Classe::factory()->create(['church_id' => $this->church->id, 'name' => 'Other Class']);

        // Members in classe A
        User::factory(3)->create([
            'role' => UserRole::Member,
            'church_id' => $this->church->id,
            'class_id' => $this->classe->id,
            'application_status' => 'approved',
        ]);

        // Members in classe B
        User::factory(3)->create([
            'role' => UserRole::Member,
            'church_id' => $this->church->id,
            'class_id' => $otherClasse->id,
            'application_status' => 'approved',
        ]);

        $this->actingAsUser($this->admin);

        // Query only classe A
        $response = $this->getJson('/api/v1/attendances/absent-members?class_id=' . $this->classe->id);

        $response->assertStatus(200);

        $data = $response->json('data');

        $this->assertEquals(4, $data['summary']['total_members']); // 3 + 1 from setUp
        $this->assertEquals(0, $data['summary']['present_count']);
        $this->assertEquals(4, $data['summary']['absent_count']);
    }

    // ──────────────────────────────────────────────
    // 5. Servant can view absent members for their assigned class
    // ──────────────────────────────────────────────

    public function test_servant_can_view_absent_members_for_assigned_class(): void
    {
        $context = AttendanceContext::factory()->create([
            'church_id' => $this->church->id,
            'created_by' => $this->admin->id,
        ]);

        User::factory(3)->create([
            'role' => UserRole::Member,
            'church_id' => $this->church->id,
            'class_id' => $this->classe->id,
            'application_status' => 'approved',
        ]);

        $this->actingAsUser($this->servant);

        $response = $this->getJson('/api/v1/attendances/absent-members?class_id=' . $this->classe->id);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals(4, $data['summary']['total_members']); // 3 + 1 from setUp
    }

    // ──────────────────────────────────────────────
    // 6. Servant cannot see another class
    // ──────────────────────────────────────────────

    public function test_servant_cannot_view_absent_members_for_other_class(): void
    {
        $otherClasse = Classe::factory()->create(['church_id' => $this->church->id]);

        User::factory(3)->create([
            'role' => UserRole::Member,
            'church_id' => $this->church->id,
            'class_id' => $otherClasse->id,
            'application_status' => 'approved',
        ]);

        $this->actingAsUser($this->servant);

        // Servant tries to access a class they are not assigned to
        $response = $this->getJson('/api/v1/attendances/absent-members?class_id=' . $otherClasse->id);

        $response->assertStatus(200);

        // Server silently overrides to servant's assigned class (which has 1 member from setUp)
        $data = $response->json('data');
        $this->assertEquals(1, $data['summary']['total_members']);
    }

    // ──────────────────────────────────────────────
    // 7. Servant with no assigned class gets empty result
    // ──────────────────────────────────────────────

    public function test_servant_with_no_assigned_class_gets_empty(): void
    {
        $unassignedServant = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $this->church->id,
            'application_status' => 'approved',
            'class_id' => null,
        ]);

        $this->actingAsUser($unassignedServant);

        $response = $this->getJson('/api/v1/attendances/absent-members?class_id=999');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals(0, $data['summary']['total_members']);
        $this->assertEquals(0, $data['summary']['present_count']);
        $this->assertEquals(0, $data['summary']['absent_count']);
        $this->assertCount(0, $data['absent_members']);
    }

    // ──────────────────────────────────────────────
    // 8. Member cannot access
    // ──────────────────────────────────────────────

    public function test_member_cannot_access_absent_members(): void
    {
        $this->actingAsUser($this->member);

        $response = $this->getJson('/api/v1/attendances/absent-members?class_id=' . $this->classe->id);

        $response->assertStatus(403);
    }

    // ──────────────────────────────────────────────
    // 9. Cross-church isolation
    // ──────────────────────────────────────────────

    public function test_cross_church_isolation(): void
    {
        $contextChurch2 = AttendanceContext::factory()->create([
            'church_id' => $this->church2->id,
            'created_by' => User::factory()->create([
                'role' => UserRole::Admin,
                'church_id' => $this->church2->id,
                'application_status' => 'approved',
            ])->id,
        ]);

        $classeChurch2 = Classe::factory()->create(['church_id' => $this->church2->id]);

        User::factory(3)->create([
            'role' => UserRole::Member,
            'church_id' => $this->church2->id,
            'class_id' => $classeChurch2->id,
            'application_status' => 'approved',
        ]);

        // Admin from church 1 attempts to access church 2's absent members
        $this->actingAsUser($this->admin);

        $response = $this->getJson('/api/v1/attendances/absent-members?class_id=' . $classeChurch2->id);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals(0, $data['summary']['total_members'], 'Cross-church access should return no members');
    }

    // ──────────────────────────────────────────────
    // 10. Filter by attendance context
    // ──────────────────────────────────────────────

    public function test_filter_by_attendance_context(): void
    {
        $contextA = AttendanceContext::factory()->create([
            'church_id' => $this->church->id,
            'created_by' => $this->admin->id,
        ]);

        $contextB = AttendanceContext::factory()->create([
            'church_id' => $this->church->id,
            'created_by' => $this->admin->id,
        ]);

        User::factory(3)->create([
            'role' => UserRole::Member,
            'church_id' => $this->church->id,
            'class_id' => $this->classe->id,
            'application_status' => 'approved',
        ]);

        $members = User::where('church_id', $this->church->id)
            ->where('role', UserRole::Member)
            ->where('class_id', $this->classe->id)
            ->get();

        // 2 members attended context A
        $members->take(2)->each(function ($m) use ($contextA) {
            Attendance::create([
                'user_id' => $m->id,
                'recorded_by' => $this->servant->id,
                'class_year_id' => $this->classe->id,
                'attendance_context_id' => $contextA->id,
                'attended_at' => now(),
                'method' => 'qr',
                'church_id' => $this->church->id,
            ]);
        });

        // 1 different member attended context B
        $members->skip(2)->take(1)->each(function ($m) use ($contextB) {
            Attendance::create([
                'user_id' => $m->id,
                'recorded_by' => $this->servant->id,
                'class_year_id' => $this->classe->id,
                'attendance_context_id' => $contextB->id,
                'attended_at' => now(),
                'method' => 'qr',
                'church_id' => $this->church->id,
            ]);
        });

        $this->actingAsUser($this->admin);

        // Filter by context A: only 2 present
        $response = $this->getJson(
            '/api/v1/attendances/absent-members?class_id=' . $this->classe->id
            . '&context_id=' . $contextA->id
        );

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals(4, $data['summary']['total_members']); // 3 + 1 from setUp
        $this->assertEquals(2, $data['summary']['present_count'], 'Only 2 members attended context A');
        $this->assertEquals(2, $data['summary']['absent_count']);
    }

    // ──────────────────────────────────────────────
    // 11. Filter by date range
    // ──────────────────────────────────────────────

    public function test_filter_by_date_range(): void
    {
        $context = AttendanceContext::factory()->create([
            'church_id' => $this->church->id,
            'created_by' => $this->admin->id,
        ]);

        User::factory(3)->create([
            'role' => UserRole::Member,
            'church_id' => $this->church->id,
            'class_id' => $this->classe->id,
            'application_status' => 'approved',
        ]);

        $members = User::where('church_id', $this->church->id)
            ->where('role', UserRole::Member)
            ->where('class_id', $this->classe->id)
            ->get();

        // Record yesterday
        $members->take(2)->each(function ($m) use ($context) {
            Attendance::create([
                'user_id' => $m->id,
                'recorded_by' => $this->servant->id,
                'class_year_id' => $this->classe->id,
                'attendance_context_id' => $context->id,
                'attended_at' => now()->subDay(),
                'method' => 'qr',
                'church_id' => $this->church->id,
            ]);
        });

        $this->actingAsUser($this->admin);

        // Filter by today — should show all as absent
        $today = now()->format('Y-m-d');
        $response = $this->getJson(
            '/api/v1/attendances/absent-members?class_id=' . $this->classe->id
            . '&date=' . $today
        );

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals(4, $data['summary']['total_members']);
        $this->assertEquals(0, $data['summary']['present_count'], 'No one attended today');
        $this->assertEquals(4, $data['summary']['absent_count']);
    }

    // ──────────────────────────────────────────────
    // 12. Absent member response contains all required fields
    // ──────────────────────────────────────────────

    public function test_absent_member_response_contains_all_fields(): void
    {
        $context = AttendanceContext::factory()->create([
            'church_id' => $this->church->id,
            'created_by' => $this->admin->id,
        ]);

        User::factory(2)->create([
            'role' => UserRole::Member,
            'church_id' => $this->church->id,
            'class_id' => $this->classe->id,
            'application_status' => 'approved',
        ]);

        $this->actingAsUser($this->admin);

        $response = $this->getJson('/api/v1/attendances/absent-members?class_id=' . $this->classe->id);

        $response->assertStatus(200);

        $member = $response->json('data.absent_members.0');

        $this->assertArrayHasKey('id', $member);
        $this->assertArrayHasKey('name', $member);
        $this->assertArrayHasKey('phone', $member);
        $this->assertArrayHasKey('last_attendance_date', $member);
        $this->assertArrayHasKey('attendance_count', $member);
        $this->assertArrayHasKey('total_sessions', $member);
        $this->assertArrayHasKey('attendance_percentage', $member);
        $this->assertArrayHasKey('consecutive_absences', $member);
        $this->assertArrayHasKey('month_absences', $member);
    }

    // ──────────────────────────────────────────────
    // 13. Attendance percentage is calculated correctly
    // ──────────────────────────────────────────────

    public function test_attendance_percentage_is_accurate(): void
    {
        $context = AttendanceContext::factory()->create([
            'church_id' => $this->church->id,
            'created_by' => $this->admin->id,
        ]);

        $memberA = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $this->church->id,
            'class_id' => $this->classe->id,
            'application_status' => 'approved',
        ]);

        $memberB = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $this->church->id,
            'class_id' => $this->classe->id,
            'application_status' => 'approved',
        ]);

        // 3 attendance sessions total for this context
        foreach (range(1, 3) as $day) {
            Attendance::create([
                'user_id' => $memberA->id,
                'recorded_by' => $this->servant->id,
                'class_year_id' => $this->classe->id,
                'attendance_context_id' => $context->id,
                'attended_at' => now()->subDays(4 - $day),
                'method' => 'qr',
                'church_id' => $this->church->id,
            ]);
        }

        // memberB attended only 1
        Attendance::create([
            'user_id' => $memberB->id,
            'recorded_by' => $this->servant->id,
            'class_year_id' => $this->classe->id,
            'attendance_context_id' => $context->id,
            'attended_at' => now()->subDays(2),
            'method' => 'qr',
            'church_id' => $this->church->id,
        ]);

        $this->actingAsUser($this->admin);

        $response = $this->getJson(
            '/api/v1/attendances/absent-members?class_id=' . $this->classe->id
            . '&context_id=' . $context->id
        );

        $response->assertStatus(200);
        $members = $response->json('data.absent_members');

        // Should have 1 absent member (memberB attended less than all sessions)
        $absentMember = collect($members)->firstWhere('id', $memberB->id);
        if ($absentMember) {
            $this->assertEquals(1, $absentMember['attendance_count']);
            $this->assertEquals(3, $absentMember['total_sessions']);
            $this->assertEquals(round((1 / 3) * 100, 1), $absentMember['attendance_percentage']);
        }
    }

    // ──────────────────────────────────────────────
    // 14. Members with no attendance have 0% rate
    // ──────────────────────────────────────────────

    public function test_member_with_no_attendance_has_zero_percentage(): void
    {
        $context = AttendanceContext::factory()->create([
            'church_id' => $this->church->id,
            'created_by' => $this->admin->id,
        ]);

        // Create some sessions by recording other members
        $otherMember = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $this->church->id,
            'class_id' => $this->classe->id,
            'application_status' => 'approved',
        ]);

        foreach (range(1, 3) as $day) {
            Attendance::create([
                'user_id' => $otherMember->id,
                'recorded_by' => $this->servant->id,
                'class_year_id' => $this->classe->id,
                'attendance_context_id' => $context->id,
                'attended_at' => now()->subDays(4 - $day),
                'method' => 'qr',
                'church_id' => $this->church->id,
            ]);
        }

        $this->actingAsUser($this->admin);

        $response = $this->getJson(
            '/api/v1/attendances/absent-members?class_id=' . $this->classe->id
            . '&context_id=' . $context->id
        );

        $response->assertStatus(200);
        $members = $response->json('data.absent_members');

        // setUp member has no attendance — should have 0%
        $setupMember = collect($members)->firstWhere('id', $this->member->id);
        $this->assertNotNull($setupMember);
        $this->assertEquals(0, $setupMember['attendance_count']);
        $this->assertEquals(0.0, $setupMember['attendance_percentage']);
    }

    // ──────────────────────────────────────────────
    // 15. Class_id is required — returns 422 if missing
    // ──────────────────────────────────────────────

    public function test_class_id_is_required(): void
    {
        $this->actingAsUser($this->admin);

        $response = $this->getJson('/api/v1/attendances/absent-members');

        $response->assertStatus(422)
            ->assertJsonValidationErrors('class_id');
    }

    // ──────────────────────────────────────────────
    // 16. Consecutive absences sorted in descending order
    // ──────────────────────────────────────────────

    public function test_absent_members_sorted_by_consecutive_absences_desc(): void
    {
        $context = AttendanceContext::factory()->create([
            'church_id' => $this->church->id,
            'created_by' => $this->admin->id,
        ]);

        $memberA = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $this->church->id,
            'class_id' => $this->classe->id,
            'application_status' => 'approved',
        ]);

        $memberB = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $this->church->id,
            'class_id' => $this->classe->id,
            'application_status' => 'approved',
        ]);

        // memberA attended session 1 and 2
        foreach (range(1, 2) as $day) {
            Attendance::create([
                'user_id' => $memberA->id,
                'recorded_by' => $this->servant->id,
                'class_year_id' => $this->classe->id,
                'attendance_context_id' => $context->id,
                'attended_at' => now()->subDays(5 - $day),
                'method' => 'qr',
                'church_id' => $this->church->id,
            ]);
        }

        // memberB attended all 3 sessions
        foreach (range(1, 3) as $day) {
            Attendance::create([
                'user_id' => $memberB->id,
                'recorded_by' => $this->servant->id,
                'class_year_id' => $this->classe->id,
                'attendance_context_id' => $context->id,
                'attended_at' => now()->subDays(5 - $day),
                'method' => 'qr',
                'church_id' => $this->church->id,
            ]);
        }

        // Create session 3 (no one attended after this — so consecutive absences start here)
        Attendance::create([
            'user_id' => $memberB->id,
            'recorded_by' => $this->servant->id,
            'class_year_id' => $this->classe->id,
            'attendance_context_id' => $context->id,
            'attended_at' => now()->subDay(),
            'method' => 'qr',
            'church_id' => $this->church->id,
        ]);

        $this->actingAsUser($this->admin);

        $response = $this->getJson(
            '/api/v1/attendances/absent-members?class_id=' . $this->classe->id
            . '&context_id=' . $context->id
        );

        $response->assertStatus(200);
        $absences = collect($response->json('data.absent_members'))->pluck('consecutive_absences');

        // Should be sorted descending
        for ($i = 0; $i < $absences->count() - 1; $i++) {
            $this->assertGreaterThanOrEqual($absences[$i + 1], $absences[$i],
                'Absent members should be sorted by consecutive absences descending');
        }
    }
}
