<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AttendanceContext;
use App\Models\Classe;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Permission::clearCache();
    }

    public function test_servant_can_record_attendance_for_member(): void
    {
        $classe = Classe::factory()->create();
        $servant = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $classe->church_id,
            'class_id' => $classe->id,
        ]);
        $context = AttendanceContext::factory()->create([
            'church_id' => $classe->church_id,
            'created_by' => $servant->id,
        ]);
        $member = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $classe->church_id,
            'class_id' => $classe->id,
            'attendance_qr_token' => User::generateAttendanceQrToken(),
        ]);

        $token = $servant->createToken('test', [$servant->role->value])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/attendances/record', [
                'qr_token' => $member->attendance_qr_token,
                'attendance_context_id' => $context->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.attendance.user.id', $member->id);
    }

    public function test_duplicate_attendance_same_day_is_rejected(): void
    {
        $classe = Classe::factory()->create();
        $servant = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $classe->church_id,
            'class_id' => $classe->id,
        ]);
        $context = AttendanceContext::factory()->create([
            'church_id' => $classe->church_id,
            'created_by' => $servant->id,
        ]);
        $member = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $classe->church_id,
            'class_id' => $classe->id,
            'attendance_qr_token' => User::generateAttendanceQrToken(),
        ]);

        $token = $servant->createToken('test', [$servant->role->value])->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/attendances/record', [
                'qr_token' => $member->attendance_qr_token,
                'attendance_context_id' => $context->id,
            ])->assertStatus(201);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/attendances/record', [
                'qr_token' => $member->attendance_qr_token,
                'attendance_context_id' => $context->id,
            ]);

        $response->assertStatus(422);
    }

    public function test_member_cannot_record_attendance(): void
    {
        $member = User::factory()->create(['role' => UserRole::Member]);

        $token = $member->createToken('test', [$member->role->value])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/attendances/record', [
                'qr_token' => 'some-token',
            ]);

        $response->assertStatus(403);
    }
}
