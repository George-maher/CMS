<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\EventType;
use App\Enums\RegistrationStatus;
use App\Enums\UserRole;
use App\Models\Church;
use App\Models\Event;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end coverage of the Responsible Servant flow:
 * list eligibility → require on Conference/Trip → persist on create →
 * route reservation notifications → authorize approval → survive edits.
 */
class ResponsibleServantFlowTest extends TestCase
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

    private function actAs(User $user): self
    {
        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('test', [$user->role->value])->plainTextToken);
    }

    public function test_conference_requires_a_responsible_servant_on_create(): void
    {
        $church = Church::factory()->create();
        $admin = $this->makeUser($church, UserRole::Admin);

        $this->actAs($admin)
            ->postJson('/api/v1/events', [
                'name' => 'Conference Without Servant',
                'type' => EventType::Conference->value,
                'is_active' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('responsible_servant_id');
    }

    public function test_trip_requires_a_responsible_servant_on_create(): void
    {
        $church = Church::factory()->create();
        $admin = $this->makeUser($church, UserRole::Admin);

        $this->actAs($admin)
            ->postJson('/api/v1/events', [
                'name' => 'Trip Without Servant',
                'type' => EventType::Trip->value,
                'is_active' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('responsible_servant_id');
    }

    public function test_regular_event_does_not_require_a_responsible_servant(): void
    {
        $church = Church::factory()->create();
        $admin = $this->makeUser($church, UserRole::Admin);

        $this->actAs($admin)
            ->postJson('/api/v1/events', [
                'name' => 'Regular Gathering',
                'type' => EventType::Service->value,
                'is_active' => true,
                'event_date' => now()->addDays(7)->toDateString(),
            ])
            ->assertStatus(201);
    }

    public function test_creating_a_trip_persists_the_selected_responsible_servant(): void
    {
        $church = Church::factory()->create();
        $admin = $this->makeUser($church, UserRole::Admin);
        $servant = $this->makeUser($church, UserRole::Servant);

        $response = $this->actAs($admin)
            ->postJson('/api/v1/events', [
                'name' => 'Youth Trip',
                'type' => EventType::Trip->value,
                'status' => EventStatus::Open->value,
                'is_active' => true,
                'event_date' => now()->addDays(14)->toDateString(),
                'destination' => 'Alexandria',
                'max_capacity' => 50,
                'responsible_servant_id' => $servant->id,
            ]);

        $response->assertStatus(201);
        $eventId = $response->json('data.id');

        $this->assertDatabaseHas('events', [
            'id' => $eventId,
            'name' => 'Youth Trip',
            'responsible_servant_id' => $servant->id,
        ]);
    }

    public function test_admin_cannot_assign_a_servant_from_another_church(): void
    {
        $churchA = Church::factory()->create();
        $admin = $this->makeUser($churchA, UserRole::Admin);
        $foreignServant = $this->makeUser(Church::factory()->create(), UserRole::Servant);

        $this->actAs($admin)
            ->postJson('/api/v1/events', [
                'name' => 'Cross Church Trip',
                'type' => EventType::Trip->value,
                'status' => EventStatus::Open->value,
                'is_active' => true,
                'responsible_servant_id' => $foreignServant->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('responsible_servant_id');
    }

    public function test_registration_request_notifies_the_responsible_servant(): void
    {
        $church = Church::factory()->create();
        $admin = $this->makeUser($church, UserRole::Admin);
        $servant = $this->makeUser($church, UserRole::Servant);
        $member = $this->makeUser($church, UserRole::Member);

        $event = Event::factory()->create([
            'church_id' => $church->id,
            'type' => EventType::Trip->value,
            'status' => EventStatus::Open->value,
            'responsible_servant_id' => $servant->id,
        ]);

        // Admin (NOT the servant) registers the member — notification must still
        // be routed to the Responsible Servant of the event.
        $this->actAs($admin)
            ->postJson("/api/v1/events/{$event->id}/registrations", ['user_id' => $member->id])
            ->assertStatus(201);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $servant->id,
            'event_id' => $event->id,
            'church_id' => $church->id,
        ]);
    }

    public function test_responsible_servant_can_approve_but_other_servants_cannot(): void
    {
        $church = Church::factory()->create();
        $admin = $this->makeUser($church, UserRole::Admin);
        $responsible = $this->makeUser($church, UserRole::Servant);
        $otherServant = $this->makeUser($church, UserRole::Servant);
        $member = $this->makeUser($church, UserRole::Member);

        $event = Event::factory()->create([
            'church_id' => $church->id,
            'type' => EventType::Conference->value,
            'status' => EventStatus::Open->value,
            'responsible_servant_id' => $responsible->id,
        ]);

        $registrationId = $this->actAs($admin)
            ->postJson("/api/v1/events/{$event->id}/registrations", ['user_id' => $member->id])
            ->assertStatus(201)
            ->json('data.id');

        // A non-responsible servant must be rejected.
        $this->actAs($otherServant)
            ->postJson("/api/v1/events/{$event->id}/registrations/{$registrationId}/approve")
            ->assertStatus(422);

        // The Responsible Servant can approve.
        $this->actAs($responsible)
            ->postJson("/api/v1/events/{$event->id}/registrations/{$registrationId}/approve")
            ->assertStatus(200);

        $this->assertDatabaseHas('event_registrations', [
            'id' => $registrationId,
            'status' => RegistrationStatus::Approved->value,
        ]);
    }

    public function test_editing_an_event_without_sending_the_servant_preserves_the_assignment(): void
    {
        $church = Church::factory()->create();
        $admin = $this->makeUser($church, UserRole::Admin);
        $servant = $this->makeUser($church, UserRole::Servant);

        $event = Event::factory()->create([
            'church_id' => $church->id,
            'type' => EventType::Conference->value,
            'responsible_servant_id' => $servant->id,
        ]);

        $this->actAs($admin)
            ->patchJson("/api/v1/events/{$event->id}", [
                'name' => 'Renamed Conference',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'name' => 'Renamed Conference',
            'responsible_servant_id' => $servant->id,
        ]);
    }

    public function test_edit_can_change_the_responsible_servant(): void
    {
        $church = Church::factory()->create();
        $admin = $this->makeUser($church, UserRole::Admin);
        $oldServant = $this->makeUser($church, UserRole::Servant);
        $newServant = $this->makeUser($church, UserRole::Servant);

        $event = Event::factory()->create([
            'church_id' => $church->id,
            'type' => EventType::Trip->value,
            'responsible_servant_id' => $oldServant->id,
        ]);

        $this->actAs($admin)
            ->putJson("/api/v1/events/{$event->id}", [
                'name' => $event->name,
                'type' => EventType::Trip->value,
                'is_active' => true,
                'responsible_servant_id' => $newServant->id,
            ])
            ->assertStatus(200);

        $this->assertDatabaseMissing('events', [
            'id' => $event->id,
            'responsible_servant_id' => $oldServant->id,
        ]);
        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'responsible_servant_id' => $newServant->id,
        ]);
    }
}
