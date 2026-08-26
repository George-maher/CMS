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
use App\Models\EventAccommodation;
use App\Models\EventRegistration;
use App\Models\EventRoom;
use App\Models\EventRoomCell;
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
        // actingAs() re-syncs the sanctum guard when switching users within
        // the same test — without it the guard may serve its memoized user
        // from an earlier request regardless of the Authorization header.
        $this->actingAs($user, 'sanctum');

        return $this->withHeader('Authorization', 'Bearer '.$this->token($user));
    }

    public function test_admin_can_create_trip_event_with_details(): void
    {
        $church = Church::factory()->create();
        $admin = $this->makeUser($church, UserRole::Admin);
        $servant = $this->makeUser($church, UserRole::Servant);

        $response = $this->actAs($admin)
            ->postJson('/api/v1/events', [
                'name' => 'Alexandria Youth Trip',
                'type' => EventType::Trip->value,
                'status' => EventStatus::Open->value,
                'is_active' => true,
                'responsible_servant_id' => $servant->id,
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
        $servant = $this->makeUser($church, UserRole::Servant);

        $response = $this->actAs($admin)
            ->putJson("/api/v1/events/{$event->id}", [
                'name' => $event->name,
                'type' => EventType::Trip->value,
                'is_active' => true,
                'responsible_servant_id' => $servant->id,
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

    public function test_admin_can_approve_and_reject_reservation(): void
    {
        $church = Church::factory()->create();
        $event = Event::factory()->create(['church_id' => $church->id, 'status' => EventStatus::Open->value]);
        $admin = $this->makeUser($church, UserRole::Admin);
        $member = $this->makeUser($church, UserRole::Member);

        $registration = EventRegistration::factory()->create([
            'event_id' => $event->id,
            'user_id' => $member->id,
            'status' => RegistrationStatus::Pending->value,
        ]);

        // Approve
        $this->actAs($admin)
            ->postJson("/api/v1/events/{$event->id}/registrations/{$registration->id}/approve")
            ->assertStatus(200)
            ->assertJsonPath('data.status', RegistrationStatus::Approved->value);

        // Reject
        $registration2 = EventRegistration::factory()->create([
            'event_id' => $event->id,
            'user_id' => $this->makeUser($church, UserRole::Member)->id,
            'status' => RegistrationStatus::Pending->value,
        ]);

        $this->actAs($admin)
            ->postJson("/api/v1/events/{$event->id}/registrations/{$registration2->id}/reject", [
                'reason' => 'Duplicate registration',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', RegistrationStatus::Rejected->value);

        $this->assertDatabaseHas('event_registrations', [
            'id' => $registration2->id,
            'status' => RegistrationStatus::Rejected->value,
            'rejection_reason' => 'Duplicate registration',
        ]);
    }

    public function test_only_approved_registrations_can_be_approved(): void
    {
        $church = Church::factory()->create();
        $event = Event::factory()->create(['church_id' => $church->id]);
        $admin = $this->makeUser($church, UserRole::Admin);

        $confirmed = EventRegistration::factory()->create([
            'event_id' => $event->id,
            'user_id' => $this->makeUser($church, UserRole::Member)->id,
            'status' => RegistrationStatus::Confirmed->value,
        ]);

        $this->actAs($admin)
            ->postJson("/api/v1/events/{$event->id}/registrations/{$confirmed->id}/approve")
            ->assertStatus(422);
    }

    public function test_responsible_servant_can_approve_reservations(): void
    {
        $church = Church::factory()->create();
        $servant = $this->makeUser($church, UserRole::Servant);
        $event = Event::factory()->create([
            'church_id' => $church->id,
            'status' => EventStatus::Open->value,
            'responsible_servant_id' => $servant->id,
        ]);
        $member = $this->makeUser($church, UserRole::Member);

        $registration = EventRegistration::factory()->create([
            'event_id' => $event->id,
            'user_id' => $member->id,
            'status' => RegistrationStatus::Pending->value,
        ]);

        $this->actAs($servant)
            ->postJson("/api/v1/events/{$event->id}/registrations/{$registration->id}/approve")
            ->assertStatus(200)
            ->assertJsonPath('data.status', RegistrationStatus::Approved->value);
    }

    public function test_non_responsible_servant_cannot_approve_reservations(): void
    {
        $church = Church::factory()->create();
        $otherServant = $this->makeUser($church, UserRole::Servant);
        $event = Event::factory()->create(['church_id' => $church->id]);
        $member = $this->makeUser($church, UserRole::Member);

        $registration = EventRegistration::factory()->create([
            'event_id' => $event->id,
            'user_id' => $member->id,
            'status' => RegistrationStatus::Pending->value,
        ]);

        $this->actAs($otherServant)
            ->postJson("/api/v1/events/{$event->id}/registrations/{$registration->id}/approve")
            ->assertStatus(422);
    }

    public function test_admin_can_create_conference_with_rooms(): void
    {
        $church = Church::factory()->create();
        $admin = $this->makeUser($church, UserRole::Admin);
        $servant = $this->makeUser($church, UserRole::Servant);

        $response = $this->actAs($admin)
            ->postJson('/api/v1/events', [
                'name' => 'Annual Conference',
                'type' => EventType::Conference->value,
                'is_active' => true,
                'responsible_servant_id' => $servant->id,
                'event_date' => now()->addDays(30)->toDateString(),
                'room_groups' => [
                    ['count' => 2, 'capacity' => 5],
                    ['count' => 1, 'capacity' => 3],
                ],
            ]);

        $response->assertStatus(201);

        $eventId = $response->json('data.id');

        // 3 rooms created (2 + 1)
        $this->assertDatabaseCount('event_rooms', 3);
        // 2 rooms * 5 cells + 1 room * 3 cells = 13 cells
        $this->assertDatabaseCount('event_room_cells', 13);

        // Check servant cells exist (1 per room)
        $servantCells = EventRoomCell::query()
            ->whereHas('room', fn ($q) => $q->where('event_id', $eventId))
            ->where('type', 'servant_reserved')
            ->count();
        $this->assertSame(3, $servantCells);
    }

    public function test_bulk_room_creation_via_event_create(): void
    {
        $church = Church::factory()->create();
        $admin = $this->makeUser($church, UserRole::Admin);
        $servant = $this->makeUser($church, UserRole::Servant);

        $this->actAs($admin)
            ->postJson('/api/v1/events', [
                'name' => 'Test Conference',
                'type' => EventType::Conference->value,
                'is_active' => true,
                'responsible_servant_id' => $servant->id,
                'room_groups' => [
                    ['count' => 1, 'capacity' => 6],
                ],
            ])
            ->assertStatus(201);

        $this->assertDatabaseCount('event_rooms', 1);
        // 1 servant + 5 member cells
        $this->assertDatabaseCount('event_room_cells', 6);
    }

    public function test_accommodation_assign_and_remove(): void
    {
        $church = Church::factory()->create();
        $admin = $this->makeUser($church, UserRole::Admin);
        $event = Event::factory()->create(['church_id' => $church->id]);
        $member = $this->makeUser($church, UserRole::Member);

        // Create room with cells manually
        $room = EventRoom::create([
            'event_id' => $event->id,
            'room_number' => 1,
            'capacity' => 3,
            'member_capacity' => 2,
        ]);

        $servantCell = EventRoomCell::create([
            'room_id' => $room->id,
            'cell_number' => 1,
            'type' => 'servant_reserved',
            'is_available' => false,
        ]);

        $memberCell = EventRoomCell::create([
            'room_id' => $room->id,
            'cell_number' => 2,
            'type' => 'member',
            'is_available' => true,
        ]);

        // Create approved registration
        $registration = EventRegistration::factory()->create([
            'event_id' => $event->id,
            'user_id' => $member->id,
            'status' => RegistrationStatus::Approved->value,
        ]);

        // Assign
        $this->actAs($admin)
            ->postJson("/api/v1/events/{$event->id}/accommodation/assign", [
                'registration_id' => $registration->id,
                'cell_id' => $memberCell->id,
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('event_accommodations', [
            'registration_id' => $registration->id,
            'cell_id' => $memberCell->id,
        ]);

        // Cell now occupied
        $this->assertDatabaseHas('event_room_cells', [
            'id' => $memberCell->id,
            'is_available' => false,
        ]);

        // Remove
        $this->actAs($admin)
            ->deleteJson("/api/v1/events/{$event->id}/accommodation/registrations/{$registration->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('event_accommodations', [
            'registration_id' => $registration->id,
        ]);

        // Cell released
        $this->assertDatabaseHas('event_room_cells', [
            'id' => $memberCell->id,
            'is_available' => true,
        ]);
    }

    public function test_duplicate_accommodation_is_prevented(): void
    {
        $church = Church::factory()->create();
        $admin = $this->makeUser($church, UserRole::Admin);
        $event = Event::factory()->create(['church_id' => $church->id]);
        $member = $this->makeUser($church, UserRole::Member);

        $room = EventRoom::create(['event_id' => $event->id, 'room_number' => 1, 'capacity' => 4, 'member_capacity' => 3]);
        $cell1 = EventRoomCell::create(['room_id' => $room->id, 'cell_number' => 2, 'type' => 'member', 'is_available' => true]);
        $cell2 = EventRoomCell::create(['room_id' => $room->id, 'cell_number' => 3, 'type' => 'member', 'is_available' => true]);

        $registration = EventRegistration::factory()->create([
            'event_id' => $event->id,
            'user_id' => $member->id,
            'status' => RegistrationStatus::Approved->value,
        ]);

        // First assignment
        $this->actAs($admin)
            ->postJson("/api/v1/events/{$event->id}/accommodation/assign", [
                'registration_id' => $registration->id,
                'cell_id' => $cell1->id,
            ])
            ->assertStatus(201);

        // Duplicate assignment should fail
        $this->actAs($admin)
            ->postJson("/api/v1/events/{$event->id}/accommodation/assign", [
                'registration_id' => $registration->id,
                'cell_id' => $cell2->id,
            ])
            ->assertStatus(422);
    }

    public function test_cannot_assign_to_servant_cell(): void
    {
        $church = Church::factory()->create();
        $admin = $this->makeUser($church, UserRole::Admin);
        $event = Event::factory()->create(['church_id' => $church->id]);

        $room = EventRoom::create(['event_id' => $event->id, 'room_number' => 1, 'capacity' => 3, 'member_capacity' => 2]);
        $servantCell = EventRoomCell::create(['room_id' => $room->id, 'cell_number' => 1, 'type' => 'servant_reserved', 'is_available' => false]);

        $registration = EventRegistration::factory()->create([
            'event_id' => $event->id,
            'user_id' => $this->makeUser($church, UserRole::Member)->id,
            'status' => RegistrationStatus::Approved->value,
        ]);

        $this->actAs($admin)
            ->postJson("/api/v1/events/{$event->id}/accommodation/assign", [
                'registration_id' => $registration->id,
                'cell_id' => $servantCell->id,
            ])
            ->assertStatus(422);
    }

    public function test_cross_church_accommodation_access_is_blocked(): void
    {
        $churchA = Church::factory()->create();
        $churchB = Church::factory()->create();
        $adminA = $this->makeUser($churchA, UserRole::Admin);
        $eventB = Event::factory()->create(['church_id' => $churchB->id]);

        $room = EventRoom::create(['event_id' => $eventB->id, 'room_number' => 1, 'capacity' => 3, 'member_capacity' => 2]);
        $cell = EventRoomCell::create(['room_id' => $room->id, 'cell_number' => 2, 'type' => 'member', 'is_available' => true]);

        $registration = EventRegistration::factory()->create([
            'event_id' => $eventB->id,
            'user_id' => $this->makeUser($churchB, UserRole::Member)->id,
            'status' => RegistrationStatus::Approved->value,
        ]);

        // Admin A tries to assign accommodation on Church B's event
        $this->actAs($adminA)
            ->postJson("/api/v1/events/{$eventB->id}/accommodation/assign", [
                'registration_id' => $registration->id,
                'cell_id' => $cell->id,
            ])
            ->assertStatus(404);
    }

    public function test_only_approved_registrations_can_be_assigned_accommodation(): void
    {
        $church = Church::factory()->create();
        $admin = $this->makeUser($church, UserRole::Admin);
        $event = Event::factory()->create(['church_id' => $church->id]);

        $room = EventRoom::create(['event_id' => $event->id, 'room_number' => 1, 'capacity' => 3, 'member_capacity' => 2]);
        $cell = EventRoomCell::create(['room_id' => $room->id, 'cell_number' => 2, 'type' => 'member', 'is_available' => true]);

        $pending = EventRegistration::factory()->create([
            'event_id' => $event->id,
            'user_id' => $this->makeUser($church, UserRole::Member)->id,
            'status' => RegistrationStatus::Pending->value,
        ]);

        $this->actAs($admin)
            ->postJson("/api/v1/events/{$event->id}/accommodation/assign", [
                'registration_id' => $pending->id,
                'cell_id' => $cell->id,
            ])
            ->assertStatus(422);
    }

    public function test_delete_room_with_occupants_is_blocked(): void
    {
        $church = Church::factory()->create();
        $admin = $this->makeUser($church, UserRole::Admin);
        $event = Event::factory()->create(['church_id' => $church->id]);

        $room = EventRoom::create(['event_id' => $event->id, 'room_number' => 1, 'capacity' => 3, 'member_capacity' => 2]);
        $cell = EventRoomCell::create(['room_id' => $room->id, 'cell_number' => 2, 'type' => 'member', 'is_available' => false]);

        $registration = EventRegistration::factory()->create([
            'event_id' => $event->id,
            'user_id' => $this->makeUser($church, UserRole::Member)->id,
            'status' => RegistrationStatus::Approved->value,
        ]);

        EventAccommodation::create([
            'registration_id' => $registration->id,
            'cell_id' => $cell->id,
        ]);

        $this->actAs($admin)
            ->deleteJson("/api/v1/events/{$event->id}/accommodation/rooms/{$room->id}")
            ->assertStatus(422);
    }

    public function test_responsible_servant_can_update_event(): void
    {
        $church = Church::factory()->create();
        $servant = $this->makeUser($church, UserRole::Servant);
        $event = Event::factory()->create([
            'church_id' => $church->id,
            'responsible_servant_id' => $servant->id,
        ]);

        $this->actAs($servant)
            ->putJson("/api/v1/events/{$event->id}", [
                'name' => 'Updated by servant',
                'type' => EventType::Conference->value,
                'is_active' => true,
                'responsible_servant_id' => $servant->id,
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'name' => 'Updated by servant',
        ]);
    }

    public function test_non_responsible_servant_cannot_update_event(): void
    {
        $church = Church::factory()->create();
        $servant = $this->makeUser($church, UserRole::Servant);
        $otherChurch = Church::factory()->create();
        $otherEvent = Event::factory()->create(['church_id' => $otherChurch->id]);

        // Servant from church A cannot see/update event of church B (404 via BelongsToChurch scope)
        $this->actAs($servant)
            ->putJson("/api/v1/events/{$otherEvent->id}", [
                'name' => 'Hacked',
                'type' => EventType::Conference->value,
                'is_active' => true,
                'responsible_servant_id' => $servant->id,
            ])
            ->assertStatus(404);
    }

    private function makeTripEvent(Church $church, ?User $servant = null): Event
    {
        return Event::factory()->trip()->create([
            'church_id' => $church->id,
            'status' => EventStatus::Open->value,
            'is_active' => true,
            'responsible_servant_id' => $servant?->id,
        ]);
    }

    public function test_member_can_submit_reservation_request(): void
    {
        $church = Church::factory()->create();
        $servant = $this->makeUser($church, UserRole::Servant);
        $event = $this->makeTripEvent($church, $servant);
        $member = $this->makeUser($church, UserRole::Member);

        $response = $this->actAs($member)
            ->postJson("/api/v1/events/{$event->id}/member-reservation-request", [
                'status' => RegistrationStatus::Booked->value,
                'booked_with' => 'Hotel Nile',
                'amount_paid' => '250.00',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', RegistrationStatus::Booked->value);

        $this->assertDatabaseHas('event_registrations', [
            'event_id' => $event->id,
            'user_id' => $member->id,
            'status' => RegistrationStatus::Booked->value,
            'booking_with' => 'Hotel Nile',
        ]);

        // Responsible servant notified in-app.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $servant->id,
            'type' => 'event_reservation',
            'event_id' => $event->id,
        ]);
    }

    public function test_member_reservation_submission_updates_existing_request(): void
    {
        $church = Church::factory()->create();
        $servant = $this->makeUser($church, UserRole::Servant);
        $event = $this->makeTripEvent($church, $servant);
        $member = $this->makeUser($church, UserRole::Member);

        $this->actAs($member)
            ->postJson("/api/v1/events/{$event->id}/member-reservation-request", [
                'status' => RegistrationStatus::Thinking->value,
            ])
            ->assertStatus(201);

        $response = $this->actAs($member)
            ->postJson("/api/v1/events/{$event->id}/member-reservation-request", [
                'status' => RegistrationStatus::NotReserved->value,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', RegistrationStatus::NotReserved->value);

        // Exactly one registration — updated in place, not duplicated.
        $count = EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('user_id', $member->id)
            ->count();
        $this->assertSame(1, $count);
    }

    public function test_admin_can_list_event_registrations(): void
    {
        // Regression: the participants list previously used MySQL-only
        // FIELD() ordering, which crashed with a 500 on PostgreSQL.
        $church = Church::factory()->create();
        $admin = $this->makeUser($church, UserRole::Admin);
        $event = $this->makeTripEvent($church);
        $member = $this->makeUser($church, UserRole::Member);

        EventRegistration::factory()->create([
            'event_id' => $event->id,
            'user_id' => $member->id,
            'status' => RegistrationStatus::Booked->value,
        ]);

        $this->actAs($admin)
            ->getJson("/api/v1/events/{$event->id}/registrations?page=1&per_page=20")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_member_reservation_requires_valid_status(): void
    {
        $church = Church::factory()->create();
        $servant = $this->makeUser($church, UserRole::Servant);
        $event = $this->makeTripEvent($church, $servant);
        $member = $this->makeUser($church, UserRole::Member);

        // Missing status.
        $this->actAs($member)
            ->postJson("/api/v1/events/{$event->id}/member-reservation-request", [])
            ->assertStatus(422);

        // Servant-managed status submitted by a member is rejected.
        $this->actAs($member)
            ->postJson("/api/v1/events/{$event->id}/member-reservation-request", [
                'status' => RegistrationStatus::Approved->value,
            ])
            ->assertStatus(422);

        $count = EventRegistration::query()->where('event_id', $event->id)->count();
        $this->assertSame(0, $count);
    }

    public function test_member_cannot_override_servant_managed_registration(): void
    {
        $church = Church::factory()->create();
        $servant = $this->makeUser($church, UserRole::Servant);
        $admin = $this->makeUser($church, UserRole::Admin);
        $event = $this->makeTripEvent($church, $servant);
        $member = $this->makeUser($church, UserRole::Member);

        EventRegistration::factory()->create([
            'event_id' => $event->id,
            'user_id' => $member->id,
            'status' => RegistrationStatus::Approved->value,
        ]);

        $this->actAs($member)
            ->postJson("/api/v1/events/{$event->id}/member-reservation-request", [
                'status' => RegistrationStatus::Thinking->value,
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('event_registrations', [
            'event_id' => $event->id,
            'user_id' => $member->id,
            'status' => RegistrationStatus::Approved->value,
        ]);
    }

    public function test_member_identity_is_taken_from_token_not_payload(): void
    {
        $church = Church::factory()->create();
        $servant = $this->makeUser($church, UserRole::Servant);
        $event = $this->makeTripEvent($church, $servant);
        $memberA = $this->makeUser($church, UserRole::Member);
        $memberB = $this->makeUser($church, UserRole::Member);

        // Member A tries to submit on behalf of Member B — must be ignored.
        $this->actAs($memberA)
            ->postJson("/api/v1/events/{$event->id}/member-reservation-request", [
                'status' => RegistrationStatus::Booked->value,
                'user_id' => $memberB->id,
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('event_registrations', [
            'event_id' => $event->id,
            'user_id' => $memberA->id,
            'status' => RegistrationStatus::Booked->value,
        ]);

        $this->assertDatabaseMissing('event_registrations', [
            'event_id' => $event->id,
            'user_id' => $memberB->id,
        ]);
    }

    public function test_member_cannot_submit_reservation_for_cross_church_event(): void
    {
        $churchA = Church::factory()->create();
        $churchB = Church::factory()->create();
        $memberA = $this->makeUser($churchA, UserRole::Member);
        $eventB = $this->makeTripEvent($churchB);

        $this->actAs($memberA)
            ->postJson("/api/v1/events/{$eventB->id}/member-reservation-request", [
                'status' => RegistrationStatus::Booked->value,
            ])
            ->assertStatus(404);

        $count = EventRegistration::query()->where('event_id', $eventB->id)->count();
        $this->assertSame(0, $count);
    }

    private function makeApprovedMember(Church $church, Event $event): User
    {
        $member = $this->makeUser($church, UserRole::Member);

        EventRegistration::factory()->create([
            'event_id' => $event->id,
            'user_id' => $member->id,
            'status' => RegistrationStatus::Approved->value,
        ]);

        return $member;
    }

    /**
     * Regression: the unaccommodated endpoint previously crashed on
     * PostgreSQL because EventRegistration::accommodation() was declared as
     * belongsTo() referencing a nonexistent event_accommodation_id column.
     */
    public function test_unaccommodated_lists_approved_registrations_without_accommodation(): void
    {
        $church = Church::factory()->create();
        $admin = $this->makeUser($church, UserRole::Admin);
        $event = $this->makeTripEvent($church);
        $this->makeApprovedMember($church, $event);

        $this->actAs($admin)
            ->getJson("/api/v1/events/{$event->id}/accommodation/unaccommodated?per_page=50")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_accommodation_structure_cannot_be_created_twice(): void
    {
        $church = Church::factory()->create();
        $admin = $this->makeUser($church, UserRole::Admin);
        $event = $this->makeTripEvent($church);

        $this->actAs($admin)
            ->postJson("/api/v1/events/{$event->id}/accommodation/rooms", [
                'room_groups' => [
                    ['count' => 15, 'capacity' => 5],
                    ['count' => 5, 'capacity' => 7],
                ],
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.rooms_created', 20);

        // Exactly one servant-reserved cell per room.
        $servantCells = EventRoomCell::query()
            ->whereHas('room', fn ($q) => $q->where('event_id', $event->id))
            ->where('type', 'servant_reserved')
            ->count();
        $this->assertSame(20, $servantCells);

        // Second generation must be rejected with a clear validation error,
        // never a 500 or duplicated rooms.
        $this->actAs($admin)
            ->postJson("/api/v1/events/{$event->id}/accommodation/rooms", [
                'room_groups' => [['count' => 1, 'capacity' => 5]],
            ])
            ->assertStatus(422);

        $roomCount = EventRoom::query()->where('event_id', $event->id)->count();
        $this->assertSame(20, $roomCount);
    }

    public function test_member_accommodation_view_is_gated_by_approval(): void
    {
        $church = Church::factory()->create();
        $admin = $this->makeUser($church, UserRole::Admin);
        $event = $this->makeTripEvent($church);
        $member = $this->makeUser($church, UserRole::Member);

        $this->actAs($admin)->postJson("/api/v1/events/{$event->id}/accommodation/rooms", [
            'room_groups' => [['count' => 1, 'capacity' => 5]],
        ])->assertStatus(201);

        EventRegistration::factory()->create([
            'event_id' => $event->id,
            'user_id' => $member->id,
            'status' => RegistrationStatus::Thinking->value,
        ]);

        // Pending: rooms hidden.
        $response = $this->actAs($member)
            ->getJson("/api/v1/events/{$event->id}/accommodation/my-view")
            ->assertStatus(200)
            ->assertJsonPath('data.registration_status', RegistrationStatus::Thinking->value);

        $this->assertCount(0, $response->json('data.rooms'));

        // Approved: rooms exposed.
        EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('user_id', $member->id)
            ->update(['status' => RegistrationStatus::Approved->value]);

        $response = $this->actAs($member)
            ->getJson("/api/v1/events/{$event->id}/accommodation/my-view")
            ->assertStatus(200)
            ->assertJsonPath('data.registration_status', RegistrationStatus::Approved->value);

        $this->assertCount(1, $response->json('data.rooms'));
    }

    public function test_approved_member_can_select_an_available_member_cell(): void
    {
        $church = Church::factory()->create();
        $event = $this->makeTripEvent($church);
        $member = $this->makeApprovedMember($church, $event);

        $this->actAs($this->makeUser($church, UserRole::Admin))
            ->postJson("/api/v1/events/{$event->id}/accommodation/rooms", [
                'room_groups' => [['count' => 1, 'capacity' => 5]],
            ])->assertStatus(201);

        /** @var EventRoomCell $cell */
        $cell = EventRoomCell::query()
            ->whereHas('room', fn ($q) => $q->where('event_id', $event->id))
            ->where('type', 'member')
            ->where('is_available', true)
            ->first();

        $this->actAs($member)
            ->postJson("/api/v1/events/{$event->id}/accommodation/select-cell", [
                'cell_id' => $cell->id,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.cell_id', $cell->id);

        $this->assertDatabaseHas('event_room_cells', [
            'id' => $cell->id,
            'is_available' => false,
        ]);

        $this->assertDatabaseHas('event_accommodations', [
            'cell_id' => $cell->id,
        ]);

        // Selecting again is blocked (already has accommodation).
        $this->actAs($member)
            ->postJson("/api/v1/events/{$event->id}/accommodation/select-cell", [
                'cell_id' => $cell->id,
            ])
            ->assertStatus(422);
    }

    public function test_member_cannot_select_pending_servant_or_occupied_cells(): void
    {
        $church = Church::factory()->create();
        $event = $this->makeTripEvent($church);
        $pendingMember = $this->makeUser($church, UserRole::Member);
        $approvedMember = $this->makeApprovedMember($church, $event);
        $secondApproved = $this->makeApprovedMember($church, $event);

        $this->actAs($this->makeUser($church, UserRole::Admin))
            ->postJson("/api/v1/events/{$event->id}/accommodation/rooms", [
                'room_groups' => [['count' => 1, 'capacity' => 3]],
            ])->assertStatus(201);

        $cells = EventRoomCell::query()
            ->whereHas('room', fn ($q) => $q->where('event_id', $event->id))
            ->orderBy('id')
            ->get();

        $servantCell = $cells->firstWhere('type', 'servant_reserved');
        $freeMemberCell = $cells->firstWhere(fn ($c) => $c->type === 'member' && $c->is_available);

        // Pending member cannot select any cell.
        $this->actAs($pendingMember)
            ->postJson("/api/v1/events/{$event->id}/accommodation/select-cell", [
                'cell_id' => $freeMemberCell->id,
            ])
            ->assertStatus(422);

        // Servant-reserved cell is blocked even for approved members.
        $this->actAs($approvedMember)
            ->postJson("/api/v1/events/{$event->id}/accommodation/select-cell", [
                'cell_id' => $servantCell->id,
            ])
            ->assertStatus(422);

        // First approved member takes the free member cell.
        $this->actAs($approvedMember)
            ->postJson("/api/v1/events/{$event->id}/accommodation/select-cell", [
                'cell_id' => $freeMemberCell->id,
            ])
            ->assertStatus(201);

        // A second approved member hitting the same (now occupied) cell loses
        // the race and receives a clear error.
        $this->actAs($secondApproved)
            ->postJson("/api/v1/events/{$event->id}/accommodation/select-cell", [
                'cell_id' => $freeMemberCell->id,
            ])
            ->assertStatus(422);
    }

    /**
     * Regression: member requests arrive with status booked/not_reserved/
     * thinking — the review service previously only accepted Pending, so a
     * responsible servant could never approve a real member request.
     */
    public function test_responsible_servant_can_approve_booked_member_request(): void
    {
        $church = Church::factory()->create();
        $servant = $this->makeUser($church, UserRole::Servant);
        $event = $this->makeTripEvent($church, $servant);
        $member = $this->makeUser($church, UserRole::Member);

        // Member submits a real request through the API (status = booked).
        $regId = $this->actAs($member)
            ->postJson("/api/v1/events/{$event->id}/member-reservation-request", [
                'status' => RegistrationStatus::Booked->value,
            ])
            ->assertStatus(201)
            ->json('data.id');

        $response = $this->actAs($servant)
            ->postJson("/api/v1/events/{$event->id}/registrations/{$regId}/approve")
            ->assertStatus(200)
            ->assertJsonPath('data.status', RegistrationStatus::Approved->value);

        $approvedAt = $response->json('data.approved_at');
        $this->assertNotNull($approvedAt);

        $registration = EventRegistration::query()->findOrFail($regId);
        $this->assertSame($servant->id, $registration->approved_by);
        $this->assertNotNull($registration->approved_at);

        // Member notified in-app.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $member->id,
            'type' => 'event_reservation',
            'event_id' => $event->id,
        ]);
    }

    public function test_double_approve_is_rejected(): void
    {
        $church = Church::factory()->create();
        $servant = $this->makeUser($church, UserRole::Servant);
        $event = $this->makeTripEvent($church, $servant);
        $member = $this->makeApprovedMember($church, $event);

        $regId = EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('user_id', $member->id)
            ->value('id');

        // Already approved — approving again must not create another approval.
        $this->actAs($servant)
            ->postJson("/api/v1/events/{$event->id}/registrations/{$regId}/approve")
            ->assertStatus(422);

        $registration = EventRegistration::query()->findOrFail($regId);
        $this->assertSame(RegistrationStatus::Approved->value, $registration->status->value);
    }

    public function test_rejection_records_metadata_and_keeps_accommodation_locked(): void
    {
        $church = Church::factory()->create();
        $servant = $this->makeUser($church, UserRole::Servant);
        $event = $this->makeTripEvent($church, $servant);
        $member = $this->makeUser($church, UserRole::Member);

        $regId = $this->actAs($member)
            ->postJson("/api/v1/events/{$event->id}/member-reservation-request", [
                'status' => RegistrationStatus::Thinking->value,
            ])
            ->assertStatus(201)
            ->json('data.id');

        $admin = $this->makeUser($church, UserRole::Admin);
        $roomsResp = $this->actAs($admin)->postJson("/api/v1/events/{$event->id}/accommodation/rooms", [
            'room_groups' => [['count' => 1, 'capacity' => 5]],
        ]);
        if (! $roomsResp->isSuccessful()) {
            fwrite(STDERR, 'DEBUG admin-tok='.substr($this->token($admin), 0, 12).' member-tok='.substr($this->token($member), 0, 12).PHP_EOL);
            fwrite(STDERR, 'DEBUG rooms response: '.$roomsResp->getContent().PHP_EOL);
        }
        $roomsResp->assertStatus(201);

        $this->actAs($servant)
            ->postJson("/api/v1/events/{$event->id}/registrations/{$regId}/reject", [
                'reason' => 'No spaces left with the group',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', RegistrationStatus::Rejected->value);

        $registration = EventRegistration::query()->findOrFail($regId);
        $this->assertSame($servant->id, $registration->rejected_by);
        $this->assertNotNull($registration->rejected_at);
        $this->assertSame('No spaces left with the group', $registration->rejection_reason);

        // Member notified.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $member->id,
            'type' => 'event_reservation',
        ]);

        // Accommodation stays locked for rejected members.
        $view = $this->actAs($member)
            ->getJson("/api/v1/events/{$event->id}/accommodation/my-view")
            ->assertStatus(200)
            ->json('data');

        $this->assertSame(RegistrationStatus::Rejected->value, $view['registration_status']);
        $this->assertCount(0, $view['rooms']);

        // Double rejection is not processed again.
        $this->actAs($servant)
            ->postJson("/api/v1/events/{$event->id}/registrations/{$regId}/reject")
            ->assertStatus(422);
    }
}
