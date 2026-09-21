<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ChurchApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChurchApplicationApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $platformAdmin;

    private ChurchApplication $application;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::factory()->create([
            'role' => UserRole::PlatformAdmin,
        ]);

        $this->application = ChurchApplication::factory()->pending()->create();
    }

    public function test_platform_admin_can_approve_application(): void
    {
        $token = $this->platformAdmin->createToken('test', ['platform_admin'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/platform/applications/{$this->application->id}/approve", [
                'notes' => 'Approved after review',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('church_applications', [
            'id' => $this->application->id,
            'status' => 'approved',
            'reviewed_by' => $this->platformAdmin->id,
        ]);
    }

    public function test_approval_with_arabic_church_name_works(): void
    {
        $application = ChurchApplication::factory()->pending()->create([
            'church_name' => 'كنيسة القديس مارمرقس',
            'priest_name' => 'الأب يوحنا',
            'address' => 'القاهرة، مصر',
        ]);

        $token = $this->platformAdmin->createToken('test', ['platform_admin'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/platform/applications/{$application->id}/approve", [
                'notes' => 'موافقة',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('church_applications', [
            'id' => $application->id,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('churches', [
            'name' => 'كنيسة القديس مارمرقس',
        ]);
    }

    public function test_approval_creates_audit_log(): void
    {
        $token = $this->platformAdmin->createToken('test', ['platform_admin'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/platform/applications/{$this->application->id}/approve", [
                'notes' => 'Approved',
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'resource_type' => 'App\Models\Church',
            'action' => 'created',
        ]);
    }

    public function test_already_approved_cannot_be_approved_again(): void
    {
        $application = ChurchApplication::factory()->approved()->create();

        $token = $this->platformAdmin->createToken('test', ['platform_admin'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/platform/applications/{$application->id}/approve", [
                'notes' => 'Trying again',
            ]);

        $response->assertStatus(422);
    }

    public function test_non_platform_admin_cannot_approve(): void
    {
        $servant = User::factory()->create(['role' => UserRole::Servant]);
        $token = $servant->createToken('test', ['servant'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/platform/applications/{$this->application->id}/approve", [
                'notes' => 'Trying to approve',
            ]);

        $response->assertStatus(403);
    }
}
