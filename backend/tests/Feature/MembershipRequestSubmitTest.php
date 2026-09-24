<?php

namespace Tests\Feature;

use App\Models\Church;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MembershipRequestSubmitTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_pending_membership_request_is_rejected(): void
    {
        $church = Church::factory()->create();
        $payload = [
            'church_id' => $church->id,
            'name' => 'Join Seeker',
            'email' => 'seeker@sample.org',
        ];

        $this->postJson('/api/v1/membership-requests', $payload)
            ->assertStatus(201);

        // A duplicate pending submission must be rejected with a validation
        // error — the church-bound duplicate lookup must not be bypassable
        // (bypassing it would hit the global email unique index → 500).
        $this->postJson('/api/v1/membership-requests', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('membership_requests', 1);
    }
}
