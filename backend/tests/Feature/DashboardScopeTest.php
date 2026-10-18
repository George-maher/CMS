<?php

namespace Tests\Feature;

use App\Enums\PointType;
use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\AttendanceContext;
use App\Models\Church;
use App\Models\Classe;
use App\Models\Permission;
use App\Models\Point;
use App\Models\Stage;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DashboardScopeTest extends TestCase
{
    use RefreshDatabase;

    private Church $church;

    private Stage $stage;

    private Classe $class;

    private User $admin;

    private User $assistant;

    private User $stageAdmin;

    private User $member;

    private User $otherStageMember;

    private User $foreignMember;

    private AttendanceContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Permission::clearCache();
        Cache::flush();

        $this->church = Church::factory()->create();
        $this->stage = Stage::factory()->forChurch($this->church)->create();
        $this->class = Classe::factory()->forChurch($this->church)->state(['stage_id' => $this->stage->id])->create();
        $otherStage = Stage::factory()->forChurch($this->church)->create();
        $otherClass = Classe::factory()->forChurch($this->church)->state(['stage_id' => $otherStage->id])->create();

        $foreignChurch = Church::factory()->create();
        $foreignStage = Stage::factory()->forChurch($foreignChurch)->create();
        $foreignClass = Classe::factory()->forChurch($foreignChurch)->state(['stage_id' => $foreignStage->id])->create();

        $this->admin = $this->makeUser(UserRole::Admin, $this->church);
        $this->assistant = $this->makeUser(UserRole::AssistantAdmin, $this->church);
        $this->stageAdmin = $this->makeUser(UserRole::StageAdmin, $this->church, $this->stage, $this->class);
        $this->makeUser(UserRole::Servant, $this->church, $this->stage, $this->class);
        $this->member = $this->makeUser(UserRole::Member, $this->church, $this->stage, $this->class);
        $this->otherStageMember = $this->makeUser(UserRole::Member, $this->church, $otherStage, $otherClass);
        $this->foreignMember = $this->makeUser(UserRole::Member, $foreignChurch, $foreignStage, $foreignClass);

        $this->context = AttendanceContext::factory()->create([
            'church_id' => $this->church->id,
            'created_by' => $this->admin->id,
        ]);

        $this->recordAttendance($this->member, $this->class, now(), $this->context);
        $this->recordAttendance($this->member, $this->class, now()->subDays(2));
        $this->recordAttendance($this->member, $this->class, now()->subMonth());
        $this->recordAttendance($this->otherStageMember, $otherClass, now());
        $this->recordAttendance($this->foreignMember, $foreignClass, now());

        Point::create([
            'user_id' => $this->member->id,
            'points' => 7,
            'type' => PointType::Attendance->value,
            'church_id' => $this->church->id,
        ]);
        Point::create([
            'user_id' => $this->otherStageMember->id,
            'points' => 11,
            'type' => PointType::Attendance->value,
            'church_id' => $this->church->id,
        ]);
        Point::create([
            'user_id' => $this->foreignMember->id,
            'points' => 13,
            'type' => PointType::Attendance->value,
            'church_id' => $foreignChurch->id,
        ]);
    }

    private function makeUser(UserRole $role, Church $church, ?Stage $stage = null, ?Classe $class = null): User
    {
        return User::factory()->create([
            'role' => $role,
            'church_id' => $church->id,
            'stage_id' => $stage?->id,
            'class_id' => $class?->id,
            'application_status' => 'approved',
        ]);
    }

    private function recordAttendance(User $member, Classe $class, $attendedAt, ?AttendanceContext $context = null): void
    {
        Attendance::create([
            'user_id' => $member->id,
            'recorded_by' => (int) $member->church_id === (int) $this->admin->church_id ? $this->admin->id : $member->id,
            'class_year_id' => $class->id,
            'attendance_context_id' => $context?->id,
            'attended_at' => $attendedAt,
            'method' => 'id',
            'church_id' => $member->church_id,
        ]);
    }

    private function authAs(User $user): self
    {
        $token = $user->createToken('test', [$user->role->value])->plainTextToken;

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_admin_and_assistant_share_church_wide_dashboard_aggregates(): void
    {
        // Prime the narrow-stage cache first to catch scope key collisions.
        $this->authAs($this->stageAdmin)
            ->getJson('/api/v1/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('data.total_members', 1)
            ->assertJsonPath('data.total_servants', 1)
            ->assertJsonPath('data.total_attendances', 3)
            ->assertJsonPath('data.total_points', 7);

        $adminResponse = $this->authAs($this->admin)->getJson('/api/v1/dashboard/stats')->assertOk();
        $assistantResponse = $this->authAs($this->assistant)->getJson('/api/v1/dashboard/stats')->assertOk();
        $this->assertSame($adminResponse->json('data'), $assistantResponse->json('data'));
        $this->assertSame(2, $adminResponse->json('data.total_members'));
        $this->assertSame(4, $adminResponse->json('data.total_attendances'));
        $this->assertSame(18, $adminResponse->json('data.total_points'));
    }

    public function test_dashboard_stats_are_denied_to_servants_and_members(): void
    {
        foreach ([$this->member, $this->otherStageMember] as $member) {
            $this->authAs($member)->getJson('/api/v1/dashboard/stats')->assertForbidden();
        }
    }

    public function test_stage_attendance_statistics_are_scoped_and_support_date_and_context_filters(): void
    {
        $this->authAs($this->stageAdmin)
            ->getJson('/api/v1/attendances/stage-stats')
            ->assertOk()
            ->assertJsonPath('data.total_attendances', 3)
            ->assertJsonPath('data.this_month', 2);

        $this->authAs($this->stageAdmin)
            ->getJson('/api/v1/attendances/stage-stats?date_from='.now()->toDateString().'&date_to='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.total_attendances', 1)
            ->assertJsonPath('data.this_month', 1);

        $this->authAs($this->stageAdmin)
            ->getJson('/api/v1/attendances/stage-stats?attendance_context_id='.$this->context->id)
            ->assertOk()
            ->assertJsonPath('data.total_attendances', 1)
            ->assertJsonPath('data.this_month', 1);

        $this->authAs($this->member)
            ->getJson('/api/v1/attendances/stage-stats')
            ->assertForbidden();
    }
}
