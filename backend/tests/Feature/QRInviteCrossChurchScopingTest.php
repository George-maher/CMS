<?php

namespace Tests\Feature;

use App\Enums\QRInviteType;
use App\Enums\UserRole;
use App\Enums\UserScope;
use App\Models\AttendanceContext;
use App\Models\Church;
use App\Models\Classe;
use App\Models\Permission;
use App\Models\QRInvite;
use App\Models\Stage;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class QRInviteCrossChurchScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Permission::clearCache();
    }

    private function stagedServantFor(Church $church): User
    {
        $stage = Stage::factory()->forChurch($church)->create();
        $classe = Classe::factory()->forChurch($church)->state(['stage_id' => $stage->id])->create();

        return User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $church->id,
            'stage_id' => $stage->id,
            'class_id' => $classe->id,
            'scope' => UserScope::ClassScope->value,
            'application_status' => 'approved',
        ]);
    }

    public function test_invite_cannot_reference_attendance_context_from_another_church(): void
    {
        $churchA = Church::factory()->create();
        $churchB = Church::factory()->create();

        $foreignContext = AttendanceContext::factory()->create(['church_id' => $churchB->id]);

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $churchA->id,
            'application_status' => 'approved',
        ]);
        $token = $admin->createToken('test', [$admin->role->value])->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/qr/invites', [
                'type' => QRInviteType::AttendanceQR->value,
                'attendance_context_id' => $foreignContext->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('attendance_context_id');
    }

    public function test_invite_can_reference_attendance_context_from_own_church(): void
    {
        $church = Church::factory()->create();

        $context = AttendanceContext::factory()->create(['church_id' => $church->id]);

        $servant = $this->stagedServantFor($church);
        $token = $servant->createToken('test', [$servant->role->value])->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/qr/invites', [
                'type' => QRInviteType::AttendanceQR->value,
                'attendance_context_id' => $context->id,
            ])
            ->assertStatus(201)
            ->assertJsonStructure(['data' => ['invite' => ['id', 'type', 'url'], 'url']]);
    }

    public function test_lookup_by_token_does_not_leak_foreign_church_attendance_context(): void
    {
        $churchA = Church::factory()->create();
        $churchB = Church::factory()->create();

        $foreignContext = AttendanceContext::factory()->create(['church_id' => $churchB->id]);

        $servant = $this->stagedServantFor($churchA);
        $token = $servant->createToken('test', [$servant->role->value])->plainTextToken;

        $invite = QRInvite::create([
            'type' => QRInviteType::AttendanceQR,
            'token' => Str::random(48),
            'created_by' => $servant->id,
            'church_id' => $churchA->id,
            'attendance_context_id' => $foreignContext->id,
            'expires_at' => now()->addHours(4),
            'is_single_use' => true,
            'max_uses' => 1,
            'use_count' => 0,
            'is_revoked' => false,
        ]);

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/attendances/lookup/{$invite->token}")
            ->assertOk()
            ->assertJsonPath('data.attendance_context_id', null)
            ->assertJsonPath('data.attendance_context', null);
    }
}
