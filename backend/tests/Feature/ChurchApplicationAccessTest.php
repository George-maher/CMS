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

    public function test_lookup_reveals_nothing_to_unauthenticated_caller(): void
    {
        $response = $this->postJson('/api/v1/church-applications/lookup', [
            'email' => 'applicant@gracechurch.org',
        ]);

        $response->assertOk();

        // An anonymous caller must learn nothing at all: not the application
        // body, not the contact email, not the approval status, and not even
        // whether an application exists for the address.
        $response->assertJsonPath('data', null);
        $response->assertJsonPath('message', __('application.exists_sign_in'));

        /** @var array<string, mixed> $body */
        $body = $response->json();
        $this->assertSame(['data', 'message'], array_keys($body));
    }

    public function test_lookup_is_indistinguishable_for_existing_missing_and_foreign_owner(): void
    {
        $existing = $this->postJson('/api/v1/church-applications/lookup', [
            'email' => 'applicant@gracechurch.org',
        ]);

        $missing = $this->postJson('/api/v1/church-applications/lookup', [
            'email' => 'nobody@gracechurch.org',
        ]);

        $this->assertSame(200, $existing->getStatusCode());
        $this->assertSame($existing->getStatusCode(), $missing->getStatusCode());
        $this->assertSame($existing->json('data'), $missing->json('data'));
        $this->assertSame($existing->json('message'), $missing->json('message'));

        // A signed-in account that is NOT the application owner gets the very
        // same response, so the endpoint cannot be used as an existence or
        // approval-status oracle by an authenticated bystander either.
        Sanctum::actingAs(User::factory()->create([
            'email' => 'bystander@gracechurch.org',
            'role' => UserRole::Member,
        ]));

        $bystander = $this->postJson('/api/v1/church-applications/lookup', [
            'email' => 'applicant@gracechurch.org',
        ]);

        $this->assertSame(200, $bystander->getStatusCode());
        $this->assertSame($existing->json('data'), $bystander->json('data'));
        $this->assertSame($existing->json('message'), $bystander->json('message'));
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
