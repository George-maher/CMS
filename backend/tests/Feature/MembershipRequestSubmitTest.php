<?php

namespace Tests\Feature;

use App\Models\Church;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SECURITY: the public join endpoint must not reveal whether an address
 * already has an account or a pending request in this church.
 *
 * `/membership-requests` is unauthenticated and `/churches/active` is public,
 * so a distinguishable outcome would be a cross-church member enumeration
 * oracle. A duplicate is suppressed internally and acknowledged with the
 * identical response a first-time submission receives.
 */
class MembershipRequestSubmitTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_pending_membership_request_is_indistinguishable_from_a_new_one(): void
    {
        $church = Church::factory()->create();
        $payload = [
            'church_id' => $church->id,
            'name' => 'Join Seeker',
            'email' => 'seeker@sample.org',
        ];

        $first = $this->postJson('/api/v1/membership-requests', $payload);
        $first->assertStatus(201);

        $duplicate = $this->postJson('/api/v1/membership-requests', $payload);
        $duplicate->assertStatus(201);

        $this->assertSame(
            $first->json(),
            $duplicate->json(),
            'A duplicate must be indistinguishable from a new submission.'
        );

        // The duplicate is still refused internally: the church-bound duplicate
        // lookup must not be bypassable (bypassing it would hit the unique index
        // on (church_id, email) and surface a 500).
        $this->assertDatabaseCount('membership_requests', 1);
    }

    /**
     * SECURITY: an address that already has an account must be indistinguishable
     * from one that does not.
     */
    public function test_address_that_already_has_an_account_is_indistinguishable(): void
    {
        $church = Church::factory()->create();

        User::factory()->create([
            'church_id' => $church->id,
            'email' => 'existing@sample.org',
            'application_status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $existingAccount = $this->postJson('/api/v1/membership-requests', [
            'church_id' => $church->id,
            'name' => 'Someone',
            'email' => 'existing@sample.org',
        ]);

        $noAccount = $this->postJson('/api/v1/membership-requests', [
            'church_id' => $church->id,
            'name' => 'Someone',
            'email' => 'stranger@sample.org',
        ]);

        $existingAccount->assertStatus(201);
        $noAccount->assertStatus(201);

        $this->assertSame(
            $noAccount->json(),
            $existingAccount->json(),
            'An address with an account must be indistinguishable from one without.'
        );

        // Only the genuine newcomer is persisted.
        $this->assertDatabaseCount('membership_requests', 1);
        $this->assertDatabaseHas('membership_requests', ['email' => 'stranger@sample.org']);
    }

    public function test_unknown_church_is_rejected(): void
    {
        $this->postJson('/api/v1/membership-requests', [
            'church_id' => 999999,
            'name' => 'Nobody',
            'email' => 'nobody@sample.org',
        ])->assertStatus(404)->assertJsonPath('code', 'CHURCH_NOT_FOUND');
    }
}
