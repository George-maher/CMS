<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Classe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_servant_can_record_attendance_for_member(): void
    {
        $classe = Classe::factory()->create();
        $servant = User::factory()->create([
            'role' => UserRole::Servant,
            'class_year_id' => $classe->id,
        ]);
        $member = User::factory()->create([
            'role' => UserRole::Member,
            'class_year_id' => $classe->id,
            'attendance_qr_token' => User::generateAttendanceQrToken(),
        ]);

        $token = $servant->createToken('test', [$servant->role->value])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/attendances/record', [
                'qr_token' => $member->attendance_qr_token,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.attendance.user.id', $member->id);
    }

    public function test_duplicate_attendance_same_day_is_rejected(): void
    {
        $classe = Classe::factory()->create();
        $servant = User::factory()->create([
            'role' => UserRole::Servant,
            'class_year_id' => $classe->id,
        ]);
        $member = User::factory()->create([
            'role' => UserRole::Member,
            'class_year_id' => $classe->id,
            'attendance_qr_token' => User::generateAttendanceQrToken(),
        ]);

        $token = $servant->createToken('test', [$servant->role->value])->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/attendances/record', [
                'qr_token' => $member->attendance_qr_token,
            ])->assertStatus(201);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/attendances/record', [
                'qr_token' => $member->attendance_qr_token,
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
