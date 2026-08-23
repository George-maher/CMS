<?php

namespace Tests\Feature;

use App\Enums\EventAttendanceStatus;
use App\Enums\EventPaymentStatus;
use App\Enums\EventStatus;
use App\Enums\EventType;
use App\Enums\RegistrationStatus;
use App\Enums\UserRole;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Permission::clearCache();
    }

    private function makeUser(Church $church, UserRole $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'church_id' => $church->id,
        ]);
    }

    private function token(User $user): string
    {
        return $user->createToken('test', [$user->role->value])->plainTextToken;
    }

    private function actAs(User $user): self
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token($user));
    }

    public function test_admin_can_create_trip_event_with_details(): void
    {
        $admin = $this->makeUser(Church::factory()->create(), UserRole::Admin);

        $response = $this->actAs($admin)
            ->postJson('/api/v1/events', [
                'name' => 'Alexandria Youth Trip',
                'type' => EventType::Trip->value,
                'status' => EventStatus::Open->value,
                'is_active' => true,
                'event_date' => now()->addDays(10)->toDateString(),
                'location' => 'Alexandria',
                'destination' => 'Alexandria',
                'departure_location' => 'Church Hall',
                'max_capacity' => 90,
                'price_per_participant' => 250.5,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', EventType::Trip->value)
            ->assertJsonPath('data.status', EventStatus::Open->value);

        $this->assertDatabaseHas('events', [
            'name' => 'Alexandria Youth Trip',
            'destination' => 'Alexandria',
            'max_capacity' => 90,
        ]);
    }

    public function test_admin_can_update_event(): void
    {
        $church = Church::factory()->create();
        $event = Event::factory()->create(['church_id' => $church->id, 'max_capacity' => 50]);
        $admin = $this->makeUser($church, UserRole::Admin);

        $response = $this->actAs($admin)
            ->putJson("/api/v1/events/{$event->id}", [
                'name' => $event->name,
                'type' => EventType::Trip->value,
                'is_active' => true,
                'location' => 'New Location',
                'max_capacity' => 120,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'location' => 'New Location',
            'max_capacity' => 120,
        ]);
    }

    public function test_draft_event_can_be_published_closed_and_reopened(): void
    {
        $church = Church::factory()->create();
        $event = Event::factory()->create([
            'church_id' => $church->id,
            'status' => EventStatus::Draft->value,
            'is_active' => false,
        ]);
        $admin = $this->makeUser($church, UserRole::Admin);

        // Registration blocked while draft.
        $this->actAs($admin)
            ->postJson("/api/v1/events/{$event->id}/registrations", ['user_id' => $admin->id])
            ->assertStatus(422);

        $this->actAs($admin)->postJson("/api/v1/events/{$event->id}/publish")
            ->assertStatus(200)
            ->assertJsonPath('data.status', EventStatus::Open->value);

        $this->actAs($admin)->postJson("/api/v1/events/{$event->id}/close-registration")
            ->assertStatus(200)
            ->assertJsonPath('data.status', EventStatus::Closed->value);

        $this->actAs($admin)->postJson("/api/v1/events/{$event->id}/reopen-registration")
            ->assertStatus(200)
            ->assertJsonPath('data.status', EventStatus::Open->value);

        $this->assertSame(EventStatus::Open, $event->fresh()->status);
    }

    public function test_admin_can_register_member_for_event(): void
    {
        $church = Church::factory()->create();
        $event = Event::factory()->create(['church_id' => $church->id]);
        $admin = $this->makeUser($church, UserRole::Admin);
        $member = $this->makeUser($church, UserRole::Member);

        $response = $this->actAs($admin)
            ->postJson("/api/v1/events/{$event->id}/registrations", [
                'user_id' => $member->id,
                'notes' => 'Paid at church office',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.user.id', $member->id)
            ->assertJsonPath('data.status', RegistrationStatus::Pending->value);

        $this->assertDatabaseHas('event_registrations', [
            'event_id' => $event->id,
            'user_id' => $member->id,
            'registered_by' => $admin->id,
        ]);
    }

    public function test_duplicate_registration_is_prevented(): void
    {
        $church = Church::factory()->create();
        $event = Event::factory()->create(['church_id' => $church->id]);
        $admin = $this->makeUser($church, UserRole::Admin);
        $member = $this->makeUser($church, UserRole::Member);

        EventRegistration::factory()->create([
            'event_id' => $event->id,
            'user_id' => $member->id,
            'status' => RegistrationStatus::Confirmed->value,
        ]);

        $this->actAs($admin)
            ->postJson("/api/v1/events/{$event->id}/registrations", ['user_id' => $member->id])
            ->assertStatus(422);
    }

    public function test_registration_beyond_capacity_is_waitlisted(): void
    {
        $church = Church::factory()->create();
        $event = Event::factory()->create(['church_id' => $church->id, 'max_capacity' => 2]);
        $admin = $this->makeUser($church, UserRole::Admin);

        foreach ([1, 2] as $i) {
            EventRegistration::factory()->create([
                'event_id' => $event->id,
                'user_id' => $this->makeUser($church, UserRole::Member)->id,
            ]);
        }

        $extra = $this->makeUser($church, UserRole::Member);

        $response = $this->actAs($admin)
            ->postJson("/api/v1/events/{$event->id}/registrations", ['user_id' => $extra->id]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', RegistrationStatus::Waitlisted->value);

        $this->assertDatabaseHas('event_registrations', [
            'event_id' => $event->id,
            'user_id' => $extra->id,
            'status' => RegistrationStatus::Waitlisted->value,
        ]);
    }

    public function test_cancelling_a_registration_promotes_the_first_waitlisted_participant(): void
    {
        $church = Church::factory()->create();
        $event = Event::factory()->create(['church_id' => $church->id, 'max_capacity' => 1]);
        $admin = $this->makeUser($church, UserRole::Admin);
        $first = $this->makeUser($church, UserRole::Member);
        $waitlistedUser = $this->makeUser($church, UserRole::Member);

        $firstReg = EventRegistration::factory()->create([
            'event_id' => $event->id,
            'user_id' => $first->id,
        ]);
        $waitReg = EventRegistration::factory()->waitlisted()->create([
            'event_id' => $event->id,
            'user_id' => $waitlistedUser->id,
        ]);

        $this->actAs($admin)
            ->postJson("/api/v1/events/{$event->id}/registrations/{$firstReg->id}/cancel")
            ->assertStatus(200);

        $this->assertDatabaseHas('event_registrations', [
            'id' => $waitReg->id,
            'status' => RegistrationStatus::Pending->value,
        ]);
    }

    public function test_payment_tracking_updates_status_and_rejects_overpayment(): void
    {
        $church = Church::factory()->create();
        $event = Event::factory()->trip()->create(['church_id' => $church->id, 'price_per_participant' => 100]);
        $admin = $this->makeUser($church, UserRole::Admin);
        $member = $this->makeUser($church, UserRole::Member);

        $registration = EventRegistration::factory()->create([
            'event_id' => $event->id,
            'user_id' => $member->id,
        ]);

        // Partial payment.
        $this->actAs($admin)
            ->postJson("/api/v1/events/{$event->id}/registrations/{$registration->id}/payments", [
                'amount' => 60,
                'method' => 'cash',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('event_registrations', [
            'id' => $registration->id,
            'payment_status' => EventPaymentStatus::PartiallyPaid->value,
        ]);

        // Overpayment rejected.
        $this->actAs($admin)
            ->postJson("/api/v1/events/{$event->id}/registrations/{$registration->id}/payments", [
                'amount' => 500,
                'method' => 'cash',
            ])
            ->assertStatus(422);

        // Remaining payment completes it.
        $this->actAs($admin)
            ->postJson("/api/v1/events/{$event->id}/registrations/{$registration->id}/payments", [
                'amount' => 40,
                'method' => 'bank_transfer',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('event_registrations', [
            'id' => $registration->id,
            'payment_status' => EventPaymentStatus::Paid->value,
        ]);
    }

    public function test_check_in_by_qr_token_and_undo_check_in(): void
    {
        $church = Church::factory()->create();
        $event = Event::factory()->create(['church_id' => $church->id]);
        $servant = $this->makeUser($church, UserRole::Servant);
        $member = $this->makeUser($church, UserRole::Member);

        $registration = EventRegistration::factory()->create([
            'event_id' => $event->id,
            'user_id' => $member->id,
        ]);

        $this->actAs($servant)
            ->postJson("/api/v1/events/{$event->id}/registrations/check-in-by-token", [
                'qr_token' => $registration->qr_token,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.attendance_status', EventAttendanceStatus::CheckedIn->value);

        $this->assertNotNull($registration->fresh()->checked_in_at);

        // Duplicate check-in rejected.
        $this->actAs($servant)
            ->postJson("/api/v1/events/{$event->id}/registrations/check-in-by-token", [
                'qr_token' => $registration->qr_token,
            ])
            ->assertStatus(422);

        // Undo works.
        $this->actAs($servant)
            ->postJson("/api/v1/events/{$event->id}/registrations/{$registration->id}/undo-check-in")
            ->assertStatus(200)
            ->assertJsonPath('data.attendance_status', EventAttendanceStatus::NotCheckedIn->value);
    }

    public function test_member_cannot_manage_registrations_or_payments(): void
    {
        $church = Church::factory()->create();
        $event = Event::factory()->create(['church_id' => $church->id]);
        $member = $this->makeUser($church, UserRole::Member);

        $this->actAs($member)
            ->getJson("/api/v1/events/{$event->id}/registrations")
            ->assertStatus(403);

        $this->actAs($member)
            ->postJson("/api/v1/events/{$event->id}/registrations", ['user_id' => $member->id])
            ->assertStatus(403);

        $this->actAs($member)
            ->postJson("/api/v1/events/{$event->id}/registrations/1/payments", [
                'amount' => 50,
                'method' => 'cash',
            ])
            ->assertStatus(403);
    }

    public function test_bus_assignment_respects_bus_capacity(): void
    {
        $church = Church::factory()->create();
        $event = Event::factory()->trip()->create(['church_id' => $church->id]);
        $admin = $this->makeUser($church, UserRole::Admin);

        $busResponse = $this->actAs($admin)
            ->postJson("/api/v1/events/{$event->id}/buses", [
                'bus_number' => 'Bus 1',
                'capacity' => 1,
                'driver_name' => 'John',
            ])
            ->assertStatus(201);

        $busId = $busResponse->json('data.id');

        $regA = EventRegistration::factory()->create([
            'event_id' => $event->id,
            'user_id' => $this->makeUser($church, UserRole::Member)->id,
        ]);
        $regB = EventRegistration::factory()->create([
            'event_id' => $event->id,
            'user_id' => $this->makeUser($church, UserRole::Member)->id,
        ]);

        $this->actAs($admin)
            ->postJson("/api/v1/events/{$event->id}/registrations/{$regA->id}/assign-bus", ['bus_id' => $busId])
            ->assertStatus(200);

        // Second assignment exceeds capacity of 1.
        $this->actAs($admin)
            ->postJson("/api/v1/events/{$event->id}/registrations/{$regB->id}/assign-bus", ['bus_id' => $busId])
            ->assertStatus(422);

        $this->assertSame($busId, $regA->fresh()->bus_id);
        $this->assertNull($regB->fresh()->bus_id);
    }

    public function test_cancelled_event_rejects_new_registrations(): void
    {
        $church = Church::factory()->create();
        $event = Event::factory()->create(['church_id' => $church->id]);
        $admin = $this->makeUser($church, UserRole::Admin);
        $member = $this->makeUser($church, UserRole::Member);

        $this->actAs($admin)->postJson("/api/v1/events/{$event->id}/cancel")->assertStatus(200);

        $this->actAs($admin)
            ->postJson("/api/v1/events/{$event->id}/registrations", ['user_id' => $member->id])
            ->assertStatus(422);
    }

    public function test_completed_event_rejects_new_registrations(): void
    {
        $church = Church::factory()->create();
        $event = Event::factory()->create(['church_id' => $church->id]);
        $admin = $this->makeUser($church, UserRole::Admin);
        $member = $this->makeUser($church, UserRole::Member);

        $this->actAs($admin)->postJson("/api/v1/events/{$event->id}/complete")->assertStatus(200);

        $this->actAs($admin)
            ->postJson("/api/v1/events/{$event->id}/registrations", ['user_id' => $member->id])
            ->assertStatus(422);
    }
}
