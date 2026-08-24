<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\EventType;
use App\Enums\UserRole;
use App\Models\Church;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DebugApproveTest extends TestCase
{
    use RefreshDatabase;

    public function test_debug(): void
    {
        $this->seed(PermissionSeeder::class);
        Permission::clearCache();

        $church = Church::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin, 'church_id' => $church->id]);
        $responsible = User::factory()->create(['role' => UserRole::Servant, 'church_id' => $church->id]);
        $otherServant = User::factory()->create(['role' => UserRole::Servant, 'church_id' => $church->id]);
        $member = User::factory()->create(['role' => UserRole::Member, 'church_id' => $church->id]);

        $event = \App\Models\Event::factory()->create([
            'church_id' => $church->id,
            'type' => EventType::Conference->value,
            'status' => EventStatus::Open->value,
            'responsible_servant_id' => $responsible->id,
        ]);

        dump('event responsible_servant_id persisted: '.var_export($event->fresh()->responsible_servant_id, true));
        dump('other servant id: '.var_export($otherServant->id, true));
        dump('rsid type: '.gettype($event->fresh()->responsible_servant_id).' / uid type: '.gettype($otherServant->id));
        dump('role class: '.get_class($otherServant->role));

        try {
            app(\App\Contracts\EventReservationServiceInterface::class)
                ->approve($event->fresh(), \App\Models\EventRegistration::findOrFail($regId), $otherServant);
            dump('direct service approve: ALLOWED');
        } catch (\Throwable $e) {
            dump('direct service approve threw: '.get_class($e).' — '.$e->getMessage());
        }

        $regId = $this->withHeader('Authorization', 'Bearer '.$admin->createToken('t', ['admin'])->plainTextToken)
            ->postJson("/api/v1/events/{$event->id}/registrations", ['user_id' => $member->id])
            ->assertStatus(201)
            ->json('data.id');

        $response = $this->withHeader('Authorization', 'Bearer '.$otherServant->createToken('t', ['servant'])->plainTextToken)
            ->postJson("/api/v1/events/{$event->id}/registrations/{$regId}/approve");

        dump('other servant approve status: '.$response->status());
        dump($response->json());

        $this->assertTrue(true);
    }
}
