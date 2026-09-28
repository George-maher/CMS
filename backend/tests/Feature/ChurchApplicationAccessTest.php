<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ChurchApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Access control and enumeration resistance for the public church application
 * endpoint.
 *
 * `/church-applications` and `/church-applications/lookup` are unauthenticated,
 * so every response they produce is observable by anyone on the internet.
 */
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
     * A complete submission body.
     *
     * The password and the identity documents are included for every address on
     * purpose: the FormRequest's requirements must not depend on whether an
     * application already exists, otherwise the validation response itself is an
     * enumeration oracle.
     *
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
            'password' => 'Supplied@12345',
            'password_confirmation' => 'Supplied@12345',
        ], $overrides);
    }

    /**
     * Submit as multipart, which is how the real client sends it.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function submitApplication(array $overrides = []): TestResponse
    {
        $frontId = UploadedFile::fake()->create('front.jpg', 8, 'image/jpeg');
        $backId = UploadedFile::fake()->create('back.jpg', 8, 'image/jpeg');

        return $this->post(
            '/api/v1/church-applications',
            array_merge($this->updatePayload($overrides), [
                'front_id' => $frontId,
                'back_id' => $backId,
            ]),
            ['Accept' => 'application/json'],
        );
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

    /**
     * SECURITY: an anonymous resubmission for an address that already has an
     * application must be indistinguishable from a first-time submission.
     *
     * A distinguishable "this email already exists" outcome is an enumeration
     * oracle on a public endpoint, so the submission is suppressed internally and
     * answered with the acknowledgement a new applicant receives.
     */
    public function test_anonymous_update_of_existing_application_is_indistinguishable_from_a_new_one(): void
    {
        $knownAddress = $this->submitApplication();
        $unknownAddress = $this->submitApplication([
            'email' => 'brand-new-applicant@gracechurch.org',
        ]);

        $knownAddress->assertStatus(201);
        $unknownAddress->assertStatus(201);

        $knownBody = $knownAddress->json();
        $unknownBody = $unknownAddress->json();
        $this->assertIsArray($knownBody);
        $this->assertIsArray($unknownBody);

        $this->assertSame(array_keys($unknownBody), array_keys($knownBody));
        $this->assertSame($unknownBody['code'], $knownBody['code']);
        $this->assertSame($unknownBody['is_update'], $knownBody['is_update']);
        $this->assertSame($unknownBody['message'], $knownBody['message']);

        // The only field allowed to differ is the address the caller itself
        // supplied, which it already knows. Everything that describes the stored
        // record must be identical.
        $knownData = $knownBody['data'];
        $unknownData = $unknownBody['data'];
        $this->assertIsArray($knownData);
        $this->assertIsArray($unknownData);
        $this->assertSame(array_keys($knownData), array_keys($unknownData));
        $this->assertSame('pending', $knownData['status']);
        $this->assertSame('pending', $unknownData['status']);
        unset($knownData['contact_email'], $unknownData['contact_email']);
        $this->assertSame($unknownData, $knownData);

        // No application identity is disclosed for the suppressed case.
        $this->assertArrayNotHasKey('id', $knownBody['data']);

        // Only the genuine newcomer produced a record; the existing one is intact.
        $this->assertDatabaseCount('church_applications', 2);
        $this->assertDatabaseHas('church_applications', [
            'id' => $this->application->id,
            'church_name' => 'Grace Community Church',
            'address' => '12 Nile Corniche, Cairo',
        ]);
    }

    /**
     * SECURITY: the *validation response* must also be identical, otherwise the
     * set of reported errors reveals which addresses are already registered.
     */
    public function test_validation_requirements_do_not_depend_on_whether_the_address_is_known(): void
    {
        $body = [
            'church_name' => 'Updated Grace Church',
            'priest_name' => 'Fr. Peter Mourad',
            'main_servant_name' => 'Basil Adel',
            'phone' => '01087654321',
            'address' => '45 Lake View, Giza',
            'id_type' => 'national_id',
        ];

        $known = $this->postJson('/api/v1/church-applications', array_merge($body, [
            'email' => 'applicant@gracechurch.org',
        ]));

        $unknown = $this->postJson('/api/v1/church-applications', array_merge($body, [
            'email' => 'brand-new-applicant@gracechurch.org',
        ]));

        $known->assertStatus(422);
        $unknown->assertStatus(422);

        /** @var array<string, mixed> $knownErrors */
        $knownErrors = $known->json('errors') ?? [];
        /** @var array<string, mixed> $unknownErrors */
        $unknownErrors = $unknown->json('errors') ?? [];

        $this->assertSame(
            array_keys($unknownErrors),
            array_keys($knownErrors),
            'The reported validation errors must not reveal whether the address is already registered.'
        );
        $this->assertArrayHasKey('password', $knownErrors);
        $this->assertArrayHasKey('password', $unknownErrors);
        $this->assertArrayHasKey('front_id', $knownErrors);
        $this->assertArrayHasKey('front_id', $unknownErrors);
    }

    /**
     * SECURITY: a wrong applicant password must not become an existence probe.
     */
    public function test_wrong_password_update_of_existing_application_is_indistinguishable(): void
    {
        $response = $this->submitApplication([
            'password' => 'WrongPassword@1',
            'password_confirmation' => 'WrongPassword@1',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('is_update', false);

        $this->assertDatabaseCount('church_applications', 1);
        $this->assertDatabaseHas('church_applications', [
            'id' => $this->application->id,
            'church_name' => 'Grace Community Church',
        ]);
    }

    /**
     * SECURITY: an address that already holds an account but no application must
     * also be suppressed rather than surfacing a unique-constraint 500.
     */
    public function test_address_holding_an_account_without_an_application_is_suppressed(): void
    {
        User::factory()->create([
            'email' => 'stranger@gracechurch.org',
            'church_application_id' => null,
            'application_status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $response = $this->submitApplication([
            'email' => 'stranger@gracechurch.org',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('is_update', false);

        $this->assertDatabaseCount('church_applications', 1);
    }

    public function test_owner_password_update_of_existing_application_is_accepted(): void
    {
        $response = $this->submitApplication([
            'password' => 'Original@123',
            'password_confirmation' => 'Original@123',
        ]);

        $response->assertOk()
            ->assertJsonPath('is_update', true)
            ->assertJsonPath('data.church_name', 'Updated Grace Church');

        $this->assertDatabaseHas('church_applications', [
            'id' => $this->application->id,
            'church_name' => 'Updated Grace Church',
        ]);
    }

    public function test_authenticated_owner_session_update_is_accepted(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->submitApplication();

        $response->assertOk()
            ->assertJsonPath('is_update', true);

        $this->assertDatabaseHas('church_applications', [
            'id' => $this->application->id,
            'church_name' => 'Updated Grace Church',
        ]);
    }

    /**
     * A proven applicant session is the only caller attribute that may relax the
     * submission requirements, and it is derived from the authenticated principal
     * rather than from the submitted address.
     */
    public function test_proven_applicant_session_does_not_need_to_resupply_credentials(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/v1/church-applications', [
            'church_name' => 'Updated Grace Church',
            'priest_name' => 'Fr. Peter Mourad',
            'main_servant_name' => 'Basil Adel',
            'phone' => '01087654321',
            'email' => 'applicant@gracechurch.org',
            'address' => '45 Lake View, Giza',
            'id_type' => 'national_id',
        ]);

        $response->assertOk()
            ->assertJsonPath('is_update', true);
    }

    public function test_approved_application_cannot_be_updated_via_public_endpoint(): void
    {
        $this->application->update(['status' => 'approved']);
        Sanctum::actingAs($this->owner);

        $response = $this->submitApplication();

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

        $this->submitApplication()
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
