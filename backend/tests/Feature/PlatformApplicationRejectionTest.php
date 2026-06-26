<?php

namespace Tests\Feature;

use App\Models\ChurchApplication;
use App\Models\User;
use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformApplicationRejectionTest extends TestCase
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

    public function test_platform_admin_can_reject_with_reason(): void
    {
        $token = $this->platformAdmin->createToken('test', ['platform_admin'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/platform/applications/{$this->application->id}/reject", [
                'rejection_reason' => 'Missing required documentation.',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('church_applications', [
            'id' => $this->application->id,
            'status' => 'rejected',
            'rejection_reason' => 'Missing required documentation.',
            'reviewed_by' => $this->platformAdmin->id,
        ]);
    }

    public function test_rejection_reason_is_required(): void
    {
        $token = $this->platformAdmin->createToken('test', ['platform_admin'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/platform/applications/{$this->application->id}/reject", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['rejection_reason']);
    }

    public function test_rejection_reason_must_be_string(): void
    {
        $token = $this->platformAdmin->createToken('test', ['platform_admin'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/platform/applications/{$this->application->id}/reject", [
                'rejection_reason' => 12345,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['rejection_reason']);
    }

    public function test_rejection_reason_max_length(): void
    {
        $token = $this->platformAdmin->createToken('test', ['platform_admin'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/platform/applications/{$this->application->id}/reject", [
                'rejection_reason' => str_repeat('a', 2001),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['rejection_reason']);
    }

    public function test_non_platform_admin_cannot_reject(): void
    {
        $servant = User::factory()->create(['role' => UserRole::Servant]);
        $token = $servant->createToken('test', ['servant'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/platform/applications/{$this->application->id}/reject", [
                'rejection_reason' => 'Test reason.',
            ]);

        $response->assertStatus(403);
    }

    public function test_rejection_updates_user_application_status(): void
    {
        $user = User::factory()->create([
            'church_application_id' => $this->application->id,
            'application_status' => 'pending',
        ]);

        $token = $this->platformAdmin->createToken('test', ['platform_admin'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/platform/applications/{$this->application->id}/reject", [
                'rejection_reason' => 'Invalid ID documents.',
            ]);

        $user->refresh();
        $this->assertEquals('rejected', $user->application_status);
    }

    public function test_rejection_reason_appears_in_resource(): void
    {
        $token = $this->platformAdmin->createToken('test', ['platform_admin'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/platform/applications/{$this->application->id}/reject", [
                'rejection_reason' => 'Church name mismatch.',
            ]);

        $response->assertJsonPath('data.rejection_reason', 'Church name mismatch.');
    }

    public function test_pending_dashboard_returns_rejection_reason(): void
    {
        $application = ChurchApplication::factory()->rejected('Invalid contact info')->create();
        $user = User::factory()->create([
            'church_application_id' => $application->id,
            'application_status' => 'rejected',
            'role' => UserRole::Member,
        ]);

        $token = $user->createToken('test', ['member'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/pending/status');

        $response->assertStatus(200)
            ->assertJsonPath('data.application.rejection_reason', 'Invalid contact info');
    }
}
