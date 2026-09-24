<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ChurchApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChurchApplicationAccessTest extends TestCase
{
    use RefreshDatabase;

    private ChurchApplication $application;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->application = ChurchApplication::factory()->create([
            'church_name' => 'Grace Community Church',
            'priest_name' => 'Fr. John Paul',
            'main_servant_name' => 'Mary Isaac',
            'priest_phone' => '01012345678',
            'phone' => '01012345678',
            'address' => '12 Nile Corniche, Cairo',
            'contact_email' => 'applicant@gracechurch.org',
            'status' => 'pending',
            'front_id_path' => 'ids/church-applications/1/front-secret.jpg',
        ]);

        $this->owner = User::factory()->create([
            'church_application_id' => $this->application->id,
            'email' => 'applicant@gracechurch.org',
            'password' => 'Original@123',
            'role' => UserRole::Admin,
            'application_status' => 'pending',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function updatePayload(array $overrides = []): array
    {
        return array_merge([
            'church_name' => 'Updated Grace Church',
            'priest_name' => 'Fr. Peter Mourad',
            'main_servant_name' => 'Basil Adel',
            'phone' => '01087654321',
            'email' => 'applicant@gracechurch.org',
            'address' => '45 Lake View, Giza',
            'id_type' => 'national_id',
        ], $overrides);
    }

    public function test_lookup_does_not_leak_application_pii_to_unauthenticated_caller(): void
    {
        $response = $this->postJson('/api/v1/church-applications/lookup', [
            'email' => 'applicant@gracechurch.org',
        ]);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertIsArray($data);

        // Anonymous callers may only learn existence + status. Every PII field
        // of ChurchApplicationResource must be absent from the projection.
        $piiKeys = [
            'church_name', 'service_name', 'priest_name', 'main_servant_name',
            'priest_phone', 'phone', 'address',
            'front_id_url', 'back_id_url', 'church_permission_doc_url',
            'id_type', 'admin_notes', 'rejection_reason',
        ];
        foreach ($piiKeys as $piiKey) {
            $this->assertArrayNotHasKey(
                $piiKey,
                $data,
                "PII field [{$piiKey}] leaked to an unauthenticated lookup caller."
            );
        }

        $this->assertSame('pending', $data['status'] ?? null);
        $this->assertSame('applicant@gracechurch.org', $data['contact_email'] ?? null);
    }

    public function test_lookup_returns_full_application_to_authenticated_owner(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/v1/church-applications/lookup', [
            'email' => 'applicant@gracechurch.org',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.church_name', 'Grace Community Church')
            ->assertJsonPath('data.phone', '01012345678')
            ->assertJsonPath('data.address', '12 Nile Corniche, Cairo');
    }

    public function test_anonymous_update_of_existing_application_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/church-applications', $this->updatePayload());

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseHas('church_applications', [
            'id' => $this->application->id,
            'church_name' => 'Grace Community Church',
            'address' => '12 Nile Corniche, Cairo',
        ]);
    }

    public function test_wrong_password_update_of_existing_application_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/church-applications', $this->updatePayload([
            'password' => 'WrongPassword@1',
            'password_confirmation' => 'WrongPassword@1',
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseHas('church_applications', [
            'id' => $this->application->id,
            'church_name' => 'Grace Community Church',
        ]);
    }

    public function test_owner_password_update_of_existing_application_is_accepted(): void
    {
        $response = $this->postJson('/api/v1/church-applications', $this->updatePayload([
            'password' => 'Original@123',
            'password_confirmation' => 'Original@123',
        ]));

        $response->assertOk()
            ->assertJsonPath('is_update', true);

        $this->assertDatabaseHas('church_applications', [
            'id' => $this->application->id,
            'church_name' => 'Updated Grace Church',
        ]);
    }

    public function test_authenticated_owner_session_update_is_accepted(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/v1/church-applications', $this->updatePayload());

        $response->assertOk()
            ->assertJsonPath('is_update', true);

        $this->assertDatabaseHas('church_applications', [
            'id' => $this->application->id,
            'church_name' => 'Updated Grace Church',
        ]);
    }

    public function test_approved_application_cannot_be_updated_via_public_endpoint(): void
    {
        $this->application->update(['status' => 'approved']);
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/v1/church-applications', $this->updatePayload());

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseHas('church_applications', [
            'id' => $this->application->id,
            'church_name' => 'Grace Community Church',
            'status' => 'approved',
        ]);
    }

    public function test_rejected_application_resubmission_by_owner_resets_to_pending(): void
    {
        $this->application->update([
            'status' => 'rejected',
            'rejection_reason' => 'Missing documents',
            'rejected_at' => now(),
        ]);
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/church-applications', $this->updatePayload())
            ->assertOk();

        $this->assertDatabaseHas('church_applications', [
            'id' => $this->application->id,
            'status' => 'pending',
            'rejection_reason' => null,
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $this->owner->id,
            'application_status' => 'pending',
        ]);
    }
}
