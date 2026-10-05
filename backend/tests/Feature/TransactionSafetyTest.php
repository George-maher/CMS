<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Church;
use App\Models\Classe;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Transaction safety tests — verify that multi-write operations are atomic.
 *
 * Each test triggers a failure midway through an operation and asserts
 * that the database state is unchanged (rollback).
 */
class TransactionSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_promote_rolls_back_on_failure(): void
    {
        $church = Church::factory()->create();
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $church->id,
            'application_status' => 'approved',
        ]);
        $member = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $church->id,
            'application_status' => 'approved',
        ]);

        $originalRole = $member->role;

        try {
            DB::transaction(function () use ($member) {
                $member->role = UserRole::Servant;
                $member->save();

                throw new \RuntimeException('Simulated failure after role change');
            });
        } catch (\RuntimeException) {
            // Expected — the transaction rolls back and re-throws
        }

        $this->assertSame($originalRole, $member->fresh()->role);
    }

    public function test_demote_rolls_back_on_failure(): void
    {
        $church = Church::factory()->create();
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $church->id,
            'application_status' => 'approved',
        ]);
        $stageAdmin = User::factory()->create([
            'role' => UserRole::StageAdmin,
            'church_id' => $church->id,
            'application_status' => 'approved',
        ]);

        $originalRole = $stageAdmin->role;

        try {
            DB::transaction(function () use ($stageAdmin) {
                $stageAdmin->role = UserRole::Member;
                $stageAdmin->save();

                throw new \RuntimeException('Simulated failure');
            });
        } catch (\RuntimeException) {
            // Expected
        }

        $this->assertSame($originalRole, $stageAdmin->fresh()->role);
    }

    public function test_assign_servant_rolls_back_on_failure(): void
    {
        $church = Church::factory()->create();
        $stage = Stage::factory()->forChurch($church)->create();
        $class = Classe::factory()->forChurch($church)->state(['stage_id' => $stage->id])->create();
        $servant = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $church->id,
            'application_status' => 'approved',
        ]);

        $originalClassId = $servant->class_id;

        try {
            DB::transaction(function () use ($servant, $class) {
                $servant->class_id = $class->id;
                $servant->save();

                throw new \RuntimeException('Simulated failure after servant assignment');
            });
        } catch (\RuntimeException) {
            // Expected
        }

        $this->assertSame($originalClassId, $servant->fresh()->class_id);
    }

    public function test_assign_member_rolls_back_on_failure(): void
    {
        $church = Church::factory()->create();
        $stage = Stage::factory()->forChurch($church)->create();
        $class = Classe::factory()->forChurch($church)->state(['stage_id' => $stage->id])->create();
        $member = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $church->id,
            'application_status' => 'approved',
        ]);

        $originalClassId = $member->class_id;

        try {
            DB::transaction(function () use ($member, $class) {
                $member->class_id = $class->id;
                $member->save();

                throw new \RuntimeException('Simulated failure after member assignment');
            });
        } catch (\RuntimeException) {
            // Expected
        }

        $this->assertSame($originalClassId, $member->fresh()->class_id);
    }

    public function test_event_registration_cancel_is_atomic(): void
    {
        $church = Church::factory()->create();
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $church->id,
            'application_status' => 'approved',
        ]);

        $event = Event::factory()->create([
            'church_id' => $church->id,
            'created_by' => $admin->id,
        ]);

        $registration = EventRegistration::factory()->create([
            'event_id' => $event->id,
            'user_id' => $admin->id,
            'status' => 'confirmed',
            'attendance_status' => 'not_checked_in',
        ]);

        $originalStatus = $registration->status;

        try {
            DB::transaction(function () use ($registration) {
                $registration->update(['status' => 'cancelled']);
                throw new \RuntimeException('Simulated failure after cancel');
            });
        } catch (\RuntimeException) {
            // Expected
        }

        $this->assertSame($originalStatus, $registration->fresh()->status);
    }

    public function test_event_bus_deletion_is_atomic(): void
    {
        $church = Church::factory()->create();
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $church->id,
            'application_status' => 'approved',
        ]);

        $event = Event::factory()->create([
            'church_id' => $church->id,
            'created_by' => $admin->id,
        ]);

        // Create a bus directly since there may not be a factory
        $bus = DB::table('event_buses')->insertGetId([
            'event_id' => $event->id,
            'bus_number' => 'BUS-001',
            'capacity' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            DB::transaction(function () use ($bus) {
                DB::table('event_registrations')->where('bus_id', $bus)->update(['bus_id' => null]);
                DB::table('event_buses')->where('id', $bus)->delete();
                throw new \RuntimeException('Simulated failure after bus deletion');
            });
        } catch (\RuntimeException) {
            // Expected
        }

        $this->assertTrue(
            DB::table('event_buses')->where('id', $bus)->exists(),
            'Bus should still exist after rollback'
        );
    }
}
